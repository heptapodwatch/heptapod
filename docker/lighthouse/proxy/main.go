package main

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log"
	"net"
	"net/http"
	"net/netip"
	"strings"
	"sync"
	"time"

	"github.com/stripe/goproxy"
	"github.com/stripe/smokescreen/pkg/smokescreen"
	"github.com/stripe/smokescreen/pkg/smokescreen/conntrack"
)

type policy struct {
	SiteHost string `json:"siteHost"`
}
type scope struct {
	policy
	token            string
	connections      []net.Conn
	expires          time.Time
	initialIPs       []netip.Addr
	blockedHosts     map[string]bool
	blockedClasses   map[string]string
	blockedAddresses map[string]string
}
type scopeKey struct{}
type broker struct {
	sync.Mutex
	scopes map[string]*scope
}

func (b *broker) revoke(token string) {
	b.Lock()
	s := b.scopes[token]
	delete(b.scopes, token)
	b.Unlock()
	if s != nil {
		for _, conn := range s.connections {
			conn.Close()
		}
	}
}
func (b *broker) control(w http.ResponseWriter, r *http.Request) {
	if r.Method == "GET" {
		b.Lock()
		s := b.scopes[strings.TrimPrefix(r.URL.Path, "/scopes/")]
		if s == nil || time.Now().After(s.expires) {
			b.Unlock()
			http.NotFound(w, r)
			return
		}
		blocked := len(s.blockedHosts) > 0
		if host := r.URL.Query().Get("host"); host != "" {
			normalized, err := normalizeHost(host)
			if err != nil {
				b.Unlock()
				http.Error(w, "invalid host", http.StatusBadRequest)
				return
			}
			blocked = s.blockedHosts[normalized]
		}
		host, _ := normalizeHost(r.URL.Query().Get("host"))
		class := s.blockedClasses[host]
		address := s.blockedAddresses[host]
		b.Unlock()
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]interface{}{"blockedTarget": blocked, "targetClass": class, "targetAddress": address})
		return
	}
	if r.Method == "DELETE" {
		b.revoke(strings.TrimPrefix(r.URL.Path, "/scopes/"))
		w.WriteHeader(204)
		return
	}
	if r.Method != "POST" || r.URL.Path != "/scopes" {
		http.NotFound(w, r)
		return
	}
	// A rolling upgrade can briefly pair an old app with this proxy. Accept only its retired flag,
	// without granting any private-target permission; all other unknown fields still fail.
	var input struct {
		SiteHost              string `json:"siteHost"`
		LegacyPrivateOverride *bool  `json:"allowPrivateExternalTargets"`
	}
	decoder := json.NewDecoder(http.MaxBytesReader(w, r.Body, 4096))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&input) != nil {
		http.Error(w, "invalid policy", 400)
		return
	}
	p := policy{SiteHost: input.SiteHost}
	host, err := normalizeHost(p.SiteHost)
	if err != nil {
		http.Error(w, "invalid host", 400)
		return
	}
	p.SiteHost = host
	bytes := make([]byte, 32)
	if _, err := rand.Read(bytes); err != nil {
		http.Error(w, "scope creation failed", 500)
		return
	}
	token := hex.EncodeToString(bytes)
	b.Lock()
	b.scopes[token] = &scope{policy: p, token: token, expires: time.Now().Add(160 * time.Second)}
	b.Unlock()
	time.AfterFunc(160*time.Second, func() { b.revoke(token) })
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"token": token})
}
func (b *broker) authenticated(proxy http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "GET" && r.URL.Path == "/health" && !r.URL.IsAbs() {
			w.Write([]byte("up"))
			return
		}
		encoded, err := base64.StdEncoding.DecodeString(strings.TrimPrefix(r.Header.Get("Proxy-Authorization"), "Basic "))
		credentials := strings.SplitN(string(encoded), ":", 2)
		if err != nil || len(credentials) != 2 || credentials[0] != "run" {
			http.Error(w, "invalid scope", 407)
			return
		}
		b.Lock()
		s := b.scopes[credentials[1]]
		valid := s != nil && time.Now().Before(s.expires)
		b.Unlock()
		if !valid {
			http.Error(w, "invalid scope", 407)
			return
		}
		r.Header.Del("Proxy-Authorization")
		host, err := normalizeHost(r.URL.Hostname())
		if err == nil {
			ip, ipError := netip.ParseAddr(host)
			if metadataHost(host) || (ipError == nil && !allowedAddress(ip, host, s.SiteHost)) {
				b.blocked(s, host)
				http.Error(w, "Blocked target", http.StatusForbidden)
				return
			}
		}
		proxy.ServeHTTP(w, r.WithContext(context.WithValue(r.Context(), scopeKey{}, s)))
	})
}
func (b *broker) dial(ctx context.Context, network, address string, timeout time.Duration) (net.Conn, error) {
	pctx, ok := ctx.Value(goproxy.ProxyContextKey).(*goproxy.ProxyCtx)
	if !ok {
		return nil, errors.New("missing proxy context")
	}
	s, ok := pctx.Req.Context().Value(scopeKey{}).(*scope)
	if !ok {
		return nil, errors.New("missing scope")
	}
	decision := pctx.UserData.(*smokescreen.SmokescreenContext).Decision
	host, port, err := net.SplitHostPort(decision.OutboundHost)
	if err != nil {
		return nil, err
	}
	host, err = normalizeHost(host)
	if err != nil {
		return nil, err
	}
	if metadataHost(host) {
		b.blocked(s, host)
		return nil, errors.New("blocked metadata hostname")
	}
	lookup, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	ips, err := net.DefaultResolver.LookupNetIP(lookup, "ip", host)
	if err != nil || len(ips) == 0 {
		return nil, errors.New("resolution failed")
	}
	b.Lock()
	// Only the configured name's first connect-time resolution establishes intranet trust.
	if host == s.SiteHost && s.initialIPs == nil {
		s.initialIPs = append([]netip.Addr(nil), ips...)
	}
	initialIPs := append([]netip.Addr(nil), s.initialIPs...)
	b.Unlock()
	for _, ip := range ips {
		trustedIP := false
		for _, initial := range initialIPs {
			trustedIP = trustedIP || initial.Unmap() == ip.Unmap()
		}
		if !allowedAddress(ip, host, s.SiteHost) || (host == s.SiteHost && !publicAddress(ip) && !trustedIP) {
			b.blocked(s, host)
			b.Lock()
			s.blockedClasses[host] = addressClass(ip)
			s.blockedAddresses[host] = ip.Unmap().String()
			if host == s.SiteHost && !publicAddress(ip) && !trustedIP && s.blockedClasses[host] == "private_network" {
				s.blockedClasses[host] = "changed_private"
			}
			b.Unlock()
			return nil, errors.New("blocked target")
		}
		// The proxy's own listener can never be a destination, even under the operator override.
		if port == "3128" || port == "3129" {
			local, err := localAddress(ip)
			if err != nil {
				return nil, err
			}
			if local {
				b.blocked(s, host)
				return nil, errors.New("blocked proxy listener")
			}
		}
	}
	var conn net.Conn
	for _, ip := range ips {
		conn, err = (&net.Dialer{}).DialContext(lookup, network, net.JoinHostPort(ip.String(), port))
		if err == nil {
			break
		}
	}
	if err != nil {
		return nil, err
	}
	b.Lock()
	if time.Now().After(s.expires) {
		b.Unlock()
		conn.Close()
		return nil, errors.New("expired scope")
	}
	if b.scopes[s.token] != s {
		b.Unlock()
		conn.Close()
		return nil, errors.New("revoked scope")
	}
	s.connections = append(s.connections, conn)
	b.Unlock()
	return conn, nil
}
func (b *broker) blocked(s *scope, host string) {
	b.Lock()
	if s.blockedHosts == nil {
		s.blockedHosts = make(map[string]bool)
		s.blockedClasses = make(map[string]string)
		s.blockedAddresses = make(map[string]string)
	}
	s.blockedHosts[host] = true
	s.blockedClasses[host] = "private_network"
	if metadataHost(host) {
		s.blockedClasses[host] = "cloud_metadata"
	}
	if ip, err := netip.ParseAddr(host); err == nil {
		s.blockedClasses[host] = addressClass(ip)
		s.blockedAddresses[host] = ip.Unmap().String()
	}
	b.Unlock()
}
func localAddress(ip netip.Addr) (bool, error) {
	if ip.Unmap().IsLoopback() || ip.Unmap().IsUnspecified() {
		return true, nil
	}
	addresses, err := net.InterfaceAddrs()
	if err != nil {
		return false, err
	}
	for _, address := range addresses {
		prefix, err := netip.ParsePrefix(address.String())
		if err != nil {
			return false, err
		}
		if prefix.Addr().Unmap() == ip.Unmap() {
			return true, nil
		}
	}
	return false, nil
}
func newProxy(b *broker) http.Handler {
	config := smokescreen.NewConfig()
	// Smokescreen owns proxy framing and numeric dialing; the connect hook applies core policy to every resolved address.
	config.AllowRanges = []smokescreen.RuleRange{{Net: *mustCIDR("0.0.0.0/0")}, {Net: *mustCIDR("::/0")}}
	config.ProxyDialTimeout = b.dial
	config.Resolver = net.DefaultResolver
	config.Log.SetOutput(io.Discard) // Proxy logs otherwise include URLs and request credentials.
	config.ConnTracker = conntrack.NewTracker(165*time.Second, config.MetricsClient, config.Log, config.ShuttingDown, nil)
	proxy := smokescreen.BuildProxy(config)
	proxy.Tr.DisableKeepAlives = true
	proxy.Tr.Proxy = nil
	config.RejectResponseHandler = func(response *http.Response) {
		response.Header = make(http.Header)
		response.Body = io.NopCloser(strings.NewReader("Proxy request failed"))
		response.ContentLength = 20
	}
	return b.authenticated(proxy)
}
func main() {
	b := &broker{scopes: make(map[string]*scope)}
	handler := newProxy(b)
	// Bind only the management-network address; Chromium has no attachment to that network.
	go func() {
		log.Fatal((&http.Server{Addr: "lighthouse-proxy-control:3129", Handler: http.HandlerFunc(b.control), ReadHeaderTimeout: 5 * time.Second}).ListenAndServe())
	}()
	log.Fatal((&http.Server{Addr: ":3128", Handler: handler, ReadHeaderTimeout: 5 * time.Second}).ListenAndServe())
}
func mustCIDR(text string) *net.IPNet {
	_, block, err := net.ParseCIDR(text)
	if err != nil {
		panic(err)
	}
	return block
}

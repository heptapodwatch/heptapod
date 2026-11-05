package main

import (
	"bufio"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"os"
	"strings"
	"testing"
)

func TestCoreRangeParity(t *testing.T) {
	data, err := os.ReadFile("policy-cases.json")
	if err != nil {
		t.Fatal(err)
	}
	var cases map[string][]string
	if err := json.Unmarshal(data, &cases); err != nil {
		t.Fatal(err)
	}
	for kind, ips := range cases {
		for _, ip := range ips {
			t.Run(ip, func(t *testing.T) {
				address := netip.MustParseAddr(ip)
				if got := allowedAddress(address, "external.test", "site.test"); got != (kind == "allowed") {
					t.Fatalf("policy mismatch %s", ip)
				}
				if allowedAddress(address, "site.test", "site.test") != (kind != "alwaysBlocked") || allowedAddress(address, "external.test", "site.test") != (kind == "allowed") {
					t.Fatal("operator exception policy mismatch")
				}
			})
		}
	}
}
func TestLegacyScopeFlagIgnored(t *testing.T) {
	for _, flag := range []string{"true", "false"} {
		b := &broker{scopes: make(map[string]*scope)}
		response := httptest.NewRecorder()
		b.control(response, httptest.NewRequest("POST", "/scopes", strings.NewReader(`{"siteHost":"site.test","allowPrivateExternalTargets":`+flag+`}`)))
		if response.Code != 200 {
			t.Fatalf("old app scope rejected: %d", response.Code)
		}
		var created map[string]string
		json.Unmarshal(response.Body.Bytes(), &created)
		defer b.revoke(created["token"])
		if allowedAddress(netip.MustParseAddr("10.0.0.5"), "foreign.test", b.scopes[created["token"]].SiteHost) {
			t.Fatal("legacy flag granted foreign private access")
		}
	}
}
func TestMetadataNamesAlwaysBlocked(t *testing.T) {
	for _, host := range metadataTargets.Hosts {
		if allowedAddress(netip.MustParseAddr("8.8.8.8"), host, host) {
			t.Fatalf("metadata host admitted: %s", host)
		}
	}
}
func TestScopesThroughSmokescreen(t *testing.T) {
	b := &broker{scopes: make(map[string]*scope)}
	server := httptest.NewServer(newProxy(b))
	defer server.Close()
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("arrived")) }))
	defer target.Close()
	control := httptest.NewRecorder()
	b.control(control, httptest.NewRequest("POST", "/scopes", strings.NewReader(`{"siteHost":"127.0.0.1"}`)))
	var created map[string]string
	json.Unmarshal(control.Body.Bytes(), &created)
	token := created["token"]
	if len(token) != 64 {
		t.Fatal("missing random scope")
	}
	parsed, _ := url.Parse(server.URL)
	client := &http.Client{Transport: &http.Transport{Proxy: http.ProxyURL(parsed)}}
	get := func(destination, credential string) int {
		req, _ := http.NewRequestWithContext(context.Background(), "GET", destination, nil)
		req.Header.Set("Proxy-Authorization", "Basic "+base64.StdEncoding.EncodeToString([]byte("run:"+credential)))
		response, err := client.Do(req)
		if err != nil {
			t.Fatal(err)
		}
		io.Copy(io.Discard, response.Body)
		response.Body.Close()
		return response.StatusCode
	}
	if get(target.URL, token) != 200 {
		t.Fatal("own private host denied")
	}
	verdict := httptest.NewRecorder()
	b.control(verdict, httptest.NewRequest("GET", "/scopes/"+token+"?host=127.0.0.1", nil))
	if verdict.Code != 200 || !strings.Contains(verdict.Body.String(), `"blockedTarget":false`) {
		t.Fatal("invalid allowed-host verdict")
	}
	if get(strings.Replace(target.URL, "127.0.0.1", "localhost", 1), token) == 200 {
		t.Fatal("other private host admitted")
	}
	verdict = httptest.NewRecorder()
	b.control(verdict, httptest.NewRequest("GET", "/scopes/"+token+"?host=localhost", nil))
	if !strings.Contains(verdict.Body.String(), `"blockedTarget":true`) {
		t.Fatal("blocked host verdict missing")
	}
	if get(target.URL, "guess") != 407 {
		t.Fatal("guessable scope")
	}
	tlsTarget := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Proxy-Authorization") != "" {
			t.Error("proxy credential reached origin")
		}
		w.Write([]byte("encrypted"))
	}))
	defer tlsTarget.Close()
	roots := x509.NewCertPool()
	roots.AddCert(tlsTarget.Certificate())
	tlsClient := &http.Client{Transport: &http.Transport{Proxy: http.ProxyURL(parsed), TLSClientConfig: &tls.Config{RootCAs: roots}, ProxyConnectHeader: http.Header{"Proxy-Authorization": []string{"Basic " + base64.StdEncoding.EncodeToString([]byte("run:"+token))}}}}
	response, err := tlsClient.Get(tlsTarget.URL)
	if err != nil {
		t.Fatal(err)
	}
	io.Copy(io.Discard, response.Body)
	response.Body.Close()
	if response.StatusCode != 200 {
		t.Fatal("CONNECT scope failed")
	}
	b.Lock()
	connections := append([]net.Conn(nil), b.scopes[token].connections...)
	b.Unlock()
	b.revoke(token)
	for _, conn := range connections {
		if _, err := conn.Write([]byte("revoked")); err == nil {
			t.Fatal("revoked scope retained live connection")
		}
	}
	if get(target.URL, token) != 407 {
		t.Fatal("reusable revoked scope")
	}
	protected := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { t.Error("protected listener reached") }))
	listener, err := net.Listen("tcp", ":3128")
	if err != nil {
		t.Fatal(err)
	}
	protected.Listener.Close()
	protected.Listener = listener
	protected.Start()
	defer protected.Close()
	control = httptest.NewRecorder()
	b.control(control, httptest.NewRequest("POST", "/scopes", strings.NewReader(`{"siteHost":"127.0.0.1"}`)))
	json.Unmarshal(control.Body.Bytes(), &created)
	if created["token"] == token {
		t.Fatal("scope credential reused")
	}
	if get("http://127.0.0.1:3128/health", created["token"]) != 500 {
		t.Fatal("proxy listener not denied for configured host")
	}
	for _, address := range []string{"0.0.0.0", "::", "::ffff:0.0.0.0"} {
		destination := net.JoinHostPort(address, "3128")
		t.Run(address, func(t *testing.T) {
			if get("http://"+destination+"/health", created["token"]) != 403 {
				t.Error("unspecified HTTP target reached listener")
			}
			conn, err := net.Dial("tcp", server.Listener.Addr().String())
			if err != nil {
				t.Fatal(err)
			}
			defer conn.Close()
			fmt.Fprintf(conn, "CONNECT %s HTTP/1.1\r\nHost: %s\r\nProxy-Authorization: Basic %s\r\n\r\n", destination, destination, base64.StdEncoding.EncodeToString([]byte("run:"+created["token"])))
			response, err := http.ReadResponse(bufio.NewReader(conn), &http.Request{Method: "CONNECT"})
			if err != nil {
				t.Fatal(err)
			}
			response.Body.Close()
			if response.StatusCode != 403 {
				t.Error("unspecified CONNECT target reached listener")
			}
		})
	}
	b.revoke(created["token"])
}

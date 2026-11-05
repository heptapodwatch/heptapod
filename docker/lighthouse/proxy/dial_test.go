package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"golang.org/x/net/dns/dnsmessage"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestConnectTimeResolutionAndNumericDial(t *testing.T) {
	for _, mode := range []string{"rebinding", "mixed", "pinned", "same-host-rebinding"} {
		t.Run(mode, func(t *testing.T) {
			socket, err := net.ListenPacket("udp", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			defer socket.Close()
			var lookups atomic.Int32
			go func() {
				for {
					buffer := make([]byte, 4096)
					n, address, err := socket.ReadFrom(buffer)
					if err != nil {
						if !errors.Is(err, net.ErrClosed) {
							t.Error(err)
						}
						return
					}
					var query dnsmessage.Message
					if err := query.Unpack(buffer[:n]); err != nil {
						t.Error(err)
						return
					}
					response := dnsmessage.Message{Header: dnsmessage.Header{ID: query.ID, Response: true, Authoritative: true}, Questions: query.Questions}
					for _, question := range query.Questions {
						if question.Type != dnsmessage.TypeA {
							continue
						}
						count := lookups.Add(1)
						ips := [][4]byte{{8, 8, 8, 8}}
						if mode == "mixed" {
							ips = append(ips, [4]byte{127, 0, 0, 1})
						} else if mode == "pinned" || mode == "same-host-rebinding" {
							ips = [][4]byte{{127, 0, 0, 2}}
							if count == 2 {
								ips = [][4]byte{{127, 0, 0, 1}}
							}
						} else if count >= 2 {
							ips = [][4]byte{{127, 0, 0, 1}}
						}
						for _, ip := range ips {
							response.Answers = append(response.Answers, dnsmessage.Resource{Header: dnsmessage.ResourceHeader{Name: question.Name, Type: dnsmessage.TypeA, Class: dnsmessage.ClassINET}, Body: &dnsmessage.AResource{A: ip}})
						}
					}
					packet, err := response.Pack()
					if err != nil {
						t.Error(err)
						return
					}
					if _, err := socket.WriteTo(packet, address); err != nil {
						t.Error(err)
						return
					}
				}
			}()
			original := net.DefaultResolver
			net.DefaultResolver = &net.Resolver{PreferGo: true, Dial: func(ctx context.Context, network, address string) (net.Conn, error) {
				return (&net.Dialer{}).DialContext(ctx, "udp", socket.LocalAddr().String())
			}}
			defer func() { net.DefaultResolver = original }()
			var arrivals atomic.Int32
			target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				arrivals.Add(1)
				if mode == "same-host-rebinding" && r.URL.Path != "/final" {
					w.Header().Set("Location", "/final")
					w.WriteHeader(http.StatusFound)
					return
				}
				w.Write([]byte("arrived"))
			}))
			defer target.Close()
			if mode == "same-host-rebinding" {
				_, port, _ := net.SplitHostPort(target.Listener.Addr().String())
				listener, err := net.Listen("tcp", net.JoinHostPort("127.0.0.2", port))
				if err != nil {
					t.Fatal(err)
				}
				second := &http.Server{Handler: target.Config.Handler}
				go second.Serve(listener)
				defer second.Close()
			}
			b := &broker{scopes: make(map[string]*scope)}
			proxy := httptest.NewServer(newProxy(b))
			defer proxy.Close()
			siteHost := "site.test"
			if mode == "pinned" || mode == "same-host-rebinding" {
				siteHost = "resource.test"
			}
			body, _ := json.Marshal(policy{SiteHost: siteHost})
			control := httptest.NewRecorder()
			b.control(control, httptest.NewRequest("POST", "/scopes", strings.NewReader(string(body))))
			var created map[string]string
			json.Unmarshal(control.Body.Bytes(), &created)
			defer b.revoke(created["token"])
			parsed, _ := url.Parse(proxy.URL)
			client := &http.Client{Transport: &http.Transport{Proxy: http.ProxyURL(parsed)}, Timeout: 15 * time.Second}
			req, _ := http.NewRequest("GET", strings.Replace(target.URL, "127.0.0.1", "resource.test", 1), nil)
			req.Header.Set("Proxy-Authorization", "Basic "+base64.StdEncoding.EncodeToString([]byte("run:"+created["token"])))
			response, err := client.Do(req)
			if err != nil {
				t.Fatal(err)
			}
			io.Copy(io.Discard, response.Body)
			response.Body.Close()
			if mode == "pinned" {
				if response.StatusCode != 200 || arrivals.Load() != 1 || lookups.Load() != 2 {
					t.Fatalf("not a numeric pinned dial: status %d arrivals %d DNS %d", response.StatusCode, arrivals.Load(), lookups.Load())
				}
			} else if mode == "same-host-rebinding" {
				if response.StatusCode != 500 || arrivals.Load() != 1 || lookups.Load() != 4 {
					t.Fatalf("same-host private rebind admitted: status %d arrivals %d DNS %d", response.StatusCode, arrivals.Load(), lookups.Load())
				}
			} else if response.StatusCode != 500 || arrivals.Load() != 0 || lookups.Load() != 2 {
				t.Fatalf("unsafe connect-time policy: status %d arrivals %d DNS %d", response.StatusCode, arrivals.Load(), lookups.Load())
			}
		})
	}
}

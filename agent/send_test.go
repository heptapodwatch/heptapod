package main

import (
	"context"
	"io"
	"math"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

type step struct {
	status     int
	body       string
	retryAfter string
	wait       time.Duration
	keepSample bool
	logs       bool
}

// TestScheduleFollowsContractResponseTable posts to a fake instance and checks every row of the contract's response table.
func TestScheduleFollowsContractResponseTable(t *testing.T) {
	steps := []step{
		{status: 202, body: `{"interval_seconds": 60}`, wait: time.Minute, logs: true},
		{status: 202, body: `{"interval_seconds": 60}`, wait: time.Minute},
		{status: 200, body: `{"interval_seconds": 60}`, wait: time.Minute},
		{status: 202, body: `{"interval_seconds": 120}`, wait: 2 * time.Minute},
		{status: 202, body: `{"interval_seconds": 1}`, wait: 20 * time.Second}, // never faster than the instance's rate limit
		{status: 401, wait: 10 * time.Minute, logs: true},
		{status: 401, wait: 10 * time.Minute}, // logged once
		{status: 404, wait: 15 * time.Minute, logs: true},
		{status: 404, wait: 15 * time.Minute},
		{status: 413, body: `{"message":"too large", "echo":"Bearer hpa_srv1_secret"}`, wait: 20 * time.Second, logs: true},
		{status: 422, body: `{"message":"observed_at is stale"}`, wait: 20 * time.Second, logs: true},
		{status: 422, body: `{"message":"observed_at is stale"}`, wait: 20 * time.Second, logs: true},
		{status: 429, retryAfter: "45", wait: 45 * time.Second, logs: true},
		{status: 429, retryAfter: "Fri, 27 Nov 2026 07:02:00 GMT", wait: 2 * time.Minute},
		{status: 429, wait: 20 * time.Second}, // no Retry-After: the current interval
		{status: 429, retryAfter: "7200", wait: 2 * time.Hour},
		{status: 429, retryAfter: "99999999999999999999", wait: math.MaxInt64}, // saturates, never overflows
		{status: 429, retryAfter: "99999999999999999999junk", wait: 20 * time.Second}, // invalid: the current interval
		{status: 429, retryAfter: "+30", wait: 20 * time.Second},
		{status: 500, wait: 30 * time.Second, keepSample: true, logs: true},
		{status: 502, wait: time.Minute, keepSample: true, logs: true},
		{status: 503, wait: time.Minute, keepSample: true, logs: true}, // never longer than a minute
		{status: 504, wait: time.Minute, keepSample: true, logs: true},
		{status: 500, wait: time.Minute, keepSample: true, logs: true},
		{status: 202, body: `{"interval_seconds": 60}`, wait: time.Minute, logs: true},
		{status: 503, wait: 30 * time.Second, keepSample: true, logs: true}, // a success resets the backoff
		{status: 503, retryAfter: "15", wait: 15 * time.Second, keepSample: true, logs: true}, // maintenance: Retry-After wins
		{status: 503, retryAfter: "Fri, 27 Nov 2026 07:00:40 GMT", wait: 40 * time.Second, keepSample: true, logs: true},
		{status: 503, retryAfter: "3600", wait: time.Minute, keepSample: true, logs: true}, // bounded by the longest failure wait
		{status: 503, retryAfter: "junk", wait: time.Minute, keepSample: true, logs: true},
		{status: 500, retryAfter: "5", wait: time.Minute, keepSample: true, logs: true}, // only a 503 is read
	}
	var current step
	var received []*http.Request
	var bodies []string
	instance := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		received, bodies = append(received, r), append(bodies, string(body))
		if current.retryAfter != "" {
			w.Header().Set("Retry-After", current.retryAfter)
		}
		w.WriteHeader(current.status)
		io.WriteString(w, current.body)
	}))
	defer instance.Close()

	out := newSender(instance.URL+"/agent/v1/report", "hpa_srv1_secret")
	var plan schedule
	now := time.Date(2026, 11, 27, 7, 0, 0, 0, time.UTC)
	for i, next := range steps {
		current = next
		got := plan.next(out.post(context.Background(), []byte(`{"sample_id":"s"}`)), now)
		if got.wait != current.wait || got.keepSample != current.keepSample || (got.logLine != "") != current.logs {
			t.Errorf("step %d (HTTP %d): got wait %s keep %v log %q; want wait %s keep %v log %v",
				i, current.status, got.wait, got.keepSample, got.logLine, current.wait, current.keepSample, current.logs)
		}
		if strings.Contains(got.logLine, "hpa_") || strings.Contains(got.logLine, "message") {
			t.Errorf("step %d (HTTP %d) logged response body content: %q", i, current.status, got.logLine)
		}
	}

	request := received[0]
	if request.Method != http.MethodPost || request.URL.Path != "/agent/v1/report" ||
		request.Header.Get("Authorization") != "Bearer hpa_srv1_secret" || request.Header.Get("Content-Type") != "application/json" ||
		bodies[0] != `{"sample_id":"s"}` {
		t.Fatalf("unexpected request %s %s %v %q", request.Method, request.URL.Path, request.Header, bodies[0])
	}
}

// TestStatusIsActedOnWithoutWaitingForASlowBody: a 401 whose body never finishes is still a 401, not a network error.
func TestStatusIsActedOnWithoutWaitingForASlowBody(t *testing.T) {
	release := make(chan struct{})
	instance := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		io.WriteString(w, `{"message":`)
		w.(http.Flusher).Flush()
		select {
		case <-release:
		case <-r.Context().Done():
		}
	}))
	defer instance.Close()
	defer close(release)
	out := newSender(instance.URL, "hpa_x")
	out.client.Timeout = time.Second
	var plan schedule
	if got := plan.next(out.post(context.Background(), []byte(`{}`)), time.Now()); got.wait != 10*time.Minute || got.keepSample {
		t.Fatalf("slow 401 body: got %+v, want the 10-minute token wait", got)
	}
}

func TestRejectionLogNeverContainsTheResponseBody(t *testing.T) {
	var plan schedule
	for _, status := range []int{413, 422} {
		got := plan.next(reply{status: status, body: []byte(`{"echo":"Bearer hpa_srv1_secret"}`)}, time.Now())
		if got.logLine == "" || strings.Contains(got.logLine, "hpa_") {
			t.Fatalf("HTTP %d logged %q", status, got.logLine)
		}
	}
}

func TestNetworkErrorKeepsSampleAndBacksOff(t *testing.T) {
	instance := httptest.NewServer(http.NotFoundHandler())
	instance.Close()
	var plan schedule
	got := plan.next(newSender(instance.URL, "hpa_x").post(context.Background(), []byte(`{}`)), time.Now())
	if got.wait != 30*time.Second || !got.keepSample || !strings.Contains(got.logLine, "send failed") {
		t.Fatalf("got %+v", got)
	}
}

func TestRedirectIsNotFollowed(t *testing.T) {
	elsewhere := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Errorf("redirect followed with Authorization %q", r.Header.Get("Authorization"))
	}))
	defer elsewhere.Close()
	instance := httptest.NewServer(http.RedirectHandler(elsewhere.URL, http.StatusTemporaryRedirect))
	defer instance.Close()
	var plan schedule
	if got := plan.next(newSender(instance.URL, "hpa_x").post(context.Background(), []byte(`{}`)), time.Now()); !got.keepSample {
		t.Fatalf("a redirect must count as an instance error, got %+v", got)
	}
}

func TestLoadConfig(t *testing.T) {
	dir := t.TempDir()
	endpoint, credentials := filepath.Join(dir, "endpoint"), filepath.Join(dir, "credentials")
	os.Mkdir(credentials, 0o700)
	write := func(path, content string) {
		if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	write(filepath.Join(credentials, "token"), "hpa_srv1_c2VjcmV0\n")
	for url, ok := range map[string]bool{
		"https://heptapod.example.com/agent/v1/report\n": true,
		"http://127.0.0.1:8080/agent/v1/report":          true,
		"http://localhost:8080/agent/v1/report":          true,
		"http://heptapod.example.com/agent/v1/report":    false,
		"https://user:pass@heptapod.example.com/":        false,
		"heptapod.example.com/agent/v1/report":           false,
	} {
		write(endpoint, url)
		gotEndpoint, gotToken, err := loadConfig(endpoint, credentials)
		if (err == nil) != ok {
			t.Errorf("endpoint %q: err %v, want ok=%v", url, err, ok)
		}
		if ok && (gotEndpoint != strings.TrimSpace(url) || gotToken != "hpa_srv1_c2VjcmV0") {
			t.Errorf("endpoint %q: got %q %q", url, gotEndpoint, gotToken)
		}
	}

	write(endpoint, "https://heptapod.example.com/agent/v1/report")
	if _, _, err := loadConfig(endpoint, ""); err == nil {
		t.Error("accepted a missing credentials directory")
	}
	write(filepath.Join(credentials, "token"), "hpa_one\nhpa_two")
	if _, _, err := loadConfig(endpoint, credentials); err == nil {
		t.Error("accepted a token with a line break")
	}
}

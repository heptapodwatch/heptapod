package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"math"
	"net/http"
	"strconv"
	"strings"
	"time"
)

const (
	defaultInterval      = 60 * time.Second
	unauthorizedWait     = 10 * time.Minute
	notFoundWait         = 15 * time.Minute
	firstFailureWait     = 30 * time.Second
	maxFailureWait       = time.Minute // after instance downtime the agent reports within a minute of it answering again
	minWait              = 20 * time.Second // the instance accepts one sample per 20 s
	maxInterval          = time.Hour
	requestTimeout       = 10 * time.Second
	maxBufferedSampleAge = 9 * time.Minute // the instance rejects samples older than 10 minutes
)

type sender struct {
	endpoint string
	token    string
	client   *http.Client
}

func newSender(endpoint, token string) sender {
	return sender{endpoint: endpoint, token: token, client: &http.Client{
		Timeout: requestTimeout,
		// A redirect would resend the token somewhere the admin never configured.
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}}
}

// reply is what one POST produced: a status with its headers (and the body of a success), or a transport error.
type reply struct {
	status     int
	body       []byte
	retryAfter string
	err        error
}

func (s sender) post(ctx context.Context, body []byte) reply {
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, s.endpoint, bytes.NewReader(body))
	if err != nil {
		return reply{err: err}
	}
	request.Header.Set("Authorization", "Bearer "+s.token)
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")
	request.Header.Set("User-Agent", "heptapod-agent/"+version)
	response, err := s.client.Do(request)
	if err != nil {
		return reply{err: err}
	}
	defer response.Body.Close()
	out := reply{status: response.StatusCode, retryAfter: response.Header.Get("Retry-After")}
	// Only a success carries a body we use; a slow or broken one keeps the default interval instead of
	// turning a received status into a network error.
	if out.status == http.StatusAccepted || out.status == http.StatusOK {
		out.body, _ = io.ReadAll(io.LimitReader(response.Body, 4096))
	}
	return out
}

// schedule turns replies into the contract's response table: how long to wait, whether to resend the same sample,
// and what to log. It logs a repeated 2xx, 401, 404 or 429 only once.
type schedule struct {
	interval    time.Duration
	failures    int
	lastOutcome string
}

type decision struct {
	wait       time.Duration
	keepSample bool
	logLine    string
}

func (s *schedule) next(r reply, now time.Time) decision {
	if s.interval == 0 {
		s.interval = defaultInterval
	}
	outcome, d := s.decide(r, now)
	if outcome != "failure" {
		s.failures = 0
	}
	repeated := outcome == s.lastOutcome && outcome != "invalid" && outcome != "failure"
	s.lastOutcome = outcome
	if repeated {
		d.logLine = ""
	}
	return d
}

func (s *schedule) decide(r reply, now time.Time) (string, decision) {
	switch {
	case r.err != nil:
		return s.failure(fmt.Sprintf("send failed: %v", r.err))
	case r.status == http.StatusAccepted || r.status == http.StatusOK:
		var body struct {
			IntervalSeconds int `json:"interval_seconds"`
		}
		if json.Unmarshal(r.body, &body) == nil && body.IntervalSeconds > 0 {
			s.interval = clamp(time.Duration(body.IntervalSeconds) * time.Second)
		}
		return "accepted", decision{wait: s.interval, logLine: fmt.Sprintf("report accepted (HTTP %d); next in %s", r.status, s.interval)}
	case r.status == http.StatusUnauthorized:
		return "unauthorized", decision{wait: unauthorizedWait, logLine: "token rejected (HTTP 401): check /etc/heptapod-agent/token or rotate the token; retrying every 10 minutes"}
	case r.status == http.StatusNotFound:
		return "not-found", decision{wait: notFoundWait, logLine: "endpoint not found (HTTP 404): servers are off on the instance or this server was removed; retrying every 15 minutes"}
	case r.status == http.StatusRequestEntityTooLarge || r.status == http.StatusUnprocessableEntity:
		// The body is never logged: an instance may echo request data, including the Authorization header.
		return "invalid", decision{wait: s.interval, logLine: fmt.Sprintf("sample rejected (HTTP %d), dropped", r.status)}
	case r.status == http.StatusTooManyRequests:
		wait := min(retryAfter(r.retryAfter, now, s.interval), maxInterval)
		return "rate-limited", decision{wait: wait, logLine: fmt.Sprintf("rate limited (HTTP 429); next in %s", wait)}
	case r.status == http.StatusServiceUnavailable && r.retryAfter != "":
		// Maintenance: the instance says when to come back, never later than the longest failure wait.
		kind, d := s.failure("instance unavailable (HTTP 503)")
		d.wait = min(retryAfter(r.retryAfter, now, d.wait), maxFailureWait)
		d.logLine = fmt.Sprintf("instance unavailable (HTTP 503); retrying in %s", d.wait)
		return kind, d
	}
	return s.failure(fmt.Sprintf("instance error (HTTP %d)", r.status))
}

// failure backs off exponentially and keeps the sample: its sample_id makes the resend safe if the first one was stored.
func (s *schedule) failure(message string) (string, decision) {
	s.failures++
	wait := maxFailureWait // 30 s, then every minute
	if s.failures == 1 {
		wait = firstFailureWait
	}
	return "failure", decision{wait: wait, keepSample: true, logLine: fmt.Sprintf("%s; retrying in %s", message, wait)}
}

// retryAfter reads the header as delta seconds or an HTTP date, and falls back to the interval if it is neither.
// Values beyond what a Duration holds saturate instead of overflowing; callers cap the result.
func retryAfter(header string, now time.Time, fallback time.Duration) time.Duration {
	header = strings.TrimSpace(header)
	if header != "" && strings.Trim(header, "0123456789") == "" {
		seconds, err := strconv.ParseUint(header, 10, 64)
		if err != nil || seconds > uint64(math.MaxInt64/time.Second) { // only ErrRange is possible for digits
			return math.MaxInt64
		}
		return max(time.Duration(seconds)*time.Second, time.Second)
	}
	if date, err := http.ParseTime(header); err == nil {
		return max(date.Sub(now), time.Second)
	}
	return fallback
}

func clamp(wait time.Duration) time.Duration {
	return min(max(wait, minWait), maxInterval)
}

// run sends a sample per interval until ctx ends. At most one unsent sample is kept, and only while it is fresh enough.
func run(ctx context.Context, sys *system, out sender) error {
	cpu, _, err := sys.cpu()
	if err != nil {
		return err
	}
	if !sleep(ctx, time.Second) {
		return nil
	}
	var pending *sample
	var pendingSince time.Time
	var plan schedule
	for {
		now := time.Now()
		if pending == nil || now.Sub(pendingSince) > maxBufferedSampleAge {
			fresh, next, err := sys.collect(ctx, cpu, now)
			cpu = next
			if err != nil {
				log.Printf("collect failed: %v", err)
				if !sleep(ctx, defaultInterval) {
					return nil
				}
				continue
			}
			pending, pendingSince = &fresh, now
		}
		body, err := json.Marshal(pending)
		if err != nil {
			return err
		}
		d := plan.next(out.post(ctx, body), time.Now())
		if ctx.Err() != nil {
			return nil
		}
		if d.logLine != "" {
			log.Print(d.logLine)
		}
		if !d.keepSample {
			pending = nil
		}
		if !sleep(ctx, d.wait) {
			return nil
		}
	}
}

func sleep(ctx context.Context, wait time.Duration) bool {
	timer := time.NewTimer(wait)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-timer.C:
		return true
	}
}

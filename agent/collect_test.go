package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

var observedAt = time.Date(2026, 11, 27, 7, 0, 0, 0, time.UTC)

func fixtureSystem(t *testing.T, fixture, kernel string, disks map[string][2]uint64) *system {
	t.Helper()
	root := t.TempDir()
	if err := os.CopyFS(root, os.DirFS(filepath.Join("testdata", fixture))); err != nil {
		t.Fatal(err)
	}
	return &system{
		root:          root,
		statfsBudget:  3 * time.Second,
		uname:         func() (string, string, error) { return "web-1", kernel, nil },
		statfs: func(path string) (uint64, uint64, error) {
			disk, ok := disks["/"+strings.TrimPrefix(strings.TrimPrefix(path, root), "/")]
			if !ok {
				return 0, 0, os.ErrNotExist
			}
			return disk[0], disk[1], nil
		},
		interfaceAddrs: func() ([]netip.Addr, error) {
			var addrs []netip.Addr
			for _, ip := range []string{"127.0.0.1", "10.0.0.5", "172.17.0.1", "203.0.113.10", "fe80::1", "2001:db8::1", "100.101.1.2", "fd00::1", "::ffff:203.0.113.10"} {
				addrs = append(addrs, netip.MustParseAddr(ip))
			}
			return addrs, nil
		},
	}
}

func statBefore(t *testing.T) cpuTimes {
	t.Helper()
	data, err := os.ReadFile("testdata/proc-stat-before")
	if err != nil {
		t.Fatal(err)
	}
	before, _, err := parseStat(data)
	if err != nil {
		t.Fatal(err)
	}
	return before
}

func TestDebianSample(t *testing.T) {
	sys := fixtureSystem(t, "debian13", "6.12.48+deb13-amd64", map[string][2]uint64{
		"/":                {81923145728, 6549790720},
		"/boot":            {1023303680, 870318080}, // /boot and /boot/efi are skipped even when statfs answers
		"/boot/efi":        {535805952, 529530880},
		"/var/lib/docker":  {163857235968, 47552321536},
		"/srv/client data": {52710469632, 52710469632},
		"/mnt/archive":     {1 << 30, 1 << 29}, // autofs, nfs4, fuse.sshfs and cifs are skipped even when statfs answers
		"/mnt/backup":      {1 << 30, 1 << 29},
		"/mnt/remote":      {1 << 30, 1 << 29},
		"/mnt/share":       {1 << 30, 1 << 29},
		"/home":            {4096, 4096},
	})
	got, _, err := sys.collect(context.Background(), statBefore(t), observedAt)
	if err != nil {
		t.Fatal(err)
	}
	if !regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`).MatchString(got.SampleID) {
		t.Fatalf("sample_id %q is not a v4 UUID", got.SampleID)
	}
	got.SampleID, got.Agent.Arch = "", ""
	// cpu: busy 13500-12100 of total 94100-91600 jiffies = 56 %; memory in KiB*1024; /tmp and /etc/heptapod-agent
	// are bind mounts of /, /home is hidden by ProtectHome's tmpfs, /mnt/* are automount and network filesystems.
	want := `{"sample_id":"","observed_at":"2026-11-27T07:00:00Z",
		"agent":{"version":"dev","os":"linux","arch":"","distro":"Debian GNU/Linux 13 (trixie)","kernel":"6.12.48+deb13-amd64"},
		"host":{"hostname":"web-1","uptime_seconds":3542400,"reboot_required":true,"ips":["203.0.113.10","2001:db8::1"]},
		"cpu":{"cores":2,"used_percent":56},
		"load":{"1":1.8,"5":1.6,"15":1.4},
		"memory":{"total_bytes":8589934592,"available_bytes":1825361920},
		"filesystems":[
			{"mount":"/","total_bytes":81923145728,"free_bytes":6549790720},
			{"mount":"/srv/client data","total_bytes":52710469632,"free_bytes":52710469632},
			{"mount":"/var/lib/docker","total_bytes":163857235968,"free_bytes":47552321536}],
		"updates":{"pending":null,"security":null}}`
	assertJSON(t, got, want)
}

func TestDebianRebootRequired(t *testing.T) {
	for _, tc := range []struct {
		name, kernel string
		boot         []string
		want         string
	}{
		{"running the newest kernel", "6.12.57+deb13-amd64", []string{"vmlinuz-6.12.48+deb13-amd64", "vmlinuz-6.12.57+deb13-amd64"}, "false"},
		{"newer kernel installed", "6.12.9+deb13-amd64", []string{"vmlinuz-6.12.9+deb13-amd64", "vmlinuz-6.12.10+deb13-amd64"}, "true"},
		{"only older kernels left in /boot", "6.12.57+deb13-amd64", []string{"vmlinuz-6.12.48+deb13-amd64"}, "false"},
		{"no kernel images in /boot", "6.12.57+deb13-amd64", nil, "null"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			sys := fixtureSystem(t, "debian13", tc.kernel, nil)
			os.RemoveAll(filepath.Join(sys.root, "boot"))
			os.Mkdir(filepath.Join(sys.root, "boot"), 0o755)
			for _, image := range tc.boot {
				os.WriteFile(filepath.Join(sys.root, "boot", image), nil, 0o644)
			}
			got, _, err := sys.collect(context.Background(), statBefore(t), observedAt)
			if err != nil {
				t.Fatal(err)
			}
			assertJSON(t, got.Host.RebootRequired, tc.want)
		})
	}
}

func TestUnknownDistributionReportsNulls(t *testing.T) {
	sys := fixtureSystem(t, "debian13", "6.1.0", nil)
	os.WriteFile(filepath.Join(sys.root, "etc/os-release"), []byte("NAME=\"Alpine Linux\"\nID=alpine\nPRETTY_NAME=\"Alpine Linux v3.23\"\n"), 0o644)
	got, _, err := sys.collect(context.Background(), statBefore(t), observedAt)
	if err != nil {
		t.Fatal(err)
	}
	assertJSON(t, got.Host.RebootRequired, "null")
	assertJSON(t, got.Updates, `{"pending":null,"security":null}`)
}

func TestUbuntuSampleRunsAptCheckOnlyWhenPackagesChange(t *testing.T) {
	sys := fixtureSystem(t, "ubuntu2404", "6.8.0-88-generic", map[string][2]uint64{"/": {40000000000, 30000000000}})
	runs := filepath.Join(sys.root, "usr/lib/update-notifier/runs")
	collect := func() sample {
		got, _, err := sys.collect(context.Background(), statBefore(t), observedAt)
		if err != nil {
			t.Fatal(err)
		}
		return got
	}

	got := collect()
	assertJSON(t, got.Updates, `{"pending":7,"security":3}`)
	assertJSON(t, got.Host.RebootRequired, "true")
	assertJSON(t, got.Agent.Distro, `"Ubuntu 24.04.3 LTS"`)
	collect()
	if data, _ := os.ReadFile(runs); strings.Count(string(data), "run") != 1 {
		t.Fatalf("apt-check ran %d times for unchanged packages, want 1", strings.Count(string(data), "run"))
	}

	later := time.Now().Add(time.Minute)
	os.Chtimes(filepath.Join(sys.root, "var/lib/dpkg/status"), later, later)
	os.Remove(filepath.Join(sys.root, "run/reboot-required"))
	got = collect()
	assertJSON(t, got.Updates, `{"pending":7,"security":3}`)
	assertJSON(t, got.Host.RebootRequired, "false")
	if data, _ := os.ReadFile(runs); strings.Count(string(data), "run") != 2 {
		t.Fatal("apt-check did not rerun after dpkg changed")
	}
}

func TestFailedAptCheckIsRetriedAfterFifteenMinutes(t *testing.T) {
	sys := fixtureSystem(t, "ubuntu2404", "6.8.0-88-generic", nil)
	script := filepath.Join(sys.root, "usr/lib/update-notifier/apt-check")
	working, _ := os.ReadFile(script)
	os.WriteFile(script, []byte("#!/bin/sh\necho 'E: lock held' >&2\nexit 1\n"), 0o755)
	updatesAt := func(at time.Time) string {
		got, _, err := sys.collect(context.Background(), statBefore(t), at)
		if err != nil {
			t.Fatal(err)
		}
		value, _ := json.Marshal(got.Updates)
		return string(value)
	}

	if got := updatesAt(observedAt); got != `{"pending":null,"security":null}` {
		t.Fatalf("failed apt-check reported %s", got)
	}
	os.WriteFile(script, working, 0o755)
	if got := updatesAt(observedAt.Add(14 * time.Minute)); got != `{"pending":null,"security":null}` {
		t.Fatalf("apt-check reran before the retry interval: %s", got)
	}
	if got := updatesAt(observedAt.Add(15 * time.Minute)); got != `{"pending":7,"security":3}` {
		t.Fatalf("apt-check not retried after 15 minutes: %s", got)
	}
}

func TestHungMountDoesNotBlockTheSample(t *testing.T) {
	sys := fixtureSystem(t, "ubuntu2404", "6.8.0-88-generic", nil)
	sys.statfsBudget = 200 * time.Millisecond
	release := make(chan struct{})
	defer close(release)
	var calls atomic.Int32
	sys.statfs = func(string) (uint64, uint64, error) {
		calls.Add(1)
		<-release
		return 1, 1, nil
	}
	for range 2 {
		got, _, err := sys.collect(context.Background(), statBefore(t), observedAt)
		if err != nil {
			t.Fatal(err)
		}
		assertJSON(t, got.Filesystems, `[]`)
	}
	if calls.Load() != 1 {
		t.Fatalf("a hung mount was queried %d times, want once until it returns", calls.Load())
	}
}

func TestSeveralHungMountsShareOneBudget(t *testing.T) {
	sys := fixtureSystem(t, "debian13", "6.12.57+deb13-amd64", nil)
	sys.statfsBudget = 300 * time.Millisecond
	release := make(chan struct{})
	defer close(release)
	var mu sync.Mutex
	calls := map[string]int{}
	sys.statfs = func(path string) (uint64, uint64, error) {
		mount := "/" + strings.TrimPrefix(strings.TrimPrefix(path, sys.root), "/")
		mu.Lock()
		calls[mount]++
		mu.Unlock()
		if mount != "/" {
			<-release // /srv/client data, /var/lib/docker and /var/www hang
		}
		return 100, 50, nil
	}
	for range 2 {
		started := time.Now()
		got, _, err := sys.collect(context.Background(), statBefore(t), observedAt)
		if err != nil {
			t.Fatal(err)
		}
		// One shared budget, not one per mount: three hung mounts in sequence would take 900 ms.
		if elapsed := time.Since(started); elapsed > 800*time.Millisecond {
			t.Fatalf("sample took %s with three hung mounts and a 300 ms budget", elapsed)
		}
		assertJSON(t, got.Filesystems, `[{"mount":"/","total_bytes":100,"free_bytes":50}]`)
	}
	mu.Lock()
	defer mu.Unlock()
	for _, mount := range []string{"/srv/client data", "/var/lib/docker", "/var/www"} {
		if calls[mount] != 1 {
			t.Errorf("hung mount %s was queried %d times, want once while its call is pending", mount, calls[mount])
		}
	}
	if calls["/"] != 2 {
		t.Errorf("the healthy mount was queried %d times, want once per sample", calls["/"])
	}
}

func TestParseAptCheck(t *testing.T) {
	got, err := parseAptCheck([]byte("W: some apt warning\n12;0"))
	if err != nil {
		t.Fatal(err)
	}
	assertJSON(t, got, `{"pending":12,"security":0}`)
	for _, output := range []string{"", "E: Error: BrokenCount > 0", "3", "-1;0"} {
		if _, err := parseAptCheck([]byte(output)); err == nil {
			t.Errorf("parseAptCheck(%q) accepted invalid output", output)
		}
	}
}

func TestCompareVersions(t *testing.T) {
	ordered := []string{"6.1.0-9-amd64", "6.1.0-10-amd64", "6.12.9+deb13-amd64", "6.12.10+deb13-amd64", "6.12.57+deb13-amd64"}
	for i := 1; i < len(ordered); i++ {
		if compareVersions(ordered[i-1], ordered[i]) >= 0 || compareVersions(ordered[i], ordered[i-1]) <= 0 {
			t.Errorf("%s must sort before %s", ordered[i-1], ordered[i])
		}
	}
	if compareVersions("6.12.57+deb13-amd64", "6.12.57+deb13-amd64") != 0 {
		t.Error("equal versions must compare equal")
	}
}

// TestHostSampleMatchesContract runs the --once --dry-run path on the machine running the tests.
func TestHostSampleMatchesContract(t *testing.T) {
	var out bytes.Buffer
	if err := printSample(&out); err != nil {
		t.Fatal(err)
	}
	if problems := contractProblems(out.Bytes()); len(problems) > 0 {
		t.Fatalf("sample violates the ingest contract: %v\n%s", problems, out.String())
	}
	t.Logf("%s", out.String())
}

func TestContractProblemsRejectsInvalidSamples(t *testing.T) {
	valid := `{"sample_id":"0b9c6f8e-1c2d-4e5f-8a9b-0c1d2e3f4a5b","observed_at":"2026-11-27T07:00:00Z",
		"agent":{"version":"0.1.0","os":"linux","arch":"amd64","distro":"Debian 13","kernel":"6.12.0-1-amd64"},
		"host":{"hostname":"web-1","uptime_seconds":3542400,"reboot_required":null,"ips":["203.0.113.10"]},
		"cpu":{"cores":4,"used_percent":23.4},"load":{"1":1.8,"5":1.6,"15":1.4},
		"memory":{"total_bytes":8589934592,"available_bytes":1825361100},
		"filesystems":[{"mount":"/","total_bytes":81923145728,"free_bytes":6549790720}],
		"updates":{"pending":null,"security":null}}`
	if problems := contractProblems([]byte(valid)); len(problems) > 0 {
		t.Fatalf("contract example rejected: %v", problems)
	}
	for _, broken := range []string{
		strings.Replace(valid, `"used_percent":23.4`, `"used_percent":123.4`, 1),
		strings.Replace(valid, `"observed_at":"2026-11-27T07:00:00Z"`, `"observed_at":"2026-11-27 07:00"`, 1),
		strings.Replace(valid, `"mount":"/"`, `"mount":"relative"`, 1),
		strings.Replace(valid, `"203.0.113.10"`, `"not-an-ip"`, 1),
		strings.Replace(valid, `"total_bytes":8589934592`, `"total_bytes":-1`, 1),
		strings.Replace(valid, `"pending":null`, `"pending":"7"`, 1),
	} {
		if len(contractProblems([]byte(broken))) == 0 {
			t.Errorf("accepted an invalid sample: %s", broken)
		}
	}
}

// contractProblems checks a sample against the field rules of ingest contract v1, independently of the sample types.
func contractProblems(body []byte) []string {
	var problems []string
	fail := func(format string, args ...any) { problems = append(problems, fmt.Sprintf(format, args...)) }
	var s map[string]any
	if err := json.Unmarshal(body, &s); err != nil {
		return []string{err.Error()}
	}
	object := func(parent map[string]any, key string) map[string]any {
		value, ok := parent[key].(map[string]any)
		if !ok {
			fail("%s must be an object", key)
		}
		return value
	}
	text := func(parent map[string]any, key string, limit int) string {
		value, ok := parent[key].(string)
		if !ok || value == "" || len([]rune(value)) > limit {
			fail("%s must be a string of 1..%d chars, got %v", key, limit, parent[key])
		}
		return value
	}
	number := func(parent map[string]any, key string, low, high float64) float64 {
		value, ok := parent[key].(float64)
		if !ok || value < low || value > high {
			fail("%s must be a number in %v..%v, got %v", key, low, high, parent[key])
		}
		return value
	}
	count := func(parent map[string]any, key string) {
		if value := number(parent, key, 0, 1<<63); value != float64(int64(value)) {
			fail("%s must be an integer", key)
		}
	}
	nullableCount := func(parent map[string]any, key string) {
		if value, present := parent[key]; !present || value != nil {
			count(parent, key)
		}
	}

	if !regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`).MatchString(text(s, "sample_id", 36)) {
		fail("sample_id must be a UUID")
	}
	if observed, err := time.Parse(time.RFC3339, text(s, "observed_at", 40)); err != nil || observed.Location() != time.UTC {
		fail("observed_at must be RFC 3339 UTC")
	}
	agent := object(s, "agent")
	text(agent, "version", 32)
	text(agent, "os", 32)
	text(agent, "arch", 32)
	text(agent, "distro", 64)
	text(agent, "kernel", 128)
	host := object(s, "host")
	text(host, "hostname", 253)
	count(host, "uptime_seconds")
	if value := host["reboot_required"]; value != nil {
		if _, ok := value.(bool); !ok {
			fail("reboot_required must be a boolean or null")
		}
	}
	ips, ok := host["ips"].([]any)
	if !ok || len(ips) > 16 {
		fail("ips must be a list of at most 16")
	}
	for _, ip := range ips {
		value, _ := ip.(string)
		if _, err := netip.ParseAddr(value); err != nil {
			fail("invalid ip %v", ip)
		}
	}
	cpu := object(s, "cpu")
	count(cpu, "cores")
	number(cpu, "used_percent", 0, 100)
	load := object(s, "load")
	for _, key := range []string{"1", "5", "15"} {
		number(load, key, 0, 1e9)
	}
	memory := object(s, "memory")
	count(memory, "total_bytes")
	count(memory, "available_bytes")
	filesystems, ok := s["filesystems"].([]any)
	if !ok || len(filesystems) > 16 {
		fail("filesystems must be a list of at most 16")
	}
	for _, entry := range filesystems {
		fs, _ := entry.(map[string]any)
		if mount := text(fs, "mount", 256); !strings.HasPrefix(mount, "/") {
			fail("mount %q must be absolute", mount)
		}
		count(fs, "total_bytes")
		count(fs, "free_bytes")
	}
	updates := object(s, "updates")
	nullableCount(updates, "pending")
	nullableCount(updates, "security")
	return problems
}

func assertJSON(t *testing.T, got any, want string) {
	t.Helper()
	gotJSON, err := json.Marshal(got)
	if err != nil {
		t.Fatal(err)
	}
	var compact bytes.Buffer
	if err := json.Compact(&compact, []byte(want)); err != nil {
		t.Fatal(err)
	}
	if string(gotJSON) != compact.String() {
		t.Fatalf("got  %s\nwant %s", gotJSON, compact.String())
	}
}

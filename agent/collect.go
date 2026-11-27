package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/rand"
	"errors"
	"fmt"
	"io/fs"
	"log"
	"math"
	"net"
	"net/netip"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"slices"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
	"unicode/utf8"
)

// sample is the request body of ingest contract v1.
type sample struct {
	SampleID    string       `json:"sample_id"`
	ObservedAt  string       `json:"observed_at"`
	Agent       agentInfo    `json:"agent"`
	Host        hostInfo     `json:"host"`
	CPU         cpuInfo      `json:"cpu"`
	Load        loadInfo     `json:"load"`
	Memory      memoryInfo   `json:"memory"`
	Filesystems []filesystem `json:"filesystems"`
	Updates     updates      `json:"updates"`
}
type agentInfo struct {
	Version string `json:"version"`
	OS      string `json:"os"`
	Arch    string `json:"arch"`
	Distro  string `json:"distro"`
	Kernel  string `json:"kernel"`
}
type hostInfo struct {
	Hostname       string   `json:"hostname"`
	UptimeSeconds  uint64   `json:"uptime_seconds"`
	RebootRequired *bool    `json:"reboot_required"`
	IPs            []string `json:"ips"`
}
type cpuInfo struct {
	Cores       int     `json:"cores"`
	UsedPercent float64 `json:"used_percent"`
}
type loadInfo struct {
	One     float64 `json:"1"`
	Five    float64 `json:"5"`
	Fifteen float64 `json:"15"`
}
type memoryInfo struct {
	TotalBytes     uint64 `json:"total_bytes"`
	AvailableBytes uint64 `json:"available_bytes"`
}
type filesystem struct {
	Mount      string `json:"mount"`
	TotalBytes uint64 `json:"total_bytes"`
	FreeBytes  uint64 `json:"free_bytes"`
}

// updates holds nil counts where the distribution offers no unprivileged, offline count.
type updates struct {
	Pending  *int `json:"pending"`
	Security *int `json:"security"`
}

const maxListEntries = 16

// system is the machine the agent reads. Production reads "/"; tests point root at a fixture tree.
type system struct {
	root           string
	uname          func() (hostname, kernel string, err error)
	statfs         func(path string) (total, free uint64, err error)
	interfaceAddrs func() ([]netip.Addr, error)
	statfsBudget   time.Duration

	// A statfs that outlived statfsBudget may never return (a hung mount); that mount is skipped until it does.
	stuckMounts sync.Map

	// apt-check costs about a second of CPU, so a count is kept until dpkg or the apt lists change.
	// A failed run is retried after aptCheckRetry.
	aptCheck aptCheckResult
}

type aptCheckResult struct {
	key string
	ok  bool
	at  time.Time
	updates
}

const aptCheckRetry = 15 * time.Minute

func hostSystem() *system {
	return &system{
		root:          "/",
		statfsBudget:  3 * time.Second,
		uname: func() (string, string, error) {
			var u syscall.Utsname
			if err := syscall.Uname(&u); err != nil {
				return "", "", err
			}
			return utsString(u.Nodename[:]), utsString(u.Release[:]), nil
		},
		statfs: func(path string) (uint64, uint64, error) {
			var st syscall.Statfs_t
			if err := syscall.Statfs(path, &st); err != nil {
				return 0, 0, err
			}
			size := uint64(st.Frsize)
			if size == 0 {
				size = uint64(st.Bsize)
			}
			return st.Blocks * size, st.Bavail * size, nil
		},
		interfaceAddrs: func() ([]netip.Addr, error) {
			addrs, err := net.InterfaceAddrs()
			if err != nil {
				return nil, err
			}
			var ips []netip.Addr
			for _, addr := range addrs {
				if prefix, err := netip.ParsePrefix(addr.String()); err == nil {
					ips = append(ips, prefix.Addr())
				}
			}
			return ips, nil
		},
	}
}

func utsString(field []int8) string {
	b := make([]byte, 0, len(field))
	for _, c := range field {
		if c == 0 {
			break
		}
		b = append(b, byte(c))
	}
	return string(b)
}

func (s *system) read(path string) ([]byte, error) {
	return os.ReadFile(filepath.Join(s.root, path))
}

// cpuTimes is the aggregate "cpu" line of /proc/stat.
type cpuTimes struct{ busy, total uint64 }

func (s *system) cpu() (cpuTimes, int, error) {
	data, err := s.read("proc/stat")
	if err != nil {
		return cpuTimes{}, 0, err
	}
	return parseStat(data)
}

func parseStat(data []byte) (cpuTimes, int, error) {
	var times cpuTimes
	found, cores := false, 0
	for line := range strings.Lines(string(data)) {
		fields := strings.Fields(line)
		if len(fields) == 0 || !strings.HasPrefix(fields[0], "cpu") {
			continue
		}
		if fields[0] != "cpu" {
			cores++
			continue
		}
		// user nice system idle iowait irq softirq steal; guest time is already part of user.
		if len(fields) < 9 {
			return cpuTimes{}, 0, errors.New("proc/stat: short cpu line")
		}
		for i, field := range fields[1:9] {
			value, err := strconv.ParseUint(field, 10, 64)
			if err != nil {
				return cpuTimes{}, 0, fmt.Errorf("proc/stat: %w", err)
			}
			times.total += value
			if i != 3 && i != 4 {
				times.busy += value
			}
		}
		found = true
	}
	if !found || cores == 0 {
		return cpuTimes{}, 0, errors.New("proc/stat: no cpu lines")
	}
	return times, cores, nil
}

func usedPercent(before, after cpuTimes) float64 {
	if after.total <= before.total || after.busy < before.busy {
		return 0
	}
	percent := float64(after.busy-before.busy) / float64(after.total-before.total) * 100
	return math.Round(min(percent, 100)*10) / 10
}

// collect reads one sample; before is the previous /proc/stat reading, and the current one is returned for the next call.
func (s *system) collect(ctx context.Context, before cpuTimes, now time.Time) (sample, cpuTimes, error) {
	after, cores, err := s.cpu()
	if err != nil {
		return sample{}, before, err
	}
	out := sample{
		SampleID:   newSampleID(),
		ObservedAt: now.UTC().Format(time.RFC3339),
		Agent:      agentInfo{Version: capRunes(version, 32), OS: "linux", Arch: runtime.GOARCH},
		CPU:        cpuInfo{Cores: cores, UsedPercent: usedPercent(before, after)},
	}
	hostname, kernel, err := s.uname()
	if err != nil {
		return sample{}, after, fmt.Errorf("uname: %w", err)
	}
	out.Host.Hostname, out.Agent.Kernel = capRunes(hostname, 253), capRunes(kernel, 128)

	osRelease, err := s.osRelease()
	if err != nil {
		return sample{}, after, err
	}
	out.Agent.Distro = capRunes(osRelease["PRETTY_NAME"], 64)

	if out.Load, err = s.load(); err != nil {
		return sample{}, after, err
	}
	if out.Memory, err = s.memory(); err != nil {
		return sample{}, after, err
	}
	if out.Host.UptimeSeconds, err = s.uptime(); err != nil {
		return sample{}, after, err
	}
	if out.Filesystems, err = s.filesystems(); err != nil {
		return sample{}, after, err
	}
	addrs, err := s.interfaceAddrs()
	if err != nil {
		return sample{}, after, fmt.Errorf("interfaces: %w", err)
	}
	out.Host.IPs = reportableIPs(addrs)
	out.Host.RebootRequired = s.rebootRequired(osRelease["ID"], kernel)
	out.Updates = s.updates(ctx, osRelease["ID"], now)
	return out, after, nil
}

func newSampleID() string {
	var b [16]byte
	rand.Read(b[:])
	b[6] = b[6]&0x0f | 0x40 // version 4
	b[8] = b[8]&0x3f | 0x80 // RFC 4122 variant
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:])
}

func capRunes(value string, limit int) string {
	if utf8.RuneCountInString(value) <= limit {
		return value
	}
	return string([]rune(value)[:limit])
}

func (s *system) osRelease() (map[string]string, error) {
	data, err := s.read("etc/os-release")
	if errors.Is(err, fs.ErrNotExist) {
		data, err = s.read("usr/lib/os-release")
	}
	if err != nil {
		return nil, err
	}
	values := map[string]string{}
	for line := range strings.Lines(string(data)) {
		key, value, ok := strings.Cut(strings.TrimSpace(line), "=")
		if !ok || strings.HasPrefix(key, "#") {
			continue
		}
		if unquoted, err := strconv.Unquote(value); err == nil {
			value = unquoted
		} else {
			value = strings.Trim(value, `'"`)
		}
		values[key] = value
	}
	return values, nil
}

func (s *system) load() (loadInfo, error) {
	data, err := s.read("proc/loadavg")
	if err != nil {
		return loadInfo{}, err
	}
	fields := strings.Fields(string(data))
	if len(fields) < 3 {
		return loadInfo{}, errors.New("proc/loadavg: short line")
	}
	var values [3]float64
	for i := range values {
		if values[i], err = strconv.ParseFloat(fields[i], 64); err != nil || values[i] < 0 {
			return loadInfo{}, fmt.Errorf("proc/loadavg: invalid value %q", fields[i])
		}
	}
	return loadInfo{One: values[0], Five: values[1], Fifteen: values[2]}, nil
}

func (s *system) memory() (memoryInfo, error) {
	data, err := s.read("proc/meminfo")
	if err != nil {
		return memoryInfo{}, err
	}
	kib := map[string]uint64{}
	for line := range strings.Lines(string(data)) {
		fields := strings.Fields(line)
		if len(fields) >= 2 && (fields[0] == "MemTotal:" || fields[0] == "MemAvailable:") {
			value, err := strconv.ParseUint(fields[1], 10, 64)
			if err != nil {
				return memoryInfo{}, fmt.Errorf("proc/meminfo: %w", err)
			}
			kib[fields[0]] = value
		}
	}
	total, hasTotal := kib["MemTotal:"]
	available, hasAvailable := kib["MemAvailable:"]
	if !hasTotal || !hasAvailable {
		return memoryInfo{}, errors.New("proc/meminfo: MemTotal or MemAvailable missing")
	}
	return memoryInfo{TotalBytes: total * 1024, AvailableBytes: available * 1024}, nil
}

func (s *system) uptime() (uint64, error) {
	data, err := s.read("proc/uptime")
	if err != nil {
		return 0, err
	}
	fields := strings.Fields(string(data))
	if len(fields) == 0 {
		return 0, errors.New("proc/uptime: empty")
	}
	seconds, err := strconv.ParseFloat(fields[0], 64)
	if err != nil || seconds < 0 {
		return 0, fmt.Errorf("proc/uptime: invalid value %q", fields[0])
	}
	return uint64(seconds), nil
}

var pseudoFilesystems = []string{"tmpfs", "devtmpfs", "overlay", "squashfs", "proc", "sysfs", "cgroup", "cgroup2", "nsfs"}

// Automount points and network filesystems can hang statfs or trigger a mount; they are not this server's disks.
var remoteFilesystems = []string{"autofs", "nfs", "nfs4", "cifs", "smb3", "smbfs", "sshfs", "ceph", "glusterfs", "9p", "fuse"}

func skippedFilesystem(fsType string) bool {
	return slices.Contains(pseudoFilesystems, fsType) || slices.Contains(remoteFilesystems, fsType) || strings.HasPrefix(fsType, "fuse.")
}

type mountEntry struct{ device, mount, fsType string }

func (s *system) filesystems() ([]filesystem, error) {
	data, err := s.read("proc/self/mountinfo")
	if err != nil {
		return nil, err
	}
	mounts := realMounts(parseMountinfo(data))
	paths := make([]string, len(mounts))
	for i, entry := range mounts {
		paths[i] = filepath.Join(s.root, entry.mount)
	}
	sizes := s.statfsAll(paths)
	out := []filesystem{}
	for i, entry := range mounts {
		// Mounts the sandboxed agent cannot reach (e.g. below ProtectHome) or that hang are not reported.
		if sizes[i].err != nil || sizes[i].total == 0 {
			continue
		}
		out = append(out, filesystem{Mount: entry.mount, TotalBytes: sizes[i].total, FreeBytes: sizes[i].free})
		if len(out) == maxListEntries {
			break
		}
	}
	return out, nil
}

type statfsResult struct {
	total, free uint64
	err         error
}

var errStatfsPending = errors.New("statfs pending")

// statfsAll runs one statfs per path concurrently and waits at most statfsBudget for all of them, so hung mounts
// cannot stop the sample. A path whose earlier call never returned gets no second call until that one does.
func (s *system) statfsAll(paths []string) []statfsResult {
	type indexed struct {
		index int
		statfsResult
	}
	results := make([]statfsResult, len(paths))
	done := make(chan indexed, len(paths)) // buffered: calls that outlive the budget never block
	outstanding := 0
	for i, path := range paths {
		results[i].err = errStatfsPending
		if _, stuck := s.stuckMounts.LoadOrStore(path, true); stuck {
			continue
		}
		outstanding++
		go func() {
			total, free, err := s.statfs(path)
			s.stuckMounts.Delete(path)
			done <- indexed{i, statfsResult{total, free, err}}
		}()
	}
	timer := time.NewTimer(s.statfsBudget)
	defer timer.Stop()
	for ; outstanding > 0; outstanding-- {
		select {
		case r := <-done:
			results[r.index] = r.statfsResult
		case <-timer.C:
			log.Printf("%d statfs calls did not return within %s; skipping those mounts", outstanding, s.statfsBudget)
			return results
		}
	}
	return results
}

// parseMountinfo returns the visible mount per mount point: a later mount on the same point hides the earlier one.
func parseMountinfo(data []byte) []mountEntry {
	var entries []mountEntry
	index := map[string]int{}
	for line := range strings.Lines(string(data)) {
		fields := strings.Fields(line)
		separator := slices.Index(fields, "-")
		if separator < 6 || len(fields) < separator+2 {
			continue
		}
		entry := mountEntry{device: fields[2], mount: unescapeMount(fields[4]), fsType: fields[separator+1]}
		if i, seen := index[entry.mount]; seen {
			entries[i] = entry
			continue
		}
		index[entry.mount] = len(entries)
		entries = append(entries, entry)
	}
	return entries
}

// unescapeMount decodes the octal escapes (\040 for a space) the kernel writes into mountinfo paths.
func unescapeMount(path string) string {
	var b strings.Builder
	for i := 0; i < len(path); i++ {
		if path[i] == '\\' && i+3 < len(path) {
			if value, err := strconv.ParseUint(path[i+1:i+4], 8, 8); err == nil {
				b.WriteByte(byte(value))
				i += 3
				continue
			}
		}
		b.WriteByte(path[i])
	}
	return b.String()
}

// realMounts drops the contract's pseudo filesystems and keeps one mount per device (bind mounts repeat it), sorted by path.
func realMounts(entries []mountEntry) []mountEntry {
	byDevice := map[string]mountEntry{}
	for _, entry := range entries {
		if skippedFilesystem(entry.fsType) || !filepath.IsAbs(entry.mount) || len(entry.mount) > 256 || underVirtualTree(entry.mount) {
			continue
		}
		if kept, seen := byDevice[entry.device]; seen && len(kept.mount) <= len(entry.mount) {
			continue
		}
		byDevice[entry.device] = entry
	}
	mounts := make([]mountEntry, 0, len(byDevice))
	for _, entry := range byDevice {
		mounts = append(mounts, entry)
	}
	slices.SortFunc(mounts, func(a, b mountEntry) int { return strings.Compare(a.mount, b.mount) })
	return mounts
}

func underVirtualTree(mount string) bool {
	for _, tree := range []string{"/proc", "/sys", "/dev", "/run"} {
		if mount == tree || strings.HasPrefix(mount, tree+"/") {
			return true
		}
	}
	return false
}

// Shared address space (RFC 6598), used by carrier NAT and tailnets; never what a site's DNS points at.
var sharedAddressSpace = netip.MustParsePrefix("100.64.0.0/10")

// reportableIPs keeps public unicast addresses: the instance matches them against the sites' DNS records.
func reportableIPs(addrs []netip.Addr) []string {
	ips := []string{}
	for _, addr := range addrs {
		addr = addr.Unmap()
		if !addr.IsGlobalUnicast() || addr.IsPrivate() || sharedAddressSpace.Contains(addr) || slices.Contains(ips, addr.String()) {
			continue
		}
		ips = append(ips, addr.String())
		if len(ips) == maxListEntries {
			break
		}
	}
	return ips
}

// rebootRequired uses Ubuntu's (and unattended-upgrades') flag file, then on Debian checks whether a kernel newer
// than the running one is installed. Elsewhere it is unknown.
func (s *system) rebootRequired(distroID, runningKernel string) *bool {
	if _, err := os.Stat(filepath.Join(s.root, "run/reboot-required")); err == nil {
		return new(true)
	}
	switch distroID {
	case "ubuntu":
		return new(false)
	case "debian":
		images, _ := filepath.Glob(filepath.Join(s.root, "boot/vmlinuz-*"))
		if len(images) == 0 {
			return nil
		}
		newest := ""
		for _, image := range images {
			if candidate := strings.TrimPrefix(filepath.Base(image), "vmlinuz-"); compareVersions(candidate, newest) > 0 {
				newest = candidate
			}
		}
		return new(compareVersions(newest, runningKernel) > 0)
	}
	return nil
}

// compareVersions orders kernel release strings by their digit runs numerically and everything else bytewise.
func compareVersions(a, b string) int {
	for a != "" && b != "" {
		aDigits, bDigits := isDigit(a[0]), isDigit(b[0])
		if aDigits != bDigits {
			if aDigits {
				return 1
			}
			return -1
		}
		aRun, bRun := leadingRun(a, aDigits), leadingRun(b, bDigits)
		var order int
		if aDigits {
			aNumber, bNumber := strings.TrimLeft(aRun, "0"), strings.TrimLeft(bRun, "0")
			if order = len(aNumber) - len(bNumber); order == 0 {
				order = strings.Compare(aNumber, bNumber)
			}
		} else {
			order = strings.Compare(aRun, bRun)
		}
		if order != 0 {
			return order
		}
		a, b = a[len(aRun):], b[len(bRun):]
	}
	return len(a) - len(b)
}

func isDigit(c byte) bool { return c >= '0' && c <= '9' }

func leadingRun(value string, digits bool) string {
	end := 0
	for end < len(value) && isDigit(value[end]) == digits {
		end++
	}
	return value[:end]
}

const aptCheckPath = "usr/lib/update-notifier/apt-check"

// updates runs Ubuntu's apt-check, the only update count available without root or network.
// Debian and other distributions report both counts as unknown.
func (s *system) updates(ctx context.Context, distroID string, now time.Time) updates {
	if distroID != "ubuntu" {
		return updates{}
	}
	key := s.modTime("var/lib/dpkg/status") + "|" + s.modTime("var/lib/apt/lists")
	if key == s.aptCheck.key && (s.aptCheck.ok || now.Sub(s.aptCheck.at) < aptCheckRetry) {
		return s.aptCheck.updates
	}
	value, ok := runAptCheck(ctx, filepath.Join(s.root, aptCheckPath))
	s.aptCheck = aptCheckResult{key: key, ok: ok, at: now, updates: value}
	return value
}

func runAptCheck(ctx context.Context, path string) (updates, bool) {
	ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	var stderr bytes.Buffer
	command := exec.CommandContext(ctx, path)
	command.Stderr = &stderr
	if err := command.Run(); err != nil {
		log.Printf("apt-check failed, updates unknown: %v: %s", err, capRunes(strings.TrimSpace(stderr.String()), 200))
		return updates{}, false
	}
	value, err := parseAptCheck(stderr.Bytes())
	if err != nil {
		log.Printf("apt-check: %v", err)
		return updates{}, false
	}
	return value, true
}

func (s *system) modTime(path string) string {
	info, err := os.Stat(filepath.Join(s.root, path))
	if err != nil {
		return ""
	}
	return info.ModTime().String()
}

// parseAptCheck reads apt-check's "pending;security" from the last line of its stderr.
func parseAptCheck(stderr []byte) (updates, error) {
	var last string
	scanner := bufio.NewScanner(bytes.NewReader(stderr))
	for scanner.Scan() {
		if line := strings.TrimSpace(scanner.Text()); line != "" {
			last = line
		}
	}
	pendingText, securityText, ok := strings.Cut(last, ";")
	pending, pendingErr := strconv.Atoi(pendingText)
	security, securityErr := strconv.Atoi(securityText)
	if !ok || pendingErr != nil || securityErr != nil || pending < 0 || security < 0 {
		return updates{}, fmt.Errorf("unexpected output %q", capRunes(last, 80))
	}
	return updates{Pending: &pending, Security: &security}, nil
}

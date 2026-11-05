package main

import (
	"bytes"
	_ "embed"
	"encoding/json"
	"errors"
	"github.com/stripe/smokescreen/pkg/smokescreen/hostport"
	"io"
	"net/netip"
	"strings"
)

//go:embed metadata-targets.json
var metadataJSON []byte

var metadataTargets struct {
	Hosts     []string `json:"hosts"`
	Addresses []string `json:"addresses"`
}
var metadataAddresses []netip.Addr

func init() {
	decoder := json.NewDecoder(bytes.NewReader(metadataJSON))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&metadataTargets); err != nil {
		panic(err)
	}
	if decoder.Decode(&struct{}{}) != io.EOF {
		panic("invalid outbound metadata policy JSON")
	}
	if len(metadataTargets.Hosts) == 0 || len(metadataTargets.Addresses) == 0 {
		panic("outbound metadata policy requires nonempty target lists")
	}
	for _, host := range metadataTargets.Hosts {
		normalized, err := normalizeHost(host)
		if err != nil || normalized != host {
			panic("invalid outbound metadata policy hostname")
		}
	}
	for _, address := range metadataTargets.Addresses {
		ip := netip.MustParseAddr(address)
		if ip.Zone() != "" {
			panic("invalid outbound metadata policy address")
		}
		metadataAddresses = append(metadataAddresses, ip)
	}
}

// Match PHP 8.5 FILTER_FLAG_GLOBAL_RANGE | NO_PRIV_RANGE | NO_RES_RANGE and core's tunnel exclusions.
// Do not use IsGlobalUnicast: PHP permits multicast and rejects all of 192.0.0.0/24.
var translated = []netip.Prefix{
	netip.MustParsePrefix("64:ff9b::/96"), netip.MustParsePrefix("64:ff9b:1::/48"), netip.MustParsePrefix("2002::/16"),
}
var denied = append([]netip.Prefix{
	netip.MustParsePrefix("0.0.0.0/8"), netip.MustParsePrefix("10.0.0.0/8"), netip.MustParsePrefix("100.64.0.0/10"),
	netip.MustParsePrefix("127.0.0.0/8"), netip.MustParsePrefix("169.254.0.0/16"), netip.MustParsePrefix("172.16.0.0/12"),
	netip.MustParsePrefix("192.0.0.0/24"), netip.MustParsePrefix("192.0.2.0/24"), netip.MustParsePrefix("192.168.0.0/16"),
	netip.MustParsePrefix("198.18.0.0/15"), netip.MustParsePrefix("198.51.100.0/24"), netip.MustParsePrefix("203.0.113.0/24"), netip.MustParsePrefix("240.0.0.0/4"),
	netip.MustParsePrefix("::/128"), netip.MustParsePrefix("::1/128"), netip.MustParsePrefix("100::/64"),
	netip.MustParsePrefix("2001::/23"), netip.MustParsePrefix("2001:db8::/32"), netip.MustParsePrefix("fc00::/7"), netip.MustParsePrefix("fe80::/10"),
}, translated...)

func normalizeHost(host string) (string, error) {
	host = strings.TrimRight(strings.Trim(host, "[]"), ".")
	if host == "" {
		return "", errors.New("invalid host")
	}
	return hostport.NormalizeHost(host, false)
}
func publicAddress(ip netip.Addr) bool {
	if !ip.IsValid() || ip.Zone() != "" {
		return false
	}
	ip = ip.Unmap()
	for _, prefix := range denied {
		if prefix.Contains(ip) {
			return false
		}
	}
	return true
}
func metadataHost(host string) bool {
	for _, blocked := range metadataTargets.Hosts {
		if host == blocked {
			return true
		}
	}
	return false
}
func addressClass(ip netip.Addr) string {
	ip = ip.Unmap()
	if ip.String() == "169.254.169.254" {
		return "cloud_metadata"
	}
	for _, blocked := range metadataAddresses {
		if ip == blocked {
			return "cloud_metadata"
		}
	}
	if ip.IsLinkLocalUnicast() {
		return "link_local"
	}
	for _, prefix := range translated {
		if prefix.Contains(ip) {
			return "translation"
		}
	}
	return "private_network"
}
func allowedAddress(ip netip.Addr, host, siteHost string) bool {
	if !ip.IsValid() || ip.Zone() != "" || metadataHost(host) || ip.Unmap().IsLinkLocalUnicast() {
		return false
	}
	for _, blocked := range metadataAddresses {
		if ip.Unmap() == blocked {
			return false
		}
	}
	// Tunnel/translation prefixes can conceal link-local IPv4 destinations.
	for _, prefix := range translated {
		if prefix.Contains(ip) {
			return false
		}
	}
	return host == siteHost || publicAddress(ip)
}

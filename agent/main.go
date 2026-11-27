// Command heptapod-agent reports a server's resource sample to a Heptapod instance (ingest contract v1).
// It only reads: no remote commands, no self-update, and no subprocess except Ubuntu's apt-check.
package main

import (
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/url"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

// version is set at build time: -ldflags "-X main.version=0.9.0".
var version = "dev"

//go:embed packaging/heptapod-agent.service
var unitFile string

const endpointPath = "/etc/heptapod-agent/endpoint"

func main() {
	once := flag.Bool("once", false, "with --dry-run: collect a single sample")
	dryRun := flag.Bool("dry-run", false, "with --once: print the sample as JSON instead of sending it")
	printUnit := flag.Bool("print-unit", false, "print the systemd unit this binary expects and exit")
	printVersion := flag.Bool("version", false, "print the version and exit")
	flag.Parse()
	log.SetFlags(0)

	switch {
	case flag.NArg() > 0:
		fatalf("unexpected argument %q", flag.Arg(0))
	case *printVersion:
		fmt.Println(version)
	case *printUnit:
		fmt.Print(unitFile)
	case *once != *dryRun:
		fatalf("--once and --dry-run go together; without both the agent runs as a service")
	case *once:
		if err := printSample(os.Stdout); err != nil {
			fatalf("%v", err)
		}
	default:
		endpoint, token, err := loadConfig(endpointPath, os.Getenv("CREDENTIALS_DIRECTORY"))
		if err != nil {
			fatalf("%v", err)
		}
		ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, os.Interrupt)
		defer stop()
		log.Printf("heptapod-agent %s reporting to %s", version, endpoint)
		if err := run(ctx, hostSystem(), newSender(endpoint, token)); err != nil {
			fatalf("%v", err)
		}
	}
}

func printSample(out io.Writer) error {
	sys := hostSystem()
	before, _, err := sys.cpu()
	if err != nil {
		return err
	}
	time.Sleep(time.Second)
	value, _, err := sys.collect(context.Background(), before, time.Now())
	if err != nil {
		return err
	}
	encoder := json.NewEncoder(out)
	encoder.SetIndent("", "  ")
	return encoder.Encode(value)
}

// loadConfig reads the endpoint file and the token from systemd's credentials directory (LoadCredential=token:…).
func loadConfig(endpointFile, credentialsDir string) (string, string, error) {
	data, err := os.ReadFile(endpointFile)
	if err != nil {
		return "", "", fmt.Errorf("endpoint: %w", err)
	}
	endpoint := strings.TrimSpace(string(data))
	if err := validateEndpoint(endpoint); err != nil {
		return "", "", fmt.Errorf("endpoint %s: %w", endpointFile, err)
	}
	if credentialsDir == "" {
		return "", "", errors.New("no token: run under systemd with LoadCredential=token:/etc/heptapod-agent/token")
	}
	data, err = os.ReadFile(filepath.Join(credentialsDir, "token"))
	if err != nil {
		return "", "", fmt.Errorf("token: %w", err)
	}
	token := strings.TrimSpace(string(data))
	if !strings.HasPrefix(token, "hpa_") || strings.ContainsFunc(token, func(r rune) bool { return r <= ' ' || r == 0x7f }) {
		return "", "", errors.New("token: expected one hpa_… token from the server's install command")
	}
	return endpoint, token, nil
}

// validateEndpoint allows plain HTTP only to loopback, for local testing; the token must never cross a network in clear text.
func validateEndpoint(endpoint string) error {
	parsed, err := url.Parse(endpoint)
	if err != nil {
		return err
	}
	if parsed.Host == "" || parsed.User != nil || parsed.RawQuery != "" || parsed.Fragment != "" {
		return errors.New("expected a URL like https://heptapod.example.com/agent/v1/report")
	}
	switch parsed.Scheme {
	case "https":
		return nil
	case "http":
		host := parsed.Hostname()
		if ip := net.ParseIP(host); host == "localhost" || (ip != nil && ip.IsLoopback()) {
			return nil
		}
	}
	return errors.New("must use https")
}

func fatalf(format string, args ...any) {
	log.Printf("heptapod-agent: "+format, args...)
	os.Exit(1)
}

# The Actions artifact is the host's registry-free proof of the published bytes.
def digest: type == "string" and test("^sha256:[0-9a-f]{64}$");
def provenance:
  (.id | type == "number" and . > 0) and
  (.attempt | type == "number" and . > 0) and
  (.sha | type == "string" and test("^[0-9a-f]{40}$")) and
  (.event == "push" or .event == "schedule" or .event == "workflow_dispatch") and
  (.branch | type == "string" and length > 0);
(.schema == 1 and
.reference == "ghcr.io/heptapodwatch/heptapod-php-base" and
(.run | provenance) and (.build | provenance) and
.run.sha == .build.sha and
(.indexes | type == "array") and
([.indexes[].target] | sort) == ["coverage", "runtime"] and
all(.indexes[];
  (.index_digest | digest) and
  (.tags | type == "array" and length > 0) and
  ([.tags[].tag] | length == (unique | length)) and
  all(.tags[]; (.tag | type == "string" and test("^[a-zA-Z0-9_][a-zA-Z0-9_.-]{0,127}$"))) and
  (.index_digest as $digest | all(.tags[]; .digest == $digest)) and
  (.cells | type == "array") and
  ([.cells[].architecture] | sort) == ["amd64", "arm64"] and
  all(.cells[];
    (.digest | digest) and (.scanned_config | digest) and
    .scanned_config == .pushed_config and
    .hard_gate == "success" and .push == "success")))
or error("Malformed php-base record: require both targets, green native gates/pushes, matching configs and tag digests")

#!/usr/bin/env bash
# Combine the per-package plans and write the dry-run report. Usage: report.sh <dir with *-plan.json> <combined output file>
# Writes the combined plan, a ::warning:: per deploy tag that is not on main, and the job summary tables.
set -euo pipefail
plans=$1
combined=$2
jq -s '{packages: .}' "$plans/"*-plan.json > "$combined"
cat "$combined"
jq -r '.packages[].warnings[] | "::warning::deploy-tag-not-on-main \(.)"' "$combined"
jq -r '
  def cell: tostring | gsub("[|\r\n]"; " ");
  .packages[] |
  "## \(.package) — \(.totals.versions) versions, unique blobs ≈ \(.totals.estimated_unique_bytes) bytes (est.)",
  "kept \(.totals.kept) (≈ \(.totals.estimated_kept_bytes) bytes est.) · delete \(.totals.delete) (frees ≈ \(.totals.estimated_freed_bytes) bytes est.) · unreadable \(.totals.unreadable)",
  "", "| action | digest (12) | tags | created (UTC) | est. size | reason |",
  "| --- | --- | --- | --- | --- | --- |",
  (.versions[] | "| \(.action) | \(.digest[7:19]) | \(.tags | join(", ") | cell) | \(.created_at) | \(.estimated_bytes) bytes (est.) | \(.reason | cell) |"),
  (.unknown_tags[] | "Unknown tag: \(.tag | cell) · \(.digest) · unknown-tag"),
  (.warnings[] | "WARNING: deploy-tag-not-on-main \(.)"), ""
' "$combined" >> "$GITHUB_STEP_SUMMARY"

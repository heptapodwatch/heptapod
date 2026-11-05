# Input comes from plan.sh: verified manifests, package versions and first-parent main order.
def known_tag:
  . == "main" or startswith("v") or test("^[0-9a-f]{40}$") or
  test("^[0-9a-f]{40}-(amd64|arm64)$") or
  test("^ci-[0-9a-f]{40}(-[a-z0-9_]+)?$") or test("^sha256-[0-9a-f]{64}.*$");
def blobs($digest; $manifests):
  $manifests[$digest] as $m |
  if $m == null then []
  elif $m.manifests != null then [$m.manifests[].digest | blobs(.; $manifests)[]]
  else [$m.config?, $m.layers[]?, $m.blobs[]?] | map(select(.digest != null) | {digest, size}) end;
def unique_bytes: unique_by(.digest) | map(.size) | add // 0;
def close_kept($versions; $manifests):
  . as $before |
  reduce $versions[] as $v (. ;
    $v.name as $digest |
    if has($digest) then .
    else
      . as $current |
      ([keys[] as $parent | select(any($manifests[$parent].manifests[]?; .digest == $digest)) |
         "child-of \($parent) (\($current[$parent]))"] +
       [($manifests[$digest].subject.digest // empty) as $subject | select(has($subject)) | "referrer-of \($subject)"] +
       [$v.metadata.container.tags[] | select(test("^sha256-[0-9a-f]{64}.*$")) |
         capture("^sha256-(?<hex>[0-9a-f]{64})") | "sha256:" + .hex |
         . as $subject | select($before | has($subject)) | "cosign-of \($subject)"]) as $reasons |
      if $reasons | length > 0 then .[$digest] = $reasons[0] else . end
    end) |
  if . == $before then . else close_kept($versions; $manifests) end;

$versions[0] as $versions |
$manifests[0] as $manifests |
($main_shas | split("\n") | map(select(length > 0))) as $main |
([$versions[].metadata.container.tags[] | select(test("^[0-9a-f]{40}$"))]) as $deploy_tags |
([$main[] | select(. as $sha | $deploy_tags | index($sha))][0:3]) as $kept_shas |
($now - 3 * 86400) as $cutoff |
(reduce $versions[] as $v ({};
  $v.metadata.container.tags as $tags |
  ([if $tags | index("main") then "tag main" else empty end] +
   [$tags[] | select(startswith("v")) | "tag \(.)"] +
   [$tags[] | select(known_tag | not) | "unknown-tag"] +
   [$tags[] | select(test("^[0-9a-f]{40}$")) | select(. as $sha | $main | index($sha) | not) | "deploy-tag-not-on-main"] +
   [$tags[] | select(. as $tag | $kept_shas | index($tag)) | "deploy window (last 3 on main)"] +
   [if ([$v.created_at, $v.updated_at] | map(fromdateiso8601) | max) >= $cutoff then "age-floor (<3d)" else empty end] +
   [if $manifests[$v.name] == null then "unreadable" else empty end]) as $reasons |
  if $reasons | length > 0 then .[$v.name] = $reasons[0] else . end)) |
close_kept($versions; $manifests) as $kept |
([$versions[] | select($manifests[.name] == null) | .name] +
 [$manifests[] | .manifests[]?.digest | select($manifests[.] == null)]) | unique as $unreadable |
($unreadable | length == 0) as $safe |
[$versions[] | . as $v | $v.name as $digest |
  {id, digest: $digest, tags: .metadata.container.tags, created_at, updated_at,
   action: (if $kept | has($digest) then "keep" elif $safe then "delete" else "blocked" end),
   reason: (if $manifests[$digest] == null then "unreadable" else $kept[$digest] // "not reachable from kept tag" end),
   kind: (if $manifests[$digest].subject != null or any(.metadata.container.tags[]; test("^sha256-[0-9a-f]{64}.*$")) then "referrer"
          elif $manifests[$digest].manifests != null then "index" else "image" end),
   estimated_bytes: (blobs($digest; $manifests) | unique_bytes)}] as $rows |
([$rows[] | select(.action == "keep") | blobs(.digest; $manifests)[]]) as $kept_blobs |
([$versions[] | blobs(.name; $manifests)[]] | unique_by(.digest)) as $all_blobs |
([$rows[] | select(.action == "delete")] | sort_by(
  (if .kind == "referrer" then 0 elif .kind == "index" then 1 else 2 end), .created_at, .digest)) as $delete |
if any($delete[]; any(.tags[]; . == "main" or startswith("v") or (. as $tag | $kept_shas | index($tag))))
then error("protected tag in delete set") else
{package: $package, now: $now, safe: $safe, kept_deploy_shas: $kept_shas,
 unreadable: $unreadable, versions: $rows, delete: $delete,
 unknown_tags: [$versions[] | .name as $digest | .metadata.container.tags[] |
   select(known_tag | not) | {tag: ., digest: $digest, reason: "unknown-tag"}],
 warnings: [$versions[] | select(any(.metadata.container.tags[];
   test("^[0-9a-f]{40}$") and (. as $sha | $main | index($sha) | not))) | .name],
 totals: {versions: ($rows | length), estimated_unique_bytes: ($all_blobs | unique_bytes),
   kept: ([$rows[] | select(.action == "keep")] | length), estimated_kept_bytes: ($kept_blobs | unique_bytes),
   delete: ($delete | length), estimated_freed_bytes: (if $safe then
     [$all_blobs[] | select(.digest as $blob | any($kept_blobs[]; .digest == $blob) | not)] | unique_bytes else 0 end),
   unreadable: ($unreadable | length)}} end

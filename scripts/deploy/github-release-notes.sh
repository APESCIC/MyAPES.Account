#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 <releases.json|changelog/releases/vX.Y.Z.json>" >&2
  exit 1
fi

releases_file="$1"

if [[ ! -f "$releases_file" ]]; then
  echo "Release history file not found: $releases_file" >&2
  exit 1
fi

# Accept either the legacy aggregate (array, newest first) or a single per-release object (#712).
jq -r '
  (if type == "array" then .[0] else . end) as $release |
  ($release.title // "") as $title |
  ($release.summary // "") as $summary |
  [
    (if $title != "" then "## \($title)\n" else "" end),
  $summary,
  "",
  "## Changes",
  ($release.changes // [] | if length > 0 then map("- " + .) | join("\n") else "- No detailed changes recorded." end),
  "",
  "## Affected areas",
  ($release.affected_areas // [] | if length > 0 then map("- " + .) | join("\n") else "- Not recorded." end),
  (if ($release.references // [] | length) > 0 then
    ["", "## References"] + ($release.references | map("- [\(.label)](\(.url))"))
  else
    []
  end | join("\n")),
  "",
  "_MyAPES Account \($release.version) (\($release.date)) · \($release.channel) · \($release.type)_"
  ] | join("\n")
' "$releases_file"

#!/usr/bin/env bash
# Prints the commit each platform of a published image was built from, as recorded in its
# org.opencontainers.image.revision label. Each distinct commit is printed once, separated by ", ", so an image built
# from exactly one commit prints that commit alone. A platform with no label prints "no revision label".
#
# Usage: image-revisions.sh <image>@<digest>
set -euo pipefail

# A single-platform image gives one configuration, and a multi-platform index gives one for each platform.
docker buildx imagetools inspect "$1" --format '{{ json .Image }}' \
  | jq -r 'if has("config") then [.] else [.[]] end
    | map(.config.Labels["org.opencontainers.image.revision"] // "no revision label") | unique | join(", ")'

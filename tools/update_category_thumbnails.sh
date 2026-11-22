#!/usr/bin/env bash

set -o errexit
set -o nounset
set -o pipefail

umask 0002

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

exec php "${SCRIPT_DIR}/apply_category_thumbnails_from_md.php" "$@"

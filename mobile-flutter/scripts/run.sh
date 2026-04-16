#!/usr/bin/env bash
# TPIX TRADE — Dev run with build secrets injected
#
# Usage:
#   ./scripts/run.sh                    # flutter run (default device)
#   ./scripts/run.sh -d <device-id>     # flutter run on specific device
#
# Reads REOWN_PROJECT_ID + other vars from .env.local (gitignored)
# and injects via --dart-define so they reach String.fromEnvironment().

set -euo pipefail

cd "$(dirname "$0")/.."

if [[ ! -f .env.local ]]; then
  echo "ERROR: .env.local not found."
  echo "Copy .env.example → .env.local and fill in your values."
  exit 1
fi

# shellcheck disable=SC1091
set -a
source .env.local
set +a

DEFINES=()
[[ -n "${REOWN_PROJECT_ID:-}" ]] && DEFINES+=(--dart-define=REOWN_PROJECT_ID="$REOWN_PROJECT_ID")

flutter run "${DEFINES[@]}" "$@"

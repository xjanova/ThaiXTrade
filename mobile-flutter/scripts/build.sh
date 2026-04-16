#!/usr/bin/env bash
# TPIX TRADE — Production build with secrets injected + obfuscation
#
# Usage:
#   ./scripts/build.sh apk             # signed release APK
#   ./scripts/build.sh appbundle       # AAB for Play Store
#   ./scripts/build.sh ios             # iOS release (requires macOS)
#
# - Reads REOWN_PROJECT_ID from .env.local
# - Applies --obfuscate + --split-debug-info to deter APK reverse-engineering
# - Symbol map saved to build/symbols/ (do not commit)

set -euo pipefail

cd "$(dirname "$0")/.."

TARGET="${1:-apk}"

if [[ ! -f .env.local ]]; then
  echo "ERROR: .env.local not found."
  echo "Copy .env.example → .env.local and fill in your values."
  exit 1
fi

# shellcheck disable=SC1091
set -a
source .env.local
set +a

if [[ -z "${REOWN_PROJECT_ID:-}" ]]; then
  echo "WARNING: REOWN_PROJECT_ID is empty — external wallets will be disabled."
fi

DEFINES=(
  --dart-define=REOWN_PROJECT_ID="${REOWN_PROJECT_ID:-}"
)

OBFUSCATE=(
  --obfuscate
  --split-debug-info=build/symbols
)

case "$TARGET" in
  apk)        flutter build apk        --release "${OBFUSCATE[@]}" "${DEFINES[@]}" ;;
  appbundle)  flutter build appbundle  --release "${OBFUSCATE[@]}" "${DEFINES[@]}" ;;
  ios)        flutter build ios        --release "${OBFUSCATE[@]}" "${DEFINES[@]}" ;;
  *)
    echo "Unknown target: $TARGET (expected: apk | appbundle | ios)"
    exit 1
    ;;
esac

echo "Build complete. Symbol files (for crash deobfuscation) at build/symbols/"

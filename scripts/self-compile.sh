#!/usr/bin/env bash
# Self-compile picoHP using the picoHP compiler itself.
# Usage: ./scripts/self-compile.sh [extra-args...]
#
# This uses the directory build mode with the picoHP entry point.
# Extra args (e.g. -d for debug, -vv for verbose) are passed through.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

exec "$PROJECT_DIR/picoHP" build --debug --entry=picoHP "$@" "$PROJECT_DIR"

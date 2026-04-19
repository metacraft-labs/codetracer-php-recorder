#!/bin/bash
# Run a PHP script with CodeTracer tracing enabled.
# Usage: ./scripts/run_with_tracing.sh script.php [args...]

SCRIPT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
EXT_PATH="$SCRIPT_DIR/ext/modules/codetracer.so"
TRACE_FORMAT_DIR="${TRACE_FORMAT_DIR:-$SCRIPT_DIR/../../codetracer-trace-format}"
FFI_LIB_DIR="$TRACE_FORMAT_DIR/target/release"

if [ ! -f "$EXT_PATH" ]; then
    echo "Error: extension not built. Run: cd ext && bash build.sh"
    exit 1
fi

export CODETRACER_ENABLED=1
export CODETRACER_OUTPUT_DIR="${CODETRACER_OUTPUT_DIR:-/tmp/codetracer_traces}"
export LD_LIBRARY_PATH="$FFI_LIB_DIR:$LD_LIBRARY_PATH"

mkdir -p "$CODETRACER_OUTPUT_DIR"

exec php -d "extension=$EXT_PATH" "$@"

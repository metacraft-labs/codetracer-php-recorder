#!/bin/bash
set -e

# CodeTracer PHP extension links against the **Nim** trace writer FFI from
# `codetracer-trace-format-nim`, NOT the Rust FFI from `codetracer-trace-format`.
#
# Why: only the Nim writer produces the canonical V4 multi-stream CTFS layout
# (steps.dat / calls.dat / values.dat / paths.dat / meta.dat / etc.) that
# `ct print --full` decodes directly.  The Rust CTFS writer still emits the
# legacy `events.log` + `meta.json` + `paths.json` layout that ct-print can't
# unify with the multi-stream readers.  See
# `metacraft-specs/policies/recorder-test-requirements.md` §1 for the strict
# golden-snapshot requirement that drives this choice.
#
# The Nim FFI exports the same C function names as the Rust FFI
# (`trace_writer_new`, `trace_writer_register_step`, etc.), so the C code in
# `codetracer_php.c` is unchanged — only the link line + header path differ.
#
# Override `TRACE_FORMAT_NIM_DIR` if you keep the Nim repo somewhere other
# than the workspace sibling location.

TRACE_FORMAT_NIM_DIR="${TRACE_FORMAT_NIM_DIR:-$(cd ../../codetracer-trace-format-nim && pwd)}"
FFI_HEADER="$TRACE_FORMAT_NIM_DIR/include/codetracer_trace_writer.h"
FFI_LIB_DIR="$TRACE_FORMAT_NIM_DIR"

if [ ! -f "$FFI_HEADER" ]; then
	echo "Error: trace writer header not found at $FFI_HEADER"
	echo "Expected: $FFI_HEADER (from codetracer-trace-format-nim/include/)"
	exit 1
fi

if [ ! -f "$FFI_LIB_DIR/libcodetracer_trace_writer.so" ]; then
	echo "Error: shared trace writer library not found at $FFI_LIB_DIR/libcodetracer_trace_writer.so"
	echo "Build it with:"
	echo "  cd $FFI_LIB_DIR && nimble buildSharedLib"
	echo "(or run \`nimble buildSharedLib\` from inside the codetracer-trace-format-nim repo)"
	exit 1
fi

# NOTE: we do NOT copy the upstream header here.  `ext/codetracer_trace_writer.h`
# is a tracked file with `#define` aliases (FMT_*, TK_*, ELK_*) that bridge the
# C source's legacy short names to the Nim FFI's `FFI_TRACE_FORMAT_*` /
# `FFI_TYPE_*` / `FFI_ELK_*` symbols.  Overwriting it from the Nim source would
# strip those aliases.  When the Nim FFI's surface changes, manually re-merge
# the new declarations into the tracked header instead.

# LD_LIBRARY_PATH is needed during configure so that autoconf's
# "can we run a compiled program?" test can find the shared library.
export LD_LIBRARY_PATH="$FFI_LIB_DIR:${LD_LIBRARY_PATH:-}"

# Run phpize and configure
phpize
./configure --enable-codetracer \
	CFLAGS="-I$(dirname "$FFI_HEADER")" \
	LDFLAGS="-L$FFI_LIB_DIR -lcodetracer_trace_writer -lzstd -lm -ldl -lpthread"

make clean || true
make

echo ""
echo "Extension built: modules/codetracer.so"
echo "Linked against: $FFI_LIB_DIR/libcodetracer_trace_writer.so (Nim CTFS V4 multi-stream writer)"
echo "Test with: php -d extension=$(pwd)/modules/codetracer.so -r 'phpinfo();' | grep codetracer"

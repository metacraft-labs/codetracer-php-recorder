#!/bin/bash
set -e

TRACE_FORMAT_DIR="${TRACE_FORMAT_DIR:-$(cd ../../codetracer-trace-format && pwd)}"
FFI_HEADER="$TRACE_FORMAT_DIR/codetracer_trace_writer_ffi/codetracer_trace_writer.h"
FFI_LIB_DIR="$TRACE_FORMAT_DIR/target/release"

if [ ! -f "$FFI_HEADER" ]; then
    echo "Error: trace writer header not found at $FFI_HEADER"
    echo "Expected: $FFI_HEADER"
    exit 1
fi

if [ ! -f "$FFI_LIB_DIR/libcodetracer_trace_writer_ffi.so" ]; then
    echo "Error: trace writer library not found at $FFI_LIB_DIR/libcodetracer_trace_writer_ffi.so"
    echo "Build it with: cd $TRACE_FORMAT_DIR && cargo build --release -p codetracer_trace_writer_ffi"
    exit 1
fi

# Copy header to ext directory
cp "$FFI_HEADER" .

# LD_LIBRARY_PATH is needed during configure so that autoconf's
# "can we run a compiled program?" test can find the shared library.
export LD_LIBRARY_PATH="$FFI_LIB_DIR:${LD_LIBRARY_PATH:-}"

# Run phpize and configure
phpize
./configure --enable-codetracer \
    CFLAGS="-I$(dirname "$FFI_HEADER")" \
    LDFLAGS="-L$FFI_LIB_DIR -lcodetracer_trace_writer_ffi -lm -ldl -lpthread"

make clean || true
make

echo ""
echo "Extension built: modules/codetracer.so"
echo "Test with: php -d extension=$(pwd)/modules/codetracer.so -r 'phpinfo();' | grep codetracer"

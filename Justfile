# Build the PHP extension
build-ext:
    cd ext && bash build.sh

# Run tests (unit tests only, no extension needed)
test:
    php tests/test_span.php

# Run tests with extension (requires build-ext first)
test-ext:
    CODETRACER_ENABLED=1 CODETRACER_OUTPUT_DIR=/tmp/codetracer_test_traces \
    LD_LIBRARY_PATH=../../codetracer-trace-format/target/release:$LD_LIBRARY_PATH \
    php -d extension=ext/modules/codetracer.so tests/test_extension.php

# Run integration test
test-integration:
    php tests/test_integration.php

# Run a test PHP script with span tracking
demo:
    php -d auto_prepend_file=src/auto_prepend.php -S localhost:8095 -t test-programs/

# Build the PHP extension
build:
    cd ext && bash build.sh

# Alias for the convention-mandated `build` recipe.
build-ext: build

# Run tests (unit tests only, no extension needed)
test:
    php tests/test_span.php

t: test

# Run tests with extension (requires build first)
test-ext:
    CODETRACER_ENABLED=1 CODETRACER_OUTPUT_DIR=/tmp/codetracer_test_traces \
    LD_LIBRARY_PATH=../../codetracer-trace-format/target/release:$LD_LIBRARY_PATH \
    php -d extension=ext/modules/codetracer.so tests/test_extension.php

# Run integration test
test-integration:
    php tests/test_integration.php

# Lint: php -l on every .php file we ship.  Convention-mandated.
lint:
    @find . -name '*.php' -not -path './.direnv/*' -not -path './ext/build/*' -print0 \
        | xargs -0 -r -n1 php -l

# Auto-format: nothing to format yet (no .php formatter wired in here).
# Recipe still exists per repo-requirements §3 so contributors can rely on it.
format:
fmt: format

# Run a test PHP script with span tracking
demo:
    php -d auto_prepend_file=src/auto_prepend.php -S localhost:8095 -t test-programs/

# Build the PHP extension
build:
    cd ext && bash build.sh

# Alias for the convention-mandated `build` recipe.
build-ext: build

# Run the full recorder test suite.
#
# Order:
#   1. Pure-PHP unit tests for the span tracker (no extension needed).
#   2. Build the C extension (so test_extension.php and test_e2e.php
#      have something to load).
#   3. Extension smoke test — exercises the recorder's normal entry
#      point (`-d extension=...`) and asserts the trace artefacts
#      land in the expected place.
#   4. End-to-end per-program tests — one program per universal-
#      checklist category from `metacraft-specs/policies/recorder-
#      test-requirements.md`.  Each program is recorded through the
#      same `-d extension=...` path and the produced trace artefacts
#      are asserted with EXACT counts and EXACT decoded values
#      where possible (see test_e2e.php for the per-program
#      RECORDER BUG / SKIP notes covering the deviations from the
#      canonical CTFS format).
#
# `LD_LIBRARY_PATH` is required so that the recorder's C extension
# can locate `libcodetracer_trace_writer.so` produced by the
# sibling `codetracer-trace-format-nim` checkout.  The flake's shellHook
# sets `TRACE_FORMAT_NIM_DIR` to that sibling, so we resolve the lib
# dir from it rather than the legacy `../../` path.
test: build
    php tests/test_span.php
    CODETRACER_ENABLED=1 CODETRACER_OUTPUT_DIR=/tmp/codetracer_test_traces \
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
    php -d extension=ext/modules/codetracer.so tests/test_extension.php
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
    php tests/test_e2e.php

t: test

# Extension smoke test only (skips the unit tests + the e2e suite).
# Useful when iterating on the C extension itself.
test-ext: build
    CODETRACER_ENABLED=1 CODETRACER_OUTPUT_DIR=/tmp/codetracer_test_traces \
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
    php -d extension=ext/modules/codetracer.so tests/test_extension.php

# End-to-end per-program suite only (requires `just build` first).
test-e2e: build
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
    php tests/test_e2e.php

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

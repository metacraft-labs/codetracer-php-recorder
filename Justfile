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
#   5. RS-M7 request-span integration tests — a real `php -S` process and a
#      real php-fpm pool recorded end to end, asserted through the canonical
#      Nim span reader (`ct_spans_json`).  This is the ONLY runner the repo
#      has, so a test file that is not listed here runs nowhere; several
#      pre-existing files under tests/ are in exactly that position (see
#      `test-orphans` below).
test: build
    php tests/test_span.php
    CODETRACER_ENABLED=1 CODETRACER_OUTPUT_DIR=/tmp/codetracer_test_traces \
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
    php -d extension=ext/modules/codetracer.so tests/test_extension.php
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
    php tests/test_e2e.php
    just test-request-spans

# RS-M7 request-span integration tests only.
#
# The extension is loaded in the RUNNER process as well, because the spans are
# read back through `codetracer_spans_json()` — the canonical Nim decoder,
# rather than a decoder written for the tests.  CODETRACER_ENABLED is NOT set
# here on purpose: the harness passes it only to the server processes it
# spawns, so the runner itself is not recorded.
test-request-spans: build
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
    php -d extension=ext/modules/codetracer.so tests/test_request_spans.php

# Report test files under tests/ that no recipe runs.
#
# The repo has no auto-discovering runner, so an unlisted test file is dead
# code that still looks like coverage.  This recipe names them instead of
# letting them rot silently; it does not fail the build, because the files it
# finds predate RS-M7 and adopting them is a separate piece of work.
test-orphans:
    #!/usr/bin/env bash
    set -euo pipefail
    orphans=()
    for f in tests/test_*.php; do
        base="$(basename "$f")"
        if ! grep -q "$base" Justfile; then orphans+=("$base"); fi
    done
    if [ ${#orphans[@]} -eq 0 ]; then
        echo "[test-orphans] every tests/test_*.php is wired into a recipe"
    else
        echo "[test-orphans] NOT run by any recipe:"
        printf '  %s\n' "${orphans[@]}"
    fi

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

# --- RS-M7: Request Panel demo + fixture ---

# Record the demo session and (unless CODETRACER_DEMO_RECORD_ONLY is set) open
# it in the GUI.  Invoked directly, or as the recording half of codetracer's
# `just demo-request-panel php`.
demo-request-panel-php SERVER="builtin":
    #!/usr/bin/env bash
    set -euo pipefail
    demo_dir="${CODETRACER_DEMO_DIR:-${XDG_DATA_HOME:-$HOME/.local/share}/codetracer/demos/request-panel-php}"
    echo "=== RS-M7 Request Panel demo — php/{{SERVER}} ==="
    just build
    rm -rf "$demo_dir"
    mkdir -p "$demo_dir"
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
      php -d extension=ext/modules/codetracer.so \
          tests/programs/web/session_driver.php \
          --trace-dir "$demo_dir" --print-spans
    # One worker, one container.  `ct replay -t` wants the directory holding
    # the .ct, which for a recorded worker is $demo_dir/worker_<pid>.
    worker_dir="$(dirname "$(ls "$demo_dir"/worker_*/*.ct | head -n1)")"
    echo "[demo] recorded session in $worker_dir"
    if [ -n "${CODETRACER_DEMO_RECORD_ONLY:-}" ]; then
      # Invoked as the container-production half of codetracer's
      # `just demo-request-panel php`, which opens the GUI itself.
      echo "$worker_dir" > "$demo_dir/.worker_dir"
      exit 0
    fi
    if command -v ct >/dev/null 2>&1; then
      echo "[demo] launching the GUI; the REQUESTS panel docks itself once the"
      echo "[demo] first delta arrives (bottom edge strip if you close it)."
      exec ct replay -t "$worker_dir"
    fi
    echo "[demo] no 'ct' on PATH — open it by hand with:"
    echo "         ct replay -t $worker_dir"
    echo "[demo] (or run this through codetracer's recipe, which supplies ct:"
    echo "         direnv exec ../codetracer just demo-request-panel php)"

# Record the checked-in ViewModel-test fixture into OUT.
#
# OUT ends up holding the single `app.ct` container, flattened out of the
# worker_<pid> directory so the fixture path is stable across machines.
record-request-panel-fixture OUT:
    #!/usr/bin/env bash
    set -euo pipefail
    just build
    rm -rf "{{OUT}}"
    mkdir -p "{{OUT}}"
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT
    LD_LIBRARY_PATH="${TRACE_FORMAT_NIM_DIR}:${LD_LIBRARY_PATH:-}" \
      php -d extension=ext/modules/codetracer.so \
          tests/programs/web/session_driver.php \
          --trace-dir "$tmp" --print-spans
    cp "$(ls "$tmp"/worker_*/*.ct | head -n1)" "{{OUT}}/app.ct"
    echo "[fixture] wrote {{OUT}}/app.ct"

# --- M13: Packaging UX Standardization ---
# Implements Repo-Requirements.md §2.8 packaging UX for the PHP
# language-ecosystem recorder. Single channel: composer.

# Bump the version. PHP composer packages tend to lean on git tags as
# the source of truth, but if a composer.json exists we keep it in
# sync as well.
bump-version version:
    #!/usr/bin/env python3
    import json, re
    from pathlib import Path
    raw = "{{version}}"
    cj = Path("composer.json")
    if not cj.exists():
        # No composer.json yet — write a minimal stub so the bumper has
        # something to work with. Real metadata is added by the
        # packaging publish workflow.
        cj.write_text(json.dumps({"name": "metacraft-labs/codetracer-php-recorder", "version": "0.1.0"}, indent=2) + "\n")
    data = json.loads(cj.read_text())
    cur = data.get("version", "0.1.0")
    if re.match(r"^\d+\.\d+\.\d+$", raw):
        new = raw
    else:
        a, b, p = map(int, cur.split("."))
        if raw == "major": new = f"{a+1}.0.0"
        elif raw == "minor": new = f"{a}.{b+1}.0"
        elif raw == "patch": new = f"{a}.{b}.{p+1}"
        else: raise SystemExit(f"unknown bump component: {raw!r}")
    data["version"] = new
    cj.write_text(json.dumps(data, indent=2) + "\n")
    print(f"composer.json: {cur} -> {new}")

# Build a release artifact for the given channel.
# Supported channels: composer
build-package channel:
    #!/usr/bin/env bash
    set -euo pipefail
    case "{{channel}}" in
        composer)
            just build
            mkdir -p dist
            # Composer packages don't need a local archive — Packagist
            # resolves the tag directly from the git repo. We package a
            # tarball locally for verification only.
            git archive --format=tar.gz --prefix=codetracer-php-recorder/ \
                -o dist/codetracer-php-recorder.tar.gz HEAD
            ;;
        *)
            echo "::error::unknown channel '{{channel}}'. PHP recorder only supports 'composer'." >&2
            exit 1
            ;;
    esac

# Verify the artifact produced by `build-package <channel>`.
verify-package channel:
    #!/usr/bin/env python3
    import json, os, shutil, subprocess, sys
    from pathlib import Path
    ch = "{{channel}}"
    strict = os.environ.get("CT_VERIFY_STRICT") == "1"
    if ch != "composer":
        print(f"::error::unknown channel {ch!r}; PHP recorder only supports 'composer'")
        sys.exit(1)
    cj = Path("composer.json")
    if cj.exists():
        json.loads(cj.read_text())
        print(f"[verify] composer.json: valid JSON")
        if shutil.which("composer"):
            subprocess.run(["composer", "validate", "--no-check-all", str(cj)], check=True)
        else:
            if strict:
                print("::error::composer required in strict mode"); sys.exit(1)
            print("[verify] SKIP: composer not on PATH")
    else:
        print("[verify] no composer.json present; nothing to validate")

# Per-channel shortcut.
build-composer:
    just build-package composer

verify-composer:
    just verify-package composer

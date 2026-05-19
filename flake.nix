{
  description = "Development environment for codetracer-php-recorder";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-25.11";
    pre-commit-hooks.url = "github:cachix/git-hooks.nix";

    # Sibling repo source — the PHP extension links against the Nim trace
    # writer FFI shared library produced from codetracer-trace-format-nim
    # (NOT the Rust FFI in codetracer-trace-format). Only the Nim writer
    # produces the canonical V4 multi-stream CTFS layout that ct-print can
    # decode — see ext/build.sh for the rationale. We don't fetch the binary
    # here (the dev shell builds it from the sibling checkout); this input
    # only exists so `nix flake check` can resolve the source for
    # derivations that need it.
    codetracer-trace-format-nim = {
      url = "github:metacraft-labs/codetracer-trace-format-nim/main";
      flake = false;
    };
  };

  outputs =
    {
      self,
      nixpkgs,
      pre-commit-hooks,
      codetracer-trace-format-nim,
    }:
    let
      systems = [
        "x86_64-linux"
        "aarch64-linux"
        "x86_64-darwin"
        "aarch64-darwin"
      ];
      forEachSystem = nixpkgs.lib.genAttrs systems;
    in
    {
      checks = forEachSystem (system: {
        pre-commit-check = pre-commit-hooks.lib.${system}.run {
          src = ./.;
          hooks = {
            lint = {
              enable = true;
              name = "Lint";
              entry = "just lint";
              language = "system";
              pass_filenames = false;
            };
          };
        };
      });

      devShells = forEachSystem (
        system:
        let
          pkgs = import nixpkgs { inherit system; };
          preCommit = self.checks.${system}.pre-commit-check;
          # PHP with development headers and the FFI extension enabled.
          # `dev` output exposes phpize / php-config / php source headers
          # needed to build the codetracer.so PECL-style C extension.
          # FFI is required so the source-level preprocessor fallback can
          # call into libcodetracer_trace_writer_ffi.so at runtime.
          phpWithDev = pkgs.php.withExtensions (
            { enabled, all }:
            enabled
            ++ [
              all.ffi
              all.opcache
            ]
          );
        in
        {
          default = pkgs.mkShell {
            packages = with pkgs; [
              # PHP itself (with dev headers + FFI extension enabled).
              # phpize, php-config, and the PHP source headers come from
              # the .unwrapped.dev output of the underlying php derivation.
              phpWithDev
              phpWithDev.unwrapped.dev

              # Nim toolchain — only needed if rebuilding the trace writer
              # FFI from source.  Most contributors will pull the prebuilt
              # libcodetracer_trace_writer.so from the sibling checkout
              # (built via `nim c --app:lib ...` from
              # codetracer-trace-format-nim).
              nim

              # Build automation
              just
              prek
              gnumake
              autoconf
              automake
              libtool
              pkg-config

              # Trace format dependencies — zstd is needed by the Nim FFI
              # at link time (CTFS uses seekable-zstd compression).
              zstd
            ];

            # The PHP extension's build.sh expects to find the prebuilt
            # FFI library in a sibling checkout of codetracer-trace-format-nim.
            # Override with TRACE_FORMAT_NIM_DIR when working off a different
            # path.
            shellHook = ''
              ${preCommit.shellHook}
              if [ -z "''${TRACE_FORMAT_NIM_DIR:-}" ] && [ -d "$PWD/../codetracer-trace-format-nim" ]; then
                export TRACE_FORMAT_NIM_DIR="$PWD/../codetracer-trace-format-nim"
              fi
            '';
          };
        }
      );
    };
}

<?php
/**
 * CodeTracer PHP Source Instrumenter.
 *
 * Transforms PHP source files by inserting tracing calls:
 * - __ct_step(__FILE__, __LINE__) at each statement
 * - __ct_call(__FUNCTION__, func_get_args()) at function entry
 * - Variable capture via __ct_var()
 *
 * Usage:
 *   php src/instrumenter.php input.php > output.php
 *   php src/instrumenter.php src/ --output instrumented/
 */

class CodeTracerInstrumenter {

    public function instrumentFile(string $inputPath): string {
        $source = file_get_contents($inputPath);
        return $this->instrumentSource($source, $inputPath);
    }

    public function instrumentSource(string $source, string $filename = '<inline>'): string {
        $tokens = token_get_all($source);
        $output = '';
        $inFunction = false;
        $braceDepth = 0;
        $parenDepth = 0;
        $functionBraceDepth = 0;
        $lastLine = 0;
        $needsStep = false;
        $inFunctionParams = false;

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                [$id, $text, $line] = $token;

                // Track line changes for step events
                if ($line !== $lastLine && $line > 0) {
                    $lastLine = $line;
                    $needsStep = true;
                }

                // Insert step event before statements (only at top level, not inside parens)
                if ($needsStep && $parenDepth === 0 && !$inFunctionParams && $this->isStatementStart($id)) {
                    $output .= "__ct_step(__FILE__, $line); ";
                    $needsStep = false;
                }

                // Detect function/method declarations
                if ($id === T_FUNCTION) {
                    $inFunction = true;
                    $inFunctionParams = true;
                }

                $output .= $text;
            } else {
                // Single-character token
                if ($token === '(') {
                    $parenDepth++;
                    $output .= $token;
                } elseif ($token === ')') {
                    $parenDepth--;
                    if ($inFunctionParams && $parenDepth === 0) {
                        $inFunctionParams = false;
                    }
                    $output .= $token;
                } elseif ($token === '{') {
                    $braceDepth++;
                    $output .= $token;

                    // Insert call tracing at function body start
                    if ($inFunction && $braceDepth === $functionBraceDepth + 1) {
                        $output .= ' __ct_call(__FUNCTION__, __FILE__, __LINE__);';
                        $functionBraceDepth = $braceDepth;
                        $inFunction = false;
                    }
                } elseif ($token === '}') {
                    if ($braceDepth === $functionBraceDepth) {
                        $output = rtrim($output) . ' __ct_return(); ';
                        $functionBraceDepth = 0;
                    }
                    $braceDepth--;
                    $output .= $token;
                } else {
                    $output .= $token;
                }
            }
        }

        return $output;
    }

    private function isStatementStart(int $tokenId): bool {
        return in_array($tokenId, [
            T_VARIABLE, T_IF, T_ELSE, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH,
            T_SWITCH, T_CASE, T_RETURN, T_ECHO, T_PRINT, T_THROW,
            T_TRY, T_CATCH, T_FINALLY,
        ]);
    }

    public function instrumentDirectory(string $inputDir, string $outputDir): int {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($inputDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                // Copy non-PHP files as-is
                $relPath = substr($file->getPathname(), strlen($inputDir));
                $destPath = $outputDir . $relPath;
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                copy($file->getPathname(), $destPath);
                continue;
            }

            $relPath = substr($file->getPathname(), strlen($inputDir));
            $destPath = $outputDir . $relPath;
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);

            $instrumented = $this->instrumentFile($file->getPathname());
            file_put_contents($destPath, $instrumented);
            $count++;
        }

        return $count;
    }
}

// CLI entry point
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    $instrumenter = new CodeTracerInstrumenter();

    if (is_dir($argv[1])) {
        $outputDir = $argv[2] ?? $argv[1] . '_instrumented';
        if (isset($argv[2]) && $argv[2] === '--output' && isset($argv[3])) {
            $outputDir = $argv[3];
        }
        $count = $instrumenter->instrumentDirectory($argv[1], $outputDir);
        echo "Instrumented $count PHP files → $outputDir\n";
    } else {
        echo $instrumenter->instrumentFile($argv[1]);
    }
}

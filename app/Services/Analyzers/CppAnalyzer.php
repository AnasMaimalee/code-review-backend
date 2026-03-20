<?php

namespace App\Services\Analyzers;

use Illuminate\Support\Facades\Log;

class CppAnalyzer
{
    public function analyze(string $code): array
    {
        $file = storage_path('app/tmp_' . uniqid() . '.cpp');
        file_put_contents($file, $code);

        $cppcheck = env('CPPCHECK_PATH', '/usr/bin/cppcheck');

        // Using SARIF — most structured format supported by recent cppcheck
        $command = escapeshellcmd("{$cppcheck} --enable=all --output-format=sarif {$file}") . ' 2>&1';

        $rawOutput = shell_exec($command);

        Log::debug('Cppcheck command executed', [
            'command'    => $command,
            'raw_output' => $rawOutput,
            'file'       => $file,
        ]);

        unlink($file);

        if (empty($rawOutput)) {
            Log::warning('Cppcheck returned empty output');
            return $this->errorResult('Cppcheck returned no output – check binary path/permissions');
        }

        // ────────────────────────────────────────────────
        // Same cleaning strategy as PHPStan: take up to last }
        // ────────────────────────────────────────────────
        $jsonEndPos = strrpos($rawOutput, '}');
        if ($jsonEndPos !== false) {
            $cleanJson = substr($rawOutput, 0, $jsonEndPos + 1);
        } else {
            $cleanJson = $rawOutput; // fallback if no closing brace found
        }

        // Step 2: Try decoding
        $json = json_decode($cleanJson, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            Log::info('Cppcheck SARIF parsed successfully after cleaning');

            $issues = [];

            // Extract from SARIF structure (very similar loop style as PHPStan files→messages)
            if (isset($json['runs']) && is_array($json['runs'])) {
                foreach ($json['runs'] as $run) {
                    if (!isset($run['results']) || !is_array($run['results'])) {
                        continue;
                    }
                    foreach ($run['results'] as $result) {
                        $level = $result['level'] ?? 'warning';
                        $loc   = $result['locations'][0]['physicalLocation'] ?? [];
                        $reg   = $loc['region'] ?? [];

                        $issues[] = [
                            'message'    => $result['message']['text'] ?? 'Unknown issue',
                            'line'       => $reg['startLine'] ?? null,
                            'severity'   => $this->mapSeverity($level),
                            'identifier' => $result['ruleId'] ?? null,
                        ];
                    }
                }
            }

            // If we got issues → return (same as PHPStan)
            if (!empty($issues)) {
                return [
                    'issues' => $issues,
                    'raw'    => json_encode($json, JSON_PRETTY_PRINT),
                ];
            }

            // JSON valid but no issues extracted → treat as clean
            return $this->noIssuesResult('No issues found in valid SARIF output');
        }

        // ────────────────────────────────────────────────
        // Fallback: text parsing — very similar to your PHPStan fallback
        // ────────────────────────────────────────────────
        Log::info('JSON parsing failed - falling back to text parsing');

        $lines = explode("\n", $rawOutput);
        $issues = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                empty($line) ||
                str_contains($line, 'Checking ') ||
                str_contains($line, 'Done.') ||
                str_contains($line, '^') ||
                str_contains($line, '(nofile)')
            ) {
                continue;
            }

            // Try to match classic cppcheck style: file:line:severity:message
            // or file:line:message
            if (preg_match('/(?:[^:]+)?(?:\.cpp)?:(\d+)(?::\d+)?\s*:\s*(.+)/i', $line, $matches)) {
                $msg = trim($matches[2]);
                $issues[] = [
                    'message'  => $msg,
                    'line'     => (int)$matches[1],
                    'severity' => $this->guessSeverity($msg),
                ];
            }
            // Catch lines that look like serious messages without file:line
            elseif (
                stripos($line, 'error:') !== false ||
                stripos($line, 'internal error') !== false ||
                stripos($line, 'syntax error') !== false
            ) {
                $issues[] = [
                    'message'  => $line,
                    'line'     => null,
                    'severity' => 'error',
                ];
            }
        }

        $issues = array_unique($issues, SORT_REGULAR);

        if (!empty($issues)) {
            return [
                'issues' => $issues,
                'raw'    => $rawOutput,
            ];
        }

        return $this->noIssuesResult('No parsable issues found (check raw output in logs)');
    }

    private function mapSeverity(string $level): string
    {
        $level = strtolower($level);
        if ($level === 'error') {
            return 'error';
        }
        if ($level === 'warning') {
            return 'warning';
        }
        // note, style, performance, portability, information → treated as warning (like PHPStan ignorable)
        return 'warning';
    }

    private function guessSeverity(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'error') || str_contains($lower, 'internal error')) {
            return 'error';
        }
        return 'warning';
    }

    private function errorResult(string $msg): array
    {
        return [
            'issues' => [
                [
                    'message'  => $msg,
                    'severity' => 'critical',
                    'line'     => null,
                ]
            ],
            'raw' => ''
        ];
    }

    // Added to mirror the "clean / no issues" case more clearly (like PHPStan would)
    private function noIssuesResult(string $msg): array
    {
        return [
            'issues' => [],
            'raw'    => $msg,
        ];
    }
}
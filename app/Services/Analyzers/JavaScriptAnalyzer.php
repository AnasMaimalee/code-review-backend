<?php

namespace App\Services\Analyzers;

use Illuminate\Support\Facades\Log;

class JavaScriptAnalyzer
{
    public function analyze(string $code): array
    {
        $file = storage_path('app/tmp_' . uniqid() . '.js');
        file_put_contents($file, $code);

        $eslint = env('ESLINT_PATH', 'npx eslint'); // fallback to npx if not set in .env

        // Use -f json for structured output + --no-error-on-unmatched-pattern to avoid exit 2 on no files
        $command = escapeshellcmd("{$eslint} {$file} -f json --no-error-on-unmatched-pattern") . ' 2>&1';

        $rawOutput = shell_exec($command);

        Log::debug('ESLint command executed', [
            'command'    => $command,
            'raw_output' => $rawOutput,
            'file'       => $file,
        ]);

        unlink($file);

        if (empty($rawOutput)) {
            Log::warning('ESLint returned empty output');
            return $this->errorResult('ESLint returned no output – check ESLINT_PATH / permissions / npx availability');
        }

        // ────────────────────────────────────────────────
        // Try to parse as JSON (ESLint -f json output is usually clean array)
        // ────────────────────────────────────────────────
        $json = json_decode($rawOutput, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            Log::info('ESLint JSON parsed successfully');

            $issues = [];

            // ESLint JSON is array of file objects → usually [0] for single file
            if (isset($json[0]) && is_array($json[0]) && isset($json[0]['messages'])) {
                foreach ($json[0]['messages'] as $msg) {
                    $issues[] = [
                        'message'    => $msg['message'] ?? 'Unknown issue',
                        'line'       => $msg['line'] ?? null,
                        'column'     => $msg['column'] ?? null,
                        'severity'   => ($msg['severity'] ?? 1) === 2 ? 'error' : 'warning',
                        'identifier' => $msg['ruleId'] ?? null,
                    ];
                }
            }

            // Also check for fatal parse errors (e.g. syntax error in JS)
            if (isset($json[0]['errorCount']) && $json[0]['errorCount'] > 0) {
                // Already captured in messages, but ensure we don't miss
            }

            if (!empty($issues)) {
                return [
                    'issues' => $issues,
                    'raw'    => json_encode($json, JSON_PRETTY_PRINT),
                ];
            }

            // Valid JSON + no messages = clean code
            return [
                'issues' => [],
                'raw'    => json_encode($json, JSON_PRETTY_PRINT),
            ];
        }

        // ────────────────────────────────────────────────
        // Fallback: text parsing if JSON decode fails (e.g. ESLint crash or wrong output)
        // ────────────────────────────────────────────────
        Log::info('ESLint JSON parsing failed - falling back to text parsing');

        $lines = explode("\n", $rawOutput);
        $issues = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_contains($line, 'ESLint couldn\'t find')) {
                continue;
            }

            // Typical ESLint CLI output:  file.js:line:column  error/warning  message  rule-id
            if (preg_match('/^(?:.+?\.js):(\d+):(\d+)\s+(error|warning)\s+(.+?)(?:\s+([a-zA-Z\-\/]+))?$/i', $line, $matches)) {
                $issues[] = [
                    'message'    => trim($matches[4]),
                    'line'       => (int)$matches[1],
                    'column'     => (int)$matches[2],
                    'severity'   => strtolower($matches[3]) === 'error' ? 'error' : 'warning',
                    'identifier' => $matches[5] ?? null,
                ];
            }
            // Catch obvious syntax / fatal errors
            elseif (stripos($line, 'Parsing error') !== false || stripos($line, 'error:') !== false) {
                $issues[] = [
                    'message'  => $line,
                    'severity' => 'error',
                    'line'     => null,
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

        return [
            'issues' => [],
            'raw'    => $rawOutput,
        ];
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
            'raw' => '',
        ];
    }
}
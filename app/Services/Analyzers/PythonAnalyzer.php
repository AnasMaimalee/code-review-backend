<?php

namespace App\Services\Analyzers;

use Illuminate\Support\Facades\Log;

class PythonAnalyzer
{
    public function analyze(string $code): array
    {
        $file = storage_path('app/tmp_' . uniqid() . '.py');
        file_put_contents($file, $code);

        $pylint = env('PYLINT_PATH', 'pylint'); // fallback — assumes pylint in PATH

        if (!$pylint) {
            Log::warning('PYLINT_PATH not configured');
            return $this->errorResult('Pylint path not configured in environment');
        }

        // Use --output-format=json for structured output
        // --score=no to avoid extra score line in some versions
        // --reports=no to reduce noise
        $command = escapeshellcmd(
            "{$pylint} {$file} --output-format=json --score=no --reports=no"
        ) . ' 2>&1';

        $rawOutput = shell_exec($command);

        Log::debug('Pylint command executed', [
            'command'    => $command,
            'raw_output' => $rawOutput,
            'file'       => $file,
        ]);

        unlink($file);

        if (empty($rawOutput)) {
            Log::warning('Pylint returned empty output');
            return $this->errorResult('Pylint returned no output – check PYLINT_PATH / installation / permissions');
        }

        // ────────────────────────────────────────────────
        // Try to parse JSON (Pylint --output-format=json returns array of messages)
        // ────────────────────────────────────────────────
        $json = json_decode($rawOutput, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            Log::info('Pylint JSON parsed successfully');

            $issues = [];

            foreach ($json as $item) {
                if (!isset($item['message'])) {
                    continue;
                }

                $issues[] = [
                    'message'    => trim($item['message']),
                    'line'       => $item['line'] ?? null,
                    'column'     => $item['column'] ?? null,
                    'severity'   => $this->mapSeverity($item['type'] ?? 'warning'),
                    'identifier' => $item['symbol'] ?? $item['msg_id'] ?? null,
                ];
            }

            return [
                'issues' => $issues,
                'raw'    => json_encode($json, JSON_PRETTY_PRINT),
            ];
        }

        // ────────────────────────────────────────────────
        // Fallback: text parsing if JSON fails (older pylint or error output)
        // ────────────────────────────────────────────────
        Log::info('Pylint JSON parsing failed - falling back to text parsing');

        $lines = explode("\n", $rawOutput);
        $issues = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, 'No config file found')) {
                continue;
            }

            // Classic pylint format: file.py:line:column: severity: message (symbol)
            if (preg_match('/(?:.+?\.py):(\d+):(\d+): ([a-z]+): (.+?)(?:\s*\((.+?)\))?$/i', $line, $matches)) {
                $issues[] = [
                    'message'    => trim($matches[4]),
                    'line'       => (int)$matches[1],
                    'column'     => (int)$matches[2],
                    'severity'   => $this->mapSeverity($matches[3]),
                    'identifier' => $matches[5] ?? null,
                ];
            }
            // Catch standalone errors or warnings
            elseif (stripos($line, 'error:') !== false || stripos($line, 'SyntaxError') !== false) {
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

        // No issues found → clean
        return [
            'issues' => [],
            'raw'    => $rawOutput,
        ];
    }

    private function mapSeverity(string $type): string
    {
        $type = strtolower($type);
        return match ($type) {
            'error', 'fatal' => 'error',
            'warning', 'refactor' => 'warning',
            default => 'info', // convention, info, etc.
        };
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
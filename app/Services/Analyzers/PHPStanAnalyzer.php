<?php

namespace App\Services\Analyzers;

use Illuminate\Support\Facades\Log;

class PHPStanAnalyzer
{
    public function analyze(string $code): array
    {
        $file = storage_path('app/tmp_' . uniqid() . '.php');
        file_put_contents($file, $code);

        $phpstan = base_path(env('PHPSTAN_PATH', 'vendor/bin/phpstan'));

        $command = escapeshellcmd("{$phpstan} analyse {$file} --level=9 --no-progress --error-format=json") . ' 2>&1';

        $rawOutput = shell_exec($command);

        Log::debug('PHPStan command executed', [
            'command' => $command,
            'raw_output' => $rawOutput,
            'file' => $file,
        ]);

        unlink($file);

        if (empty($rawOutput)) {
            Log::warning('PHPStan returned empty output');
            return $this->errorResult('PHPStan returned no output – check binary path/permissions');
        }

        // Step 1: Strip trailing non-JSON warning text (PHPStan appends this on severe errors)
        $jsonEndPos = strrpos($rawOutput, '}');
        if ($jsonEndPos !== false) {
            $cleanJson = substr($rawOutput, 0, $jsonEndPos + 1);
        } else {
            $cleanJson = $rawOutput; // fallback if no closing brace found
        }

        // Step 2: Try decoding the cleaned string
        $json = json_decode($cleanJson, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            Log::info('PHPStan JSON parsed successfully after cleaning');

            $issues = [];

            // Extract from standard structure
            if (isset($json['files']) && is_array($json['files'])) {
                foreach ($json['files'] as $filePath => $fileData) {
                    if (isset($fileData['messages']) && is_array($fileData['messages'])) {
                        foreach ($fileData['messages'] as $msg) {
                            $issues[] = [
                                'message'  => $msg['message'] ?? 'Unknown issue',
                                'line'     => $msg['line'] ?? null,
                                'severity' => ($msg['ignorable'] ?? false) ? 'warning' : 'error',
                                'identifier' => $msg['identifier'] ?? null, // useful for AI prompt
                            ];
                        }
                    }
                }
            }

            // Flat errors (rare, but handle)
            if (isset($json['errors']) && is_array($json['errors'])) {
                foreach ($json['errors'] as $err) {
                    $issues[] = [
                        'message'  => is_string($err) ? $err : json_encode($err),
                        'severity' => 'error',
                    ];
                }
            }

            // If we got issues, return them
            if (!empty($issues)) {
                return [
                    'issues' => $issues,
                    'raw'    => json_encode($json, JSON_PRETTY_PRINT) // or keep $rawOutput
                ];
            }

            // If JSON valid but no issues extracted (unlikely)
            return $this->errorResult('No issues found in valid JSON output');
        }

        // Fallback text parsing only if JSON completely fails
        Log::info('JSON parsing failed - falling back to text parsing');

        $lines = explode("\n", $rawOutput);
        $issues = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '⚠️') || str_contains($line, 'Fix these errors first')) {
                continue;
            }

            // Catch file:line: messages and obvious errors
            if (preg_match('#^[^:]+:\d+:#', $line) ||
                stripos($line, 'syntax error') !== false ||
                stripos($line, 'unexpected') !== false ||
                stripos($line, 'expecting') !== false ||
                stripos($line, 'undefined') !== false) {
                $issues[] = $line;
            }
        }

        $issues = array_unique(array_filter(array_map('trim', $issues)));

        if (!empty($issues)) {
            return [
                'issues' => array_map(fn($m) => ['message' => $m, 'severity' => 'error'], $issues),
                'raw'    => $rawOutput
            ];
        }

        return $this->errorResult('No parsable issues found (check raw output in logs)');
    }

    private function errorResult(string $msg): array
    {
        return [
            'issues' => [['message' => $msg, 'severity' => 'critical']],
            'raw'    => ''
        ];
    }
}
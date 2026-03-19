<?php

namespace App\Services\Analyzers;

class CppAnalyzer
{
    public function analyze(string $code): array
    {
        $file = storage_path('app/tmp_' . uniqid() . '.cpp');
        file_put_contents($file, $code);

        $cppcheck = env('CPPCHECK_PATH');
        $command = "{$cppcheck} --enable=all {$file} 2>&1";

        $output = shell_exec($command);
        unlink($file);

        if (!$output) {
            return ['issues' => ['Cppcheck execution failed'], 'raw' => []];
        }

        $issues = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);

            if (
                empty($line) ||
                str_contains($line, 'Checking') ||
                str_contains($line, '^') ||
                str_contains($line, 'nofile')
            ) {
                continue;
            }

            // remove file path prefix
            $line = preg_replace('/.*\.cpp:\d+:\d+:\s*/', '', $line);

            // keep only real issues
            if (str_contains($line, 'error') || str_contains($line, 'warning')) {
                $issues[] = $line;
            }
        }

        return ['issues' => array_values($issues), 'raw' => $output];
    }
}
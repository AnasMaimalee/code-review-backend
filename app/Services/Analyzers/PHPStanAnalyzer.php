<?php

namespace App\Services\Analyzers;

class PHPStanAnalyzer
{
    public function analyze(string $code): array
    {
        $file = storage_path('app/tmp_' . uniqid() . '.php');
        file_put_contents($file, $code);

        $phpstan = base_path(env('PHPSTAN_PATH', 'vendor/bin/phpstan'));

        $command = "{$phpstan} analyse {$file} --level=max --no-progress 2>&1";
        $output = shell_exec($command);

        unlink($file);

        if (!$output) {
            return [
                'issues' => ['PHPStan execution failed'],
                'raw' => []
            ];
        }

        $issues = [];
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || str_contains($line, '[ERROR]')) {
                continue;
            }

            // remove line numbers like "1      "
            $line = preg_replace('/^\d+\s+/', '', $line);

            if (
                str_contains($line, 'Function') ||
                str_contains($line, 'Binary') ||
                str_contains($line, 'parameter')
            ) {
                $issues[] = $line;
            }
        }

        return [
            'issues' => array_values(array_unique($issues)),
            'raw' => $output
        ];
    }
}
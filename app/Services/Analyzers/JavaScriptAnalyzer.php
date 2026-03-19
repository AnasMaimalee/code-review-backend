<?php

namespace App\Services\Analyzers;

class JavaScriptAnalyzer
{
    public function analyze(string $code): array
    {
        $file = storage_path('app/tmp_' . uniqid() . '.js');
        file_put_contents($file, $code);

        $eslint = env('ESLINT_PATH');
        $command = "{$eslint} {$file} -f json 2>&1";

        $output = shell_exec($command);
        unlink($file);

        if (!$output) {
            return ['issues' => ['ESLint execution failed'], 'raw' => []];
        }

        $data = json_decode($output, true);
        $issues = [];

        if (isset($data[0]['messages'])) {
            foreach ($data[0]['messages'] as $msg) {
                $issues[] = $msg['message'];
            }
        }

        return ['issues' => $issues, 'raw' => $data];
    }
}
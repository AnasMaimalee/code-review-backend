<?php

namespace App\Services\Analyzers;

class PythonAnalyzer
{
    public function analyze($code)
    {
        $file = storage_path('app/tmp_' . uniqid() . '.py');
        file_put_contents($file, $code);

        $pylint = env('PYLINT_PATH');

        if (!$pylint) {
            return [
                'issues' => ['Pylint path not configured']
            ];
        }

        $command = "{$pylint} {$file} --output-format=json 2>&1";
        $output = shell_exec($command);

        unlink($file);

        $data = json_decode($output, true);

        $issues = [];

        if (is_array($data)) {
            foreach ($data as $item) {
                $issues[] = $item['message'];
            }
        } else {
            $issues[] = "Analyzer failed or returned invalid output";
        }

        return ['issues' => $issues];
    }
}
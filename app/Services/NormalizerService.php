<?php

namespace App\Services;

class NormalizerService
{
    public function normalize(string $language, string $rawOutput): array
    {
        $data = json_decode($rawOutput, true);

        if (!$data) {
            return [];
        }

        return match ($language) {
            'php' => $this->normalizePHPStan($data),
            'javascript', 'js' => $this->normalizeESLint($data),
            'python' => $this->normalizePylint($data),
            'cpp' => $this->normalizeCppcheck($data),
            default => []
        };
    }

    private function normalizePHPStan(array $data): array
    {
        return collect($data['files'] ?? [])
            ->flatMap(fn($file) => $file['messages'] ?? [])
            ->map(fn($msg) => [
                'message' => $msg['message'],
                'line' => $msg['line'] ?? null,
                'severity' => 'error'
            ])
            ->toArray();
    }

    private function normalizeESLint(array $data): array
    {
        return collect($data)
            ->flatMap(fn($file) => $file['messages'] ?? [])
            ->map(fn($msg) => [
                'message' => $msg['message'],
                'line' => $msg['line'],
                'severity' => $msg['severity'] === 2 ? 'error' : 'warning'
            ])
            ->toArray();
    }

    private function normalizePylint(array $data): array
    {
        return collect($data)
            ->map(fn($msg) => [
                'message' => $msg['message'],
                'line' => $msg['line'],
                'severity' => $msg['type']
            ])
            ->toArray();
    }

    private function normalizeCppcheck(array $data): array
    {
        return collect($data['issues'] ?? [])
            ->map(fn($msg) => [
                'message' => $msg['message'],
                'line' => $msg['line'] ?? null,
                'severity' => $msg['severity'] ?? 'error'
            ])
            ->toArray();
    }
}
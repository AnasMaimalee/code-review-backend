<?php

namespace App\DTOs;

class AnalysisResult
{
    public function __construct(
        public float $score,
        public array $issues,
        public string $explanation,
        public string $code
    ) {}

    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'issues' => $this->issues,
            'explanation' => $this->explanation,
            'code' => $this->code,
        ];
    }
}
<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Services\Analyzers\AnalyzerFactory;
use App\Services\NormalizerService;
use App\Services\AIExplanationService;
use App\DTOs\AnalysisResult;
use InvalidArgumentException;

class CodeAnalysisService
{
    public function __construct(
        protected NormalizerService $normalizer,
        protected AIExplanationService $ai
    ) {}
public function analyze(string $language, string $code): AnalysisResult
{
    Log::info('--- ANALYSIS START ---');

    try {
        $analyzer = AnalyzerFactory::make($language);
    } catch (\Exception $e) {
        Log::error('Analyzer error: ' . $e->getMessage());

        return new AnalysisResult(0, [], 'Unsupported language', $code);
    }

    // 🔥 STEP 1: Run analyzer
    $result = $analyzer->analyze($code);

    Log::info('RAW ANALYZER RESULT:', $result);

    $rawOutput = $result['raw'] ?? '';

    if (empty($rawOutput)) {
        Log::error('Analyzer returned EMPTY output');

        return new AnalysisResult(
            0,
            [],
            'Analyzer failed or returned empty output.',
            $code
        );
    }

    // 🔥 STEP 2: Normalize
    $normalized = $this->normalizer->normalize($language, $rawOutput);

    Log::info('NORMALIZED ISSUES:', $normalized);

    if (empty($normalized)) {
        Log::warning('Normalization returned EMPTY issues');

        return new AnalysisResult(
            10,
            [],
            'No issues detected by analyzer.',
            $code
        );
    }

    // 🔥 STEP 3: AI
    try {
        $aiResponse = $this->ai->explain($normalized, $code);

        Log::info('AI RESPONSE:', $aiResponse);

    } catch (\Exception $e) {
        Log::error('AI ERROR: ' . $e->getMessage());

        return new AnalysisResult(
            5,
            $normalized,
            'AI failed. Showing raw issues.',
            $code
        );
    }

    $score = max(0, 10 - count($normalized));

    return new AnalysisResult(
        $score,
        $aiResponse['issues'] ?? $normalized,
        $aiResponse['summary'] ?? 'AI-generated analysis',
        $code
    );
}

    private function calculateScore(array $issues): float
    {
        $penalty = count($issues);

        return max(0, 10 - $penalty);
    }
}
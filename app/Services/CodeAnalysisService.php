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
        $code = trim($code);
        if (empty($code)) {
            Log::warning('Empty code submitted for analysis');
            return new AnalysisResult(
                0,
                [],
                'No code provided for analysis.',
                ''
            );
        }

        Log::info('--- ANALYSIS START ---', [
            'language' => $language,
            'code_length' => strlen($code),
        ]);

        // STEP 0: Get analyzer
        try {
            $analyzer = AnalyzerFactory::make($language);
        } catch (InvalidArgumentException $e) {
            Log::error('Unsupported language', ['language' => $language, 'error' => $e->getMessage()]);
            return new AnalysisResult(
                0,
                [],
                "Unsupported language: {$language}",
                $code
            );
        } catch (\Exception $e) {
            Log::error('Analyzer factory failed', ['error' => $e->getMessage()]);
            return new AnalysisResult(
                0,
                [],
                'Internal error while selecting analyzer.',
                $code
            );
        }

        // 🔥 STEP 1: Run static analyzer
        $result = $analyzer->analyze($code);

        Log::debug('RAW ANALYZER RESULT', $result);

        $rawOutput = trim($result['raw'] ?? '');

        if (empty($rawOutput)) {
            Log::error('Analyzer returned empty output', [
                'language' => $language,
                'analyzer_class' => get_class($analyzer),
            ]);

            return new AnalysisResult(
                0,
                [],
                'Static analyzer failed or returned no output. Check server logs.',
                $code
            );
        }

        // 🔥 STEP 2: Normalize issues
        $normalized = $this->normalizer->normalize($language, $rawOutput);

        Log::info('NORMALIZED ISSUES', ['count' => count($normalized), 'issues' => $normalized]);

        // Early return for clean code (real 0 issues)
        if (empty($normalized)) {
            Log::info('No issues found after normalization');

            // Still send to AI for better explanation / confirmation
            try {
                $aiResponse = $this->ai->explain([], $code);
                Log::info('AI RESPONSE (clean code)', $aiResponse);

                return new AnalysisResult(
                    $aiResponse['score'] ?? 10,
                    $aiResponse['issues'] ?? [],
                    $aiResponse['summary'] ?? 'Code appears clean — no issues detected.',
                    $code
                );
            } catch (\Exception $e) {
                Log::warning('AI failed on clean code', ['error' => $e->getMessage()]);
                return new AnalysisResult(
                    10,
                    [],
                    'No issues detected by static analyzer. AI explanation unavailable.',
                    $code
                );
            }
        }

        // 🔥 STEP 3: AI explanation (for cases with issues)
        try {
            $aiResponse = $this->ai->explain($normalized, $code);
            Log::info('AI RESPONSE', $aiResponse);

            $finalIssues = $aiResponse['issues'] ?? $normalized;
            $finalSummary = $aiResponse['summary'] ?? 'AI-enhanced analysis of detected issues';
            $finalScore = $aiResponse['score'] ?? $this->calculateScore($finalIssues);

        } catch (\Exception $e) {
            Log::error('AI explanation failed', ['error' => $e->getMessage()]);

            $finalIssues = $normalized;
            $finalSummary = 'AI explanation failed — showing raw static analysis issues only.';
            $finalScore = $this->calculateScore($finalIssues);
        }

        return new AnalysisResult(
            max(0, min(10, $finalScore)), // ensure 0–10 range
            $finalIssues,
            $finalSummary,
            $code
        );
    }

    private function calculateScore(array $issues): int
    {
        $penalty = count($issues);

        // Optional: you can make it more sophisticated later
        // e.g. weight errors higher than warnings
        return max(0, 10 - $penalty);
    }
}
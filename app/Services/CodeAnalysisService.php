<?php

namespace App\Services;

use App\Services\Analyzers\AnalyzerFactory;
use App\DTOs\AnalysisResult;

class CodeAnalysisService
{
    public function analyze(string $language, string $code): AnalysisResult
    {
        try {
            $analyzer = AnalyzerFactory::make($language);
        } catch (\InvalidArgumentException $e) {
            return new AnalysisResult(
                0,
                [],
                "Unsupported language selected."
            );
        }

        $result = $analyzer->analyze($code);

        $rawIssues = $result['issues'] ?? [];

        // 🔥 Convert to structured explainable AI format
        $issues = array_map(fn($i) => $this->explainIssue($i), $rawIssues);

        $score = $this->calculateScore($rawIssues);

        return new AnalysisResult(
            $score,
            $issues,
            $this->generateExplanation($issues, $score)
        );
    }

    private function calculateScore(array $issues): float
    {
        $penalty = min(count($issues) * 0.7, 10);
        return max(0, 10 - $penalty);
    }

    private function generateExplanation(array $issues, float $score): string
    {
        if (empty($issues)) {
            return "Excellent code quality with no detected issues.";
        }

        return "Detected " . count($issues) .
               " issues including critical problems affecting reliability and maintainability. Final score: {$score}.";
    }

    // 🔥 Explainable AI core (structured output)
    private function explainIssue(string $issue): array
    {
        if (str_contains($issue, 'Uninitialized variable')) {
            return [
                'title' => 'Uninitialized variable',
                'explanation' => 'Variable is used before being assigned a value.',
                'impact' => 'May cause unpredictable behavior or bugs.',
                'suggestion' => 'Initialize the variable before use.',
                'severity' => 'high'
            ];
        }

        if (str_contains($issue, 'null pointer')) {
            return [
                'title' => 'Null pointer dereference',
                'explanation' => 'Pointer is accessed without valid memory reference.',
                'impact' => 'Can cause crashes or segmentation faults.',
                'suggestion' => 'Ensure pointer is initialized before use.',
                'severity' => 'critical'
            ];
        }

        if (str_contains($issue, 'Binary operation')) {
            return [
                'title' => 'Type mismatch',
                'explanation' => 'Incompatible data types are used in an operation.',
                'impact' => 'May result in runtime errors.',
                'suggestion' => 'Ensure operands are of compatible types.',
                'severity' => 'high'
            ];
        }

        if (str_contains($issue, 'no return type')) {
            return [
                'title' => 'Missing return type',
                'explanation' => 'Function does not define a return type.',
                'impact' => 'Reduces code predictability and safety.',
                'suggestion' => 'Specify a return type for the function.',
                'severity' => 'medium'
            ];
        }

        if (str_contains($issue, 'no type specified')) {
            return [
                'title' => 'Missing parameter type',
                'explanation' => 'Function parameter lacks a type definition.',
                'impact' => 'Makes code harder to understand and debug.',
                'suggestion' => 'Add type hints to function parameters.',
                'severity' => 'medium'
            ];
        }

        // fallback
        return [
            'title' => 'General issue',
            'explanation' => $issue,
            'impact' => 'May affect code quality.',
            'suggestion' => 'Review and improve this part of the code.',
            'severity' => 'low'
        ];
    }
}
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIExplanationService
{
    public function explain(array $issues, string $code): array
    {
        $prompt = $this->buildPrompt($issues, $code);

        try {
            // In explain() method — replace the post body:

            $response = Http::withToken(env('OPENAI_API_KEY'))
                ->timeout(30) // give more time for complex code
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini', // or gpt-4o-2024-11-xx for better structured support
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a senior code reviewer. Respond only with valid structured JSON.'],
                        ['role' => 'user', 'content' => $this->buildPrompt($issues, $code)],
                    ],
                    'temperature' => 0.1,
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'code_review',
                            'strict' => true,
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'summary' => ['type' => 'string'],
                                    'issues' => [
                                        'type' => 'array',
                                        'items' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'title'     => ['type' => 'string'],
                                                'explanation' => ['type' => 'string'],
                                                'impact'    => ['type' => 'string'],
                                                'suggestion'=> ['type' => 'string'],
                                                'severity'  => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                                            ],
                                            'required' => ['title', 'explanation', 'severity'],
                                            'additionalProperties' => false,
                                        ],
                                    ],
                                ],
                                'required' => ['summary', 'issues'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ]);
            // 🔥 Handle API failure
            if ($response->failed()) {
                $status = $response->status();               // e.g. 401, 429, 400
                $body   = $response->body();                 // usually JSON with OpenAI's error message
                $errorMessage = $response->json('error.message') ?? $body; // try to extract message

                Log::error('OpenAI API call failed', [
                    'status'       => $status,
                    'response_body'=> $body,
                    'error_message'=> $errorMessage,
                    'headers'      => $response->headers(),
                    'prompt_snippet'=> substr($prompt, 0, 200) . '...', // for size check
                ]);

                return $this->fallback($issues, "AI request failed (HTTP {$status}): {$errorMessage}");
            }

            $content = $response['choices'][0]['message']['content'] ?? '';

            // 🔥 Try to decode JSON
            $decoded = json_decode($content, true);

            // ❌ If AI did NOT return valid JSON
            if (!$decoded) {
                return $this->fallback($issues, $content);
            }

            return $decoded;

        } catch (\Exception $e) {
            return $this->fallback($issues, $e->getMessage());
        }
    }

    // 🔥 Build strong prompt (VERY IMPORTANT)
    private function buildPrompt(array $issues, string $code): string
    {
        return "
You are an expert software engineer.

Analyze the issues and code.

Return STRICT JSON ONLY (no text outside JSON):

{
  \"summary\": \"...\",
  \"issues\": [
    {
      \"title\": \"...\",
      \"explanation\": \"...\",
      \"impact\": \"...\",
      \"suggestion\": \"...\",
      \"severity\": \"low|medium|high|critical\"
    }
  ]
}

Issues:
" . json_encode($issues, JSON_PRETTY_PRINT) . "

Code:
" . $code;
    }

    // 🔥 Safe fallback (VERY IMPORTANT)
    private function fallback(array $issues, string $reason): array
    {
        return [
            'summary' => "AI unavailable: " . $reason,
            'issues' => $issues
        ];
    }
}
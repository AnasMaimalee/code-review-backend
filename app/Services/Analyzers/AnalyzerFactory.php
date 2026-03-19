<?php

namespace App\Services\Analyzers;

use InvalidArgumentException;

class AnalyzerFactory
{
    protected static array $map = [
        'php' => PHPStanAnalyzer::class,
        'python' => PythonAnalyzer::class,
        'py' => PythonAnalyzer::class,
        'javascript' => JavaScriptAnalyzer::class,
        'js' => JavaScriptAnalyzer::class,
        'cpp' => CppAnalyzer::class,
        'c++' => CppAnalyzer::class,
    ];

    public static function make(string $language)
    {
        $normalized = self::normalize($language);

        if (!isset(self::$map[$normalized])) {
            throw new InvalidArgumentException("Unsupported language: {$language}");
        }

        $class = self::$map[$normalized];

        return app($class); // uses Laravel container (better than new)
    }

    protected static function normalize(string $language): string
    {
        return strtolower(trim($language));
    }

    public static function supportedLanguages(): array
    {
        return array_unique(array_keys(self::$map));
    }
}
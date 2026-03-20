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

        // TEMP TEST: use new instead of app()
        if (!class_exists($class)) {
            throw new \Exception("Class $class still not found even with direct check");
        }
        return new $class();  // bypass container
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
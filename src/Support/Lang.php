<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stringhe UI centralizzate (lang/it.php). Segnaposto nel formato :nome.
 */
final class Lang
{
    /** @var array<string, string> */
    private readonly array $strings;

    public function __construct(string $rootPath)
    {
        $strings = require $rootPath . '/lang/it.php';
        $clean = [];
        if (is_array($strings)) {
            foreach ($strings as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $clean[$key] = $value;
                }
            }
        }
        $this->strings = $clean;
    }

    /** @param array<string, string|int|float> $params */
    public function t(string $key, array $params = []): string
    {
        $text = $this->strings[$key] ?? $key;
        if ($params !== []) {
            $replacements = [];
            foreach ($params as $name => $value) {
                $replacements[':' . $name] = (string) $value;
            }
            $text = strtr($text, $replacements);
        }

        return $text;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stringhe UI centralizzate e multi-lingua (lang/it.php + lang/en.php).
 * Segnaposto nel formato :nome. Locale di default: italiano; le chiavi
 * mancanti in una lingua ricadono sull'italiano, poi sulla chiave stessa.
 *
 * Il locale corrente è mutabile (setLocale): viene impostato a inizio
 * richiesta dal SessionMiddleware e, per le email, forzato al locale del
 * destinatario da OrderMailer (admin sempre in italiano).
 */
final class Lang
{
    public const LOCALES = ['it', 'en'];
    public const DEFAULT_LOCALE = 'it';

    /** @var array<string, array<string, string>> locale => chiave => testo */
    private array $strings = [];

    private string $locale = self::DEFAULT_LOCALE;

    public function __construct(private readonly string $rootPath)
    {
    }

    public function setLocale(string $locale): void
    {
        $this->locale = in_array($locale, self::LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, string|int|float> $params */
    public function t(string $key, array $params = []): string
    {
        $text = $this->stringsFor($this->locale)[$key]
            ?? $this->stringsFor(self::DEFAULT_LOCALE)[$key]
            ?? $key;
        if ($params !== []) {
            $replacements = [];
            foreach ($params as $name => $value) {
                $replacements[':' . $name] = (string) $value;
            }
            $text = strtr($text, $replacements);
        }

        return $text;
    }

    /** Traduzione in un locale esplicito, senza toccare quello corrente. */
    /** @param array<string, string|int|float> $params */
    public function tIn(string $locale, string $key, array $params = []): string
    {
        $previous = $this->locale;
        $this->setLocale($locale);
        $text = $this->t($key, $params);
        $this->locale = $previous;

        return $text;
    }

    /** @return array<string, string> */
    private function stringsFor(string $locale): array
    {
        if (!isset($this->strings[$locale])) {
            $file = $this->rootPath . '/lang/' . $locale . '.php';
            $strings = is_file($file) ? require $file : [];
            $clean = [];
            if (is_array($strings)) {
                foreach ($strings as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $clean[$key] = $value;
                    }
                }
            }
            $this->strings[$locale] = $clean;
        }

        return $this->strings[$locale];
    }
}

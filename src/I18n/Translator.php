<?php

declare(strict_types=1);

namespace Studbook\I18n;

/**
 * Looks up UI strings in `lang/{language}.php`. English is the fallback for
 * missing keys; an unknown key renders as the key itself so it is easy to spot.
 */
final class Translator
{
    public const FALLBACK = 'en';
    /** Supported UI languages => BCP 47 locale used for formatting. */
    public const LANGUAGES = [
        'en' => 'en-GB',
        'hu' => 'hu-HU',
    ];

    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    public function __construct(private readonly string $langDirectory, private string $language = self::FALLBACK)
    {
        if (!self::isSupported($language)) {
            $this->language = self::FALLBACK;
        }
    }

    public static function isSupported(string $language): bool
    {
        return isset(self::LANGUAGES[$language]);
    }

    public function language(): string
    {
        return $this->language;
    }

    public function locale(): string
    {
        return self::LANGUAGES[$this->language];
    }

    /** @param array<string, string|int|float> $params replaces `{name}` placeholders */
    public function translate(string $key, array $params = []): string
    {
        $text = $this->catalogue($this->language)[$key]
            ?? $this->catalogue(self::FALLBACK)[$key]
            ?? $key;
        if ($params === []) {
            return $text;
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    /** @return array<string, string> */
    public function catalogue(string $language): array
    {
        if (!isset($this->catalogues[$language])) {
            $this->catalogues[$language] = self::loadFile($this->langDirectory . '/' . $language . '.php');
        }

        return $this->catalogues[$language];
    }

    /** @return array<string, string> */
    public static function loadFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        if (!is_array($data)) {
            throw new \UnexpectedValueException(sprintf('%s must return an array.', $file));
        }

        return $data;
    }
}

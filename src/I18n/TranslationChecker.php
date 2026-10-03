<?php

declare(strict_types=1);

namespace Studbook\I18n;

/** Compares the key sets of all language files against each other. */
final class TranslationChecker
{
    public function __construct(private readonly string $langDirectory)
    {
    }

    /**
     * @return array<string, list<string>> "hu: missing key" style problems, keyed by language
     */
    public function problems(): array
    {
        $catalogues = [];
        foreach (array_keys(Translator::LANGUAGES) as $language) {
            $file = $this->langDirectory . '/' . $language . '.php';
            $catalogues[$language] = is_file($file) ? Translator::loadFile($file) : null;
        }

        $allKeys = [];
        foreach ($catalogues as $catalogue) {
            $allKeys = array_merge($allKeys, array_keys($catalogue ?? []));
        }
        $allKeys = array_values(array_unique($allKeys));
        sort($allKeys);

        $problems = [];
        foreach ($catalogues as $language => $catalogue) {
            if ($catalogue === null) {
                $problems[$language][] = sprintf('file lang/%s.php is missing', $language);
                continue;
            }
            foreach ($allKeys as $key) {
                if (!array_key_exists($key, $catalogue)) {
                    $problems[$language][] = sprintf('missing key "%s"', $key);
                } elseif (!is_string($catalogue[$key]) || trim($catalogue[$key]) === '') {
                    $problems[$language][] = sprintf('empty value for "%s"', $key);
                }
            }
        }

        return $problems;
    }
}

<?php

declare(strict_types=1);

namespace Studbook\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Studbook\I18n\TranslationChecker;

/** Health check: the shipped language files have identical key sets. */
final class TranslationKeysTest extends TestCase
{
    public function testShippedLanguageFilesHaveTheSameKeys(): void
    {
        self::assertSame([], (new TranslationChecker(dirname(__DIR__, 2) . '/lang'))->problems());
    }

    public function testEveryKeyUsedInCodeExists(): void
    {
        $en = require dirname(__DIR__, 2) . '/lang/en.php';
        $root = dirname(__DIR__, 2);
        $missing = [];
        foreach (['src', 'templates'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                // Literal keys only: t('a.b') or t('a.b', [...]); dynamic keys are checked elsewhere.
                preg_match_all('/(?<![\w>])t\(\s*\'([a-z0-9_.]+)\'\s*[,)]/', $code, $m);
                $prefixes = 'error|nav|login|settings|home|footer|setup|credentials|import'
                    . '|collection|box|box_type|labels|owned|batch|entry|search|picker|history'
                    . '|set|set_state|set_lock|build|build_state|scan|camera|labels_photo|identify';
                preg_match_all('/(?:=>|return|\(|,)\s*\'((?:' . $prefixes . ')\.[a-z0-9_.]*[a-z0-9])\'/', $code, $m2);
                foreach (array_merge($m[1], $m2[1]) as $key) {
                    if (!array_key_exists($key, $en)) {
                        $missing[] = $key . ' (' . basename($file->getPathname()) . ')';
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)));
    }

    public function testCheckerReportsMissingKeys(): void
    {
        $dir = sys_get_temp_dir() . '/studbook-check-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/en.php', "<?php return ['a' => 'A', 'b' => 'B'];");
        file_put_contents($dir . '/hu.php', "<?php return ['a' => 'Á', 'c' => 'C'];");

        try {
            $problems = (new TranslationChecker($dir))->problems();
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }

        self::assertSame(['missing key "c"'], $problems['en']);
        self::assertSame(['missing key "b"'], $problems['hu']);
    }
}

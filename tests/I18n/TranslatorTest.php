<?php

declare(strict_types=1);

namespace Studbook\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Studbook\I18n\Translator;

final class TranslatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/studbook-lang-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents(
            $this->dir . '/en.php',
            "<?php return ['a' => 'A', 'only_en' => 'English only', 'hi' => 'Hi {name}'];"
        );
        file_put_contents($this->dir . '/hu.php', "<?php return ['a' => 'Á', 'hi' => 'Szia {name}'];");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testTranslatesInChosenLanguage(): void
    {
        self::assertSame('Á', (new Translator($this->dir, 'hu'))->translate('a'));
    }

    public function testFallsBackToEnglishThenToKey(): void
    {
        $translator = new Translator($this->dir, 'hu');

        self::assertSame('English only', $translator->translate('only_en'));
        self::assertSame('missing.key', $translator->translate('missing.key'));
    }

    public function testReplacesPlaceholders(): void
    {
        self::assertSame('Szia Péter', (new Translator($this->dir, 'hu'))->translate('hi', ['name' => 'Péter']));
    }

    public function testUnsupportedLanguageFallsBackToEnglish(): void
    {
        $translator = new Translator($this->dir, 'xx');

        self::assertSame('en', $translator->language());
        self::assertSame('A', $translator->translate('a'));
    }
}

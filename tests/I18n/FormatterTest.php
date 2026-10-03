<?php

declare(strict_types=1);

namespace Studbook\Tests\I18n;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Studbook\I18n\Formatter;

final class FormatterTest extends TestCase
{
    public function testFallbackFormatsWithoutIntl(): void
    {
        $date = new DateTimeImmutable('2026-10-03 18:05:00', new DateTimeZone('UTC'));
        $en = new Formatter('en', 'en-GB', new DateTimeZone('UTC'), useIntl: false);
        $hu = new Formatter('hu', 'hu-HU', new DateTimeZone('Europe/Budapest'), useIntl: false);

        self::assertSame('1,234.50', $en->number(1234.5, 2));
        self::assertSame('1 234,50', $hu->number(1234.5, 2));
        self::assertSame('3 Oct 2026', $en->date($date));
        self::assertSame('2026. 10. 03. 20:05', $hu->dateTime($date));
    }

    #[RequiresPhpExtension('intl')]
    public function testIntlUsesLocaleConventions(): void
    {
        $hu = new Formatter('hu', 'hu-HU', new DateTimeZone('Europe/Budapest'));
        $en = new Formatter('en', 'en-GB', new DateTimeZone('UTC'));

        self::assertStringContainsString(',5', $hu->number(1234.5, 1));
        self::assertSame('1,234.5', $en->number(1234.5, 1));
        self::assertStringContainsString('2026', $hu->date(new DateTimeImmutable('2026-10-03')));
    }
}

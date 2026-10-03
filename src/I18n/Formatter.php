<?php

declare(strict_types=1);

namespace Studbook\I18n;

use DateTimeInterface;
use DateTimeZone;

/**
 * Locale-aware dates and numbers. Uses ext-intl when available and a small
 * built-in table otherwise, so the app works on hosts without intl.
 */
final class Formatter
{
    /** Fallback patterns per language: [date, datetime, decimal separator, thousands separator]. */
    private const FALLBACK = [
        'en' => ['j M Y', 'j M Y, H:i', '.', ','],
        'hu' => ['Y. m. d.', 'Y. m. d. H:i', ',', ' '],
    ];

    public function __construct(
        private readonly string $language,
        private readonly string $locale,
        private readonly DateTimeZone $timezone,
        private readonly bool $useIntl = true,
    ) {
    }

    public function number(int|float $value, int $decimals = 0): string
    {
        if ($this->intlAvailable()) {
            $formatter = new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $result = $formatter->format($value);
            if ($result !== false) {
                return $result;
            }
        }
        [, , $decimalSep, $thousandsSep] = self::FALLBACK[$this->language] ?? self::FALLBACK['en'];

        return number_format((float) $value, $decimals, $decimalSep, $thousandsSep);
    }

    public function date(DateTimeInterface $value): string
    {
        return $this->format($value, false);
    }

    public function dateTime(DateTimeInterface $value): string
    {
        return $this->format($value, true);
    }

    private function format(DateTimeInterface $value, bool $withTime): string
    {
        $local = \DateTimeImmutable::createFromInterface($value)->setTimezone($this->timezone);
        if ($this->intlAvailable()) {
            $formatter = new \IntlDateFormatter(
                $this->locale,
                \IntlDateFormatter::MEDIUM,
                $withTime ? \IntlDateFormatter::SHORT : \IntlDateFormatter::NONE,
                $this->timezone
            );
            $result = $formatter->format($local);
            if ($result !== false) {
                return $result;
            }
        }
        [$datePattern, $dateTimePattern] = self::FALLBACK[$this->language] ?? self::FALLBACK['en'];

        return $local->format($withTime ? $dateTimePattern : $datePattern);
    }

    private function intlAvailable(): bool
    {
        return $this->useIntl && extension_loaded('intl');
    }
}

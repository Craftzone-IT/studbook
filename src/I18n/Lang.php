<?php

declare(strict_types=1);

namespace Studbook\I18n;

/** Holds the request's translator and formatter for the global helpers in `functions.php`. */
final class Lang
{
    private static ?Translator $translator = null;
    private static ?Formatter $formatter = null;

    public static function set(Translator $translator, Formatter $formatter): void
    {
        self::$translator = $translator;
        self::$formatter = $formatter;
    }

    public static function translator(): Translator
    {
        return self::$translator ??= new Translator(dirname(__DIR__, 2) . '/lang');
    }

    public static function formatter(): Formatter
    {
        $translator = self::translator();

        return self::$formatter ??= new Formatter(
            $translator->language(),
            $translator->locale(),
            new \DateTimeZone('UTC')
        );
    }
}

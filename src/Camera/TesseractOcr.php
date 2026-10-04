<?php

declare(strict_types=1);

namespace Studbook\Camera;

/**
 * Server-side OCR with the Tesseract command-line tool, run through exec().
 * Many hosts disable exec() or do not have Tesseract installed; status()
 * tells which, and the camera pages hide the feature instead of failing.
 */
final class TesseractOcr
{
    public const TIMEOUT_SECONDS = 30;

    public function __construct(private readonly string $binary, private readonly string $language = 'eng')
    {
    }

    /** @return array{available: bool, reason: string, version: string} reason: '' or a `camera.ocr_status.*` key suffix */
    public function status(): array
    {
        if (!self::execAllowed()) {
            return ['available' => false, 'reason' => 'exec_disabled', 'version' => ''];
        }
        if ($this->binary === '' || (str_contains($this->binary, '/') && !is_executable($this->binary))) {
            return ['available' => false, 'reason' => 'not_installed', 'version' => ''];
        }
        $output = [];
        $code = 1;
        @exec(escapeshellarg($this->binary) . ' --version 2>&1', $output, $code);
        if ($code !== 0 || $output === [] || !preg_match('/tesseract\s+v?([\d.]+)/i', implode("\n", $output), $m)) {
            return ['available' => false, 'reason' => 'not_installed', 'version' => ''];
        }

        return ['available' => true, 'reason' => '', 'version' => $m[1]];
    }

    /** Text found in the image, one line per line of text. */
    public function read(string $image): string
    {
        if (!self::execAllowed()) {
            throw new PhotoException('ocr_unavailable');
        }
        // --psm 11: sparse text, finds numbers scattered over a lid or a sheet.
        $command = escapeshellarg($this->binary) . ' ' . escapeshellarg($image) . ' stdout'
            . ' -l ' . escapeshellarg($this->language) . ' --psm 11 2>/dev/null';
        if (is_executable('/usr/bin/timeout')) {
            $command = '/usr/bin/timeout ' . self::TIMEOUT_SECONDS . ' ' . $command;
        }
        $output = [];
        $code = 1;
        @exec($command, $output, $code);
        if ($code !== 0) {
            throw new PhotoException('ocr_failed');
        }

        return implode("\n", $output);
    }

    public static function execAllowed(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }
}

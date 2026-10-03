<?php

declare(strict_types=1);

namespace Studbook;

use Studbook\Http\Response;

/**
 * Renders errors. Stack traces are shown only in development with
 * APP_DEBUG=true; production shows a generic page and logs the details.
 */
final class ErrorPage
{
    public static function fromThrowable(\Throwable $e, bool $debug, ?View $view = null): Response
    {
        error_log(sprintf(
            'Studbook: %s: %s in %s:%d',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        $details = $debug ? $e::class . ': ' . $e->getMessage() . "\n\n" . $e->getTraceAsString() : null;
        // Configuration errors only name missing keys; installers need to see them.
        if ($e instanceof ConfigException) {
            $details = $e->getMessage();
        }

        return self::render(500, $details, $view);
    }

    public static function render(int $status, ?string $details = null, ?View $view = null): Response
    {
        $titleKey = match ($status) {
            400 => 'error.bad_request',
            403 => 'error.forbidden',
            404 => 'error.not_found',
            405 => 'error.method_not_allowed',
            419 => 'error.csrf',
            default => 'error.server',
        };
        $data = ['status' => $status, 'title' => t($titleKey), 'details' => $details];
        if ($view !== null) {
            try {
                return Response::html($view->render('error', $data), $status);
            } catch (\Throwable) {
                // Fall through to the minimal page below.
            }
        }

        $body = '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e($data['title']) . '</title></head><body><h1>' . e($data['title']) . '</h1>'
            . ($details !== null ? '<pre>' . e($details) . '</pre>' : '')
            . '</body></html>';

        return Response::html($body, $status);
    }
}

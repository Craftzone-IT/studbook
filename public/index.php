<?php

declare(strict_types=1);

use Studbook\App;
use Studbook\Config;
use Studbook\ErrorPage;
use Studbook\Http\Request;
use Studbook\Http\Session;

// PHP's built-in development server: let it serve existing static files.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file)) {
        return false;
    }
}

// Find the application folder. Two layouts are supported:
// - standard: this file is in `<app>/public/`, the web root points there;
// - split (e.g. HestiaCP): the app lives in `<domain>/private/`, the contents of
//   `public/` are copied to `<domain>/public_html/`, the default web root.
$root = null;
foreach ([dirname(__DIR__), dirname(__DIR__) . '/private'] as $candidate) {
    if (is_file($candidate . '/composer.json') && is_dir($candidate . '/src')) {
        $root = $candidate;
        break;
    }
}

if ($root === null || !is_file($root . '/vendor/autoload.php')) {
    // Details go to the log only; visitors never see server paths.
    error_log($root === null
        ? 'Studbook: application folder not found next to ' . __DIR__ . ' (expected ../ or ../private/)'
        : 'Studbook: ' . $root . '/vendor/autoload.php is missing; run `composer install --no-dev` there');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo "Studbook is not installed completely. The server's PHP error log has the details.\n";
    exit;
}
require $root . '/vendor/autoload.php';

// Never print errors into pages; they are logged and rendered by ErrorPage.
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    $config = Config::load($root);
} catch (Throwable $e) {
    $response = ErrorPage::fromThrowable($e, false)->withHeader('X-Robots-Tag', 'noindex, nofollow');
    $response->send();
    exit;
}

date_default_timezone_set($config->get('APP_TIMEZONE', 'UTC') ?: 'UTC');
Session::start($config->isHttps(), $config->basePath());

$app = new App($config);
$app->handle(Request::fromGlobals($config->basePath()))->send();

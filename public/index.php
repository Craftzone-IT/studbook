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

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dependencies are missing. Run `composer install --no-dev` in {$root}.\n";
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

<?php

declare(strict_types=1);

namespace Studbook;

use PDO;
use Studbook\Auth\Auth;
use Studbook\Auth\LoginThrottle;
use Studbook\Auth\PdoAttemptStore;
use Studbook\Auth\UserRepository;
use Studbook\Controller\AuthController;
use Studbook\Controller\HomeController;
use Studbook\Controller\SettingsController;
use Studbook\Database\Connection;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Router;
use Studbook\Http\Session;
use Studbook\Http\Url;
use Studbook\I18n\Formatter;
use Studbook\I18n\Lang;
use Studbook\I18n\Translator;

final class App
{
    /** Sent on every response; the whole app is private. */
    public const SECURITY_HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; object-src 'none'; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
    ];

    private ?PDO $pdo = null;
    private ?SettingRepository $settings = null;
    private ?Auth $auth = null;
    private readonly View $view;
    private readonly Router $router;

    public function __construct(private readonly Config $config, ?PDO $pdo = null)
    {
        $this->pdo = $pdo;
        $this->view = new View($config->rootPath() . '/templates');
        $this->router = new Router();
        Url::setBasePath($config->basePath());
        $this->view->share('sourceUrl', $config->get('APP_SOURCE_URL', 'https://github.com/Craftzone-IT/studbook'));
        $this->registerRoutes();
    }

    public static function rootPath(): string
    {
        return dirname(__DIR__);
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $response = ErrorPage::fromThrowable($e, $this->config->isDebug(), $this->view);
        }
        foreach (self::SECURITY_HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if ($response->header('Cache-Control') === null) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $this->initLanguage();

        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        if ($match === false) {
            return ErrorPage::render(405, null, $this->view)->withHeader('Allow', 'GET, POST');
        }
        [$route, $params] = $match;

        if (!$route->public && !$this->auth()->check()) {
            return Response::redirect(url('/login'));
        }
        if ($request->method === 'POST' && !Csrf::isValid($request->input(Csrf::FIELD))) {
            return ErrorPage::render(419, null, $this->view);
        }

        $userId = $this->auth()->userId();
        $this->view->share('currentUser', $userId !== null ? $this->users()->findById($userId) : null);
        $this->view->share('currentPath', $request->path);
        $this->view->share('flashes', Session::takeFlashes());

        return ($route->handler)($request, $params);
    }

    private function initLanguage(): void
    {
        $language = $this->config->get('APP_DEFAULT_LANGUAGE', Translator::FALLBACK);
        try {
            $language = $this->settings()->get(SettingRepository::UI_LANGUAGE) ?? $language;
        } catch (\PDOException $e) {
            // The database may not be migrated yet; the default language is fine.
            error_log('Studbook: cannot read settings: ' . $e->getMessage());
        }
        $translator = new Translator($this->config->rootPath() . '/lang', $language);
        $timezone = new \DateTimeZone($this->config->get('APP_TIMEZONE', 'UTC') ?: 'UTC');
        Lang::set($translator, new Formatter($translator->language(), $translator->locale(), $timezone));
        $this->view->share('lang', $translator->language());
        $this->view->share('fmt', Lang::formatter());
    }

    private function registerRoutes(): void
    {
        $auth = new AuthController(
            $this->view,
            fn (): Auth => $this->auth(),
            fn (): LoginThrottle => $this->throttle(),
            $this->config
        );
        $home = new HomeController($this->view);
        $settings = new SettingsController($this->view, fn (): SettingRepository => $this->settings());

        $this->router->get('/login', $auth->showLogin(...), public: true);
        $this->router->post('/login', $auth->login(...), public: true);
        $this->router->post('/logout', $auth->logout(...));
        $this->router->get('/robots.txt', static fn (): Response => self::robots(), public: true);

        $this->router->get('/', $home->index(...));
        $this->router->get('/settings', $settings->show(...));
        $this->router->post('/settings', $settings->save(...));
    }

    /** Disallow-all robots.txt; also served as a static file from `public/`. */
    public static function robots(): Response
    {
        return Response::text("User-agent: *\nDisallow: /\n")->withHeader('Cache-Control', 'public, max-age=86400');
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connection::fromConfig($this->config);
    }

    private function settings(): SettingRepository
    {
        return $this->settings ??= new SettingRepository($this->pdo());
    }

    private function users(): UserRepository
    {
        return new UserRepository($this->pdo());
    }

    private function auth(): Auth
    {
        return $this->auth ??= new Auth(fn (): UserRepository => $this->users());
    }

    private function throttle(): LoginThrottle
    {
        return new LoginThrottle(new PdoAttemptStore($this->pdo()));
    }
}

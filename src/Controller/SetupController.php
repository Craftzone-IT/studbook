<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Auth\Auth;
use Studbook\Auth\Credentials;
use Studbook\Auth\LoginThrottle;
use Studbook\Auth\PdoAttemptStore;
use Studbook\Auth\UserRepository;
use Studbook\Config;
use Studbook\ErrorPage;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\Setup\SetupService;
use Studbook\View;

/**
 * Browser-based setup: environment checks, migrations and the first user.
 *
 * Available only while setup is needed. Before a user exists, access needs
 * SETUP_TOKEN from `.env`; afterwards (pending migrations on update) a login.
 */
final class SetupController
{
    private const SESSION_KEY = 'setup_token';

    /** @var \Closure(): SetupService */
    private \Closure $setup;
    /** @var \Closure(): Auth */
    private \Closure $auth;

    /**
     * @param callable(): SetupService $setup
     * @param callable(): Auth $auth
     */
    public function __construct(
        private readonly View $view,
        callable $setup,
        callable $auth,
        private readonly Config $config,
    ) {
        $this->setup = \Closure::fromCallable($setup);
        $this->auth = \Closure::fromCallable($auth);
    }

    public function show(Request $request): Response
    {
        $setup = ($this->setup)();
        if (!$setup->isNeeded()) {
            return $this->notFound();
        }
        if ($setup->userExists()) {
            if (!($this->auth)()->check()) {
                return Response::redirect(url('/login'));
            }
        } elseif ($setup->tokenStatus() !== 'ok') {
            return $this->page(['stage' => 'token_missing', 'tokenStatus' => $setup->tokenStatus()]);
        } elseif (!$this->tokenAuthorized($setup)) {
            return $this->page(['stage' => 'token']);
        }

        $checks = $setup->checks();
        $pending = $setup->pendingMigrations();

        return $this->page([
            'stage' => 'wizard',
            'checks' => $checks,
            'checksFailed' => SetupService::hasErrors($checks),
            'pending' => $pending,
            'databaseOk' => $setup->databaseError() === null,
            'userExists' => $setup->userExists(),
            'minPasswordLength' => Credentials::MIN_PASSWORD_LENGTH,
        ]);
    }

    public function token(Request $request): Response
    {
        $setup = ($this->setup)();
        if (!$setup->isNeeded() || $setup->userExists() || $setup->tokenStatus() !== 'ok') {
            return $this->notFound();
        }
        $ip = $request->clientIp($this->config->trustedProxies());
        $throttle = $this->throttle($setup);
        if ($throttle !== null && $this->safely(fn (): bool => $throttle->isBlocked($ip))) {
            return $this->page(['stage' => 'token', 'error' => t('login.throttled')], 429);
        }
        if (!$setup->tokenMatches($request->input('token'))) {
            if ($throttle !== null) {
                $this->safely(static function () use ($throttle, $ip): bool {
                    $throttle->recordFailure($ip);

                    return true;
                });
            }

            return $this->page(['stage' => 'token', 'error' => t('setup.token_wrong')], 403);
        }

        Session::regenerate();
        Session::set(self::SESSION_KEY, $setup->tokenFingerprint());

        return Response::redirect(url('/setup'));
    }

    public function migrate(Request $request): Response
    {
        $setup = ($this->setup)();
        $denied = $this->deny($setup);
        if ($denied !== null) {
            return $denied;
        }
        $migrator = $setup->migrator();
        if ($migrator === null || SetupService::hasErrors($setup->checks())) {
            Session::flash('error', t('setup.fix_checks_first'));

            return Response::redirect(url('/setup'));
        }

        try {
            $applied = $migrator->migrate();
            Session::flash('success', t('setup.migrations_applied', ['count' => count($applied)]));
        } catch (\Throwable $e) {
            error_log('Studbook: migration failed: ' . $e->getMessage());
            Session::flash('error', t('setup.migrations_failed', ['message' => $e->getMessage()]));
        }

        return Response::redirect(url('/setup'));
    }

    public function createUser(Request $request): Response
    {
        $setup = ($this->setup)();
        $denied = $this->deny($setup);
        if ($denied !== null) {
            return $denied;
        }
        $pdo = $setup->pdo();
        if ($pdo === null || $setup->pendingMigrations() !== [] || $setup->userExists()) {
            return Response::redirect(url('/setup'));
        }

        $username = trim($request->input('username'));
        $password = $request->input('password');
        $error = Credentials::usernameError($username)
            ?? Credentials::passwordError($password, $request->input('password_repeat'));
        if ($error !== null) {
            Session::flash('error', t($error, ['min' => Credentials::MIN_PASSWORD_LENGTH]));
            Session::set('setup_username', $username);

            return Response::redirect(url('/setup'));
        }

        $userId = (new UserRepository($pdo))->create($username, $password);
        Session::remove(self::SESSION_KEY);
        Session::remove('setup_username');
        ($this->auth)()->login($userId);
        Session::flash('success', t('setup.done'));

        return Response::redirect(url('/'));
    }

    /** Access rules shared by the POST actions; null when the request may proceed. */
    private function deny(SetupService $setup): ?Response
    {
        if (!$setup->isNeeded()) {
            return $this->notFound();
        }
        if ($setup->userExists()) {
            return ($this->auth)()->check() ? null : Response::redirect(url('/login'));
        }
        if ($setup->tokenStatus() !== 'ok' || !$this->tokenAuthorized($setup)) {
            return ErrorPage::render(403, null, $this->view);
        }

        return null;
    }

    private function tokenAuthorized(SetupService $setup): bool
    {
        $stored = Session::get(self::SESSION_KEY);

        return is_string($stored) && hash_equals($setup->tokenFingerprint(), $stored);
    }

    /** Login throttling also limits token guesses, when its table exists yet. */
    private function throttle(SetupService $setup): ?LoginThrottle
    {
        $pdo = $setup->pdo();

        return $pdo === null ? null : new LoginThrottle(new PdoAttemptStore($pdo));
    }

    /** Runs a throttle call; before the first migration the table does not exist. */
    private function safely(callable $call): bool
    {
        try {
            return (bool) $call();
        } catch (\PDOException) {
            return false;
        }
    }

    /** @param array<string, mixed> $data */
    private function page(array $data, int $status = 200): Response
    {
        $data += [
            'title' => t('setup.title'),
            'error' => null,
            'username' => (string) Session::get('setup_username', ''),
        ];

        return Response::html($this->view->render('setup', $data), $status);
    }

    private function notFound(): Response
    {
        return ErrorPage::render(404, null, $this->view);
    }
}

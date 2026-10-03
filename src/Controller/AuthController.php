<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Auth\Auth;
use Studbook\Auth\LoginThrottle;
use Studbook\Config;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\View;

final class AuthController
{
    /** @var \Closure(): Auth */
    private \Closure $auth;
    /** @var \Closure(): LoginThrottle */
    private \Closure $throttle;
    /** @var \Closure(): bool */
    private \Closure $needsFirstUser;

    /**
     * @param callable(): Auth $auth
     * @param callable(): LoginThrottle $throttle
     * @param callable(): bool $needsFirstUser true while no user exists (the login page then points to /setup)
     */
    public function __construct(
        private readonly View $view,
        callable $auth,
        callable $throttle,
        private readonly Config $config,
        callable $needsFirstUser,
    ) {
        $this->auth = \Closure::fromCallable($auth);
        $this->throttle = \Closure::fromCallable($throttle);
        $this->needsFirstUser = \Closure::fromCallable($needsFirstUser);
    }

    public function showLogin(Request $request): Response
    {
        if (($this->auth)()->check()) {
            return Response::redirect(url('/'));
        }

        return $this->form();
    }

    public function login(Request $request): Response
    {
        $ip = $request->clientIp($this->config->trustedProxies());
        $throttle = ($this->throttle)();
        $username = trim($request->input('username'));

        if ($throttle->isBlocked($ip)) {
            return $this->form($username, t('login.throttled'), 429);
        }

        $user = ($this->auth)()->attempt($username, $request->input('password'));
        if ($user === null) {
            $throttle->recordFailure($ip);

            return $this->form($username, t('login.failed'), 401);
        }

        $throttle->recordSuccess($ip);
        ($this->auth)()->login($user['id']);

        return Response::redirect(url('/'));
    }

    public function logout(Request $request): Response
    {
        ($this->auth)()->logout();

        return Response::redirect(url('/login'));
    }

    private function form(string $username = '', ?string $error = null, int $status = 200): Response
    {
        return Response::html(
            $this->view->render('login', [
                'username' => $username,
                'error' => $error,
                'setupHint' => ($this->needsFirstUser)(),
            ]),
            $status
        );
    }
}

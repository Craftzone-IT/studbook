<?php

declare(strict_types=1);

use Studbook\Http\Csrf;
use Studbook\Setup\SetupService;

/**
 * @var string $title
 * @var string $stage token_missing | token | wizard
 * @var string|null $error
 * @var string $username
 * @var string|null $tokenStatus
 * @var list<array{label: string, params: array<string, string>, status: string, detail: ?string}> $checks
 * @var bool $checksFailed
 * @var list<string> $pending
 * @var bool $databaseOk
 * @var bool $userExists
 * @var int $minPasswordLength
 */
$csrf = '<input type="hidden" name="' . e(Csrf::FIELD) . '" value="' . e(Csrf::token()) . '">';
?>
<h1><?= e($title) ?></h1>
<?php if ($error !== null) : ?>
    <p class="flash flash-error" role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($stage === 'token_missing') : ?>
    <section class="card">
        <p><?= e(t('setup.token_missing_intro')) ?></p>
        <?php if (($tokenStatus ?? '') === 'too_short') : ?>
            <p class="flash flash-error"><?= e(t('setup.token_too_short', ['min' => SetupService::MIN_TOKEN_LENGTH])) ?></p>
        <?php endif; ?>
        <p><?= e(t('setup.token_missing_howto')) ?></p>
        <pre class="code">SETUP_TOKEN=<?= e(bin2hex(random_bytes(16))) ?></pre>
        <p class="hint"><?= e(t('setup.token_missing_reload')) ?></p>
    </section>

<?php elseif ($stage === 'token') : ?>
    <section class="card">
        <p><?= e(t('setup.token_intro')) ?></p>
        <form method="post" action="<?= e(url('/setup/token')) ?>" class="form">
            <?= $csrf ?>
            <label for="token"><?= e(t('setup.token_label')) ?></label>
            <input id="token" name="token" type="password" autocomplete="off" required autofocus>
            <button type="submit" class="button button-primary"><?= e(t('setup.token_submit')) ?></button>
        </form>
    </section>

<?php else : ?>
    <section class="card setup-step">
        <h2><?= e(t('setup.step_checks')) ?></h2>
        <ul class="checklist">
            <?php foreach ($checks as $check) : ?>
                <li class="check check-<?= e($check['status']) ?>">
                    <span class="check-status"><?= e(t('setup.status.' . $check['status'])) ?></span>
                    <span class="check-label"><?= e(t($check['label'], $check['params'])) ?></span>
                    <?php if ($check['detail'] !== null) : ?>
                        <span class="check-detail"><?= e($check['detail']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($checksFailed) : ?>
            <p class="flash flash-error"><?= e(t('setup.checks_failed')) ?></p>
            <p><a class="button" href="<?= e(url('/setup')) ?>"><?= e(t('setup.recheck')) ?></a></p>
        <?php endif; ?>
    </section>

    <section class="card setup-step">
        <h2><?= e(t('setup.step_migrations')) ?></h2>
        <?php if (!$databaseOk) : ?>
            <p class="hint"><?= e(t('setup.migrations_need_database')) ?></p>
        <?php elseif ($pending === []) : ?>
            <p><?= e(t('setup.migrations_none')) ?></p>
        <?php else : ?>
            <p><?= e(t('setup.migrations_pending', ['count' => count($pending)])) ?></p>
            <ul class="file-list">
                <?php foreach ($pending as $name) : ?>
                    <li><code><?= e($name) ?></code></li>
                <?php endforeach; ?>
            </ul>
            <form method="post" action="<?= e(url('/setup/migrate')) ?>">
                <?= $csrf ?>
                <button type="submit" class="button button-primary"<?= $checksFailed ? ' disabled' : '' ?>>
                    <?= e(t('setup.migrations_run')) ?>
                </button>
            </form>
        <?php endif; ?>
    </section>

    <?php if (!$userExists) : ?>
        <section class="card setup-step">
            <h2><?= e(t('setup.step_user')) ?></h2>
            <?php if (!$databaseOk || $pending !== []) : ?>
                <p class="hint"><?= e(t('setup.user_after_migrations')) ?></p>
            <?php else : ?>
                <p><?= e(t('setup.user_intro')) ?></p>
                <form method="post" action="<?= e(url('/setup/user')) ?>" class="form">
                    <?= $csrf ?>
                    <label for="username"><?= e(t('login.username')) ?></label>
                    <input id="username" name="username" type="text" value="<?= e($username) ?>"
                           autocomplete="username" autocapitalize="none" spellcheck="false" required maxlength="64">
                    <label for="password"><?= e(t('login.password')) ?></label>
                    <input id="password" name="password" type="password" autocomplete="new-password"
                           minlength="<?= e($minPasswordLength) ?>" required>
                    <p class="hint"><?= e(t('setup.password_hint', ['min' => $minPasswordLength])) ?></p>
                    <label for="password_repeat"><?= e(t('setup.password_repeat')) ?></label>
                    <input id="password_repeat" name="password_repeat" type="password" autocomplete="new-password"
                           minlength="<?= e($minPasswordLength) ?>" required>
                    <button type="submit" class="button button-primary"><?= e(t('setup.user_submit')) ?></button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

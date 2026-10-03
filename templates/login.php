<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var string $username
 * @var string|null $error
 */
?>
<section class="card login-card">
    <h1><?= e(t('login.title')) ?></h1>
    <?php if ($error !== null) : ?>
        <p class="flash flash-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/login')) ?>" class="form">
        <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
        <label for="username"><?= e(t('login.username')) ?></label>
        <input id="username" name="username" type="text" value="<?= e($username) ?>"
               autocomplete="username" autocapitalize="none" spellcheck="false" required
               <?= $username === '' ? 'autofocus' : '' ?>>
        <label for="password"><?= e(t('login.password')) ?></label>
        <input id="password" name="password" type="password" autocomplete="current-password" required
               <?= $username !== '' ? 'autofocus' : '' ?>>
        <button type="submit" class="button button-primary"><?= e(t('login.submit')) ?></button>
    </form>
</section>

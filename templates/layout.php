<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var string $content
 * @var string $lang
 * @var string|null $title
 * @var array{id: int, username: string}|null $currentUser
 * @var string $currentPath
 * @var list<array{type: string, message: string}> $flashes
 * @var string $sourceUrl
 */
$title ??= null;
$currentUser ??= null;
$currentPath ??= '/';
$flashes ??= [];
$nav = [
    '/' => 'nav.home',
    '/search' => 'nav.search',
    '/history' => 'nav.history',
    '/admin/import' => 'nav.import',
    '/settings' => 'nav.settings',
];
?>
<!doctype html>
<html lang="<?= e($lang ?? 'en') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title !== null ? $title . ' · ' . t('app.name') : t('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
    <link rel="icon" href="<?= e(url('/assets/favicon.svg')) ?>" type="image/svg+xml">
    <script src="<?= e(url('/assets/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main"><?= e(t('nav.skip_to_content')) ?></a>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>">
            <img class="brand-mark" src="<?= e(url('/assets/favicon.svg')) ?>" alt="" width="28" height="28">
            <?= e(t('app.name')) ?>
        </a>
        <?php if ($currentUser !== null) : ?>
            <nav class="main-nav" aria-label="<?= e(t('nav.label')) ?>">
                <ul>
                    <?php foreach ($nav as $path => $key) : ?>
                        <li>
                            <a href="<?= e(url($path)) ?>"<?= $currentPath === $path ? ' aria-current="page"' : '' ?>>
                                <?= e(t($key)) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li>
                        <form method="post" action="<?= e(url('/logout')) ?>" class="inline-form">
                            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
                            <button type="submit" class="link-button"><?= e(t('nav.logout')) ?></button>
                        </form>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</header>
<main id="main" class="container site-main">
    <?php foreach ($flashes as $flash) : ?>
        <div class="flash flash-<?= e($flash['type']) ?>" role="status">
            <span><?= e($flash['message']) ?></span>
            <?php if (($flash['undo'] ?? null) !== null) : ?>
                <form method="post" action="<?= e(url('/batches/' . $flash['undo'] . '/undo')) ?>" class="inline-form">
                    <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
                    <input type="hidden" name="return" value="<?= e($currentPath) ?>">
                    <button type="submit" class="link-button undo-button"><?= e(t('batch.undo')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
<footer class="site-footer">
    <div class="container">
        <p>
            <?= e(t('footer.rebrickable_prefix')) ?>
            <a href="https://rebrickable.com" rel="noopener noreferrer">Rebrickable</a><?= e(t('footer.rebrickable_suffix')) ?>
        </p>
        <p>
            <?= e(t('footer.licence')) ?>
            <a href="<?= e($sourceUrl ?? '') ?>" rel="noopener noreferrer"><?= e(t('footer.source')) ?></a>
        </p>
        <p class="footer-trademark"><?= e(t('footer.trademark')) ?></p>
    </div>
</footer>
</body>
</html>

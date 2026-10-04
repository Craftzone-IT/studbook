<?php

declare(strict_types=1);

use Studbook\Http\Csrf;
use Studbook\Owned\SetService;

/**
 * @var array<string, mixed> $collection
 * @var string $q
 * @var array{set_num: string, name: string, year: ?int, num_parts: int, theme: ?string}|null $set
 * @var list<array{set_num: string, name: string, year: ?int, num_parts: int, theme: ?string}> $matches
 * @var list<array<string, mixed>> $boxes
 * @var list<string> $states
 * @var list<string> $lockModes
 * @var \Studbook\I18n\Formatter $fmt
 */
$id = (int) $collection['id'];
$base = url('/c/' . $id . '/sets/new');
$setImg = static fn (string $num): string => url('/img') . '?set=' . rawurlencode($num);
?>
<p class="breadcrumb">
    <a href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a> ›
    <a href="<?= e(url('/c/' . $id)) ?>"><?= e($collection['name']) ?></a>
</p>
<h1><?= e(t('set.add_title')) ?></h1>

<form method="get" action="<?= e($base) ?>" class="form-inline" role="search">
    <label for="set-q" class="visually-hidden"><?= e(t('set.number')) ?></label>
    <input id="set-q" name="q" type="search" value="<?= e($q !== '' ? $q : ($set['set_num'] ?? '')) ?>" required
           autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="<?= e(t('set.number_placeholder')) ?>"<?= $set === null ? ' autofocus' : '' ?>>
    <button type="submit" class="button"><?= e(t('search.submit')) ?></button>
</form>

<?php if ($set === null && $q !== '') : ?>
    <?php if ($matches === []) : ?>
        <p class="flash flash-error"><?= e(t('set.not_found', ['q' => $q])) ?></p>
    <?php else : ?>
        <ul class="lot-list">
            <?php foreach ($matches as $match) : ?>
                <li class="lot">
                    <img class="set-img" src="<?= e($setImg($match['set_num'])) ?>" alt="" width="64" height="48" loading="lazy">
                    <div class="lot-main">
                        <a href="<?= e($base . '?set=' . rawurlencode($match['set_num'])) ?>"><strong><?= e($match['set_num']) ?></strong></a>
                        <?= e($match['name']) ?>
                        <span class="lot-name"><?= e(implode(' · ', array_filter([
                            $match['theme'],
                            $match['year'] !== null ? (string) $match['year'] : null,
                            t('set.parts_count', ['count' => $fmt->number($match['num_parts'])]),
                        ]))) ?></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

<?php if ($set !== null) : ?>
    <section class="card set-preview">
        <img class="set-img-large" src="<?= e($setImg($set['set_num'])) ?>" alt="" width="240" height="180">
        <div>
            <h2><?= e($set['set_num']) ?> <span class="subtitle"><?= e($set['name']) ?></span></h2>
            <p class="hint"><?= e(implode(' · ', array_filter([
                $set['theme'],
                $set['year'] !== null ? (string) $set['year'] : null,
                t('set.parts_count', ['count' => $fmt->number($set['num_parts'])]),
            ]))) ?></p>
        </div>
    </section>

    <section class="card">
        <form method="post" action="<?= e(url('/c/' . $id . '/sets')) ?>" class="form form-wide">
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="set" value="<?= e($set['set_num']) ?>">
            <?php require __DIR__ . '/_set-options.php'; ?>
            <label for="copies"><?= e(t('set.copies')) ?></label>
            <input id="copies" name="copies" type="number" min="1" max="<?= e(SetService::MAX_COPIES) ?>" value="1" inputmode="numeric">
            <button type="submit" class="button button-primary"><?= e(t('set.add_submit', ['collection' => $collection['name']])) ?></button>
        </form>
    </section>
<?php endif; ?>

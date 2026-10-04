<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array{set_num: string, name: string, year: ?int, num_parts: int, theme: ?string} $set
 * @var \Studbook\Build\BuildOptions $options
 * @var list<array{id: int, name: string, can_lend: int}> $collections
 * @var list<array<string, mixed>> $rows
 * @var \Studbook\I18n\Formatter $fmt
 */
$need = array_sum(array_column($rows, 'need'));
$loose = array_sum(array_column($rows, 'loose'));
$sets = array_sum(array_column($rows, 'sets'));
$missing = array_sum(array_column($rows, 'missing'));
$base = url('/build/set/' . rawurlencode($set['set_num']));
$columns = [
    ['key' => 'need', 'label' => t('build.col_need')],
    ['key' => 'loose', 'label' => t('build.col_loose')],
    ['key' => 'sets', 'label' => t('build.col_sets')],
    ['key' => 'missing', 'label' => t('build.col_missing')],
];
?>
<p class="breadcrumb"><a href="<?= e(url('/build') . '?' . $options->query()) ?>"><?= e(t('build.title')) ?></a></p>
<section class="set-preview">
    <img class="set-img-large" src="<?= e(url('/img') . '?set=' . rawurlencode($set['set_num'])) ?>" alt="" width="240" height="180">
    <div>
        <h1><?= e($set['set_num']) ?> <span class="subtitle"><?= e($set['name']) ?></span></h1>
        <p class="hint"><?= e(implode(' · ', array_filter([
            $set['theme'],
            $set['year'] !== null ? (string) $set['year'] : null,
            t('set.parts_count', ['count' => $fmt->number($set['num_parts'])]),
        ]))) ?></p>
        <p class="coverage-summary">
            <strong><?= e($need > 0 ? (int) floor(100 * ($loose + $sets) / $need) : 0) ?>%</strong>
            <?= e(t('build.summary', [
                'loose' => $fmt->number($loose),
                'sets' => $fmt->number($sets),
                'missing' => $fmt->number($missing),
                'need' => $fmt->number($need),
            ])) ?>
        </p>
        <?= coverage_bar($need, $loose, $sets, t('build.bar_label', [
            'loose' => $fmt->number($loose),
            'sets' => $fmt->number($sets),
            'need' => $fmt->number($need),
        ])) ?>
    </div>
</section>

<section class="card setup-step">
    <div class="build-actions">
        <form method="post" action="<?= e(url('/builds')) ?>">
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="set" value="<?= e($set['set_num']) ?>">
            <?php require __DIR__ . '/_build-options-hidden.php'; ?>
            <button type="submit" class="button button-primary"><?= e(t('build.start')) ?></button>
        </form>
        <?php if ($missing > 0) : ?>
            <a class="button" href="<?= e($base . '/wanted.xml?' . $options->query()) ?>"><?= e(t('build.wanted')) ?></a>
        <?php endif; ?>
    </div>
    <p class="hint"><?= e(t('build.start_hint')) ?></p>
</section>

<form method="get" action="<?= e($base) ?>" class="card form build-form">
    <?php require __DIR__ . '/_build-options.php'; ?>
    <button type="submit" class="button"><?= e(t('build.recalculate')) ?></button>
</form>

<section class="card setup-step">
    <h2><?= e(t('build.parts_heading')) ?></h2>
    <?php if ($rows === []) : ?>
        <p><?= e(t('set.no_inventory')) ?></p>
    <?php else : ?>
        <?php require __DIR__ . '/_coverage-rows.php'; ?>
    <?php endif; ?>
</section>

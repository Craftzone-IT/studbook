<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array<string, mixed> $build
 * @var \Studbook\Build\BuildOptions $options
 * @var list<array<string, mixed>> $rows needed, reserved and missing per part and colour
 * @var list<array<string, mixed>> $pickList reserved parts grouped by box or set
 * @var \Studbook\I18n\Formatter $fmt
 */
$csrf = '<input type="hidden" name="' . e(Csrf::FIELD) . '" value="' . e(Csrf::token()) . '">';
$id = (int) $build['id'];
$need = array_sum(array_column($rows, 'need'));
$reserved = array_sum(array_column($rows, 'reserved'));
$missing = array_sum(array_column($rows, 'missing'));
$active = $build['state'] === 'active';
$img = static fn (string $part, int $color): string => url('/img') . '?part=' . rawurlencode($part) . '&color=' . $color;
$columns = [
    ['key' => 'need', 'label' => t('build.col_need')],
    ['key' => 'reserved', 'label' => t('build.col_reserved')],
    ['key' => 'missing', 'label' => t('build.col_missing')],
];
?>
<p class="breadcrumb"><a href="<?= e(url('/build')) ?>"><?= e(t('build.title')) ?></a></p>
<section class="set-preview">
    <img class="set-img-large" src="<?= e(url('/img') . '?set=' . rawurlencode((string) $build['set_num'])) ?>" alt="" width="240" height="180">
    <div>
        <h1><?= e($build['set_num']) ?> <span class="subtitle"><?= e($build['name'] ?? '') ?></span></h1>
        <p class="hint"><?= e(t('build.for_collection', ['collection' => $build['collection_name']])) ?>
            · <span class="badge"><?= e(t('build_state.' . $build['state'])) ?></span></p>
        <?php if ($active) : ?>
            <p class="coverage-summary"><?= e(t('build.progress', [
                'reserved' => $fmt->number($reserved),
                'need' => $fmt->number($need),
                'missing' => $fmt->number($missing),
            ])) ?></p>
            <?= coverage_bar($need, $reserved, 0, t('build.progress', [
                'reserved' => $fmt->number($reserved),
                'need' => $fmt->number($need),
                'missing' => $fmt->number($missing),
            ])) ?>
        <?php endif; ?>
    </div>
</section>

<?php if ($active) : ?>
    <section class="card setup-step">
        <h2><?= e(t('build.pick_list')) ?></h2>
        <p class="hint"><?= e(t('build.pick_hint')) ?></p>
        <?php if ($pickList === []) : ?>
            <p><?= e(t('build.nothing_reserved')) ?></p>
        <?php endif; ?>
        <?php foreach ($pickList as $group) : ?>
            <h3>
                <?php if ($group['id'] === null) : ?>
                    <?= e(t('build.source_gone')) ?>
                <?php elseif ($group['kind'] === 'box') : ?>
                    <a href="<?= e(url('/b/' . $group['id'])) ?>"><?= e($group['name']) ?></a>
                <?php else : ?>
                    <?= e(t('build.from_set')) ?> <a href="<?= e(url('/s/' . $group['id'])) ?>"><?= e($group['name']) ?></a>
                <?php endif; ?>
                <span class="hint"><?= e($group['collection']) ?></span>
            </h3>
            <ul class="lot-list lot-list-compact">
                <?php foreach ($group['items'] as $item) : ?>
                    <li class="lot">
                        <img class="part-img" src="<?= e($img((string) $item['part'], (int) $item['color_id'])) ?>" alt="" width="48" height="48" loading="lazy">
                        <div class="lot-main">
                            <strong><?= e($item['bl_num'] ?? $item['part']) ?></strong>
                            <?= swatch((string) ($item['rgb'] ?? '')) ?>
                            <?= e($item['color_name'] ?? ('#' . $item['color_id'])) ?>
                            <span class="lot-name"><?= e($item['part_name'] ?? '') ?></span>
                            <?php if ($item['loose_lot_id'] !== null && (int) ($item['lot_qty'] ?? 0) < (int) $item['qty']) : ?>
                                <span class="lot-name qty-missing"><?= e(t('build.lot_short', ['qty' => $fmt->number((int) ($item['lot_qty'] ?? 0))])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="lot-qty"><?= e($fmt->number((int) $item['qty'])) ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </section>

    <section class="card setup-step">
        <h2><?= e(t('build.actions')) ?></h2>
        <div class="build-actions">
            <form method="post" action="<?= e(url('/builds/' . $id . '/reserve')) ?>">
                <?= $csrf ?>
                <button type="submit" class="button"><?= e(t('build.reserve_again')) ?></button>
            </form>
            <?php if ($missing > 0) : ?>
                <a class="button" href="<?= e(url('/builds/' . $id . '/wanted.xml')) ?>"><?= e(t('build.wanted')) ?></a>
            <?php endif; ?>
        </div>
        <h3><?= e(t('build.finish_heading')) ?></h3>
        <form method="post" action="<?= e(url('/builds/' . $id . '/finish')) ?>" class="form">
            <?= $csrf ?>
            <p class="hint"><?= e(t('build.finish_hint')) ?></p>
            <label class="checkbox">
                <input type="checkbox" name="add_set" value="1" checked>
                <?= e(t('build.add_as_set', ['collection' => $build['collection_name']])) ?>
            </label>
            <button type="submit" class="button button-primary"><?= e(t('build.finish')) ?></button>
        </form>
        <h3><?= e(t('build.release_heading')) ?></h3>
        <form method="post" action="<?= e(url('/builds/' . $id . '/release')) ?>">
            <?= $csrf ?>
            <p class="hint"><?= e(t('build.release_hint')) ?></p>
            <button type="submit" class="button button-danger"><?= e(t('build.release')) ?></button>
        </form>
    </section>
<?php endif; ?>

<section class="card setup-step">
    <details<?= $active && $missing > 0 ? ' open' : '' ?>>
        <summary><h2 class="summary-heading"><?= e(t('build.parts_heading')) ?></h2></summary>
        <?php require __DIR__ . '/_coverage-rows.php'; ?>
    </details>
</section>

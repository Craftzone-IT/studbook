<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array<string, mixed> $set owned set with catalogue data
 * @var list<array<string, mixed>> $rows contents: official inventory plus deltas
 * @var list<array<string, mixed>> $deltas
 * @var list<array<string, mixed>> $boxes boxes of the set's collection
 * @var list<array{id: int, name: string}> $collections other collections
 * @var list<string> $states
 * @var list<string> $lockModes
 * @var \Studbook\I18n\Formatter $fmt
 */
$csrf = '<input type="hidden" name="' . e(Csrf::FIELD) . '" value="' . e(Csrf::token()) . '">';
$id = (int) $set['id'];
$img = static fn (string $part, int $color): string => url('/img') . '?part=' . rawurlencode($part) . '&color=' . $color;
$total = array_sum(array_column($rows, 'qty'));
$current = $set;
?>
<p class="breadcrumb">
    <a href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a> ›
    <a href="<?= e(url('/c/' . $set['collection_id'])) ?>"><?= e($set['collection_name']) ?></a>
    <?php if ($set['storage_id'] !== null) : ?>
        › <a href="<?= e(url('/b/' . $set['storage_id'])) ?>"><?= e($set['box_name']) ?></a>
    <?php endif; ?>
</p>

<section class="set-preview">
    <img class="set-img-large" src="<?= e(url('/img') . '?set=' . rawurlencode((string) $set['set_num'])) ?>" alt="" width="240" height="180">
    <div>
        <h1><?= e($set['set_num']) ?> <span class="subtitle"><?= e($set['name'] ?? t('set.unknown')) ?></span></h1>
        <p class="hint"><?= e(implode(' · ', array_filter([
            $set['theme'] ?? null,
            $set['year'] !== null ? (string) $set['year'] : null,
            t('set.parts_count', ['count' => $fmt->number($total)]),
        ]))) ?></p>
        <p>
            <span class="badge"><?= e(t('set_state.' . $set['state'])) ?></span>
            <span class="badge"><?= e(t('set_lock.' . $set['lock_mode'])) ?></span>
        </p>
    </div>
</section>

<section class="card setup-step">
    <h2><?= e(t('set.deltas')) ?></h2>
    <p class="hint"><?= e(t('set.deltas_hint')) ?></p>
    <?php if ($deltas !== []) : ?>
        <ul class="lot-list">
            <?php foreach ($deltas as $delta) : ?>
                <li class="lot">
                    <img class="part-img" src="<?= e($img((string) $delta['part'], (int) $delta['color_id'])) ?>" alt="" width="48" height="48" loading="lazy">
                    <div class="lot-main">
                        <strong><?= e($delta['bl_num'] ?? $delta['part']) ?></strong>
                        <?= swatch((string) ($delta['rgb'] ?? '')) ?>
                        <?= e($delta['color_name'] ?? ('#' . $delta['color_id'])) ?>
                        <span class="lot-name"><?= e($delta['part_name'] ?? '') ?></span>
                    </div>
                    <div class="lot-qty <?= (int) $delta['qty'] < 0 ? 'qty-missing' : 'qty-extra' ?>">
                        <?= e(t((int) $delta['qty'] < 0 ? 'set.missing_n' : 'set.extra_n', ['qty' => $fmt->number(abs((int) $delta['qty']))])) ?>
                    </div>
                    <form method="post" action="<?= e(url('/deltas/' . $delta['id'] . '/delete')) ?>" class="lot-actions">
                        <?= $csrf ?>
                        <button type="submit" class="button"><?= e(t('set.delta_remove')) ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <div class="delta-forms">
        <?php foreach (['missing', 'extra'] as $kind) : ?>
            <form method="get" action="<?= e(url('/s/' . $id . '/delta')) ?>" class="form form-inline">
                <input type="hidden" name="kind" value="<?= e($kind) ?>">
                <label for="delta-<?= e($kind) ?>"><?= e(t('set.record_' . $kind)) ?></label>
                <input id="delta-<?= e($kind) ?>" name="part" type="text" required autocapitalize="none" spellcheck="false"
                       placeholder="<?= e(t('box.part_placeholder')) ?>">
                <button type="submit" class="button"><?= e(t('box.next')) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
</section>

<section class="card setup-step">
    <details>
        <summary><h2 class="summary-heading"><?= e(t('set.contents', ['count' => $fmt->number(count($rows))])) ?></h2></summary>
        <?php if ($rows === []) : ?>
            <p><?= e(t('set.no_inventory')) ?></p>
        <?php else : ?>
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th scope="col"><?= e(t('set.part')) ?></th>
                        <th scope="col"><?= e(t('box.color')) ?></th>
                        <th scope="col" class="num"><?= e(t('set.official')) ?></th>
                        <th scope="col" class="num"><?= e(t('set.difference')) ?></th>
                        <th scope="col" class="num"><?= e(t('set.has')) ?></th>
                        <th scope="col"><span class="visually-hidden"><?= e(t('box.actions')) ?></span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td>
                                <span class="part-cell">
                                    <img class="part-img" src="<?= e($img($row['part'], $row['color_id'])) ?>" alt="" width="40" height="40" loading="lazy">
                                    <span><strong><?= e($row['display']) ?></strong> <span class="lot-name"><?= e($row['part_name']) ?></span></span>
                                </span>
                            </td>
                            <td><?= swatch($row['rgb']) ?> <?= e($row['color_name']) ?></td>
                            <td class="num"><?= e($fmt->number($row['official'])) ?><?= $row['spare'] > 0 ? ' <span class="hint">+' . e($fmt->number($row['spare'])) . '</span>' : '' ?></td>
                            <td class="num"><?= $row['delta'] !== 0 ? e(($row['delta'] > 0 ? '+' : '−') . $fmt->number(abs($row['delta']))) : '' ?></td>
                            <td class="num"><strong><?= e($fmt->number($row['qty'])) ?></strong></td>
                            <td>
                                <?php if ($row['qty'] > 0) : ?>
                                    <a href="<?= e(url('/s/' . $id . '/delta') . '?kind=missing&part=' . rawurlencode($row['part']) . '&color=' . $row['color_id']) ?>"><?= e(t('set.mark_missing')) ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="hint"><?= e(t('set.spare_hint')) ?></p>
        <?php endif; ?>
    </details>
</section>

<section class="card setup-step">
    <h2><?= e(t('set.settings')) ?></h2>
    <form method="post" action="<?= e(url('/s/' . $id . '/update')) ?>" class="form form-wide">
        <?= $csrf ?>
        <?php require __DIR__ . '/_set-options.php'; ?>
        <button type="submit" class="button button-primary"><?= e(t('settings.save')) ?></button>
    </form>

    <?php if ($collections !== []) : ?>
        <h3><?= e(t('set.move_heading')) ?></h3>
        <form method="post" action="<?= e(url('/s/' . $id . '/move')) ?>" class="form form-inline">
            <?= $csrf ?>
            <label for="set-move" class="visually-hidden"><?= e(t('set.move_heading')) ?></label>
            <select id="set-move" name="collection">
                <?php foreach ($collections as $other) : ?>
                    <option value="<?= e($other['id']) ?>"><?= e($other['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?= e(t('set.move_submit')) ?></button>
        </form>
    <?php endif; ?>

    <h3><?= e(t('set.break_up_heading')) ?></h3>
    <p class="hint"><?= e(t('set.break_up_hint')) ?></p>
    <p><a class="button" href="<?= e(url('/s/' . $id . '/break-up')) ?>"><?= e(t('set.break_up_open')) ?></a></p>

    <h3><?= e(t('set.remove_heading')) ?></h3>
    <form method="post" action="<?= e(url('/s/' . $id . '/delete')) ?>">
        <?= $csrf ?>
        <p class="hint"><?= e(t('set.remove_hint')) ?></p>
        <button type="submit" class="button button-danger"><?= e(t('set.remove')) ?></button>
    </form>
</section>

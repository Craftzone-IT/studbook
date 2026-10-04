<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * Lot list of a box; used on the box page and refreshed by the entry page.
 *
 * @var list<array<string, mixed>> $lots
 * @var list<array<string, mixed>> $otherBoxes boxes to move to (none = no move form)
 * @var bool|null $compact true hides the take out / move actions
 * @var \Studbook\I18n\Formatter $fmt
 */
$compact ??= false;
$csrf = '<input type="hidden" name="' . e(Csrf::FIELD) . '" value="' . e(Csrf::token()) . '">';
$img = static fn (string $part, int $color): string => url('/img') . '?part=' . rawurlencode($part) . '&color=' . $color;
?>
<?php if ($lots === []) : ?>
    <p><?= e(t('box.empty')) ?></p>
<?php else : ?>
    <ul class="lot-list">
        <?php foreach ($lots as $lot) : ?>
            <li class="lot">
                <img class="part-img" src="<?= e($img((string) $lot['part'], (int) $lot['color_id'])) ?>" alt="" width="48" height="48" loading="lazy">
                <div class="lot-main">
                    <strong><?= e($lot['bl_num'] ?? $lot['part']) ?></strong>
                    <?= swatch((string) ($lot['rgb'] ?? '')) ?>
                    <?= e($lot['color_name'] ?? ('#' . $lot['color_id'])) ?>
                    <span class="lot-name"><?= e($lot['part_name'] ?? '') ?></span>
                </div>
                <div class="lot-qty"><?= e($fmt->number((int) $lot['qty'])) ?></div>
                <?php if (!$compact) : ?>
                <details class="lot-actions">
                    <summary><?= e(t('box.actions')) ?></summary>
                    <form method="post" action="<?= e(url('/lots/' . $lot['id'] . '/take')) ?>" class="form-inline">
                        <?= $csrf ?>
                        <label for="take-<?= e($lot['id']) ?>"><?= e(t('box.take_out')) ?></label>
                        <input id="take-<?= e($lot['id']) ?>" name="qty" type="number" min="1" max="<?= e($lot['qty']) ?>" value="1" inputmode="numeric">
                        <button type="submit" class="button"><?= e(t('box.take_out_submit')) ?></button>
                    </form>
                    <?php if ($otherBoxes !== []) : ?>
                        <form method="post" action="<?= e(url('/lots/' . $lot['id'] . '/move')) ?>" class="form-inline">
                            <?= $csrf ?>
                            <label for="move-qty-<?= e($lot['id']) ?>"><?= e(t('box.move')) ?></label>
                            <input id="move-qty-<?= e($lot['id']) ?>" name="qty" type="number" min="1" max="<?= e($lot['qty']) ?>" value="<?= e($lot['qty']) ?>" inputmode="numeric">
                            <label for="move-to-<?= e($lot['id']) ?>" class="visually-hidden"><?= e(t('box.move_to')) ?></label>
                            <select id="move-to-<?= e($lot['id']) ?>" name="target">
                                <?php foreach ($otherBoxes as $other) : ?>
                                    <option value="<?= e($other['id']) ?>"><?= e($other['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button"><?= e(t('box.move_submit')) ?></button>
                        </form>
                    <?php endif; ?>
                </details>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

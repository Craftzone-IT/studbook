<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var string $title
 * @var array<string, mixed> $set
 * @var array{rb_num: string, bl_num: ?string, name: string, display: string} $part
 * @var string $kind missing|extra
 * @var list<array{id: int, name: string, rgb: string, part: string}> $colors part = Rebrickable part stored for the colour
 * @var array<int, int> $inSet colour id => quantity the set has
 * @var int $selected preselected colour id
 * @var \Studbook\I18n\Formatter $fmt
 */
$id = (int) $set['id'];
$first = in_array($selected, array_column($colors, 'id'), true) ? $selected : ($colors[0]['id'] ?? null);
?>
<p class="breadcrumb">
    <a href="<?= e(url('/s/' . $id)) ?>"><?= e($set['set_num']) ?> <?= e($set['name'] ?? '') ?></a>
</p>
<h1><?= e($title) ?> <span class="subtitle"><?= e($part['name']) ?></span></h1>

<section class="card">
    <?php if ($colors === []) : ?>
        <p><?= e(t($kind === 'missing' ? 'set.part_not_in_set' : 'box.no_colors')) ?></p>
        <?php if ($kind === 'missing') : ?>
            <p><a href="<?= e(url('/s/' . $id . '/delta') . '?kind=extra&part=' . rawurlencode($part['rb_num'])) ?>"><?= e(t('set.record_extra_instead')) ?></a></p>
        <?php endif; ?>
    <?php else : ?>
        <form method="post" action="<?= e(url('/s/' . $id . '/delta')) ?>" class="form form-wide">
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="part" value="<?= e($part['rb_num']) ?>">
            <input type="hidden" name="kind" value="<?= e($kind) ?>">
            <fieldset class="color-grid">
                <legend><?= e(t('box.color')) ?></legend>
                <?php foreach ($colors as $color) : ?>
                    <label class="color-option">
                        <input type="radio" name="color" value="<?= e($color['id']) ?>" required<?= $color['id'] === $first ? ' checked autofocus' : '' ?>>
                        <img class="part-img" src="<?= e(url('/img') . '?part=' . rawurlencode($color['part'] ?? $part['rb_num']) . '&color=' . $color['id']) ?>" alt="" width="48" height="48" loading="lazy">
                        <?= swatch($color['rgb']) ?>
                        <?= e($color['name']) ?>
                        <?php if (isset($inSet[$color['id']])) : ?>
                            <span class="hint nowrap">(<?= e(t('set.in_set_n', ['qty' => $fmt->number($inSet[$color['id']])])) ?>)</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <label for="qty"><?= e(t('box.quantity')) ?></label>
            <input id="qty" name="qty" type="number" min="1" max="100000" value="1" inputmode="numeric" required>
            <button type="submit" class="button button-primary"><?= e(t('set.save_' . $kind)) ?></button>
        </form>
    <?php endif; ?>
</section>

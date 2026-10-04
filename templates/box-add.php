<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var string $title
 * @var array<string, mixed> $box
 * @var array{rb_num: string, bl_num: ?string, name: string, display: string} $part
 * @var list<array{id: int, name: string, rgb: string, part: string}> $colors part = Rebrickable part stored for the colour
 * @var list<array{id: int, name: string}> $hint boxes labelled for this part (when this one is not)
 */
$id = (int) $box['id'];
?>
<p class="breadcrumb">
    <a href="<?= e(url('/b/' . $id)) ?>"><?= e($box['name']) ?></a>
</p>
<h1><?= e($part['display']) ?> <span class="subtitle"><?= e($part['name']) ?></span></h1>

<?php if ($hint !== []) : ?>
    <p class="flash flash-info">
        <?= e(t('entry.hint')) ?>
        <?php foreach ($hint as $i => $other) : ?>
            <a href="<?= e(url('/b/' . $other['id'])) ?>"><?= e($other['name']) ?></a><?= $i < count($hint) - 1 ? ',' : '' ?>
        <?php endforeach; ?>
    </p>
<?php endif; ?>
<section class="card">
    <?php if ($colors === []) : ?>
        <p><?= e(t('box.no_colors')) ?></p>
    <?php else : ?>
        <form method="post" action="<?= e(url('/b/' . $id . '/lots')) ?>" class="form form-wide">
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="part" value="<?= e($part['rb_num']) ?>">
            <fieldset class="color-grid">
                <legend><?= e(t('box.color')) ?></legend>
                <?php foreach ($colors as $i => $color) : ?>
                    <label class="color-option">
                        <input type="radio" name="color" value="<?= e($color['id']) ?>" required<?= $i === 0 ? ' autofocus' : '' ?>>
                        <img class="part-img" src="<?= e(url('/img') . '?part=' . rawurlencode($color['part'] ?? $part['rb_num']) . '&color=' . $color['id']) ?>" alt="" width="48" height="48" loading="lazy">
                        <?= swatch($color['rgb']) ?>
                        <?= e($color['name']) ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <label for="qty"><?= e(t('box.quantity')) ?></label>
            <input id="qty" name="qty" type="number" min="1" max="100000" value="1" inputmode="numeric" required>
            <button type="submit" class="button button-primary"><?= e(t('box.add_submit', ['box' => $box['name']])) ?></button>
        </form>
    <?php endif; ?>
</section>

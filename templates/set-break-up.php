<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array<string, mixed> $set
 * @var list<array<string, mixed>> $boxes boxes of the set's collection, Inbox first
 * @var int $parts parts the set contains (with deltas, without spares)
 * @var int $spares spare parts of the official inventory
 * @var \Studbook\I18n\Formatter $fmt
 */
$id = (int) $set['id'];
?>
<p class="breadcrumb">
    <a href="<?= e(url('/s/' . $id)) ?>"><?= e($set['set_num']) ?> <?= e($set['name'] ?? '') ?></a>
</p>
<h1><?= e(t('set.break_up_title', ['set' => $set['set_num']])) ?></h1>
<p><?= e(t('set.break_up_intro', ['parts' => $fmt->number($parts)])) ?></p>

<section class="card">
    <form method="post" action="<?= e(url('/s/' . $id . '/break-up')) ?>" class="form form-wide">
        <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
        <label class="checkbox">
            <input type="checkbox" name="use_labels" value="1" checked>
            <?= e(t('set.break_up_labels')) ?>
        </label>
        <label for="break-up-box"><?= e(t('set.break_up_box')) ?></label>
        <select id="break-up-box" name="storage">
            <?php foreach ($boxes as $box) : ?>
                <option value="<?= e($box['id']) ?>"><?= e($box['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($spares > 0) : ?>
            <label class="checkbox">
                <input type="checkbox" name="spares" value="1" checked>
                <?= e(t('set.break_up_spares', ['count' => $fmt->number($spares)])) ?>
            </label>
        <?php endif; ?>
        <p class="hint"><?= e(t('set.break_up_undo')) ?></p>
        <button type="submit" class="button button-primary"><?= e(t('set.break_up_submit')) ?></button>
    </form>
</section>

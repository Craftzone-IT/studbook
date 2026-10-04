<?php

declare(strict_types=1);

/**
 * State, lock and box fields of a set form.
 *
 * @var list<string> $states
 * @var list<string> $lockModes
 * @var list<array<string, mixed>> $boxes
 * @var array<string, mixed>|null $current owned set being edited (null for a new one)
 */
$current ??= null;
$state = (string) ($current['state'] ?? 'built');
$lock = (string) ($current['lock_mode'] ?? 'locked');
$storage = (int) ($current['storage_id'] ?? 0);
?>
<fieldset class="choice-row">
    <legend><?= e(t('set.state')) ?></legend>
    <?php foreach ($states as $option) : ?>
        <label class="radio">
            <input type="radio" name="state" value="<?= e($option) ?>"<?= $option === $state ? ' checked' : '' ?>>
            <?= e(t('set_state.' . $option)) ?>
        </label>
    <?php endforeach; ?>
</fieldset>
<fieldset class="choice-row">
    <legend><?= e(t('set.lock')) ?></legend>
    <?php foreach ($lockModes as $option) : ?>
        <label class="radio">
            <input type="radio" name="lock_mode" value="<?= e($option) ?>"<?= $option === $lock ? ' checked' : '' ?>>
            <?= e(t('set_lock.' . $option)) ?>
        </label>
    <?php endforeach; ?>
    <p class="hint"><?= e(t('set.lock_hint')) ?></p>
</fieldset>
<label for="set-storage"><?= e(t('set.kept_in')) ?></label>
<select id="set-storage" name="storage">
    <option value=""><?= e(t('set.no_box')) ?></option>
    <?php foreach ($boxes as $box) : ?>
        <option value="<?= e($box['id']) ?>"<?= (int) $box['id'] === $storage ? ' selected' : '' ?>><?= e($box['name']) ?></option>
    <?php endforeach; ?>
</select>

<?php

declare(strict_types=1);

/**
 * Visible "what can I build" options (inside a GET form).
 *
 * @var \Studbook\Build\BuildOptions $options
 * @var list<array{id: int, name: string, can_lend: int}> $collections
 */
$lenders = array_values(array_filter(
    $collections,
    static fn (array $c): bool => $c['can_lend'] === 1 && $c['id'] !== $options->home
));
?>
<input type="hidden" name="opt" value="1">
<div class="build-options">
    <div>
        <label for="opt-home"><?= e(t('build.home')) ?></label>
        <select id="opt-home" name="home">
            <?php foreach ($collections as $c) : ?>
                <option value="<?= e($c['id']) ?>"<?= $c['id'] === $options->home ? ' selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($lenders !== []) : ?>
        <fieldset class="choice-row">
            <legend><?= e(t('build.others')) ?></legend>
            <?php foreach ($lenders as $c) : ?>
                <label class="checkbox">
                    <input type="checkbox" name="others[]" value="<?= e($c['id']) ?>"<?= in_array($c['id'], $options->others, true) ? ' checked' : '' ?>>
                    <?= e($c['name']) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
    <?php endif; ?>
    <div>
        <label for="opt-mode"><?= e(t('build.mode')) ?></label>
        <select id="opt-mode" name="mode">
            <?php foreach (\Studbook\Build\PartEquivalence::MODES as $mode) : ?>
                <option value="<?= e($mode) ?>"<?= $mode === $options->mode ? ' selected' : '' ?>><?= e(t('build.mode_' . $mode)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="build-checks">
        <label class="checkbox">
            <input type="checkbox" name="figs" value="0"<?= $options->minifigs ? '' : ' checked' ?>>
            <?= e(t('build.ignore_minifigs')) ?>
        </label>
        <label class="checkbox">
            <input type="checkbox" name="any_color" value="1"<?= $options->anyColor ? ' checked' : '' ?>>
            <?= e(t('build.any_color')) ?>
        </label>
    </div>
</div>

<?php

declare(strict_types=1);

/**
 * The options as hidden fields of a POST form.
 *
 * @var \Studbook\Build\BuildOptions $options
 */
?>
<input type="hidden" name="opt" value="1">
<input type="hidden" name="home" value="<?= e($options->home) ?>">
<?php foreach ($options->others as $other) : ?>
    <input type="hidden" name="others[]" value="<?= e($other) ?>">
<?php endforeach; ?>
<input type="hidden" name="mode" value="<?= e($options->mode) ?>">
<input type="hidden" name="figs" value="<?= $options->minifigs ? '1' : '0' ?>">
<input type="hidden" name="any_color" value="<?= $options->anyColor ? '1' : '0' ?>">

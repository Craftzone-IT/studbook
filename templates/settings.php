<?php

declare(strict_types=1);

use Studbook\Http\Csrf;
use Studbook\I18n\Formatter;

/**
 * @var string $title
 * @var string $lang
 * @var list<string> $languages
 * @var \DateTimeImmutable $now
 * @var Formatter $fmt
 */
?>
<h1><?= e($title) ?></h1>
<section class="card">
    <form method="post" action="<?= e(url('/settings')) ?>" class="form">
        <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
        <label for="ui_language"><?= e(t('settings.language')) ?></label>
        <select id="ui_language" name="ui_language">
            <?php foreach ($languages as $code) : ?>
                <option value="<?= e($code) ?>"<?= $code === $lang ? ' selected' : '' ?>>
                    <?= e(t('language.' . $code)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="hint">
            <?= e(t('settings.format_example', [
                'date' => $fmt->dateTime($now),
                'number' => $fmt->number(1234567.89, 2),
            ])) ?>
        </p>
        <button type="submit" class="button button-primary"><?= e(t('settings.save')) ?></button>
    </form>
</section>

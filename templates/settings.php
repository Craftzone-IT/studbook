<?php

declare(strict_types=1);

use Studbook\Http\Csrf;
use Studbook\I18n\Formatter;

/**
 * @var string $title
 * @var string $lang
 * @var list<string> $languages
 * @var \DateTimeImmutable $now
 * @var array{ocr?: bool, ocr_reason?: string, ocr_version?: string, recognition?: bool} $camera
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

<?php if (($camera ?? []) !== []) : ?>
    <section class="card setup-step">
        <h2><?= e(t('camera.settings_heading')) ?></h2>
        <ul class="status-list">
            <li>
                <strong><?= e(t('camera.ocr')) ?>:</strong>
                <?= e($camera['ocr']
                    ? t('camera.ocr_ready', ['version' => $camera['ocr_version']])
                    : t('camera.ocr_status.' . $camera['ocr_reason'])) ?>
            </li>
            <li>
                <strong><?= e(t('camera.recognition')) ?>:</strong>
                <?= e(t($camera['recognition'] ? 'camera.recognition_on' : 'camera.recognition_off')) ?>
            </li>
        </ul>
        <p class="hint"><?= e(t('camera.settings_hint')) ?></p>
    </section>
<?php endif; ?>

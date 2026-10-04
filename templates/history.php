<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var string $title
 * @var list<array{id: int, created_at: string, description_key: string,
 *     description_params: array<string, string|int>, reverted_at: ?string, changes: int}> $batches
 * @var \Studbook\I18n\Formatter $fmt
 */
$utc = new \DateTimeZone('UTC');
?>
<h1><?= e($title) ?></h1>
<p class="hint"><?= e(t('history.hint')) ?></p>
<?php if ($batches === []) : ?>
    <p><?= e(t('history.empty')) ?></p>
<?php else : ?>
    <ul class="lot-list">
        <?php foreach ($batches as $batch) : ?>
            <li class="lot history-item">
                <div class="lot-main">
                    <strong><?= e(t($batch['description_key'], $batch['description_params'])) ?></strong>
                    <span class="lot-name">
                        <?= e($fmt->dateTime(new \DateTimeImmutable($batch['created_at'], $utc))) ?>
                        · <?= e(t('history.changes', ['count' => $batch['changes']])) ?>
                    </span>
                </div>
                <div>
                    <?php if ($batch['reverted_at'] !== null) : ?>
                        <span class="badge"><?= e(t('history.undone')) ?></span>
                    <?php else : ?>
                        <form method="post" action="<?= e(url('/batches/' . $batch['id'] . '/undo')) ?>" class="inline-form">
                            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
                            <input type="hidden" name="return" value="/history">
                            <button type="submit" class="button"><?= e(t('batch.undo')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

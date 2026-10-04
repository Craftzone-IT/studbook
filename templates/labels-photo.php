<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array<string, mixed> $box
 * @var array{ocr: bool, ocr_reason: string, ocr_version: string, recognition: bool} $status
 */
$id = (int) $box['id'];
?>
<p class="breadcrumb"><a href="<?= e(url('/b/' . $id)) ?>"><?= e($box['name']) ?></a></p>
<h1><?= e(t('labels_photo.heading')) ?></h1>

<?php if (!$status['ocr']) : ?>
    <p class="flash flash-info"><?= e(t('camera.ocr_status.' . $status['ocr_reason'])) ?></p>
    <p><a href="<?= e(url('/b/' . $id)) ?>"><?= e(t('labels_photo.type_instead')) ?></a></p>
<?php else : ?>
    <p class="hint"><?= e(t('labels_photo.intro')) ?></p>
    <section class="card">
        <form method="post" action="<?= e(url('/b/' . $id . '/labels/photo')) ?>" enctype="multipart/form-data"
              class="form" data-photo-form>
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <?php $photoLabel = t('labels_photo.photo'); require __DIR__ . '/_photo-input.php'; ?>
            <button type="submit" class="button button-primary" data-busy="<?= e(t('labels_photo.reading')) ?>"><?= e(t('labels_photo.read')) ?></button>
        </form>
    </section>
<?php endif; ?>

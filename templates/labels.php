<?php

declare(strict_types=1);

/**
 * @var string $title
 * @var array<string, mixed> $collection
 * @var list<array{box: array<string, mixed>, qr: string, parts: list<string>}> $labels
 */
?>
<div class="no-print">
    <p class="breadcrumb"><a href="<?= e(url('/c/' . $collection['id'])) ?>"><?= e($collection['name']) ?></a></p>
    <h1><?= e($title) ?></h1>
    <p class="hint"><?= e(t('labels.hint')) ?></p>
    <p><button type="button" class="button button-primary" data-print><?= e(t('labels.print_now')) ?></button></p>
</div>
<div class="label-sheet">
    <?php foreach ($labels as $label) : ?>
        <article class="box-label">
            <div class="box-label-qr"><?= $label['qr'] ?></div>
            <div class="box-label-text">
                <h2><?= e($label['box']['name']) ?></h2>
                <p class="box-label-parts"><?= e(implode('  ', $label['parts'])) ?></p>
            </div>
        </article>
    <?php endforeach; ?>
</div>

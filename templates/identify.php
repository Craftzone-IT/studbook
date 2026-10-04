<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array<string, mixed>|null $box box the part is being added to, if any
 * @var array{ocr: bool, ocr_reason: string, ocr_version: string, recognition: bool} $status
 * @var list<array<string, mixed>>|null $candidates recognition results (null before a photo was sent)
 * @var \Studbook\I18n\Formatter $fmt
 */
$boxId = $box !== null ? (int) $box['id'] : null;
?>
<?php if ($box !== null) : ?>
    <p class="breadcrumb"><a href="<?= e(url('/b/' . $boxId)) ?>"><?= e($box['name']) ?></a></p>
<?php endif; ?>
<h1><?= e(t('identify.title')) ?></h1>

<?php if (!$status['recognition']) : ?>
    <p class="flash flash-info"><?= e(t('identify.disabled')) ?></p>
    <p><a href="<?= e(url('/search')) ?>"><?= e(t('identify.search_instead')) ?></a></p>
<?php else : ?>
    <?php if ($candidates !== null) : ?>
        <section class="card setup-step">
            <h2><?= e(t('identify.results')) ?></h2>
            <?php if ($candidates === []) : ?>
                <p><?= e(t('identify.nothing')) ?></p>
            <?php else : ?>
                <p class="hint"><?= e(t('identify.pick_hint')) ?></p>
                <ul class="lot-list">
                    <?php foreach ($candidates as $c) : ?>
                        <?php
                        $target = $c['rb_num'] === null
                            ? url('/search') . '?q=' . rawurlencode($c['id'])
                            : ($boxId !== null
                                ? url('/b/' . $boxId . '/entry') . '?part=' . rawurlencode($c['rb_num'])
                                : url('/search') . '?q=' . rawurlencode($c['display']));
                        ?>
                        <li class="lot">
                            <img class="part-img" src="<?= e(url('/img') . '?part=' . rawurlencode((string) ($c['rb_num'] ?? ''))) ?>" alt="" width="48" height="48" loading="lazy">
                            <div class="lot-main">
                                <a href="<?= e($target) ?>"><strong><?= e($c['display']) ?></strong></a>
                                <?= e($c['part_name']) ?>
                                <span class="lot-name">
                                    <?php if ($c['locations'] !== []) : ?>
                                        <?= e(t('search.kept_in')) ?>
                                        <?= e(implode(', ', array_map(static fn (array $l): string => $l['box'], $c['locations']))) ?>
                                    <?php elseif ($c['rb_num'] === null) : ?>
                                        <?= e(t('identify.not_in_catalogue')) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="lot-qty"><?= e((int) round(100 * $c['score'])) ?>%</div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="hint"><?= e(t('identify.credit')) ?></p>
        </section>
    <?php endif; ?>

    <section class="card">
        <p class="hint"><?= e(t('identify.intro')) ?></p>
        <form method="post" action="<?= e(url('/identify')) ?>" enctype="multipart/form-data" class="form" data-photo-form>
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <?php if ($boxId !== null) : ?>
                <input type="hidden" name="box" value="<?= e($boxId) ?>">
            <?php endif; ?>
            <?php $photoLabel = t('identify.photo'); require __DIR__ . '/_photo-input.php'; ?>
            <button type="submit" class="button button-primary" data-busy="<?= e(t('identify.working')) ?>"><?= e(t('identify.submit')) ?></button>
        </form>
    </section>
<?php endif; ?>

<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array<string, mixed> $box
 * @var list<array{raw: string, rb_num: ?string, display: string, name: string, status: string, labelled: bool}> $candidates
 * @var string $text raw OCR output
 * @var int $labelCount labels the box has now
 */
$id = (int) $box['id'];
$known = array_values(array_filter($candidates, static fn (array $c): bool => $c['rb_num'] !== null));
$unknown = array_values(array_filter($candidates, static fn (array $c): bool => $c['rb_num'] === null));
?>
<p class="breadcrumb"><a href="<?= e(url('/b/' . $id)) ?>"><?= e($box['name']) ?></a></p>
<h1><?= e(t('labels_photo.review')) ?></h1>

<form method="post" action="<?= e(url('/b/' . $id . '/labels/photo/save')) ?>" class="card form form-wide">
    <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
    <?php if ($known === []) : ?>
        <p><?= e(t('labels_photo.none_found')) ?></p>
    <?php else : ?>
        <p class="hint"><?= e(t('labels_photo.review_hint')) ?></p>
        <ul class="tile-list">
            <?php foreach ($known as $c) : ?>
                <li>
                    <label class="tile tile-check">
                        <input type="checkbox" name="parts[]" value="<?= e($c['rb_num']) ?>"<?= $c['labelled'] ? '' : ' checked' ?>>
                        <img class="part-img" src="<?= e(url('/img') . '?part=' . rawurlencode((string) $c['rb_num'])) ?>" alt="" width="64" height="64" loading="lazy">
                        <strong><?= e($c['display']) ?></strong>
                        <span class="tile-name"><?= e($c['name']) ?></span>
                        <?php if ($c['status'] === 'fixed') : ?>
                            <span class="tile-name"><?= e(t('labels_photo.read_as', ['raw' => $c['raw']])) ?></span>
                        <?php endif; ?>
                        <?php if ($c['labelled']) : ?>
                            <span class="badge"><?= e(t('labels_photo.already')) ?></span>
                        <?php endif; ?>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <label for="extra"><?= e(t('labels_photo.extra')) ?></label>
    <input id="extra" name="extra" type="text" autocapitalize="none" spellcheck="false"
           value="<?= e(implode(' ', array_column($unknown, 'raw'))) ?>">
    <p class="hint"><?= e($unknown !== [] ? t('labels_photo.unknown_hint') : t('labels_photo.extra_hint')) ?></p>

    <fieldset class="choice-row">
        <legend><?= e(t('labels_photo.mode')) ?></legend>
        <label class="radio"><input type="radio" name="mode" value="append" checked> <?= e(t('labels_photo.append', ['count' => $labelCount])) ?></label>
        <label class="radio"><input type="radio" name="mode" value="replace"> <?= e(t('labels_photo.replace')) ?></label>
    </fieldset>
    <button type="submit" class="button button-primary"><?= e(t('labels_photo.save')) ?></button>
</form>

<details class="card">
    <summary><?= e(t('labels_photo.raw_text')) ?></summary>
    <pre class="log"><?= e($text) ?></pre>
</details>
<p><a href="<?= e(url('/b/' . $id . '/labels/photo')) ?>"><?= e(t('labels_photo.again')) ?></a></p>

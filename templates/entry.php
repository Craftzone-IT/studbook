<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * Fast entry into one box. Works as plain links without JavaScript;
 * assets/entry.js turns it into the keyboard flow part → colour → quantity.
 *
 * @var string $title
 * @var array<string, mixed> $box
 * @var list<array{rb_num: string, bl_num: ?string, name: string, display: string}> $labels
 * @var list<array<string, mixed>> $lots
 * @var array{batch: int, at: int}|null $session
 * @var string $preselect
 */
$id = (int) $box['id'];
$img = static fn (string $part, int $color): string => url('/img') . '?part=' . rawurlencode($part) . '&color=' . $color;
?>
<p class="breadcrumb">
    <a href="<?= e(url('/c/' . $box['collection_id'])) ?>"><?= e($box['collection_name']) ?></a> ›
    <a href="<?= e(url('/b/' . $id)) ?>"><?= e($box['name']) ?></a>
</p>
<h1><?= e($title) ?></h1>

<div class="entry" id="entry"
     data-search-url="<?= e(url('/b/' . $id . '/entry/search')) ?>"
     data-part-url="<?= e(url('/b/' . $id . '/entry/part')) ?>"
     data-add-url="<?= e(url('/b/' . $id . '/entry')) ?>"
     data-img-url="<?= e(url('/img')) ?>"
     data-box-url="<?= e(url('/b/')) ?>"
     data-csrf="<?= e(Csrf::token()) ?>"
     data-preselect="<?= e($preselect) ?>"
     data-t-no-results="<?= e(t('entry.no_results')) ?>"
     data-t-no-colors="<?= e(t('box.no_colors')) ?>"
     data-t-hint="<?= e(t('entry.hint')) ?>"
     data-t-corrected="<?= e(t('entry.corrected')) ?>"
     data-t-error="<?= e(t('entry.error')) ?>"
     data-t-undo="<?= e(t('entry.undo_session')) ?>">

    <section class="card entry-step" aria-labelledby="entry-part-label">
        <h2 id="entry-part-label"><span class="step-no">1</span> <?= e(t('entry.step_part')) ?></h2>
        <label for="entry-part" class="visually-hidden"><?= e(t('entry.step_part')) ?></label>
        <input id="entry-part" type="search" autocomplete="off" autocapitalize="none" spellcheck="false"
               placeholder="<?= e(t('entry.part_placeholder')) ?>" aria-controls="entry-part-list">
        <ul class="pick-list" id="entry-part-list" role="listbox" aria-label="<?= e(t('entry.step_part')) ?>">
            <?php foreach ($labels as $part) : ?>
                <li>
                    <a class="pick-item" href="<?= e(url('/b/' . $id . '/add') . '?part=' . rawurlencode($part['rb_num'])) ?>"
                       data-part="<?= e($part['rb_num']) ?>" role="option">
                        <img class="part-img" src="<?= e($img($part['rb_num'], -1)) ?>" alt="" width="40" height="40" loading="lazy">
                        <strong><?= e($part['display']) ?></strong>
                        <span class="pick-name"><?= e($part['name']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="hint">
            <a href="<?= e(url('/b/' . $id . '/pick')) ?>"><?= e(t('picker.open')) ?></a>
        </p>
    </section>

    <section class="card entry-step" aria-labelledby="entry-color-label">
        <h2 id="entry-color-label"><span class="step-no">2</span> <?= e(t('entry.step_color')) ?></h2>
        <p class="entry-selected" id="entry-selected" aria-live="polite"><?= e(t('entry.choose_part_first')) ?></p>
        <p class="flash flash-info" id="entry-hint" hidden></p>
        <label for="entry-color" class="visually-hidden"><?= e(t('entry.step_color')) ?></label>
        <input id="entry-color" type="search" autocomplete="off" disabled
               placeholder="<?= e(t('entry.color_placeholder')) ?>" aria-controls="entry-color-list">
        <ul class="pick-list" id="entry-color-list" role="listbox" aria-label="<?= e(t('entry.step_color')) ?>"></ul>
    </section>

    <section class="card entry-step" aria-labelledby="entry-qty-label">
        <h2 id="entry-qty-label"><span class="step-no">3</span> <?= e(t('entry.step_qty')) ?></h2>
        <form id="entry-form" class="qty-form">
            <button type="button" class="button qty-step" data-step="-1" aria-label="<?= e(t('entry.less')) ?>">−</button>
            <label for="entry-qty" class="visually-hidden"><?= e(t('box.quantity')) ?></label>
            <input id="entry-qty" type="number" min="1" max="100000" value="1" inputmode="numeric" disabled>
            <button type="button" class="button qty-step" data-step="1" aria-label="<?= e(t('entry.more')) ?>">+</button>
            <button type="submit" class="button button-primary" id="entry-submit" disabled><?= e(t('entry.add')) ?></button>
        </form>
        <p class="entry-status" id="entry-status" role="status" aria-live="polite"></p>
        <form method="post" class="inline-form" id="entry-undo"
              action="<?= e($session !== null ? url('/batches/' . $session['batch'] . '/undo') : '') ?>"
              <?= $session === null ? 'hidden' : '' ?>
              data-action-base="<?= e(url('/batches/')) ?>">
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="return" value="<?= e('/b/' . $id . '/entry') ?>">
            <span id="entry-session-summary"><?= e($session['summary'] ?? '') ?></span>
            <button type="submit" class="link-button undo-button"><?= e(t('entry.undo_session')) ?></button>
        </form>
    </section>
</div>

<p class="hint keyboard-help"><?= e(t('entry.keyboard_help')) ?></p>

<section class="card setup-step">
    <h2><?= e(t('box.contents')) ?></h2>
    <div id="entry-contents">
        <?php $otherBoxes = []; $compact = true; require __DIR__ . '/_lots.php'; ?>
    </div>
</section>
<script src="<?= e(url('/assets/entry.js')) ?>" defer></script>

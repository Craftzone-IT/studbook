<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array<string, mixed> $box
 * @var list<array{rb_num: string, bl_num: ?string, name: string, display: string}> $labels
 * @var string $labelText
 * @var list<array<string, mixed>> $lots
 * @var list<array<string, mixed>> $sets sets kept in this box
 * @var list<array{id: int, name: string, collection_name: string}> $otherBoxes
 * @var list<array{id: int, name: string}> $collections other collections
 * @var list<string> $boxTypes
 * @var \Studbook\I18n\Formatter $fmt
 */
$csrf = '<input type="hidden" name="' . e(Csrf::FIELD) . '" value="' . e(Csrf::token()) . '">';
$id = (int) $box['id'];
$img = static fn (string $part, int $color): string => url('/img') . '?part=' . rawurlencode($part) . '&color=' . $color;
?>
<p class="breadcrumb">
    <a href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a> ›
    <a href="<?= e(url('/c/' . $box['collection_id'])) ?>"><?= e($box['collection_name']) ?></a>
</p>
<div class="section-head">
    <h1><?= e($box['name']) ?> <span class="badge"><?= e(t('box_type.' . $box['type'])) ?></span></h1>
    <a class="button" href="<?= e(url('/c/' . $box['collection_id'] . '/labels') . '?box=' . $id) ?>"><?= e(t('labels.print_one')) ?></a>
</div>

<section class="card setup-step">
    <div class="section-head">
        <h2><?= e(t('box.add_here')) ?></h2>
        <span>
            <a class="button button-primary" href="<?= e(url('/b/' . $id . '/entry')) ?>"><?= e(t('entry.open')) ?></a>
            <a class="button" href="<?= e(url('/b/' . $id . '/pick')) ?>"><?= e(t('picker.open')) ?></a>
            <?php if (!empty($cameraFeatures['recognition'])) : ?>
                <a class="button" href="<?= e(url('/identify') . '?box=' . $id) ?>"><?= e(t('identify.open')) ?></a>
            <?php endif; ?>
        </span>
    </div>
    <?php if ($labels !== []) : ?>
        <ul class="tile-list">
            <?php foreach ($labels as $part) : ?>
                <li>
                    <a class="tile" href="<?= e(url('/b/' . $id . '/add') . '?part=' . rawurlencode($part['rb_num'])) ?>">
                        <img class="part-img" src="<?= e($img($part['rb_num'], -1)) ?>" alt="" width="64" height="64" loading="lazy">
                        <strong><?= e($part['display']) ?></strong>
                        <span class="tile-name"><?= e($part['name']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form method="get" action="<?= e(url('/b/' . $id . '/add')) ?>" class="form form-inline">
        <label for="add-part"><?= e(t('box.part_number')) ?></label>
        <input id="add-part" name="part" type="text" required autocapitalize="none" spellcheck="false"
               placeholder="<?= e(t('box.part_placeholder')) ?>"<?= $labels === [] ? ' autofocus' : '' ?>>
        <button type="submit" class="button button-primary"><?= e(t('box.next')) ?></button>
    </form>
</section>

<section class="card setup-step">
    <h2><?= e(t('box.contents')) ?></h2>
    <?php require __DIR__ . '/_lots.php'; ?>
</section>

<?php if ($sets !== []) : ?>
    <section class="card setup-step">
        <h2><?= e(t('box.sets')) ?></h2>
        <?php require __DIR__ . '/_sets.php'; ?>
    </section>
<?php endif; ?>

<section class="card setup-step">
    <h2><?= e(t('box.labels_heading')) ?></h2>
    <p class="hint"><?= e(t('box.labels_hint')) ?></p>
    <?php if (!empty($cameraFeatures['ocr'])) : ?>
        <p><a class="button" href="<?= e(url('/b/' . $id . '/labels/photo')) ?>"><?= e(t('labels_photo.open')) ?></a></p>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/b/' . $id . '/labels')) ?>" class="form">
        <?= $csrf ?>
        <label for="labels" class="visually-hidden"><?= e(t('box.labels_heading')) ?></label>
        <textarea id="labels" name="labels" rows="3" spellcheck="false" autocapitalize="none"><?= e($labelText) ?></textarea>
        <button type="submit" class="button button-primary"><?= e(t('box.labels_save')) ?></button>
    </form>
</section>

<section class="card setup-step">
    <h2><?= e(t('box.settings')) ?></h2>
    <form method="post" action="<?= e(url('/b/' . $id . '/update')) ?>" class="form">
        <?= $csrf ?>
        <label for="box-name"><?= e(t('box.name')) ?></label>
        <input id="box-name" name="name" type="text" maxlength="100" required value="<?= e($box['name']) ?>">
        <?php if ($box['type'] !== 'inbox') : ?>
            <label for="box-type"><?= e(t('box.type')) ?></label>
            <select id="box-type" name="type">
                <?php foreach ($boxTypes as $type) : ?>
                    <option value="<?= e($type) ?>"<?= $type === $box['type'] ? ' selected' : '' ?>><?= e(t('box_type.' . $type)) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="button button-primary"><?= e(t('settings.save')) ?></button>
    </form>
    <?php if ($box['type'] !== 'inbox' && $collections !== []) : ?>
        <h3><?= e(t('box.move_heading')) ?></h3>
        <form method="post" action="<?= e(url('/b/' . $id . '/move')) ?>" class="form form-inline">
            <?= $csrf ?>
            <label for="box-move" class="visually-hidden"><?= e(t('box.move_heading')) ?></label>
            <select id="box-move" name="collection">
                <?php foreach ($collections as $other) : ?>
                    <option value="<?= e($other['id']) ?>"><?= e($other['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?= e(t('box.move_submit_collection')) ?></button>
        </form>
        <p class="hint"><?= e(t('box.move_hint')) ?></p>
    <?php endif; ?>
    <?php if ($box['type'] !== 'inbox') : ?>
        <h3><?= e(t('box.delete_heading')) ?></h3>
        <form method="post" action="<?= e(url('/b/' . $id . '/delete')) ?>">
            <?= $csrf ?>
            <p class="hint"><?= e(t('box.delete_hint')) ?></p>
            <button type="submit" class="button button-danger"><?= e(t('box.delete')) ?></button>
        </form>
    <?php endif; ?>
</section>

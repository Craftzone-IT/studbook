<?php

declare(strict_types=1);

use Studbook\Http\Csrf;

/**
 * @var array<string, mixed> $collection
 * @var list<array<string, mixed>> $boxes
 * @var bool $empty
 * @var list<string> $boxTypes
 * @var \Studbook\I18n\Formatter $fmt
 */
$csrf = '<input type="hidden" name="' . e(Csrf::FIELD) . '" value="' . e(Csrf::token()) . '">';
$id = (int) $collection['id'];
?>
<p class="breadcrumb"><a href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a></p>
<h1>
    <?= e($collection['name']) ?>
    <?php if ($collection['archived_at'] !== null) : ?><span class="badge"><?= e(t('collection.archived')) ?></span><?php endif; ?>
</h1>

<section class="card setup-step">
    <div class="section-head">
        <h2><?= e(t('collection.boxes')) ?></h2>
        <a class="button" href="<?= e(url('/c/' . $id . '/labels')) ?>"><?= e(t('labels.print')) ?></a>
    </div>
    <div class="table-scroll">
        <table class="data-table">
            <thead>
            <tr>
                <th scope="col"><?= e(t('box.name')) ?></th>
                <th scope="col"><?= e(t('box.type')) ?></th>
                <th scope="col" class="num"><?= e(t('box.labels')) ?></th>
                <th scope="col" class="num"><?= e(t('box.parts')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($boxes as $box) : ?>
                <tr>
                    <td><a href="<?= e(url('/b/' . $box['id'])) ?>"><?= e($box['name']) ?></a></td>
                    <td><?= e(t('box_type.' . $box['type'])) ?></td>
                    <td class="num"><?= e($fmt->number((int) $box['labels'])) ?></td>
                    <td class="num"><?= e($fmt->number((int) $box['parts'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h3><?= e(t('box.new')) ?></h3>
    <form method="post" action="<?= e(url('/c/' . $id . '/boxes')) ?>" class="form form-inline">
        <?= $csrf ?>
        <label for="box-name" class="visually-hidden"><?= e(t('box.name')) ?></label>
        <input id="box-name" name="name" type="text" maxlength="100" required placeholder="<?= e(t('box.name_placeholder')) ?>">
        <label for="box-type" class="visually-hidden"><?= e(t('box.type')) ?></label>
        <select id="box-type" name="type">
            <?php foreach ($boxTypes as $type) : ?>
                <option value="<?= e($type) ?>"<?= $type === 'small' ? ' selected' : '' ?>><?= e(t('box_type.' . $type)) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button-primary"><?= e(t('box.create')) ?></button>
    </form>
</section>

<section class="card setup-step">
    <h2><?= e(t('collection.settings')) ?></h2>
    <form method="post" action="<?= e(url('/c/' . $id . '/update')) ?>" class="form">
        <?= $csrf ?>
        <label for="collection-name"><?= e(t('collection.name')) ?></label>
        <input id="collection-name" name="name" type="text" maxlength="100" required value="<?= e($collection['name']) ?>">
        <label class="checkbox">
            <input type="checkbox" name="can_lend" value="1"<?= (int) $collection['can_lend'] === 1 ? ' checked' : '' ?>>
            <?= e(t('collection.can_lend')) ?>
        </label>
        <p class="hint"><?= e(t('collection.can_lend_hint')) ?></p>
        <button type="submit" class="button button-primary"><?= e(t('settings.save')) ?></button>
    </form>

    <h3><?= e(t('collection.archive_heading')) ?></h3>
    <form method="post" action="<?= e(url('/c/' . $id . '/archive')) ?>">
        <?= $csrf ?>
        <?php if ($collection['archived_at'] === null) : ?>
            <input type="hidden" name="archive" value="1">
            <p class="hint"><?= e(t('collection.archive_hint')) ?></p>
            <button type="submit" class="button"><?= e(t('collection.archive')) ?></button>
        <?php else : ?>
            <input type="hidden" name="archive" value="0">
            <button type="submit" class="button"><?= e(t('collection.restore')) ?></button>
        <?php endif; ?>
    </form>

    <h3><?= e(t('collection.delete_heading')) ?></h3>
    <form method="post" action="<?= e(url('/c/' . $id . '/delete')) ?>" class="form">
        <?= $csrf ?>
        <?php if (!$empty) : ?>
            <p class="flash flash-error"><?= e(t('collection.delete_not_empty')) ?></p>
            <label class="checkbox">
                <input type="checkbox" name="confirm" value="1" required>
                <?= e(t('collection.delete_confirm')) ?>
            </label>
        <?php else : ?>
            <p class="hint"><?= e(t('collection.delete_hint')) ?></p>
        <?php endif; ?>
        <button type="submit" class="button button-danger"><?= e(t('collection.delete')) ?></button>
    </form>
</section>

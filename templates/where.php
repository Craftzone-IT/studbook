<?php

declare(strict_types=1);

/**
 * @var string $input the typed part number (kept in the field for the next lookup)
 * @var list<array{id: int, name: string, can_lend: int}> $collections active collections
 * @var int|null $collectionId
 * @var array{rb_num: string, bl_num: ?string, name: string, display: string}|null $part
 * @var string|null $category
 * @var list<array{box_id: int, name: string, type: string, reason: string, detail: array<string, string|int>, text: string}> $advice best first
 * @var int|null $inbox Inbox of the collection
 * @var string|null $error
 */
$addUrl = static fn (int $boxId, string $rbNum): string
    => url('/b/' . $boxId . '/add') . '?part=' . rawurlencode($rbNum);
$best = $advice[0] ?? null;
$alternatives = array_slice($advice, 1);
?>
<h1><?= e(t('where.title')) ?></h1>

<?php if ($collectionId === null) : ?>
    <p><?= e(t('home.empty')) ?></p>
<?php else : ?>
    <form method="get" action="<?= e(url('/where')) ?>" class="form-inline where-form" role="search">
        <label for="where-part"><?= e(t('where.part_label')) ?></label>
        <input id="where-part" name="part" type="text" value="<?= e($input) ?>" required autofocus
               autocomplete="off" inputmode="text" autocapitalize="none" spellcheck="false" data-select-on-load>
        <?php if (count($collections) > 1) : ?>
            <label for="where-collection" class="visually-hidden"><?= e(t('collection.name')) ?></label>
            <select id="where-collection" name="c">
                <?php foreach ($collections as $c) : ?>
                    <option value="<?= e($c['id']) ?>"<?= $c['id'] === $collectionId ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="button button-primary"><?= e(t('where.submit')) ?></button>
    </form>

    <?php if ($error !== null) : ?>
        <p class="flash flash-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if ($part !== null) : ?>
        <section class="card where-result" aria-live="polite">
            <div class="where-part">
                <img class="part-img" src="<?= e(url('/img') . '?part=' . rawurlencode($part['rb_num']) . '&color=-1') ?>"
                     alt="" width="64" height="64">
                <div>
                    <strong><?= e($part['display']) ?></strong> <?= e($part['name']) ?>
                    <?php if ($category !== null) : ?>
                        <span class="hint where-category"><?= e($category) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($best !== null) : ?>
                <div class="where-best">
                    <h2><?= e(t('where.best')) ?></h2>
                    <p class="where-box"><a href="<?= e(url('/b/' . $best['box_id'])) ?>"><?= e($best['name']) ?></a></p>
                    <p class="where-reason"><?= e($best['text']) ?></p>
                    <a class="button button-primary where-add" href="<?= e($addUrl($best['box_id'], $part['rb_num'])) ?>"><?= e(t('where.add_here')) ?></a>
                </div>
                <?php if ($alternatives !== []) : ?>
                    <h3><?= e(t('where.alternatives')) ?></h3>
                    <ul class="where-alternatives">
                        <?php foreach ($alternatives as $a) : ?>
                            <li>
                                <div>
                                    <a href="<?= e(url('/b/' . $a['box_id'])) ?>"><strong><?= e($a['name']) ?></strong></a>
                                    <span class="hint"><?= e($a['text']) ?></span>
                                </div>
                                <a class="button" href="<?= e($addUrl($a['box_id'], $part['rb_num'])) ?>"><?= e(t('where.add_here')) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php else : ?>
                <p><?= e(t('where.no_box', ['category' => $category ?? '?'])) ?></p>
                <?php if ($inbox !== null) : ?>
                    <a class="button button-primary where-add" href="<?= e($addUrl($inbox, $part['rb_num'])) ?>"><?= e(t('where.add_inbox')) ?></a>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>

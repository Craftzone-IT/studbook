<?php

declare(strict_types=1);

/**
 * @var string $q
 * @var array{parts: list<array<string, mixed>>, color: ?array{id: int, name: string, rgb: string},
 *     size: ?array{0: int, 1: int}, corrections: array<string, string>}|null $result
 * @var array<string, list<array{box_id: int, box: string, collection: string, qty: int}>> $locations
 * @var \Studbook\I18n\Formatter $fmt
 */
$color = $result['color'] ?? null;
?>
<h1><?= e(t('search.title')) ?></h1>
<form method="get" action="<?= e(url('/search')) ?>" class="form-inline" role="search">
    <label for="search-q" class="visually-hidden"><?= e(t('search.title')) ?></label>
    <input id="search-q" name="q" type="search" value="<?= e($q) ?>" autocomplete="off" autocapitalize="none"
           spellcheck="false" placeholder="<?= e(t('search.placeholder')) ?>" autofocus>
    <button type="submit" class="button button-primary"><?= e(t('search.submit')) ?></button>
</form>
<p class="hint"><?= e(t('search.examples')) ?></p>

<?php if ($result !== null) : ?>
    <?php if ($result['corrections'] !== []) : ?>
        <p class="hint"><?= e(t('entry.corrected')) ?>
            <?= e(implode(', ', array_map(
                static fn (string $from, string $to): string => $from . ' → ' . $to,
                array_keys($result['corrections']),
                $result['corrections']
            ))) ?></p>
    <?php endif; ?>
    <?php if ($color !== null) : ?>
        <p class="hint"><?= e(t('search.color_filter')) ?> <?= swatch($color['rgb']) ?> <?= e($color['name']) ?></p>
    <?php endif; ?>
    <?php if ($result['parts'] === []) : ?>
        <p><?= e(t('entry.no_results')) ?></p>
    <?php else : ?>
        <ul class="lot-list">
            <?php foreach ($result['parts'] as $part) : ?>
                <?php $where = $locations['p:' . $part['rb_num']] ?? []; ?>
                <li class="lot">
                    <img class="part-img" src="<?= e(url('/img') . '?part=' . rawurlencode($part['rb_num']) . '&color=' . ($color['id'] ?? -1)) ?>"
                         alt="" width="48" height="48" loading="lazy">
                    <div class="lot-main">
                        <strong><?= e($part['display']) ?></strong>
                        <span class="lot-name"><?= e($part['name']) ?></span>
                        <?php if ($where !== []) : ?>
                            <span class="lot-where">
                                <?= e(t('search.kept_in')) ?>
                                <?php foreach ($where as $i => $loc) : ?>
                                    <a href="<?= e(url('/b/' . $loc['box_id'])) ?>"><?= e($loc['box']) ?></a>
                                    (<?= e($loc['collection']) ?>, <?= e($fmt->number($loc['qty'])) ?>)<?= $i < count($where) - 1 ? ',' : '' ?>
                                <?php endforeach; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($where !== []) : ?>
                        <div class="lot-qty"><?= e($fmt->number(array_sum(array_column($where, 'qty')))) ?></div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
<?php endif; ?>

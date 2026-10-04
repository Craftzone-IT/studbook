<?php

declare(strict_types=1);

/**
 * Step-by-step picker: category → size → part.
 *
 * @var string $title
 * @var array<string, mixed> $box
 * @var string $step category | size | parts
 * @var list<array<string, mixed>>|null $categories
 * @var array{id: int|string, name: string}|null $category
 * @var list<array<string, mixed>>|null $sizes
 * @var list<array<string, mixed>>|null $parts
 * @var string|null $size
 * @var int|null $page
 * @var bool|null $more
 * @var \Studbook\I18n\Formatter $fmt
 */
$id = (int) $box['id'];
$base = url('/b/' . $id . '/pick');
?>
<p class="breadcrumb">
    <a href="<?= e(url('/b/' . $id)) ?>"><?= e($box['name']) ?></a> ›
    <a href="<?= e($base) ?>"><?= e(t('picker.categories')) ?></a>
    <?php if (isset($category)) : ?>
        › <a href="<?= e($base . '?cat=' . $category['id']) ?>"><?= e($category['name']) ?></a>
    <?php endif; ?>
</p>
<h1><?= e($title) ?></h1>

<?php if ($step === 'category') : ?>
    <ul class="choice-grid">
        <?php foreach ($categories as $c) : ?>
            <li><a class="choice" href="<?= e($base . '?cat=' . $c['id']) ?>">
                <?= e($c['name']) ?> <span class="choice-count"><?= e($fmt->number((int) $c['parts'])) ?></span>
            </a></li>
        <?php endforeach; ?>
    </ul>
<?php elseif ($step === 'size') : ?>
    <h2><?= e(t('picker.choose_size')) ?></h2>
    <ul class="choice-grid choice-grid-small">
        <?php foreach ($sizes as $s) : ?>
            <li><a class="choice" href="<?= e($base . '?cat=' . $category['id'] . '&size=' . $s['a'] . 'x' . $s['b']) ?>">
                <?= e($s['a'] . ' × ' . $s['b']) ?> <span class="choice-count"><?= e($fmt->number((int) $s['parts'])) ?></span>
            </a></li>
        <?php endforeach; ?>
        <li><a class="choice" href="<?= e($base . '?cat=' . $category['id'] . '&size=other') ?>"><?= e(t('picker.other_sizes')) ?></a></li>
        <li><a class="choice" href="<?= e($base . '?cat=' . $category['id'] . '&size=all') ?>"><?= e(t('picker.all_sizes')) ?></a></li>
    </ul>
<?php else : ?>
    <ul class="tile-list">
        <?php foreach ($parts as $p) : ?>
            <li><a class="tile" href="<?= e(url('/b/' . $id . '/add') . '?part=' . rawurlencode((string) $p['rb_num'])) ?>">
                <img class="part-img" src="<?= e(url('/img') . '?part=' . rawurlencode((string) $p['rb_num']) . '&color=-1') ?>" alt="" width="64" height="64" loading="lazy">
                <strong><?= e($p['bl_num'] ?? $p['rb_num']) ?></strong>
                <span class="tile-name"><?= e($p['name']) ?></span>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($more) : ?>
        <p><a class="button" href="<?= e($base . '?cat=' . $category['id'] . '&size=' . rawurlencode((string) $size) . '&page=' . ($page + 1)) ?>"><?= e(t('picker.more')) ?></a></p>
    <?php endif; ?>
<?php endif; ?>

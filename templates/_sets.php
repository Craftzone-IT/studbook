<?php

declare(strict_types=1);

/**
 * List of owned sets; used on the collection and box pages.
 *
 * @var list<array<string, mixed>> $sets
 * @var bool|null $showBox true shows the box each set is kept in
 */
$showBox ??= false;
?>
<?php if ($sets === []) : ?>
    <p><?= e(t('set.none')) ?></p>
<?php else : ?>
    <ul class="lot-list">
        <?php foreach ($sets as $owned) : ?>
            <li class="lot">
                <img class="set-img" src="<?= e(url('/img') . '?set=' . rawurlencode((string) $owned['set_num'])) ?>" alt="" width="64" height="48" loading="lazy">
                <div class="lot-main">
                    <a href="<?= e(url('/s/' . $owned['id'])) ?>"><strong><?= e($owned['set_num']) ?></strong></a>
                    <?= e($owned['name'] ?? '') ?>
                    <span class="lot-name">
                        <?= e(t('set_state.' . $owned['state'])) ?> · <?= e(t('set_lock.' . $owned['lock_mode'])) ?>
                        <?php if ((int) $owned['deltas'] > 0) : ?> · <?= e(t('set.has_deltas')) ?><?php endif; ?>
                        <?php if ($showBox && $owned['box_name'] !== null) : ?> · <?= e($owned['box_name']) ?><?php endif; ?>
                    </span>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php

declare(strict_types=1);

use Studbook\Http\Csrf;
use Studbook\I18n\Formatter;

/**
 * @var string $title
 * @var Formatter $fmt
 * @var list<array<string, mixed>> $active
 * @var list<array<string, mixed>> $archived
 * @var bool $catalogEmpty
 */
$utc = new \DateTimeZone('UTC');
?>
<h1><?= e($title) ?></h1>

<?php if ($catalogEmpty) : ?>
    <p class="flash flash-info">
        <?= e(t('home.catalog_empty')) ?> <a href="<?= e(url('/admin/import')) ?>"><?= e(t('nav.import')) ?></a>
    </p>
<?php endif; ?>

<?php if ($active === []) : ?>
    <p><?= e(t('home.empty')) ?></p>
<?php endif; ?>

<div class="card-grid">
    <?php foreach ($active as $c) : ?>
        <a class="card collection-card" href="<?= e(url('/c/' . $c['id'])) ?>">
            <h2><?= e($c['name']) ?></h2>
            <dl class="stats">
                <div><dt><?= e(t('collection.stat.sets')) ?></dt><dd><?= e($fmt->number((int) $c['sets'])) ?></dd></div>
                <div><dt><?= e(t('collection.stat.loose_parts')) ?></dt><dd><?= e($fmt->number((int) $c['loose_parts'])) ?></dd></div>
                <div><dt><?= e(t('collection.stat.boxes')) ?></dt><dd><?= e($fmt->number((int) $c['boxes'])) ?></dd></div>
            </dl>
            <p class="hint">
                <?= e(t('collection.last_change', ['time' => $fmt->dateTime(new \DateTimeImmutable((string) $c['last_change'], $utc))])) ?>
                <?php if ((int) $c['can_lend'] === 1) : ?>· <?= e(t('collection.can_lend_short')) ?><?php endif; ?>
            </p>
        </a>
    <?php endforeach; ?>

    <section class="card">
        <h2><?= e(t('collection.new')) ?></h2>
        <form method="post" action="<?= e(url('/collections')) ?>" class="form">
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <label for="new-collection-name"><?= e(t('collection.name')) ?></label>
            <input id="new-collection-name" name="name" type="text" maxlength="100" required
                   placeholder="<?= e(t('collection.name_placeholder')) ?>">
            <label class="checkbox">
                <input type="checkbox" name="can_lend" value="1">
                <?= e(t('collection.can_lend')) ?>
            </label>
            <button type="submit" class="button button-primary"><?= e(t('collection.create')) ?></button>
        </form>
    </section>
</div>

<?php if ($archived !== []) : ?>
    <details class="archived">
        <summary><?= e(t('collection.archived_list', ['count' => count($archived)])) ?></summary>
        <ul class="plain-list">
            <?php foreach ($archived as $c) : ?>
                <li><a href="<?= e(url('/c/' . $c['id'])) ?>"><?= e($c['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

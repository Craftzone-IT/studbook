<?php

declare(strict_types=1);

/**
 * @var \Studbook\Build\BuildOptions|null $options
 * @var list<array{id: int, name: string, can_lend: int}> $collections
 * @var list<array<string, mixed>> $builds active builds
 * @var list<array{id: int, label: string}> $themes
 * @var list<array<string, mixed>> $results
 * @var int $total
 * @var array<string, mixed> $filters
 * @var int $page
 * @var int $pages
 * @var \Studbook\I18n\Formatter $fmt
 */
$filters ??= [];
$query = $options !== null ? $options->toArray() + array_filter($filters, static fn ($v): bool => $v !== 0 && $v !== '' && $v !== false) : [];
if ($options !== null && empty($filters['hide_owned'])) {
    $query['hide_owned'] = '0';
}
$link = static fn (array $extra): string => url('/build') . '?' . http_build_query(array_merge($query, $extra));
$optionQuery = $options !== null ? $options->query() : '';
?>
<h1><?= e(t('build.title')) ?></h1>
<p class="hint"><?= e(t('build.intro')) ?></p>

<?php if ($builds !== []) : ?>
    <section class="card setup-step">
        <h2><?= e(t('build.active')) ?></h2>
        <ul class="lot-list">
            <?php foreach ($builds as $build) : ?>
                <li class="lot">
                    <img class="set-img" src="<?= e(url('/img') . '?set=' . rawurlencode((string) $build['set_num'])) ?>" alt="" width="64" height="48" loading="lazy">
                    <div class="lot-main">
                        <a href="<?= e(url('/builds/' . $build['id'])) ?>"><strong><?= e($build['set_num']) ?></strong></a>
                        <?= e($build['name'] ?? '') ?>
                        <span class="lot-name"><?= e($build['collection_name']) ?></span>
                    </div>
                    <div class="lot-qty"><?= e(t('build.reserved_of', [
                        'reserved' => $fmt->number((int) $build['reserved']),
                        'need' => $fmt->number((int) ($build['need_qty'] ?? 0)),
                    ])) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ($options === null) : ?>
    <p><?= e(t('build.no_collections')) ?></p>
<?php else : ?>
    <form method="get" action="<?= e(url('/build')) ?>" class="card form build-form">
        <?php require __DIR__ . '/_build-options.php'; ?>
        <details<?= ($filters['theme'] ?? 0) || ($filters['year_min'] ?? 0) || ($filters['year_max'] ?? 0) || ($filters['parts_max'] ?? 0) || ($filters['min_pct'] ?? 0) ? ' open' : '' ?>>
            <summary><?= e(t('build.filters')) ?></summary>
            <div class="build-filters">
                <div>
                    <label for="f-theme"><?= e(t('build.theme')) ?></label>
                    <select id="f-theme" name="theme">
                        <option value=""><?= e(t('build.any_theme')) ?></option>
                        <?php foreach ($themes as $theme) : ?>
                            <option value="<?= e($theme['id']) ?>"<?= $theme['id'] === ($filters['theme'] ?? 0) ? ' selected' : '' ?>><?= e($theme['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="f-year-min"><?= e(t('build.years')) ?></label>
                    <span class="range-inputs">
                        <input id="f-year-min" name="year_min" type="number" min="1949" max="2100" inputmode="numeric" value="<?= e(($filters['year_min'] ?? 0) ?: '') ?>" placeholder="<?= e(t('build.from')) ?>">
                        <input name="year_max" type="number" min="1949" max="2100" inputmode="numeric" aria-label="<?= e(t('build.to')) ?>" value="<?= e(($filters['year_max'] ?? 0) ?: '') ?>" placeholder="<?= e(t('build.to')) ?>">
                    </span>
                </div>
                <div>
                    <label for="f-parts-min"><?= e(t('build.parts')) ?></label>
                    <span class="range-inputs">
                        <input id="f-parts-min" name="parts_min" type="number" min="0" inputmode="numeric" value="<?= e($filters['parts_min'] ?? 0) ?>">
                        <input name="parts_max" type="number" min="0" inputmode="numeric" aria-label="<?= e(t('build.to')) ?>" value="<?= e(($filters['parts_max'] ?? 0) ?: '') ?>" placeholder="<?= e(t('build.to')) ?>">
                    </span>
                </div>
                <div>
                    <label for="f-pct"><?= e(t('build.min_pct')) ?></label>
                    <input id="f-pct" name="min_pct" type="number" min="0" max="100" inputmode="numeric" value="<?= e(($filters['min_pct'] ?? 0) ?: '') ?>">
                </div>
            </div>
        </details>
        <div class="build-submit">
            <label class="checkbox">
                <input type="checkbox" name="hide_owned" value="1"<?= !empty($filters['hide_owned']) ? ' checked' : '' ?>>
                <?= e(t('build.hide_owned')) ?>
            </label>
            <label for="f-sort" class="visually-hidden"><?= e(t('build.sort')) ?></label>
            <select id="f-sort" name="sort">
                <?php foreach (\Studbook\Controller\BuildController::SORTS as $sort) : ?>
                    <?php if ($sort !== 'any' || $options->anyColor) : ?>
                        <option value="<?= e($sort) ?>"<?= $sort === ($filters['sort'] ?? 'coverage') ? ' selected' : '' ?>><?= e(t('build.sort_' . $sort)) ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary"><?= e(t('build.submit')) ?></button>
        </div>
    </form>

    <p class="hint"><?= e(t('build.result_count', ['count' => $fmt->number($total)])) ?>
        <span class="legend"><span class="legend-loose"></span><?= e(t('build.legend_loose')) ?>
        <span class="legend-sets"></span><?= e(t('build.legend_sets')) ?></span></p>
    <?php if ($results === []) : ?>
        <p><?= e(t('build.no_results')) ?></p>
    <?php else : ?>
        <ul class="lot-list">
            <?php foreach ($results as $row) : ?>
                <?php $missing = $row['need'] - $row['total']; ?>
                <li class="lot build-row">
                    <img class="set-img" src="<?= e(url('/img') . '?set=' . rawurlencode($row['set_num'])) ?>" alt="" width="64" height="48" loading="lazy">
                    <div class="lot-main">
                        <a href="<?= e(url('/build/set/' . rawurlencode($row['set_num'])) . '?' . $optionQuery) ?>"><strong><?= e($row['set_num']) ?></strong></a>
                        <?= e($row['name']) ?>
                        <?php if ($row['owned']) : ?><span class="badge"><?= e(t('build.owned')) ?></span><?php endif; ?>
                        <span class="lot-name"><?= e(implode(' · ', array_filter([
                            $row['year'] !== null ? (string) $row['year'] : null,
                            t('set.parts_count', ['count' => $fmt->number($row['num_parts'])]),
                            $missing > 0 ? t('build.missing_n', ['count' => $fmt->number($missing)]) : t('build.complete'),
                            $row['any'] !== null ? t('build.any_pct', ['pct' => (int) floor(100 * $row['any'] / $row['need'])]) : null,
                        ]))) ?></span>
                        <?= coverage_bar($row['need'], $row['loose'], $row['total'] - $row['loose'], t('build.bar_label', [
                            'loose' => $fmt->number($row['loose']),
                            'sets' => $fmt->number($row['total'] - $row['loose']),
                            'need' => $fmt->number($row['need']),
                        ])) ?>
                    </div>
                    <div class="lot-qty"><?= e((int) floor(100 * $row['pct'])) ?>%</div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($pages > 1) : ?>
            <nav class="pager" aria-label="<?= e(t('build.pages')) ?>">
                <?php if ($page > 1) : ?><a class="button" href="<?= e($link(['page' => $page - 1])) ?>"><?= e(t('build.prev')) ?></a><?php endif; ?>
                <span><?= e(t('build.page_of', ['page' => $page, 'pages' => $pages])) ?></span>
                <?php if ($page < $pages) : ?><a class="button" href="<?= e($link(['page' => $page + 1])) ?>"><?= e(t('build.next')) ?></a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

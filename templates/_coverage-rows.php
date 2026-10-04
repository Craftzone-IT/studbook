<?php

declare(strict_types=1);

/**
 * Part rows of a coverage or build table.
 *
 * @var list<array<string, mixed>> $rows
 * @var list<array{key: string, label: string}> $columns numeric columns to show
 * @var \Studbook\I18n\Formatter $fmt
 */
$img = static fn (string $part, int $color): string => url('/img') . '?part=' . rawurlencode($part) . '&color=' . $color;
?>
<div class="table-scroll">
    <table class="data-table">
        <thead>
        <tr>
            <th scope="col"><?= e(t('set.part')) ?></th>
            <th scope="col"><?= e(t('box.color')) ?></th>
            <?php foreach ($columns as $column) : ?>
                <th scope="col" class="num"><?= e($column['label']) ?></th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) : ?>
            <tr<?= $row['missing'] > 0 ? ' class="row-missing"' : '' ?>>
                <td>
                    <span class="part-cell">
                        <img class="part-img" src="<?= e($img((string) $row['part'], (int) $row['color_id'])) ?>" alt="" width="40" height="40" loading="lazy">
                        <span>
                            <strong><?= e($row['display']) ?></strong>
                            <span class="lot-name"><?= e($row['part_name']) ?></span>
                            <?php if ((string) $row['g'] !== (string) $row['part']) : ?>
                                <span class="lot-name"><?= e(t('build.same_as', ['part' => $row['g']])) ?></span>
                            <?php endif; ?>
                        </span>
                    </span>
                </td>
                <td><?= swatch((string) $row['rgb']) ?> <?= e($row['color_name']) ?></td>
                <?php foreach ($columns as $column) : ?>
                    <td class="num"><?= (int) $row[$column['key']] !== 0 ? e($fmt->number((int) $row[$column['key']])) : '' ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

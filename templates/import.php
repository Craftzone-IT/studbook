<?php

declare(strict_types=1);

use Studbook\Http\Csrf;
use Studbook\I18n\Formatter;

/**
 * @var string $title
 * @var Formatter $fmt
 * @var list<array<string, mixed>> $runs
 * @var array<string, mixed>|null $report
 * @var array<string, mixed>|null $pending
 * @var \DateTimeImmutable|null $cronSeenAt
 * @var bool $cronOk
 */
$utc = new \DateTimeZone('UTC');
$when = static function (?string $value) use ($fmt, $utc): string {
    return $value === null ? '–' : $fmt->dateTime(new \DateTimeImmutable($value, $utc));
};
$stats = $report['stats'] ?? [];
?>
<h1><?= e($title) ?></h1>

<section class="card setup-step">
    <h2><?= e(t('import.status')) ?></h2>
    <?php if ($cronOk) : ?>
        <p><?= e(t('import.cron_ok', ['time' => $fmt->dateTime($cronSeenAt)])) ?></p>
    <?php else : ?>
        <p class="flash flash-error">
            <?= e($cronSeenAt === null
                ? t('import.cron_never')
                : t('import.cron_stale', ['time' => $fmt->dateTime($cronSeenAt)])) ?>
        </p>
        <pre class="code">*/15 * * * * php <?= e(dirname(__DIR__)) ?>/bin/import --cron --quiet</pre>
    <?php endif; ?>

    <?php if ($pending !== null) : ?>
        <p><?= e(t('import.pending_' . $pending['status'])) ?></p>
    <?php else : ?>
        <form method="post" action="<?= e(url('/admin/import/run')) ?>">
            <input type="hidden" name="<?= e(Csrf::FIELD) ?>" value="<?= e(Csrf::token()) ?>">
            <button type="submit" class="button button-primary"><?= e(t('import.run_now')) ?></button>
        </form>
        <p class="hint"><?= e(t('import.run_now_hint')) ?></p>
    <?php endif; ?>
</section>

<?php if ($report !== null) : ?>
    <section class="card setup-step">
        <h2><?= e(t('import.report', ['time' => $when($report['finished_at'])])) ?></h2>
        <table class="data-table">
            <tbody>
            <?php foreach (['cat_part' => 'import.count.parts', 'cat_color' => 'import.count.colors',
                'cat_set' => 'import.count.sets', 'cat_minifig' => 'import.count.minifigs',
                'cat_part_color' => 'import.count.part_colors', 'cat_inventory' => 'import.count.inventory'] as $table => $key) : ?>
                <tr>
                    <th scope="row"><?= e(t($key)) ?></th>
                    <td class="num"><?= e($fmt->number((int) ($stats['counts'][$table] ?? 0))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3><?= e(t('import.bricklink')) ?></h3>
        <?php $apiUsed = ($stats['api']['parts'] ?? 0) > 0; ?>
        <?php if (!$apiUsed) : ?>
            <p class="flash flash-info"><?= e(t('import.api_hint')) ?></p>
        <?php endif; ?>
        <?php if (!$apiUsed && (($stats['bricklink']['parts'] ?? 0) === 0 || ($stats['bricklink']['colors'] ?? 0) === 0)) : ?>
            <p class="flash flash-error"><?= e(t('import.bricklink_missing')) ?></p>
        <?php endif; ?>
        <table class="data-table">
            <tbody>
            <tr>
                <th scope="row"><?= e(t('import.match.colors')) ?></th>
                <td class="num"><?= e(t('import.match.ratio', [
                    'matched' => $fmt->number((int) ($stats['colors']['matched'] ?? 0)),
                    'total' => $fmt->number((int) ($stats['colors']['total'] ?? 0)),
                ])) ?></td>
            </tr>
            <?php if ($apiUsed) : ?>
                <tr>
                    <th scope="row"><?= e(t('import.match.parts_api')) ?></th>
                    <td class="num"><?= e($fmt->number((int) ($stats['parts']['matched_api'] ?? 0))) ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><?= e(t('import.match.parts_exact')) ?></th>
                <td class="num"><?= e($fmt->number((int) ($stats['parts']['matched_exact'] ?? 0))) ?></td>
            </tr>
            <tr>
                <th scope="row"><?= e(t('import.match.parts_alternate')) ?></th>
                <td class="num"><?= e($fmt->number((int) ($stats['parts']['matched_alternate'] ?? 0))) ?></td>
            </tr>
            <tr>
                <th scope="row"><?= e(t('import.match.parts_unmatched')) ?></th>
                <td class="num"><?= e($fmt->number((int) ($stats['parts']['unmatched'] ?? 0))) ?></td>
            </tr>
            </tbody>
        </table>
        <p class="hint"><?= e(t('import.match.hint')) ?></p>

        <?php if (($stats['colors']['unmatched'] ?? []) !== [] && ($apiUsed || ($stats['bricklink']['colors'] ?? 0) > 0)) : ?>
            <details>
                <summary><?= e(t('import.unmatched_colors', ['count' => count($stats['colors']['unmatched'])])) ?></summary>
                <p><?= e(implode(', ', $stats['colors']['unmatched'])) ?></p>
            </details>
        <?php endif; ?>
        <?php if (($stats['parts']['unmatched_samples'] ?? []) !== [] && ($apiUsed || ($stats['bricklink']['parts'] ?? 0) > 0)) : ?>
            <details>
                <summary><?= e(t('import.unmatched_parts')) ?></summary>
                <ul class="file-list">
                    <?php foreach ($stats['parts']['unmatched_samples'] as $part) : ?>
                        <li><code><?= e($part['rb_num']) ?></code> <?= e($part['name']) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card setup-step">
    <h2><?= e(t('import.runs')) ?></h2>
    <?php if ($runs === []) : ?>
        <p><?= e(t('import.no_runs')) ?></p>
    <?php else : ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                <tr>
                    <th scope="col"><?= e(t('import.col.started')) ?></th>
                    <th scope="col"><?= e(t('import.col.trigger')) ?></th>
                    <th scope="col"><?= e(t('import.col.status')) ?></th>
                    <th scope="col" class="num"><?= e(t('import.col.duration')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($runs as $run) : ?>
                    <tr>
                        <td><?= e($when($run['started_at'] ?? $run['requested_at'])) ?></td>
                        <td><?= e(t('import.trigger.' . $run['trigger_type'])) ?></td>
                        <td><span class="badge badge-<?= e($run['status']) ?>"><?= e(t('import.state.' . $run['status'])) ?></span></td>
                        <td class="num"><?= isset($run['stats']['duration_seconds'])
                            ? e(t('import.seconds', ['n' => $run['stats']['duration_seconds']])) : '–' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php $last = $runs[0]; ?>
        <?php if (($last['log'] ?? '') !== '') : ?>
            <details<?= $last['status'] === 'failed' ? ' open' : '' ?>>
                <summary><?= e(t('import.last_log')) ?></summary>
                <pre class="code log"><?= e($last['log']) ?></pre>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</section>

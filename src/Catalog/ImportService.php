<?php

declare(strict_types=1);

namespace Studbook\Catalog;

use PDO;
use Studbook\Config;
use Studbook\SettingRepository;

/**
 * Runs catalogue imports one at a time (MySQL named lock), records them in
 * `import_run`, and decides what the cron job should do.
 */
final class ImportService
{
    public const LOCK = 'studbook_catalog_import';
    public const CRON_SEEN_SETTING = 'import_cron_seen_at';
    private const SOURCE_URL = 'https://github.com/Craftzone-IT/studbook';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly ImportRunRepository $runs,
    ) {
    }

    /**
     * Cron entry point: runs a queued import, or a scheduled one when the
     * last successful import is older than IMPORT_SCHEDULE_DAYS.
     *
     * @param callable(string): void $output
     * @return string|null the run's status, or null when there was nothing to do
     */
    public function cron(callable $output): ?string
    {
        (new SettingRepository($this->pdo))->set(self::CRON_SEEN_SETTING, gmdate('Y-m-d H:i:s'));
        $queued = $this->runs->nextQueued();
        if ($queued !== null) {
            return $this->execute($queued, $output);
        }
        $last = $this->runs->lastSuccessAt();
        $days = max(1, $this->config->int('IMPORT_SCHEDULE_DAYS', 7));
        if ($last !== null && $last > new \DateTimeImmutable("-{$days} days", new \DateTimeZone('UTC'))) {
            return null;
        }

        return $this->execute($this->runs->queue('cron'), $output);
    }

    /**
     * Runs an import right away (CLI).
     *
     * @param callable(string): void $output
     */
    public function runNow(string $trigger, callable $output): string
    {
        return $this->execute($this->runs->queue($trigger), $output);
    }

    /** @param callable(string): void $output */
    private function execute(int $runId, callable $output): string
    {
        if ((int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK . "', 0)")->fetchColumn() !== 1) {
            $output('Another import is running; nothing to do.');

            return ImportRunRepository::RUNNING;
        }
        $lines = [];
        $log = static function (string $message) use (&$lines, $output): void {
            $line = gmdate('H:i:s') . ' ' . $message;
            $lines[] = $line;
            $output($line);
        };
        try {
            $this->runs->failAbandoned();
            $this->runs->start($runId);
            $started = microtime(true);
            $log('Import started.');
            try {
                $downloader = new RebrickableDownloader(
                    $this->config->get('REBRICKABLE_DOWNLOAD_BASE', 'https://cdn.rebrickable.com/media/downloads/'),
                    $this->config->path('CATALOG_DOWNLOAD_PATH', 'storage/catalog/rebrickable'),
                    $this->config->int('IMPORT_MIN_INTERVAL_HOURS', 24),
                    'Studbook (+' . $this->config->get('APP_SOURCE_URL', self::SOURCE_URL) . ')'
                );
                $files = $downloader->fetchAll($log);
                $bricklink = BrickLinkCatalog::load(
                    $this->config->path('BRICKLINK_FILES_PATH', 'storage/catalog/bricklink')
                );
                $stats = (new CatalogImporter($this->pdo, $log))->import($files, $bricklink);
                $stats['duration_seconds'] = (int) round(microtime(true) - $started);
                $log(sprintf('Import finished in %d s.', $stats['duration_seconds']));
                $this->runs->finish($runId, ImportRunRepository::SUCCESS, implode("\n", $lines), $stats);

                return ImportRunRepository::SUCCESS;
            } catch (\Throwable $e) {
                $log('ERROR ' . $e->getMessage());
                $this->runs->finish($runId, ImportRunRepository::FAILED, implode("\n", $lines), []);

                return ImportRunRepository::FAILED;
            }
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
        }
    }
}

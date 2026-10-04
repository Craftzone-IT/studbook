<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use Studbook\Catalog\ImportRunRepository;
use Studbook\Catalog\ImportService;
use Studbook\Config;
use Studbook\Database\Connection;
use Studbook\SettingRepository;
use Studbook\Tests\DatabaseTestCase;

final class ImportServiceTest extends DatabaseTestCase
{
    private string $dir;
    private ImportRunRepository $runs;
    private ImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->dir = CatalogFixtures::downloadFolder();
        $this->runs = new ImportRunRepository($this->pdo);
        $this->service = new ImportService($this->pdo, new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'x',
            'DB_NAME' => 'x',
            'DB_USER' => 'x',
            'DB_PASSWORD' => '',
            // Unreachable on purpose: the fresh fixture files must be reused, never downloaded.
            'REBRICKABLE_DOWNLOAD_BASE' => 'http://127.0.0.1:1/',
            'CATALOG_DOWNLOAD_PATH' => $this->dir,
            'BRICKLINK_FILES_PATH' => dirname(__DIR__) . '/fixtures/bricklink',
            'IMPORT_SCHEDULE_DAYS' => '7',
        ], dirname(__DIR__, 2)), $this->runs);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            CatalogFixtures::remove($this->dir);
        }
    }

    public function testRunNowRecordsASuccessfulRun(): void
    {
        self::assertSame(ImportRunRepository::SUCCESS, $this->service->runNow('cli', static fn () => null));

        $run = $this->runs->latest(1)[0];
        self::assertSame('success', $run['status']);
        self::assertSame('cli', $run['trigger_type']);
        self::assertSame(6, $run['stats']['counts']['cat_part']);
        self::assertStringContainsString('Import finished', (string) $run['log']);
    }

    public function testCronRunsWhenNeverImportedThenWaitsForTheSchedule(): void
    {
        self::assertSame(ImportRunRepository::SUCCESS, $this->service->cron(static fn () => null));
        self::assertNull($this->service->cron(static fn () => null));
        self::assertCount(1, $this->runs->latest());
        self::assertNotNull((new SettingRepository($this->pdo))->get(ImportService::CRON_SEEN_SETTING));

        $this->pdo->exec("UPDATE import_run SET finished_at = UTC_TIMESTAMP() - INTERVAL 8 DAY");
        self::assertSame(ImportRunRepository::SUCCESS, $this->service->cron(static fn () => null));
        self::assertSame('cron', $this->runs->latest(1)[0]['trigger_type']);
    }

    public function testCronRunsAQueuedImport(): void
    {
        $this->service->runNow('cli', static fn () => null);
        $id = $this->runs->queue('manual');
        self::assertSame($id, $this->runs->queue('manual'), 'queueing twice does not add a second run');

        self::assertSame(ImportRunRepository::SUCCESS, $this->service->cron(static fn () => null));
        self::assertSame('manual', $this->runs->latest(1)[0]['trigger_type']);
        self::assertNull($this->runs->nextQueued());
    }

    public function testUnreachableApiDoesNotStopTheImport(): void
    {
        $service = new ImportService($this->pdo, new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'x',
            'DB_NAME' => 'x',
            'DB_USER' => 'x',
            'DB_PASSWORD' => '',
            'REBRICKABLE_DOWNLOAD_BASE' => 'http://127.0.0.1:1/',
            'REBRICKABLE_API_KEY' => 'secret',
            'REBRICKABLE_API_BASE' => 'https://127.0.0.1:1/api/v3/',
            'CATALOG_DOWNLOAD_PATH' => $this->dir,
            'BRICKLINK_FILES_PATH' => dirname(__DIR__) . '/fixtures/bricklink',
        ], dirname(__DIR__, 2)), $this->runs);

        self::assertSame(ImportRunRepository::SUCCESS, $service->runNow('cli', static fn () => null));
        $run = $this->runs->latest(1)[0];
        self::assertStringContainsString('WARNING Rebrickable API api_parts failed', (string) $run['log']);
        self::assertSame(2, $run['stats']['parts']['matched_exact']);
    }

    public function testCommandLineRunTakingOverAQueuedRunIsRecordedAsCommandLine(): void
    {
        $id = $this->runs->queue('manual');

        $this->service->runNow('cli', static fn () => null);

        $run = $this->runs->latest(1)[0];
        self::assertSame($id, (int) $run['id']);
        self::assertSame('cli', $run['trigger_type']);
    }

    public function testCronLeavesNothingQueuedWhileAnotherImportRuns(): void
    {
        $other = Connection::create(
            (string) getenv('STUDBOOK_TEST_DB_DSN'),
            (string) getenv('STUDBOOK_TEST_DB_USER'),
            (string) getenv('STUDBOOK_TEST_DB_PASSWORD')
        );
        $other->query("SELECT GET_LOCK('" . ImportService::LOCK . "', 0)");
        try {
            self::assertNull($this->service->cron(static fn () => null));
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . ImportService::LOCK . "')");
        }

        self::assertSame([], $this->runs->latest());
    }

    public function testFailedRunIsRecorded(): void
    {
        unlink($this->dir . '/parts.csv.gz');

        self::assertSame(ImportRunRepository::FAILED, $this->service->runNow('cli', static fn () => null));
        $run = $this->runs->latest(1)[0];
        self::assertSame('failed', $run['status']);
        self::assertStringContainsString('ERROR', (string) $run['log']);
    }

    public function testAbandonedRunIsMarkedFailed(): void
    {
        $id = $this->runs->queue('cli');
        $this->runs->start($id);

        $this->service->runNow('cli', static fn () => null);

        $statuses = array_column($this->runs->latest(), 'status', 'id');
        self::assertSame('failed', $statuses[$id]);
    }
}

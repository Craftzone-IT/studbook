<?php

declare(strict_types=1);

namespace Studbook\Controller;

use PDO;
use Studbook\Catalog\ImportRunRepository;
use Studbook\Catalog\ImportService;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\SettingRepository;
use Studbook\View;

/** Admin page for the catalogue import: status, last runs, BrickLink match report, "Run import now". */
final class ImportController
{
    /** Cron counts as working when it ran within this many minutes. */
    private const CRON_FRESH_MINUTES = 90;

    /** @var \Closure(): PDO */
    private \Closure $pdo;

    /** @param callable(): PDO $pdo */
    public function __construct(private readonly View $view, callable $pdo)
    {
        $this->pdo = \Closure::fromCallable($pdo);
    }

    public function show(Request $request): Response
    {
        $pdo = ($this->pdo)();
        $runs = (new ImportRunRepository($pdo))->latest(10);
        $report = null;
        foreach ($runs as $run) {
            if ($run['status'] === ImportRunRepository::SUCCESS) {
                $report = $run;
                break;
            }
        }
        $cronSeen = (new SettingRepository($pdo))->get(ImportService::CRON_SEEN_SETTING);
        $utc = new \DateTimeZone('UTC');
        $cronSeenAt = $cronSeen !== null ? new \DateTimeImmutable($cronSeen, $utc) : null;
        $pending = array_values(array_filter(
            $runs,
            static fn (array $r): bool => in_array(
                $r['status'],
                [ImportRunRepository::QUEUED, ImportRunRepository::RUNNING],
                true
            )
        ));

        return Response::html($this->view->render('import', [
            'title' => t('import.title'),
            'runs' => $runs,
            'report' => $report,
            'pending' => $pending[0] ?? null,
            'cronSeenAt' => $cronSeenAt,
            'cronOk' => $cronSeenAt !== null
                && $cronSeenAt > new \DateTimeImmutable('-' . self::CRON_FRESH_MINUTES . ' minutes', $utc),
        ]));
    }

    public function run(Request $request): Response
    {
        (new ImportRunRepository(($this->pdo)()))->queue('manual');
        Session::flash('success', t('import.queued'));

        return Response::redirect(url('/admin/import'));
    }
}

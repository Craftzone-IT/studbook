<?php

declare(strict_types=1);

namespace Studbook\Catalog;

use PDO;

/**
 * Downloads pictures ahead of time, from cron, so pages open with them
 * already cached: what the user owns first (loose lots, box labels, set
 * pictures), then the contents of owned sets. A limited number per run,
 * one at a time with a pause, so Rebrickable's image server is not hammered.
 */
final class ImageWarmer
{
    public const DEFAULT_PER_RUN = 200;

    /** @var \Closure(): void */
    private \Closure $pause;

    /** @param (callable(): void)|null $pause between downloads; 0.3 s by default */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ImageCache $cache,
        private readonly int $perRun = self::DEFAULT_PER_RUN,
        ?callable $pause = null,
    ) {
        $this->pause = $pause !== null ? \Closure::fromCallable($pause) : static fn () => usleep(300_000);
    }

    /**
     * @param callable(string): void $log
     * @return int pictures downloaded
     */
    public function run(callable $log): int
    {
        if ($this->perRun <= 0) {
            return 0;
        }
        $fetched = 0;
        foreach ($this->candidates() as [$part, $color]) {
            $image = $this->cache->get($part, $color);
            if ($image === null && $this->cache->lastMiss === 'busy') {
                break; // the web pages are downloading right now; try again next run
            }
            if ($image !== null) {
                $fetched++;
            }
            ($this->pause)();
        }
        if ($fetched > 0) {
            $log(sprintf('Pictures downloaded in advance: %d', $fetched));
        }

        return $fetched;
    }

    /** @return list<array{0: string, 1: int}> part (or set number) and colour id, most useful first */
    public function candidates(): array
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT x.part, x.color_id, MIN(x.priority) AS priority FROM (
                SELECT part, color_id, 1 AS priority FROM loose_lot
                UNION ALL SELECT part, %d, 2 FROM storage_label
                UNION ALL SELECT set_num, %d, 3 FROM owned_set
                UNION ALL SELECT i.part, i.color_id, 4 FROM owned_set o
                    JOIN cat_inventory i ON i.set_num = o.set_num AND i.is_spare = 0
             ) x
             LEFT JOIN cat_image_cache c ON c.part = x.part AND c.color_id = x.color_id
             WHERE c.part IS NULL
                OR (c.status = 'error' AND c.fetched_at < UTC_TIMESTAMP() - INTERVAL %d MINUTE)
                OR (c.status = 'missing' AND c.fetched_at < UTC_TIMESTAMP() - INTERVAL %d HOUR)
             GROUP BY x.part, x.color_id
             ORDER BY priority, x.part, x.color_id
             LIMIT %d",
            ImageCache::ANY_COLOR,
            ImageCache::SET_IMAGE,
            ImageCache::RETRY_ERROR_MINUTES,
            ImageCache::RETRY_MISSING_HOURS,
            max(1, $this->perRun)
        ));
        $stmt->execute();

        return array_map(
            static fn (array $r): array => [(string) $r['part'], (int) $r['color_id']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}

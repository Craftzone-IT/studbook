<?php

declare(strict_types=1);

namespace Studbook\Catalog;

/**
 * Reads the official BrickLink ids of all parts and colours from the
 * Rebrickable API (`/lego/parts/?inc_part_details=1` and `/lego/colors/`),
 * which the CSV downloads do not contain. Needs the user's own free API key.
 *
 * Results are cached as JSON next to the CSV downloads and fetched again at
 * most once per day (like the downloads). Rebrickable answers a page of 1,000
 * parts in about 17 seconds, so a full refresh takes about 20 minutes; every
 * page is logged so the import does not look stuck. Requests are spaced out and HTTP
 * 429 responses are honoured. If fetching fails, an older cache is used.
 */
final class RebrickableApi
{
    public const PAGE_SIZE = 1000;
    private const MAX_PAGES = 500;
    private const MAX_RETRIES = 3;
    private const PAUSE_SECONDS = 1.1;

    /** @var \Closure(string, array<string, string>): array{status: int, body: string, retry_after: ?int} */
    private \Closure $fetch;
    /** @var \Closure(float): void */
    private \Closure $sleep;
    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param (callable(string, array<string, string>): array{status: int, body: string, retry_after: ?int})|null $fetch
     *        HTTP GET with headers; tests inject a fake
     * @param (callable(float $seconds): void)|null $sleep
     * @param (callable(): int)|null $clock
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $cacheDirectory,
        private readonly int $minIntervalHours,
        private readonly string $userAgent = 'Studbook',
        ?callable $fetch = null,
        ?callable $sleep = null,
        ?callable $clock = null,
    ) {
        $this->fetch = $fetch !== null ? \Closure::fromCallable($fetch) : $this->httpFetch(...);
        $this->sleep = $sleep !== null
            ? \Closure::fromCallable($sleep)
            : static function (float $seconds): void {
                usleep((int) ($seconds * 1_000_000));
            };
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /**
     * BrickLink item numbers per Rebrickable part number (first = preferred).
     *
     * @param callable(string): void $log
     * @return array<string, list<string>>
     */
    public function partIds(callable $log): array
    {
        $map = static function (array $row): ?array {
            $num = $row['part_num'] ?? null;
            $ids = $row['external_ids']['BrickLink'] ?? null;
            if (!is_string($num) || !is_array($ids)) {
                return null;
            }
            $ids = array_values(array_filter(array_map('strval', $ids), static fn (string $id): bool => $id !== ''));

            return $ids === [] ? null : [$num, $ids];
        };

        return $this->cached('api_parts', 'lego/parts/?inc_part_details=1', $log, $map);
    }

    /**
     * BrickLink colour ids and names per Rebrickable colour id.
     *
     * @param callable(string): void $log
     * @return array<string, array{ids: list<int>, names: list<string>}> keyed by Rebrickable id as string
     */
    public function colorIds(callable $log): array
    {
        return $this->cached('api_colors', 'lego/colors/', $log, static function (array $row): ?array {
            $id = $row['id'] ?? null;
            $bl = $row['external_ids']['BrickLink'] ?? null;
            if (!is_int($id) || !is_array($bl) || !is_array($bl['ext_ids'] ?? null)) {
                return null;
            }
            $ids = array_values(array_map('intval', $bl['ext_ids']));
            $names = [];
            foreach ((array) ($bl['ext_descrs'] ?? []) as $descr) {
                $names[] = is_array($descr) ? (string) ($descr[0] ?? '') : (string) $descr;
            }

            return $ids === [] ? null : [(string) $id, ['ids' => $ids, 'names' => $names]];
        });
    }

    /**
     * @param callable(string): void $log
     * @param callable(array<string, mixed>): ?array{0: string, 1: mixed} $map turns one API row into [key, value]
     * @return array<string, mixed>
     */
    private function cached(string $name, string $endpoint, callable $log, callable $map): array
    {
        $path = $this->cacheDirectory . '/' . $name . '.json';
        $maxAge = max(24, $this->minIntervalHours) * 3600;
        if (is_file($path) && ($this->clock)() - (int) filemtime($path) < $maxAge) {
            $when = gmdate('Y-m-d H:i', (int) filemtime($path));
            $log(sprintf('Rebrickable API %s: using cached result from %s', $name, $when));

            return $this->readCache($path);
        }
        try {
            $result = [];
            $url = rtrim($this->baseUrl, '/') . '/' . $endpoint
                . (str_contains($endpoint, '?') ? '&' : '?') . 'page_size=' . self::PAGE_SIZE;
            for ($page = 0; $url !== null; $page++) {
                if ($page >= self::MAX_PAGES) {
                    throw new ImportException('too many pages');
                }
                if ($page > 0) {
                    ($this->sleep)(self::PAUSE_SECONDS);
                }
                $data = $this->request($url);
                $pages = (int) ceil(max(1, (int) ($data['count'] ?? 0)) / self::PAGE_SIZE);
                $log(sprintf('Rebrickable API %s: page %d/%d', $name, $page + 1, $pages));
                foreach ((array) ($data['results'] ?? []) as $row) {
                    $pair = is_array($row) ? $map($row) : null;
                    if ($pair !== null) {
                        $result[$pair[0]] = $pair[1];
                    }
                }
                $url = $this->nextUrl($data['next'] ?? null);
            }
            if (!is_dir($this->cacheDirectory)) {
                @mkdir($this->cacheDirectory, 0775, true);
            }
            file_put_contents($path, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            touch($path, ($this->clock)());
            $log(sprintf('Rebrickable API %s: %d entries with BrickLink ids (%d pages)', $name, count($result), $page));

            return $result;
        } catch (\Throwable $e) {
            if (is_file($path)) {
                $log(sprintf(
                    'WARNING Rebrickable API %s failed (%s); using the older cached result',
                    $name,
                    $e->getMessage()
                ));

                return $this->readCache($path);
            }
            $log(sprintf(
                'WARNING Rebrickable API %s failed (%s); BrickLink ids come from the BrickLink files only',
                $name,
                $e->getMessage()
            ));

            return [];
        }
    }

    /** @return array<string, mixed> */
    private function request(string $url): array
    {
        $headers = ['Authorization' => 'key ' . $this->apiKey, 'Accept' => 'application/json'];
        for ($attempt = 0;; $attempt++) {
            $response = ($this->fetch)($url, $headers);
            if ($response['status'] === 429 && $attempt < self::MAX_RETRIES) {
                ($this->sleep)((float) min(60, max(1, $response['retry_after'] ?? 5)));
                continue;
            }
            if ($response['status'] === 401 || $response['status'] === 403) {
                throw new ImportException('the API key was rejected (HTTP ' . $response['status'] . ')');
            }
            if ($response['status'] !== 200) {
                throw new ImportException('HTTP ' . $response['status']);
            }
            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                throw new ImportException('invalid JSON');
            }

            return $data;
        }
    }

    /** Follows `next` only on the API's own host. */
    private function nextUrl(mixed $next): ?string
    {
        if (!is_string($next) || $next === '') {
            return null;
        }
        $expected = parse_url($this->baseUrl, PHP_URL_HOST);
        if (parse_url($next, PHP_URL_HOST) !== $expected || parse_url($next, PHP_URL_SCHEME) !== 'https') {
            throw new ImportException('unexpected next page URL');
        }

        return $next;
    }

    /** @return array<string, mixed> */
    private function readCache(string $path): array
    {
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /** @return array{status: int, body: string, retry_after: ?int} */
    private function httpFetch(string $url, array $headers): array
    {
        if (!extension_loaded('curl')) {
            throw new ImportException('the Rebrickable API needs the PHP curl extension');
        }
        $retryAfter = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => array_map(
                static fn (string $k, string $v): string => $k . ': ' . $v,
                array_keys($headers),
                $headers
            ),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = (int) trim(substr($line, 12));
                }

                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new ImportException($error !== '' ? $error : 'HTTP request failed');
        }

        return ['status' => $status, 'body' => $body, 'retry_after' => $retryAfter];
    }
}

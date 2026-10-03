<?php

declare(strict_types=1);

namespace Studbook\Catalog;

/**
 * Fetches Rebrickable's CSV downloads into a local folder. A file is
 * downloaded again only when the local copy is older than the configured
 * minimum interval (Rebrickable allows at most one download per day). If a
 * download fails, an existing local copy is used and a warning is logged.
 */
final class RebrickableDownloader
{
    public const FILES = [
        'themes',
        'colors',
        'part_categories',
        'parts',
        'part_relationships',
        'elements',
        'sets',
        'minifigs',
        'inventories',
        'inventory_parts',
        'inventory_sets',
        'inventory_minifigs',
    ];

    /** @var \Closure(string, string): void */
    private \Closure $fetch;
    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param (callable(string $url, string $target): void)|null $fetch throws on failure
     * @param (callable(): int)|null $clock current Unix time
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $directory,
        private readonly int $minIntervalHours,
        private readonly string $userAgent = 'Studbook',
        ?callable $fetch = null,
        ?callable $clock = null,
    ) {
        $this->fetch = $fetch !== null ? \Closure::fromCallable($fetch) : $this->httpFetch(...);
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /**
     * @param callable(string): void $log
     * @return array<string, string> file key => local path
     */
    public function fetchAll(callable $log): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new ImportException(sprintf('Cannot create download folder %s', $this->directory));
        }
        $paths = [];
        $maxAge = max(24, $this->minIntervalHours) * 3600;
        foreach (self::FILES as $key) {
            $path = $this->path($key);
            if (is_file($path) && ($this->clock)() - (int) filemtime($path) < $maxAge) {
                $log(sprintf('%s.csv.gz: using local copy from %s', $key, gmdate('Y-m-d H:i', (int) filemtime($path))));
                $paths[$key] = $path;
                continue;
            }
            $url = rtrim($this->baseUrl, '/') . '/' . $key . '.csv.gz';
            $tmp = $path . '.part';
            try {
                ($this->fetch)($url, $tmp);
                self::assertGzip($tmp);
                if (!rename($tmp, $path)) {
                    throw new ImportException('cannot move the downloaded file into place');
                }
                touch($path, ($this->clock)());
                $log(sprintf('%s.csv.gz: downloaded (%s KB)', $key, number_format((int) filesize($path) / 1024)));
            } catch (\Throwable $e) {
                @unlink($tmp);
                if (!is_file($path)) {
                    throw new ImportException(sprintf('Download of %s failed: %s', $url, $e->getMessage()), 0, $e);
                }
                $log(sprintf(
                    'WARNING %s.csv.gz: download failed (%s); using the older local copy',
                    $key,
                    $e->getMessage()
                ));
            }
            $paths[$key] = $path;
        }

        return $paths;
    }

    public function path(string $key): string
    {
        return $this->directory . '/' . $key . '.csv.gz';
    }

    private static function assertGzip(string $path): void
    {
        $handle = fopen($path, 'rb');
        $magic = $handle !== false ? fread($handle, 2) : false;
        if ($handle !== false) {
            fclose($handle);
        }
        if ($magic !== "\x1f\x8b") {
            throw new ImportException('the response is not a gzip file');
        }
    }

    private function httpFetch(string $url, string $target): void
    {
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new ImportException(sprintf('Cannot write %s', $target));
        }
        try {
            if (extension_loaded('curl')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_FILE => $out,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => 20,
                    CURLOPT_TIMEOUT => 600,
                    CURLOPT_USERAGENT => $this->userAgent,
                    CURLOPT_FAILONERROR => true,
                ]);
                $ok = curl_exec($ch);
                $error = curl_error($ch);
                curl_close($ch);
                if ($ok !== true) {
                    throw new ImportException($error !== '' ? $error : 'HTTP request failed');
                }

                return;
            }
            $context = stream_context_create(['http' => [
                'timeout' => 600,
                'user_agent' => $this->userAgent,
                'follow_location' => 1,
            ]]);
            $in = @fopen($url, 'rb', false, $context);
            if ($in === false) {
                throw new ImportException('HTTP request failed (is allow_url_fopen or ext-curl available?)');
            }
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
            }
        } finally {
            fclose($out);
        }
    }
}

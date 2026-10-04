<?php

declare(strict_types=1);

namespace Studbook\Catalog;

use PDO;

/**
 * Part and set images, fetched from Rebrickable on first display and served from
 * IMAGE_CACHE_PATH afterwards. A missing image (no URL, HTTP 404) is
 * retried after a week, a temporary failure (timeout, server error) after an
 * hour, so neither causes a request on every page view.
 *
 * At most MAX_PARALLEL downloads run at a time. A request that finds all
 * download slots taken gets no image at once (`lastMiss` = `busy`) instead
 * of waiting, so a page full of new images cannot tie up every PHP worker.
 */
final class ImageCache
{
    /** Colour id meaning "any colour" (box labels show the part, not a colour). */
    public const ANY_COLOR = -1;
    /** Colour id under which a set picture is cached (the "part" is the set number). */
    public const SET_IMAGE = -2;
    public const RETRY_MISSING_HOURS = 24 * 7;
    public const RETRY_ERROR_HOURS = 1;
    public const MAX_BYTES = 2_000_000;
    public const MAX_PARALLEL = 3;
    private const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** @var \Closure(string): array{body: string, content_type: string} */
    private \Closure $fetch;
    /** Why the last get() returned null: `missing` (no image exists), `error` or `busy` (try again later). */
    public ?string $lastMiss = null;

    /**
     * @param (callable(string $url): array{body: string, content_type: string})|null $fetch
     *        throws {@see ImageNotFound} for a missing image, anything else for a temporary failure
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
        private readonly string $userAgent = 'Studbook',
        ?callable $fetch = null,
    ) {
        $this->fetch = $fetch !== null ? \Closure::fromCallable($fetch) : $this->httpFetch(...);
    }

    /** @return array{path: string, content_type: string}|null null when no image is available */
    public function get(string $part, int $colorId): ?array
    {
        $this->lastMiss = null;
        $stmt = $this->pdo->prepare(
            'SELECT file, content_type, status, fetched_at FROM cat_image_cache WHERE part = ? AND color_id = ?'
        );
        $stmt->execute([$part, $colorId]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($cached)) {
            if ($cached['status'] === 'ok' && is_file($this->directory . '/' . $cached['file'])) {
                return [
                    'path' => $this->directory . '/' . $cached['file'],
                    'content_type' => (string) $cached['content_type'],
                ];
            }
            $hours = $cached['status'] === 'missing' ? self::RETRY_MISSING_HOURS : self::RETRY_ERROR_HOURS;
            $retryAfter = strtotime((string) $cached['fetched_at'] . ' UTC') + $hours * 3600;
            if ($cached['status'] !== 'ok' && time() < $retryAfter) {
                $this->lastMiss = $cached['status'] === 'missing' ? 'missing' : 'error';

                return null;
            }
        }

        $url = $this->sourceUrl($part, $colorId);
        if ($url === null) {
            $this->remember($part, $colorId, null, null, 'missing');
            $this->lastMiss = 'missing';

            return null;
        }
        $slot = $this->takeSlot();
        if ($slot === null) {
            $this->lastMiss = 'busy';

            return null;
        }
        try {
            $image = ($this->fetch)($url);
            $type = strtolower(trim(explode(';', $image['content_type'])[0]));
            $size = strlen($image['body']);
            if (!isset(self::ALLOWED_TYPES[$type]) || $size === 0 || $size > self::MAX_BYTES) {
                throw new \RuntimeException('unexpected response');
            }
            $file = substr(hash('sha256', $part . '|' . $colorId), 0, 2) . '/'
                . hash('sha256', $part . '|' . $colorId) . '.' . self::ALLOWED_TYPES[$type];
            $path = $this->directory . '/' . $file;
            if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                throw new \RuntimeException('cannot create cache folder');
            }
            if (file_put_contents($path, $image['body']) === false) {
                throw new \RuntimeException('cannot write cache file');
            }
            $this->remember($part, $colorId, $file, $type, 'ok');

            return ['path' => $path, 'content_type' => $type];
        } catch (ImageNotFound) {
            $this->remember($part, $colorId, null, null, 'missing');
            $this->lastMiss = 'missing';

            return null;
        } catch (\Throwable $e) {
            error_log(sprintf('Studbook: image %s/%d not fetched: %s', $part, $colorId, $e->getMessage()));
            $this->remember($part, $colorId, null, null, 'error');
            $this->lastMiss = 'error';

            return null;
        } finally {
            flock($slot, LOCK_UN);
            fclose($slot);
        }
    }

    /**
     * One of MAX_PARALLEL download slots (file locks, released when the request ends even if
     * it crashes); null when all are in use.
     *
     * @return resource|null
     */
    private function takeSlot()
    {
        $dir = $this->directory . '/.locks';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        for ($i = 0; $i < self::MAX_PARALLEL; $i++) {
            $handle = @fopen($dir . '/fetch-' . $i . '.lock', 'c');
            if ($handle === false) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            fclose($handle);
        }

        return null;
    }

    /** Image URL from the catalogue; for "any colour" the first colour with an image. */
    private function sourceUrl(string $part, int $colorId): ?string
    {
        if ($colorId === self::SET_IMAGE) {
            $stmt = $this->pdo->prepare('SELECT img_url FROM cat_set WHERE set_num = ?');
            $stmt->execute([$part]);
        } elseif ($colorId === self::ANY_COLOR) {
            $stmt = $this->pdo->prepare(
                'SELECT img_url FROM cat_part_color WHERE part = ? AND img_url IS NOT NULL ORDER BY color_id LIMIT 1'
            );
            $stmt->execute([$part]);
        } else {
            $stmt = $this->pdo->prepare('SELECT img_url FROM cat_part_color WHERE part = ? AND color_id = ?');
            $stmt->execute([$part, $colorId]);
        }
        $url = $stmt->fetchColumn();

        return is_string($url) && self::isAllowedUrl($url) ? $url : null;
    }

    /** Only HTTPS URLs on Rebrickable's hosts are fetched (the URLs come from imported data). */
    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'rebrickable.com' || str_ends_with($host, '.rebrickable.com'))
            && !isset($parts['user'], $parts['pass'])
            && !isset($parts['port']);
    }

    private function remember(string $part, int $colorId, ?string $file, ?string $type, string $status): void
    {
        $this->pdo->prepare(
            'INSERT INTO cat_image_cache (part, color_id, file, content_type, status, fetched_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE file = VALUES(file), content_type = VALUES(content_type),
                 status = VALUES(status), fetched_at = VALUES(fetched_at)'
        )->execute([$part, $colorId, $file, $type, $status]);
    }

    /** @return array{body: string, content_type: string} */
    private function httpFetch(string $url): array
    {
        if (!extension_loaded('curl')) {
            $context = stream_context_create(['http' => ['timeout' => 6, 'user_agent' => $this->userAgent]]);
            $body = @file_get_contents($url, false, $context, 0, self::MAX_BYTES + 1);
            if ($body === false) {
                if (preg_match('#^HTTP/\S+ (404|410)#', (string) ($http_response_header[0] ?? ''))) {
                    throw new ImageNotFound();
                }
                throw new \RuntimeException('HTTP request failed');
            }
            $type = '';
            foreach ($http_response_header ?? [] as $header) {
                if (stripos($header, 'content-type:') === 0) {
                    $type = trim(substr($header, 13));
                }
            }

            return ['body' => $body, 'content_type' => $type];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException($error !== '' ? $error : 'HTTP request failed');
        }
        if ($status === 404 || $status === 410) {
            throw new ImageNotFound();
        }
        if ($status !== 200) {
            throw new \RuntimeException('HTTP ' . $status);
        }

        return ['body' => $body, 'content_type' => $type];
    }
}

<?php

declare(strict_types=1);

namespace Studbook\Camera;

/**
 * Part recognition from a photo with the Brickognize API (`POST
 * /predict/parts/`, multipart field `query_image`). The answer lists
 * candidates by BrickLink item number with a score; it has no colour.
 * Answers are cached per image, and any failure is reported as
 * `PhotoException('recognition_failed')` so the page can offer search instead.
 */
final class Brickognize
{
    public const CACHE_DAYS = 30;
    public const MAX_CANDIDATES = 5;

    /** @var \Closure(string, string): string */
    private \Closure $post;

    /**
     * @param (callable(string $url, string $imagePath): string)|null $post returns the response body,
     *        throws on failure; defaults to an HTTPS request with curl
     */
    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeoutSeconds,
        private readonly string $cacheDirectory,
        private readonly string $userAgent = 'Studbook',
        ?callable $post = null,
    ) {
        $this->post = $post !== null ? \Closure::fromCallable($post) : $this->httpPost(...);
    }

    /** @return list<array{id: string, name: string, score: float, category: string}> best candidates first */
    public function identify(string $image): array
    {
        $hash = hash_file('sha256', $image);
        $cache = $this->cacheDirectory . '/brickognize-' . $hash . '.json';
        if (is_file($cache) && filemtime($cache) > time() - self::CACHE_DAYS * 86400) {
            $body = (string) file_get_contents($cache);
        } else {
            try {
                $body = ($this->post)(rtrim($this->apiUrl, '/') . '/predict/parts/', $image);
            } catch (\Throwable $e) {
                error_log('Studbook: Brickognize request failed: ' . $e->getMessage());
                throw new PhotoException('recognition_failed');
            }
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !is_array($data['items'] ?? null)) {
            error_log('Studbook: unexpected Brickognize answer: ' . substr($body, 0, 200));
            throw new PhotoException('recognition_failed');
        }
        if (is_dir($this->cacheDirectory) || @mkdir($this->cacheDirectory, 0775, true)) {
            @file_put_contents($cache, $body);
        }

        $candidates = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || ($item['type'] ?? 'part') !== 'part') {
                continue;
            }
            $candidates[] = [
                'id' => $item['id'],
                'name' => (string) ($item['name'] ?? ''),
                'score' => (float) ($item['score'] ?? 0),
                'category' => (string) ($item['category'] ?? ''),
            ];
        }
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, self::MAX_CANDIDATES);
    }

    private function httpPost(string $url, string $image): string
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('the curl extension is required');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['query_image' => new \CURLFile($image, 'image/jpeg', 'photo.jpg')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(1, $this->timeoutSeconds),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException($error !== '' ? $error : 'request failed');
        }
        if ($status !== 200) {
            throw new \RuntimeException('HTTP ' . $status);
        }

        return $body;
    }
}

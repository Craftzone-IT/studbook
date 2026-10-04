<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Catalog\ImageCache;
use Studbook\Http\Request;
use Studbook\Http\Response;

/**
 * Serves cached part and set images (`/img?part=3001&color=4`, `/img?set=10696-1`);
 * a neutral placeholder when none is available.
 */
final class ImageController
{
    /** @var \Closure(): ImageCache */
    private \Closure $cache;

    /** @param callable(): ImageCache $cache */
    public function __construct(callable $cache)
    {
        $this->cache = \Closure::fromCallable($cache);
    }

    public function show(Request $request): Response
    {
        $part = $request->query('part');
        $color = $request->query('color', (string) ImageCache::ANY_COLOR);
        if ($request->query('set') !== '') {
            $part = $request->query('set');
            $color = (string) ImageCache::SET_IMAGE;
        }
        if ($part === '' || strlen($part) > 64 || !preg_match('/^-?\d+$/', $color)) {
            return self::placeholder();
        }
        $image = ($this->cache)()->get($part, (int) $color);
        if ($image === null) {
            return self::placeholder();
        }
        $body = file_get_contents($image['path']);
        if ($body === false) {
            return self::placeholder();
        }

        return (new Response($body, 200, ['Content-Type' => $image['content_type']]))
            ->withHeader('Cache-Control', 'private, max-age=604800');
    }

    private static function placeholder(): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40">'
            . '<rect x="6" y="14" width="28" height="18" rx="2" fill="#d6d6d0"/>'
            . '<rect x="11" y="9" width="7" height="6" rx="1.5" fill="#d6d6d0"/>'
            . '<rect x="22" y="9" width="7" height="6" rx="1.5" fill="#d6d6d0"/></svg>';

        return (new Response($svg, 200, ['Content-Type' => 'image/svg+xml']))
            ->withHeader('Cache-Control', 'private, max-age=3600');
    }
}

<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Catalog\CatalogRepository;
use Studbook\Catalog\PartSearch;
use Studbook\ErrorPage;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\Owned\OwnedQueries;
use Studbook\Owned\OwnedService;
use Studbook\View;

/**
 * Fast entry into a box (`/b/{id}/entry`): keyboard-driven part → colour →
 * quantity, enhanced by `assets/entry.js`, with small JSON endpoints. All
 * additions to one box within an entry session form one undo batch.
 */
final class EntryController
{
    /** An entry session ends after this many seconds without an addition. */
    public const SESSION_IDLE_SECONDS = 1800;
    private const SESSION_KEY = 'entry_sessions';

    /** @var \Closure(): OwnedService */
    private \Closure $owned;
    /** @var \Closure(): OwnedQueries */
    private \Closure $queries;
    /** @var \Closure(): CatalogRepository */
    private \Closure $catalog;
    /** @var \Closure(): PartSearch */
    private \Closure $search;

    /**
     * @param callable(): OwnedService $owned
     * @param callable(): OwnedQueries $queries
     * @param callable(): CatalogRepository $catalog
     * @param callable(): PartSearch $search
     */
    public function __construct(
        private readonly View $view,
        callable $owned,
        callable $queries,
        callable $catalog,
        callable $search,
    ) {
        $this->owned = \Closure::fromCallable($owned);
        $this->queries = \Closure::fromCallable($queries);
        $this->catalog = \Closure::fromCallable($catalog);
        $this->search = \Closure::fromCallable($search);
    }

    /** @param array<string, string> $params */
    public function page(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $box = $queries->box((int) $params['id']);
        if ($box === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $labels = $queries->labels((int) $box['id']);
        $info = ($this->catalog)()->parts($labels);
        $session = $this->session((int) $box['id']);
        $open = $session !== null ? ($this->owned)()->entrySession($session['batch'], (int) $box['id']) : null;

        return Response::html($this->view->render('entry', [
            'title' => t('entry.title', ['box' => $box['name']]),
            'box' => $box,
            'labels' => array_map(
                static fn (string $p): array => $info[$p]
                    ?? ['rb_num' => $p, 'bl_num' => null, 'name' => '', 'display' => $p],
                $labels
            ),
            'lots' => $queries->lots((int) $box['id']),
            'session' => $open === null ? null : [
                'batch' => $open['batch'],
                'summary' => t('entry.session_summary', ['lots' => $open['lots'], 'parts' => $open['parts']]),
            ],
            'preselect' => $request->query('part'),
        ]));
    }

    /**
     * JSON: a part with its colours and the boxes labelled for it.
     *
     * @param array<string, string> $params
     */
    public function part(Request $request, array $params): Response
    {
        $catalog = ($this->catalog)();
        $box = ($this->queries)()->box((int) $params['id']);
        $part = $catalog->resolvePart($request->query('part'));
        if ($box === null || $part === null) {
            $typed = mb_substr($request->query('part'), 0, 64);

            return Response::json(['error' => t('box.part_unknown', ['part' => $typed])], 404);
        }

        return Response::json([
            'part' => $part,
            'colors' => $catalog->colorsForPart($part['rb_num']),
            'hint' => $this->hint($box, $part['rb_num']),
            'colorSynonyms' => $catalog->colorSynonyms(),
        ]);
    }

    /**
     * JSON: search results for the part field.
     *
     * @param array<string, string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $result = ($this->search)()->search($request->query('q'), 30);

        return Response::json([
            'parts' => $result['parts'],
            'color' => $result['color'],
            'corrections' => $result['corrections'],
        ]);
    }

    /**
     * JSON: add parts within the entry session.
     *
     * @param array<string, string> $params
     */
    public function add(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $box = $queries->box((int) $params['id']);
        if ($box === null) {
            return Response::json(['error' => t('owned.error.not_found')], 404);
        }
        $catalog = ($this->catalog)();
        $part = $catalog->resolvePart($request->input('part'));
        $colorId = $request->input('color');
        // The part actually stored may be another Rebrickable part with the same BrickLink number.
        $stored = $part !== null && preg_match('/^\d+$/', $colorId)
            ? $catalog->partForColor($part['rb_num'], (int) $colorId)
            : null;
        if ($part === null || $stored === null) {
            return Response::json(['error' => t('box.color_required')], 422);
        }
        $sessions = Session::get(self::SESSION_KEY, []);
        $current = $this->session((int) $box['id']);
        try {
            $result = ($this->owned)()->addLotInSession(
                (int) $box['id'],
                $stored,
                (int) $colorId,
                (int) $request->input('qty', '1'),
                $current['batch'] ?? null
            );
        } catch (\DomainException $e) {
            return Response::json(['error' => t('owned.error.' . $e->getMessage())], 422);
        }
        $sessions = is_array($sessions) ? $sessions : [];
        $sessions[(string) $box['id']] = ['batch' => $result['batch'], 'at' => time()];
        Session::set(self::SESSION_KEY, $sessions);

        $colorName = '';
        foreach ($catalog->colorsForPart($part['rb_num']) as $color) {
            if ($color['id'] === (int) $colorId) {
                $colorName = $color['name'];
            }
        }

        return Response::json([
            'message' => t('entry.added', [
                'qty' => (int) $request->input('qty', '1'),
                'part' => $part['display'],
                'color' => $colorName,
            ]),
            'session' => [
                'batch' => $result['batch'],
                'summary' => t('entry.session_summary', ['lots' => $result['lots'], 'parts' => $result['parts']]),
            ],
            'contents' => $this->view->render('_lots', [
                'lots' => $queries->lots((int) $box['id']),
                'box' => $box,
                'otherBoxes' => [],
                'compact' => true,
            ], null),
        ]);
    }

    /**
     * Boxes of the same collection labelled for the part, when the current box is not.
     *
     * @param array<string, mixed> $box
     * @return list<array{id: int, name: string}>
     */
    private function hint(array $box, string $part): array
    {
        $boxes = ($this->queries)()->labelledBoxes((int) $box['collection_id'], $part);
        foreach ($boxes as $labelled) {
            if ($labelled['id'] === (int) $box['id']) {
                return [];
            }
        }

        return $boxes;
    }

    /** @return array{batch: int, at: int}|null the open entry session of a box */
    private function session(int $boxId): ?array
    {
        $sessions = Session::get(self::SESSION_KEY, []);
        $session = is_array($sessions) ? ($sessions[(string) $boxId] ?? null) : null;
        if (!is_array($session) || time() - (int) ($session['at'] ?? 0) > self::SESSION_IDLE_SECONDS) {
            return null;
        }

        return ['batch' => (int) $session['batch'], 'at' => (int) $session['at']];
    }
}

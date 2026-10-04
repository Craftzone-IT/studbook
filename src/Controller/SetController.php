<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Catalog\CatalogRepository;
use Studbook\ErrorPage;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\Owned\OwnedQueries;
use Studbook\Owned\SetService;
use Studbook\View;

/** Owned sets: adding by number, the set page, deltas, breaking up, moving and removing. */
final class SetController
{
    /** @var \Closure(): SetService */
    private \Closure $sets;
    /** @var \Closure(): OwnedQueries */
    private \Closure $queries;
    /** @var \Closure(): CatalogRepository */
    private \Closure $catalog;

    /**
     * @param callable(): SetService $sets
     * @param callable(): OwnedQueries $queries
     * @param callable(): CatalogRepository $catalog
     */
    public function __construct(private readonly View $view, callable $sets, callable $queries, callable $catalog)
    {
        $this->sets = \Closure::fromCallable($sets);
        $this->queries = \Closure::fromCallable($queries);
        $this->catalog = \Closure::fromCallable($catalog);
    }

    /**
     * Step 1 of "add a set": find it by number or name, preview it, choose state, lock and box.
     *
     * @param array<string, string> $params
     */
    public function addForm(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $collection = $queries->collection((int) $params['id']);
        if ($collection === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $catalog = ($this->catalog)();
        $q = mb_substr(trim($request->query('q')), 0, 100);
        $set = $request->query('set') !== '' ? $catalog->set($request->query('set')) : null;
        $matches = [];
        if ($set === null && $q !== '') {
            $set = $catalog->set($q);
            if ($set === null) {
                $matches = $catalog->findSets($q);
                if (count($matches) === 1) {
                    $set = $matches[0];
                }
            }
        }

        return Response::html($this->view->render('set-add', [
            'title' => t('set.add_title'),
            'collection' => $collection,
            'q' => $q,
            'set' => $set,
            'matches' => $set === null ? $matches : [],
            'boxes' => $queries->boxes((int) $collection['id']),
            'states' => SetService::STATES,
            'lockModes' => SetService::LOCK_MODES,
        ]));
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $collectionId = (int) $params['id'];
        if (($this->queries)()->collection($collectionId) === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        try {
            $result = ($this->sets)()->addSet(
                $collectionId,
                $request->input('set'),
                $request->input('state'),
                $request->input('lock_mode'),
                self::optionalId($request->input('storage')),
                (int) $request->input('copies', '1')
            );
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));

            $back = url('/c/' . $collectionId . '/sets/new') . '?set=' . rawurlencode($request->input('set'));

            return Response::redirect($back);
        }
        Session::flash('success', t('set.added'), $result['batch']);
        if (count($result['ids']) > 1) {
            return Response::redirect(url('/c/' . $collectionId));
        }

        return Response::redirect(url('/s/' . $result['ids'][0]));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $set = $queries->ownedSet((int) $params['id']);
        if ($set === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $contents = ($this->sets)()->contents((int) $set['id']);
        $catalog = ($this->catalog)();
        $parts = $catalog->parts(array_column($contents, 'part'));
        $colors = $catalog->colors();
        $rows = array_map(static fn (array $row): array => $row + [
            'display' => $parts[$row['part']]['display'] ?? $row['part'],
            'part_name' => $parts[$row['part']]['name'] ?? '',
            'color_name' => $colors[$row['color_id']]['name'] ?? ('#' . $row['color_id']),
            'rgb' => $colors[$row['color_id']]['rgb'] ?? '',
        ], $contents);
        usort(
            $rows,
            static fn (array $a, array $b): int
                => [$a['display'], $a['color_name']] <=> [$b['display'], $b['color_name']]
        );

        return Response::html($this->view->render('set', [
            'title' => $set['set_num'] . ' ' . ($set['name'] ?? ''),
            'set' => $set,
            'rows' => $rows,
            'deltas' => $queries->deltas((int) $set['id']),
            'boxes' => $queries->boxes((int) $set['collection_id']),
            'collections' => array_values(array_filter(
                $queries->activeCollections(),
                static fn (array $c): bool => $c['id'] !== (int) $set['collection_id']
            )),
            'states' => SetService::STATES,
            'lockModes' => SetService::LOCK_MODES,
        ]));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $id = (int) $params['id'];

        return $this->act($id, fn (): int => ($this->sets)()->updateSet(
            $id,
            $request->input('state'),
            $request->input('lock_mode'),
            self::optionalId($request->input('storage'))
        ), 'set.updated');
    }

    /** @param array<string, string> $params */
    public function move(Request $request, array $params): Response
    {
        $id = (int) $params['id'];

        return $this->act(
            $id,
            fn (): int => ($this->sets)()->moveSet($id, (int) $request->input('collection')),
            'set.moved'
        );
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $set = ($this->queries)()->ownedSet((int) $params['id']);
        if ($set === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $batch = ($this->sets)()->removeSet((int) $set['id']);
        Session::flash('success', t('set.removed'), $batch);

        return Response::redirect(url('/c/' . $set['collection_id']));
    }

    /**
     * Records missing or extra parts: part number first, then colour and quantity.
     *
     * @param array<string, string> $params
     */
    public function deltaForm(Request $request, array $params): Response
    {
        $set = ($this->queries)()->ownedSet((int) $params['id']);
        if ($set === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $catalog = ($this->catalog)();
        $part = $catalog->resolvePart($request->query('part'));
        if ($part === null) {
            Session::flash('error', t('box.part_unknown', ['part' => mb_substr($request->query('part'), 0, 64)]));

            return Response::redirect(url('/s/' . $set['id']));
        }
        $kind = $request->query('kind') === 'extra' ? 'extra' : 'missing';
        $inSet = [];
        foreach ($this->inSet((int) $set['id'], $part['rb_num']) as $colorId => $row) {
            $inSet[$colorId] = $row['qty'];
        }
        $colors = $catalog->colorsForPart($part['rb_num']);
        if ($kind === 'missing') {
            $colors = array_values(array_filter($colors, static fn (array $c): bool => isset($inSet[$c['id']])));
        }

        return Response::html($this->view->render('set-delta', [
            'title' => t('set.delta_title_' . $kind, ['part' => $part['display']]),
            'set' => $set,
            'part' => $part,
            'kind' => $kind,
            'colors' => $colors,
            'inSet' => $inSet,
            'selected' => (int) $request->query('color', '-99'),
        ]));
    }

    /** @param array<string, string> $params */
    public function saveDelta(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $catalog = ($this->catalog)();
        $part = $catalog->resolvePart($request->input('part'));
        $colorId = $request->input('color');
        if ($part === null || !preg_match('/^\d+$/', $colorId)) {
            Session::flash('error', t('box.color_required'));

            return Response::redirect(url('/s/' . $id));
        }
        $qty = max(0, (int) $request->input('qty', '1'));
        $extra = $request->input('kind') === 'extra';
        // Store under the Rebrickable part the set (or the catalogue) has in this colour; one
        // BrickLink number can stand for several Rebrickable parts.
        $stored = $extra
            ? $catalog->partForColor($part['rb_num'], (int) $colorId)
            : ($this->inSet($id, $part['rb_num'])[(int) $colorId]['part'] ?? $part['rb_num']);
        if ($stored === null) {
            Session::flash('error', t('box.color_required'));

            return Response::redirect(url('/s/' . $id));
        }

        return $this->act(
            $id,
            fn (): int => ($this->sets)()->changeDelta($id, $stored, (int) $colorId, $extra ? $qty : -$qty),
            'set.delta_saved'
        );
    }

    /**
     * What the set contains of a part (or of parts with the same BrickLink number), per colour.
     *
     * @return array<int, array{qty: int, part: string}>
     */
    private function inSet(int $setId, string $rbNum): array
    {
        $parts = ($this->catalog)()->siblingParts($rbNum);
        $result = [];
        foreach (($this->sets)()->contents($setId) as $row) {
            if (!in_array($row['part'], $parts, true)) {
                continue;
            }
            $current = $result[$row['color_id']] ?? null;
            if ($current === null || $row['part'] === $rbNum) {
                $result[$row['color_id']] = ['qty' => $row['qty'] + ($current['qty'] ?? 0), 'part' => $row['part']];
            } else {
                $result[$row['color_id']]['qty'] += $row['qty'];
            }
        }

        return $result;
    }

    /** @param array<string, string> $params */
    public function removeDelta(Request $request, array $params): Response
    {
        $rows = ($this->queries)()->select(
            'SELECT owned_set_id FROM owned_set_delta WHERE id = ?',
            [(int) $params['id']]
        );
        if ($rows === []) {
            return ErrorPage::render(404, null, $this->view);
        }

        return $this->act(
            (int) $rows[0]['owned_set_id'],
            fn (): int => ($this->sets)()->removeDelta((int) $params['id']),
            'set.delta_removed'
        );
    }

    /** @param array<string, string> $params */
    public function breakUpForm(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $set = $queries->ownedSet((int) $params['id']);
        if ($set === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $contents = ($this->sets)()->contents((int) $set['id']);

        return Response::html($this->view->render('set-break-up', [
            'title' => t('set.break_up_title', ['set' => $set['set_num']]),
            'set' => $set,
            'boxes' => $queries->boxes((int) $set['collection_id']),
            'parts' => array_sum(array_column($contents, 'qty')),
            'spares' => array_sum(array_column($contents, 'spare')),
        ]));
    }

    /** @param array<string, string> $params */
    public function breakUp(Request $request, array $params): Response
    {
        $set = ($this->queries)()->ownedSet((int) $params['id']);
        if ($set === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        try {
            $result = ($this->sets)()->breakUp(
                (int) $set['id'],
                (int) $request->input('storage'),
                $request->input('use_labels') === '1',
                $request->input('spares') === '1'
            );
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));

            return Response::redirect(url('/s/' . $set['id'] . '/break-up'));
        }
        Session::flash('success', t('set.broken_up', [
            'parts' => $result['parts'],
            'lots' => $result['lots'],
            'boxes' => $result['boxes'],
        ]), $result['batch']);

        return Response::redirect(url('/c/' . $set['collection_id']));
    }

    /** @param callable(): int $work */
    private function act(int $setId, callable $work, string $messageKey): Response
    {
        if (($this->queries)()->ownedSet($setId) === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        try {
            $batch = $work();
            Session::flash('success', t($messageKey), $batch);
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));
        }
        $set = ($this->queries)()->ownedSet($setId);

        return Response::redirect(url($set !== null ? '/s/' . $setId : '/'));
    }

    private static function optionalId(string $value): ?int
    {
        return preg_match('/^[1-9]\d*$/', $value) ? (int) $value : null;
    }
}

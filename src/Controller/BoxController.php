<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Catalog\CatalogRepository;
use Studbook\Config;
use Studbook\ErrorPage;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\Owned\LabelParser;
use Studbook\Owned\OwnedQueries;
use Studbook\Owned\OwnedService;
use Studbook\QrCode;
use Studbook\View;

/** Box page `/b/{id}` (the QR code target): labels, contents and quick actions. */
final class BoxController
{
    /** @var \Closure(): OwnedService */
    private \Closure $owned;
    /** @var \Closure(): OwnedQueries */
    private \Closure $queries;
    /** @var \Closure(): CatalogRepository */
    private \Closure $catalog;

    /**
     * @param callable(): OwnedService $owned
     * @param callable(): OwnedQueries $queries
     * @param callable(): CatalogRepository $catalog
     */
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
        callable $owned,
        callable $queries,
        callable $catalog,
    ) {
        $this->owned = \Closure::fromCallable($owned);
        $this->queries = \Closure::fromCallable($queries);
        $this->catalog = \Closure::fromCallable($catalog);
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $box = $queries->box((int) $params['id']);
        if ($box === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $labels = $queries->labels((int) $box['id']);
        $labelParts = ($this->catalog)()->parts($labels);

        return Response::html($this->view->render('box', [
            'title' => $box['name'],
            'box' => $box,
            'labels' => array_map(
                static fn (string $p): array => $labelParts[$p]
                    ?? ['rb_num' => $p, 'bl_num' => null, 'name' => '', 'display' => $p],
                $labels
            ),
            'labelText' => Session::get('label_input_' . $box['id']) ?? implode(' ', array_map(
                static fn (string $p): string => $labelParts[$p]['display'] ?? $p,
                $labels
            )),
            'lots' => $queries->lots((int) $box['id']),
            'otherBoxes' => array_values(array_filter(
                $queries->boxes((int) $box['collection_id']),
                static fn (array $b): bool => (int) $b['id'] !== (int) $box['id']
            )),
            'boxTypes' => OwnedService::USER_BOX_TYPES,
        ]));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $id = (int) $params['id'];

        $work = fn (): int => ($this->owned)()->updateBox($id, $request->input('name'), $request->input('type'));

        return $this->act($id, $work, 'box.updated');
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $box = ($this->queries)()->box((int) $params['id']);
        if ($box === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        try {
            $batch = ($this->owned)()->deleteBox((int) $box['id']);
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));

            return Response::redirect(url('/b/' . $box['id']));
        }
        Session::flash('success', t('box.deleted'), $batch);

        return Response::redirect(url('/c/' . $box['collection_id']));
    }

    /** @param array<string, string> $params */
    public function labels(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (($this->queries)()->box($id) === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $input = $request->input('labels');
        $parsed = (new LabelParser(($this->catalog)()))->parse($input);
        if ($parsed['unknown'] !== []) {
            // Keep the typed text so the user can fix it; nothing is saved.
            Session::set('label_input_' . $id, $input);
            Session::flash('error', t('box.labels_unknown', ['parts' => implode(', ', $parsed['unknown'])]));

            return Response::redirect(url('/b/' . $id));
        }
        Session::remove('label_input_' . $id);

        return $this->act($id, fn (): int => ($this->owned)()->setLabels($id, $parsed['parts']), 'box.labels_saved');
    }

    /**
     * Step 2 of "add here": pick the colour and quantity of a part.
     *
     * @param array<string, string> $params
     */
    public function addForm(Request $request, array $params): Response
    {
        $box = ($this->queries)()->box((int) $params['id']);
        if ($box === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $part = ($this->catalog)()->resolvePart($request->query('part'));
        if ($part === null) {
            Session::flash('error', t('box.part_unknown', ['part' => mb_substr($request->query('part'), 0, 64)]));

            return Response::redirect(url('/b/' . $box['id']));
        }

        return Response::html($this->view->render('box-add', [
            'title' => t('box.add_title', ['part' => $part['display']]),
            'box' => $box,
            'part' => $part,
            'colors' => ($this->catalog)()->colorsForPart($part['rb_num']),
        ]));
    }

    /** @param array<string, string> $params */
    public function addLot(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $part = ($this->catalog)()->resolvePart($request->input('part'));
        $colorId = $request->input('color');
        $valid = $part !== null && preg_match('/^\d+$/', $colorId) === 1 && in_array(
            (int) $colorId,
            array_column(($this->catalog)()->colorsForPart($part['rb_num']), 'id'),
            true
        );
        if (!$valid) {
            Session::flash('error', t('box.color_required'));

            return Response::redirect(url('/b/' . $id));
        }

        return $this->act(
            $id,
            fn (): int => ($this->owned)()->addLot(
                $id,
                $part['rb_num'],
                (int) $colorId,
                (int) $request->input('qty', '1')
            ),
            'box.parts_added'
        );
    }

    /** @param array<string, string> $params */
    public function takeOut(Request $request, array $params): Response
    {
        $work = fn (int $lotId): int => ($this->owned)()->takeOut($lotId, (int) $request->input('qty', '1'));

        return $this->lotAction($params, $work, 'box.parts_taken');
    }

    /** @param array<string, string> $params */
    public function move(Request $request, array $params): Response
    {
        return $this->lotAction($params, fn (int $lotId): int => ($this->owned)()->moveLot(
            $lotId,
            (int) $request->input('target'),
            (int) $request->input('qty', '1')
        ), 'box.parts_moved');
    }

    /** @param array<string, string> $params */
    public function qr(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (($this->queries)()->box($id) === null) {
            return ErrorPage::render(404, null, $this->view);
        }

        return (new Response(QrCode::svg(CollectionController::boxUrl($this->config, $id)), 200, [
            'Content-Type' => 'image/svg+xml',
        ]))->withHeader('Cache-Control', 'private, max-age=86400');
    }

    /**
     * @param array<string, string> $params
     * @param callable(int): int $work
     */
    private function lotAction(array $params, callable $work, string $messageKey): Response
    {
        $lot = ($this->queries)()->lot((int) $params['id']);
        if ($lot === null) {
            return ErrorPage::render(404, null, $this->view);
        }

        return $this->act((int) $lot['storage_id'], static fn (): int => $work((int) $lot['id']), $messageKey);
    }

    /** @param callable(): int $work */
    private function act(int $boxId, callable $work, string $messageKey): Response
    {
        if (($this->queries)()->box($boxId) === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        try {
            $batch = $work();
            Session::flash('success', t($messageKey), $batch);
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));
        }

        return Response::redirect(url('/b/' . $boxId));
    }
}

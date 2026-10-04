<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Config;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\Owned\OwnedQueries;
use Studbook\Owned\OwnedService;
use Studbook\QrCode;
use Studbook\Catalog\CatalogRepository;
use Studbook\ErrorPage;
use Studbook\View;

/** Home page (collection cards), collection pages and the printable label sheet. */
final class CollectionController
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

    public function home(Request $request): Response
    {
        $collections = ($this->queries)()->collections();

        return Response::html($this->view->render('home', [
            'title' => t('home.title'),
            'active' => array_values(array_filter($collections, static fn (array $c): bool => !$c['archived_at'])),
            'archived' => array_values(array_filter($collections, static fn (array $c): bool => !!$c['archived_at'])),
            'catalogEmpty' => ($this->catalog)()->isEmpty(),
        ]));
    }

    public function create(Request $request): Response
    {
        try {
            [$id, $batch] = ($this->owned)()->createCollection(
                $request->input('name'),
                $request->input('can_lend') === '1',
                t('box.inbox_name')
            );
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));

            return Response::redirect(url('/'));
        }
        Session::flash('success', t('collection.created'), $batch);

        return Response::redirect(url('/c/' . $id));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $collection = ($this->queries)()->collection((int) $params['id']);
        if ($collection === null) {
            return ErrorPage::render(404, null, $this->view);
        }

        return Response::html($this->view->render('collection', [
            'title' => $collection['name'],
            'collection' => $collection,
            'boxes' => ($this->queries)()->boxes((int) $collection['id']),
            'sets' => ($this->queries)()->sets((int) $collection['id']),
            'empty' => ($this->queries)()->isCollectionEmpty((int) $collection['id']),
            'boxTypes' => OwnedService::USER_BOX_TYPES,
        ]));
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params): Response
    {
        $id = (int) $params['id'];

        return $this->act($id, fn (): int => ($this->owned)()->updateCollection(
            $id,
            $request->input('name'),
            $request->input('can_lend') === '1'
        ), 'collection.updated');
    }

    /** @param array<string, string> $params */
    public function archive(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $archive = $request->input('archive') === '1';

        return $this->act(
            $id,
            fn (): int => ($this->owned)()->setArchived($id, $archive),
            $archive ? 'collection.archived_done' : 'collection.restored_done'
        );
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $queries = ($this->queries)();
        if ($queries->collection($id) === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        if (!$queries->isCollectionEmpty($id) && $request->input('confirm') !== '1') {
            Session::flash('error', t('collection.delete_confirm_needed'));

            return Response::redirect(url('/c/' . $id));
        }
        $batch = ($this->owned)()->deleteCollection($id);
        Session::flash('success', t('collection.deleted'), $batch);

        return Response::redirect(url('/'));
    }

    /** @param array<string, string> $params */
    public function createBox(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        try {
            [$boxId, $batch] = ($this->owned)()->createBox($id, $request->input('name'), $request->input('type'));
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));

            return Response::redirect(url('/c/' . $id));
        }
        Session::flash('success', t('box.created'), $batch);

        return Response::redirect(url('/b/' . $boxId));
    }

    /**
     * Printable A4 sheet with one label per box: name, QR code, part numbers.
     *
     * @param array<string, string> $params
     */
    public function labels(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $collection = $queries->collection((int) $params['id']);
        if ($collection === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $boxes = $queries->boxes((int) $collection['id']);
        $only = $request->query('box');
        if ($only !== '') {
            $boxes = array_values(array_filter($boxes, static fn (array $b): bool => (string) $b['id'] === $only));
        }
        $labels = [];
        foreach ($boxes as $box) {
            $parts = $queries->labels((int) $box['id']);
            $info = ($this->catalog)()->parts($parts);
            $labels[] = [
                'box' => $box,
                'qr' => QrCode::svg(self::boxUrl($this->config, (int) $box['id'])),
                'parts' => array_map(static fn (string $p): string => $info[$p]['display'] ?? $p, $parts),
            ];
        }

        return Response::html($this->view->render('labels', [
            'title' => t('labels.title', ['name' => $collection['name']]),
            'collection' => $collection,
            'labels' => $labels,
        ]));
    }

    /** The URL a box label's QR code encodes. */
    public static function boxUrl(Config $config, int $boxId): string
    {
        return rtrim($config->get('APP_URL'), '/') . '/b/' . $boxId;
    }

    /** @param callable(): int $work */
    private function act(int $id, callable $work, string $messageKey): Response
    {
        if (($this->queries)()->collection($id) === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        try {
            $batch = $work();
            Session::flash('success', t($messageKey), $batch);
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));
        }

        return Response::redirect(url('/c/' . $id));
    }
}

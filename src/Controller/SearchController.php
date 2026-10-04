<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Catalog\PartSearch;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Owned\OwnedQueries;
use Studbook\View;

/** Global part search (`/search?q=`): catalogue matches with where you keep them. */
final class SearchController
{
    /** @var \Closure(): PartSearch */
    private \Closure $search;
    /** @var \Closure(): OwnedQueries */
    private \Closure $queries;

    /**
     * @param callable(): PartSearch $search
     * @param callable(): OwnedQueries $queries
     */
    public function __construct(private readonly View $view, callable $search, callable $queries)
    {
        $this->search = \Closure::fromCallable($search);
        $this->queries = \Closure::fromCallable($queries);
    }

    public function page(Request $request): Response
    {
        $q = trim($request->query('q'));
        $result = $q === '' ? null : ($this->search)()->search($q);
        $locations = $result === null ? [] : ($this->queries)()->locations(array_column($result['parts'], 'rb_num'));

        return Response::html($this->view->render('search', [
            'title' => $q === '' ? t('search.title') : t('search.title_for', ['q' => $q]),
            'q' => $q,
            'result' => $result,
            'locations' => $locations,
        ]));
    }
}

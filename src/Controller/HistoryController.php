<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Owned\BatchService;
use Studbook\View;

/** Recent changes (batches) with an Undo button each. */
final class HistoryController
{
    /** @var \Closure(): BatchService */
    private \Closure $batches;

    /** @param callable(): BatchService $batches */
    public function __construct(private readonly View $view, callable $batches)
    {
        $this->batches = \Closure::fromCallable($batches);
    }

    public function page(Request $request): Response
    {
        return Response::html($this->view->render('history', [
            'title' => t('history.title'),
            'batches' => ($this->batches)()->recent(50),
        ]));
    }
}

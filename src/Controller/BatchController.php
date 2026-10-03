<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\Owned\BatchConflict;
use Studbook\Owned\BatchService;

/** Undo of one batch (the "Undo" button in messages; a full batch list follows in M3). */
final class BatchController
{
    /** @var \Closure(): BatchService */
    private \Closure $batches;

    /** @param callable(): BatchService $batches */
    public function __construct(callable $batches)
    {
        $this->batches = \Closure::fromCallable($batches);
    }

    /** @param array<string, string> $params */
    public function undo(Request $request, array $params): Response
    {
        $service = ($this->batches)();
        $batch = $service->find((int) $params['id']);
        $return = self::safeReturn($request->input('return'));
        if ($batch === null) {
            Session::flash('error', t('batch.not_found'));

            return Response::redirect(url($return));
        }
        try {
            $service->revert($batch['id']);
            $what = t($batch['description_key'], $batch['description_params']);
            Session::flash('success', t('batch.undone', ['what' => $what]));
        } catch (BatchConflict $e) {
            Session::flash('error', t('batch.conflict.' . $e->getMessage()));
        }

        return Response::redirect(url($return));
    }

    /** Only app-relative paths; anything else returns to the home page. */
    public static function safeReturn(string $path): string
    {
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-]*$#', $path) === 1 ? $path : '/';
    }
}

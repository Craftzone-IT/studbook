<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Camera\Brickognize;
use Studbook\Camera\LabelCandidates;
use Studbook\Camera\Photo;
use Studbook\Camera\PhotoException;
use Studbook\Camera\TesseractOcr;
use Studbook\Catalog\CatalogRepository;
use Studbook\Config;
use Studbook\ErrorPage;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\Owned\LabelParser;
use Studbook\Owned\OwnedQueries;
use Studbook\Owned\OwnedService;
use Studbook\View;

/** Camera features: QR scanner, box labels from a photo (OCR), part recognition (Brickognize). */
final class CameraController
{
    /** @var \Closure(): OwnedService */
    private \Closure $owned;
    /** @var \Closure(): OwnedQueries */
    private \Closure $queries;
    /** @var \Closure(): CatalogRepository */
    private \Closure $catalog;
    /** @var \Closure(): Brickognize */
    private \Closure $brickognize;

    /**
     * @param callable(): OwnedService $owned
     * @param callable(): OwnedQueries $queries
     * @param callable(): CatalogRepository $catalog
     * @param callable(): Brickognize $brickognize
     */
    public function __construct(
        private readonly View $view,
        private readonly Config $config,
        private readonly TesseractOcr $ocr,
        callable $owned,
        callable $queries,
        callable $catalog,
        callable $brickognize,
    ) {
        $this->owned = \Closure::fromCallable($owned);
        $this->queries = \Closure::fromCallable($queries);
        $this->catalog = \Closure::fromCallable($catalog);
        $this->brickognize = \Closure::fromCallable($brickognize);
    }

    /**
     * Which camera features work on this instance.
     *
     * @return array{ocr: bool, ocr_reason: string, ocr_version: string, recognition: bool}
     */
    public function status(): array
    {
        $ocr = ['available' => false, 'reason' => 'disabled', 'version' => ''];
        if ($this->config->bool('OCR_ENABLED')) {
            $ocr = $this->ocr->status();
        }

        return [
            'ocr' => $ocr['available'],
            'ocr_reason' => $ocr['reason'],
            'ocr_version' => $ocr['version'],
            'recognition' => $this->config->bool('BRICKOGNIZE_ENABLED'),
        ];
    }

    public function scan(Request $request): Response
    {
        return Response::html($this->view->render('scan', ['title' => t('scan.title')]));
    }

    /** @param array<string, string> $params */
    public function labelsForm(Request $request, array $params): Response
    {
        $box = ($this->queries)()->box((int) $params['id']);
        if ($box === null) {
            return ErrorPage::render(404, null, $this->view);
        }

        return Response::html($this->view->render('labels-photo', [
            'title' => t('labels_photo.title', ['box' => $box['name']]),
            'box' => $box,
            'status' => $this->status(),
        ]));
    }

    /**
     * Reads part numbers from the photo and shows them for confirmation; nothing is saved yet.
     *
     * @param array<string, string> $params
     */
    public function labelsRead(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $box = $queries->box((int) $params['id']);
        if ($box === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $back = url('/b/' . $box['id'] . '/labels/photo');
        if (!$this->status()['ocr']) {
            return Response::redirect($back);
        }
        $work = $this->workFile('png');
        try {
            $source = Photo::uploaded($request->file('photo'));
            Photo::normalise($source, $work, 2400, true);
            $text = $this->ocr->read($work);
        } catch (PhotoException $e) {
            Session::flash('error', t('camera.error.' . $e->getMessage()));

            return Response::redirect($back);
        } finally {
            @unlink($work);
        }
        $labels = $queries->labels((int) $box['id']);
        $candidates = (new LabelCandidates(($this->catalog)()))->fromText($text);

        return Response::html($this->view->render('labels-review', [
            'title' => t('labels_photo.title', ['box' => $box['name']]),
            'box' => $box,
            'candidates' => array_map(static fn (array $c): array => $c + [
                'labelled' => $c['rb_num'] !== null && in_array($c['rb_num'], $labels, true),
            ], $candidates),
            'text' => $text,
            'labelCount' => count($labels),
        ]));
    }

    /**
     * Saves the confirmed part numbers as box labels (added to the existing ones, or replacing them).
     *
     * @param array<string, string> $params
     */
    public function labelsSave(Request $request, array $params): Response
    {
        $queries = ($this->queries)();
        $box = $queries->box((int) $params['id']);
        if ($box === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $input = $request->inputAll();
        $chosen = array_values(array_filter(
            array_map('strval', is_array($input['parts'] ?? null) ? $input['parts'] : []),
            static fn (string $p): bool => $p !== ''
        ));
        $typed = trim($request->input('extra'));
        $parsed = (new LabelParser(($this->catalog)()))->parse(implode(' ', $chosen) . ' ' . $typed);
        $parts = $request->input('mode') === 'replace'
            ? $parsed['parts']
            : array_values(array_unique([...$queries->labels((int) $box['id']), ...$parsed['parts']]));
        $parts = array_slice($parts, 0, LabelParser::MAX_LABELS);
        if ($parsed['parts'] === [] && $request->input('mode') !== 'replace') {
            Session::flash('error', t('labels_photo.nothing_chosen'));

            return Response::redirect(url('/b/' . $box['id']));
        }
        $batch = ($this->owned)()->setLabels((int) $box['id'], $parts);
        if ($parsed['unknown'] !== []) {
            Session::flash('error', t('box.labels_unknown', ['parts' => implode(', ', $parsed['unknown'])]));
        }
        Session::flash('success', t('labels_photo.saved', ['count' => count($parsed['parts'])]), $batch);

        return Response::redirect(url('/b/' . $box['id']));
    }

    public function identifyForm(Request $request): Response
    {
        return Response::html($this->view->render('identify', [
            'title' => t('identify.title'),
            'box' => $this->contextBox($request->query('box')),
            'status' => $this->status(),
            'candidates' => null,
        ]));
    }

    public function identify(Request $request): Response
    {
        $box = $this->contextBox($request->input('box'));
        $back = url('/identify') . ($box !== null ? '?box=' . $box['id'] : '');
        if (!$this->status()['recognition']) {
            return Response::redirect($back);
        }
        $work = $this->workFile('jpg');
        try {
            $source = Photo::uploaded($request->file('photo'));
            Photo::normalise($source, $work, 1024, false);
            $found = ($this->brickognize)()->identify($work);
        } catch (PhotoException $e) {
            Session::flash('error', t('camera.error.' . $e->getMessage()));

            return Response::redirect($back);
        } finally {
            @unlink($work);
        }

        $catalog = ($this->catalog)();
        $candidates = [];
        foreach ($found as $item) {
            $part = $catalog->resolvePart($item['id']);
            $candidates[] = $item + [
                'rb_num' => $part['rb_num'] ?? null,
                'display' => $part['display'] ?? $item['id'],
                'part_name' => $part['name'] ?? $item['name'],
                'locations' => [],
            ];
        }
        $locations = ($this->queries)()->locations(array_values(array_filter(array_column($candidates, 'rb_num'))));
        foreach ($candidates as $i => $c) {
            $candidates[$i]['locations'] = $c['rb_num'] !== null ? ($locations['p:' . $c['rb_num']] ?? []) : [];
        }

        return Response::html($this->view->render('identify', [
            'title' => t('identify.title'),
            'box' => $box,
            'status' => $this->status(),
            'candidates' => $candidates,
        ]));
    }

    /** @return array<string, mixed>|null */
    private function contextBox(string $id): ?array
    {
        return preg_match('/^\d+$/', $id) ? ($this->queries)()->box((int) $id) : null;
    }

    /** A temporary file for the normalised photo; deleted right after use. */
    private function workFile(string $extension): string
    {
        $dir = $this->config->path('UPLOAD_PATH', 'storage/uploads') . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
    }
}

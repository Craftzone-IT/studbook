<?php

declare(strict_types=1);

namespace Studbook\Controller;

use PDO;
use Studbook\Build\BuildOptions;
use Studbook\Build\BuildService;
use Studbook\Build\CoverageService;
use Studbook\Build\WantedList;
use Studbook\Catalog\CatalogRepository;
use Studbook\ErrorPage;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\Owned\OwnedQueries;
use Studbook\View;

/** "What can I build?": the scan over all sets, one set in detail, builds with reserved parts. */
final class BuildController
{
    public const PER_PAGE = 50;
    public const DEFAULT_MIN_PARTS = 25;
    public const SORTS = ['coverage', 'loose', 'missing', 'parts', 'year', 'any'];

    /** @var \Closure(): PDO */
    private \Closure $pdo;
    /** @var \Closure(): OwnedQueries */
    private \Closure $queries;
    /** @var \Closure(): CoverageService */
    private \Closure $coverage;
    /** @var \Closure(): BuildService */
    private \Closure $builds;
    /** @var \Closure(): CatalogRepository */
    private \Closure $catalog;

    /**
     * @param callable(): PDO $pdo
     * @param callable(): OwnedQueries $queries
     * @param callable(): CoverageService $coverage
     * @param callable(): BuildService $builds
     * @param callable(): CatalogRepository $catalog
     */
    public function __construct(
        private readonly View $view,
        callable $pdo,
        callable $queries,
        callable $coverage,
        callable $builds,
        callable $catalog,
    ) {
        $this->pdo = \Closure::fromCallable($pdo);
        $this->queries = \Closure::fromCallable($queries);
        $this->coverage = \Closure::fromCallable($coverage);
        $this->builds = \Closure::fromCallable($builds);
        $this->catalog = \Closure::fromCallable($catalog);
    }

    public function scan(Request $request): Response
    {
        $collections = ($this->queries)()->activeCollections();
        $options = BuildOptions::from($request->queryAll(), $collections);
        $data = [
            'title' => t('build.title'),
            'collections' => $collections,
            'options' => $options,
            'builds' => ($this->builds)()->active(),
            'themes' => $this->themes(),
        ];
        if ($options === null) {
            return Response::html($this->view->render('build-scan', $data + ['results' => [], 'total' => 0]));
        }

        $filters = self::filters($request, $options);
        $coverage = ($this->coverage)()->scan($options);
        $sets = [];
        $stmt = ($this->pdo)()->query('SELECT set_num, name, year, theme_id, num_parts FROM cat_set');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $set) {
            $sets['s:' . $set['set_num']] = $set;
        }
        $owned = [];
        foreach (($this->pdo)()->query('SELECT DISTINCT set_num FROM owned_set')->fetchAll(PDO::FETCH_COLUMN) as $num) {
            $owned['s:' . $num] = true;
        }
        $themeIds = $filters['theme'] > 0 ? $this->themeSubtree($filters['theme']) : null;

        $results = [];
        foreach ($coverage as $key => $c) {
            $set = $sets[$key] ?? null;
            if ($set === null || ($filters['hide_owned'] && isset($owned[$key]))) {
                continue;
            }
            $parts = (int) $set['num_parts'];
            $year = $set['year'] !== null ? (int) $set['year'] : null;
            $pct = $c['total'] / $c['need'];
            if (
                $parts < $filters['parts_min'] || ($filters['parts_max'] > 0 && $parts > $filters['parts_max'])
                || ($filters['year_min'] > 0 && ($year === null || $year < $filters['year_min']))
                || ($filters['year_max'] > 0 && ($year === null || $year > $filters['year_max']))
                || ($themeIds !== null && !isset($themeIds[(int) $set['theme_id']]))
                || $pct * 100 < $filters['min_pct']
            ) {
                continue;
            }
            $results[] = $c + [
                'set_num' => (string) $set['set_num'],
                'name' => (string) $set['name'],
                'year' => $year,
                'theme_id' => (int) $set['theme_id'],
                'num_parts' => $parts,
                'owned' => isset($owned[$key]),
                'pct' => $pct,
            ];
        }
        $sort = $filters['sort'];
        usort($results, static fn (array $a, array $b): int => match ($sort) {
            'loose' => [$b['loose'] / $b['need'], $b['pct']] <=> [$a['loose'] / $a['need'], $a['pct']],
            'missing' => [$a['need'] - $a['total'], -$a['pct']] <=> [$b['need'] - $b['total'], -$b['pct']],
            'parts' => $b['num_parts'] <=> $a['num_parts'],
            'year' => [$b['year'] ?? 0, $b['pct']] <=> [$a['year'] ?? 0, $a['pct']],
            'any' => [($b['any'] ?? 0) / $b['need'], $b['pct']] <=> [($a['any'] ?? 0) / $a['need'], $a['pct']],
            default => [$b['pct'], $b['need']] <=> [$a['pct'], $a['need']],
        } ?: strcmp($a['set_num'], $b['set_num']));

        $total = count($results);
        $page = max(1, min((int) ceil(max(1, $total) / self::PER_PAGE), (int) $request->query('page', '1')));

        return Response::html($this->view->render('build-scan', $data + [
            'filters' => $filters,
            'results' => array_slice($results, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / self::PER_PAGE),
        ]));
    }

    /** @param array<string, string> $params */
    public function target(Request $request, array $params): Response
    {
        $set = ($this->catalog)()->set($params['set']);
        $collections = ($this->queries)()->activeCollections();
        $options = BuildOptions::from($request->queryAll(), $collections);
        if ($set === null || $options === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $rows = ($this->coverage)()->target($set['set_num'], $options);

        return Response::html($this->view->render('build-target', [
            'title' => t('build.target_title', ['set' => $set['set_num']]),
            'set' => $set,
            'options' => $options,
            'collections' => $collections,
            'rows' => $this->describe($rows),
        ]));
    }

    /** @param array<string, string> $params */
    public function targetWanted(Request $request, array $params): Response
    {
        $set = ($this->catalog)()->set($params['set']);
        $options = BuildOptions::from($request->queryAll(), ($this->queries)()->activeCollections());
        if ($set === null || $options === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $rows = ($this->coverage)()->target($set['set_num'], $options);

        return $this->wanted($set['set_num'], array_map(
            static fn (array $r): array => ['part' => $r['part'], 'color_id' => $r['color_id'], 'qty' => $r['missing']],
            $rows
        ));
    }

    public function start(Request $request): Response
    {
        $options = BuildOptions::from($request->inputAll(), ($this->queries)()->activeCollections());
        if ($options === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        try {
            $result = ($this->builds)()->start($request->input('set'), $options);
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));

            return Response::redirect(url('/build'));
        }
        Session::flash('success', t('build.started', ['parts' => $result['reserved']]), $result['batch']);

        return Response::redirect(url('/builds/' . $result['id']));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params): Response
    {
        $builds = ($this->builds)();
        $build = $builds->find((int) $params['id']);
        if ($build === null) {
            return ErrorPage::render(404, null, $this->view);
        }

        return Response::html($this->view->render('build', [
            'title' => t('build.build_title', ['set' => $build['set_num']]),
            'build' => $build,
            'options' => $builds->options($build),
            'rows' => $this->describe($builds->progress((int) $build['id'])),
            'pickList' => $build['state'] === 'active' ? $builds->pickList((int) $build['id']) : [],
        ]));
    }

    /** @param array<string, string> $params */
    public function reserve(Request $request, array $params): Response
    {
        $id = (int) $params['id'];

        return $this->act($id, function () use ($id): int {
            $result = ($this->builds)()->reserve($id);
            Session::flash('success', t('build.reserved', ['parts' => $result['reserved']]), $result['batch']);

            return $result['batch'];
        });
    }

    /** @param array<string, string> $params */
    public function release(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        try {
            $batch = ($this->builds)()->release($id);
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));

            return Response::redirect(url('/builds/' . $id));
        }
        Session::flash('success', t('build.released'), $batch);

        return Response::redirect(url('/build'));
    }

    /** @param array<string, string> $params */
    public function finish(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $addAsSet = $request->input('add_set') === '1';

        return $this->act($id, function () use ($id, $addAsSet): int {
            $batch = ($this->builds)()->finish($id, $addAsSet);
            Session::flash('success', t($addAsSet ? 'build.finished_set' : 'build.finished'), $batch);

            return $batch;
        });
    }

    /** @param array<string, string> $params */
    public function buildWanted(Request $request, array $params): Response
    {
        $builds = ($this->builds)();
        $build = $builds->find((int) $params['id']);
        if ($build === null) {
            return ErrorPage::render(404, null, $this->view);
        }

        return $this->wanted((string) $build['set_num'], array_map(
            static fn (array $r): array => ['part' => $r['part'], 'color_id' => $r['color_id'], 'qty' => $r['missing']],
            $builds->progress((int) $build['id'])
        ));
    }

    /** @param callable(): int $work */
    private function act(int $id, callable $work): Response
    {
        if (($this->builds)()->find($id) === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        try {
            $work();
        } catch (\DomainException $e) {
            Session::flash('error', t('owned.error.' . $e->getMessage()));
        }

        return Response::redirect(url('/builds/' . $id));
    }

    /** @param list<array{part: string, color_id: int, qty: int}> $items */
    private function wanted(string $setNum, array $items): Response
    {
        $xml = (new WantedList(($this->pdo)()))->xml($items);
        $file = 'wanted-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $setNum) . '.xml';

        return (new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file . '"');
    }

    /**
     * Adds BrickLink numbers, names and colours to coverage rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function describe(array $rows): array
    {
        $catalog = ($this->catalog)();
        $parts = $catalog->parts(array_map('strval', array_column($rows, 'part')));
        $colors = $catalog->colors();
        $rows = array_map(static fn (array $row): array => $row + [
            'display' => $parts[$row['part']]['display'] ?? $row['part'],
            'part_name' => $parts[$row['part']]['name'] ?? '',
            'color_name' => $colors[$row['color_id']]['name'] ?? ('#' . $row['color_id']),
            'rgb' => $colors[$row['color_id']]['rgb'] ?? '',
        ], $rows);
        usort($rows, static fn (array $a, array $b): int
            => [-$a['missing'], $a['display'], $a['color_name']] <=> [-$b['missing'], $b['display'], $b['color_name']]);

        return $rows;
    }

    /**
     * @return array{theme: int, year_min: int, year_max: int, parts_min: int, parts_max: int,
     *     min_pct: int, hide_owned: bool, sort: string}
     */
    private static function filters(Request $request, BuildOptions $options): array
    {
        $int = static fn (string $key, int $default = 0): int
            => preg_match('/^\d{1,6}$/', $request->query($key)) ? (int) $request->query($key) : $default;
        $sort = $request->query('sort', 'coverage');
        if (!in_array($sort, self::SORTS, true) || ($sort === 'any' && !$options->anyColor)) {
            $sort = 'coverage';
        }

        return [
            'theme' => $int('theme'),
            'year_min' => $int('year_min'),
            'year_max' => $int('year_max'),
            'parts_min' => $int('parts_min', self::DEFAULT_MIN_PARTS),
            'parts_max' => $int('parts_max'),
            'min_pct' => min(100, $int('min_pct')),
            'hide_owned' => $request->query('hide_owned', $request->query('opt') === '' ? '1' : '0') === '1',
            'sort' => $sort,
        ];
    }

    /** @return list<array{id: int, label: string}> themes as "Parent › Child", sorted */
    private function themes(): array
    {
        $rows = ($this->pdo)()->query('SELECT id, name, parent_id FROM cat_theme')->fetchAll(PDO::FETCH_ASSOC);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $themes = [];
        foreach ($byId as $id => $row) {
            $label = (string) $row['name'];
            $parent = $row['parent_id'];
            for ($depth = 0; $parent !== null && isset($byId[(int) $parent]) && $depth < 5; $depth++) {
                $label = $byId[(int) $parent]['name'] . ' › ' . $label;
                $parent = $byId[(int) $parent]['parent_id'];
            }
            $themes[] = ['id' => $id, 'label' => $label];
        }
        usort($themes, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $themes;
    }

    /** @return array<int, true> the theme and all its descendants */
    private function themeSubtree(int $theme): array
    {
        $children = [];
        $rows = ($this->pdo)()->query('SELECT id, parent_id FROM cat_theme WHERE parent_id IS NOT NULL')
            ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $children[(int) $row['parent_id']][] = (int) $row['id'];
        }
        $result = [];
        $queue = [$theme];
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = true;
            array_push($queue, ...($children[$id] ?? []));
        }

        return $result;
    }
}

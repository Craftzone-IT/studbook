<?php

declare(strict_types=1);

namespace Studbook\Controller;

use Studbook\Catalog\CatalogRepository;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Http\Session;
use Studbook\I18n\Lang;
use Studbook\Owned\OwnedQueries;
use Studbook\Owned\PlacementAdvisor;
use Studbook\View;

/** "Where does it go?" (`/where?part=&c=`): the box a part belongs in. A plain GET form, linkable. */
final class WhereController
{
    private const SESSION_KEY = 'where_collection';

    /** @var \Closure(): OwnedQueries */
    private \Closure $queries;
    /** @var \Closure(): CatalogRepository */
    private \Closure $catalog;
    /** @var \Closure(): PlacementAdvisor */
    private \Closure $advisor;

    /**
     * @param callable(): OwnedQueries $queries
     * @param callable(): CatalogRepository $catalog
     * @param callable(): PlacementAdvisor $advisor
     */
    public function __construct(private readonly View $view, callable $queries, callable $catalog, callable $advisor)
    {
        $this->queries = \Closure::fromCallable($queries);
        $this->catalog = \Closure::fromCallable($catalog);
        $this->advisor = \Closure::fromCallable($advisor);
    }

    public function page(Request $request): Response
    {
        $collections = ($this->queries)()->activeCollections();
        $collectionId = self::collectionId($collections, $request->query('c'));
        $input = mb_substr(trim($request->query('part')), 0, 64);

        $part = null;
        $advice = [];
        $error = null;
        if ($input !== '' && $collectionId !== null) {
            $part = ($this->catalog)()->resolvePart($input);
            if ($part === null) {
                $error = t('box.part_unknown', ['part' => $input]);
            } else {
                $advice = self::withReasons(($this->advisor)()->advise($collectionId, $part['rb_num']));
            }
        }

        return Response::html($this->view->render('where', [
            'title' => $part !== null ? t('where.title') . ' ' . $part['display'] : t('where.title'),
            'input' => $input,
            'collections' => $collections,
            'collectionId' => $collectionId,
            'part' => $part,
            'category' => $part !== null ? ($this->catalog)()->categoryName($part['rb_num']) : null,
            'advice' => $advice,
            'inbox' => $part !== null && $collectionId !== null ? ($this->queries)()->inboxId($collectionId) : null,
            'error' => $error,
        ]));
    }

    /**
     * Adds the reason sentence (`text`) to each piece of advice.
     *
     * @param list<array<string, mixed>> $advice from PlacementAdvisor::advise()
     * @return list<array<string, mixed>> the same, each with `text`
     */
    public static function withReasons(array $advice): array
    {
        return array_map(static fn (array $a): array => $a + ['text' => t('where.reason.' . $a['reason'], [
            'part' => (string) ($a['detail']['part_label'] ?? ''),
            'qty' => Lang::formatter()->number((int) ($a['detail']['qty'] ?? 0)),
        ])], $advice);
    }

    /**
     * The best box for a part when the current box is not among the advised ones ("where does it go?").
     *
     * @param list<array<string, mixed>> $advice from PlacementAdvisor::advise()
     * @return list<array{id: int, name: string, reason: string}> empty or the best box
     */
    public static function hint(array $advice, int $currentBox): array
    {
        if ($advice === [] || in_array($currentBox, array_column($advice, 'box_id'), true)) {
            return [];
        }
        $best = self::withReasons([$advice[0]])[0];

        return [['id' => $best['box_id'], 'name' => $best['name'], 'reason' => $best['text']]];
    }

    /**
     * The collection to advise for: the requested one, else the one used last on this page, else the first.
     *
     * @param list<array{id: int, name: string, can_lend: int}> $collections active collections
     */
    private static function collectionId(array $collections, string $requested): ?int
    {
        $ids = array_column($collections, 'id');
        if ($ids === []) {
            return null;
        }
        $last = Session::get(self::SESSION_KEY);
        foreach ([$requested, is_int($last) ? (string) $last : ''] as $candidate) {
            if (preg_match('/^\d+$/', $candidate) === 1 && in_array((int) $candidate, $ids, true)) {
                Session::set(self::SESSION_KEY, (int) $candidate);

                return (int) $candidate;
            }
        }

        return $ids[0];
    }
}

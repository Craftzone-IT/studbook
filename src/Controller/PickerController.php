<?php

declare(strict_types=1);

namespace Studbook\Controller;

use PDO;
use Studbook\ErrorPage;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Owned\OwnedQueries;
use Studbook\View;

/**
 * Step-by-step part picker for a box (`/b/{id}/pick`): category → size →
 * part; the part then opens the colour step of "add here". Built for phones.
 */
final class PickerController
{
    public const PARTS_PER_PAGE = 60;

    /** @var \Closure(): PDO */
    private \Closure $pdo;
    /** @var \Closure(): OwnedQueries */
    private \Closure $queries;

    /**
     * @param callable(): PDO $pdo
     * @param callable(): OwnedQueries $queries
     */
    public function __construct(private readonly View $view, callable $pdo, callable $queries)
    {
        $this->pdo = \Closure::fromCallable($pdo);
        $this->queries = \Closure::fromCallable($queries);
    }

    /** @param array<string, string> $params */
    public function page(Request $request, array $params): Response
    {
        $box = ($this->queries)()->box((int) $params['id']);
        if ($box === null) {
            return ErrorPage::render(404, null, $this->view);
        }
        $pdo = ($this->pdo)();
        $category = $request->query('cat');
        $size = $request->query('size');
        $data = ['title' => t('picker.title'), 'box' => $box, 'step' => 'category'];

        if (!preg_match('/^\d+$/', $category)) {
            $data['categories'] = $pdo->query(
                'SELECT c.id, c.name, COUNT(*) AS parts, SUM(p.popularity) AS popularity
                 FROM cat_part_category c JOIN cat_part p ON p.category_id = c.id
                 GROUP BY c.id, c.name HAVING popularity > 0 ORDER BY popularity DESC'
            )->fetchAll(PDO::FETCH_ASSOC);

            return Response::html($this->view->render('picker', $data));
        }

        $stmt = $pdo->prepare('SELECT id, name FROM cat_part_category WHERE id = ?');
        $stmt->execute([(int) $category]);
        $data['category'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($data['category'] === null) {
            return ErrorPage::render(404, null, $this->view);
        }

        if ($size === '') {
            $stmt = $pdo->prepare(
                'SELECT LEAST(width, length) AS a, GREATEST(width, length) AS b, COUNT(*) AS parts,
                        SUM(popularity) AS popularity
                 FROM cat_part WHERE category_id = ? AND width IS NOT NULL
                 GROUP BY a, b ORDER BY a, b'
            );
            $stmt->execute([(int) $category]);
            $sizes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($sizes !== []) {
                $data['step'] = 'size';
                $data['sizes'] = $sizes;

                return Response::html($this->view->render('picker', $data));
            }
            $size = 'all';
        }

        $where = 'category_id = ?';
        $args = [(int) $category];
        if (preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
            $where .= ' AND ((width = ? AND length = ?) OR (width = ? AND length = ?))';
            array_push($args, (int) $m[1], (int) $m[2], (int) $m[2], (int) $m[1]);
        } elseif ($size === 'other') {
            $where .= ' AND width IS NULL';
        }
        $page = max(1, (int) $request->query('page', '1'));
        $stmt = $pdo->prepare(sprintf(
            'SELECT rb_num, bl_num, name FROM cat_part WHERE %s
             ORDER BY popularity DESC, CHAR_LENGTH(name), rb_num LIMIT %d OFFSET %d',
            $where,
            self::PARTS_PER_PAGE + 1,
            ($page - 1) * self::PARTS_PER_PAGE
        ));
        $stmt->execute($args);
        $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $data['step'] = 'parts';
        $data['size'] = $size;
        $data['page'] = $page;
        $data['more'] = count($parts) > self::PARTS_PER_PAGE;
        $data['parts'] = array_slice($parts, 0, self::PARTS_PER_PAGE);

        return Response::html($this->view->render('picker', $data));
    }
}

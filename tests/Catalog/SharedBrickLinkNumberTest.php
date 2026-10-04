<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use Studbook\App;
use Studbook\Auth\UserRepository;
use Studbook\Catalog\CatalogRepository;
use Studbook\Catalog\PartSearch;
use Studbook\Config;
use Studbook\Http\Csrf;
use Studbook\Http\Request;
use Studbook\Http\Response;
use Studbook\Owned\SetService;
use Studbook\Tests\Owned\OwnedTestCase;

/**
 * One BrickLink number, several Rebrickable parts: BrickLink 3003 is Rebrickable 3003 in solid
 * colours and 6223 in transparent ones. Entering "3003" must offer all their colours.
 */
final class SharedBrickLinkNumberTest extends OwnedTestCase
{
    private CatalogRepository $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT INTO cat_part (rb_num, name, category_id, bl_num, popularity) VALUES
            ('3003', 'Brick 2 x 2', 11, '3003', 900), ('6223', 'Brick 2 x 2 without Inside Ridges', 11, '3003', 90),
            ('3003a', 'Brick 2 x 2 without Inside Ridges', 11, '3003old', 5)");
        $this->pdo->exec("INSERT INTO cat_color (rb_id, name, rgb, is_trans, bl_id, bl_name) VALUES
            (33, 'Trans-Dark Blue', '0020A0', 1, 14, 'Trans-Dark Blue'),
            (114, 'Glitter Trans-Dark Pink', 'DF6695', 1, 100, 'Glitter Trans-Dark Pink')");
        $this->pdo->exec("INSERT INTO cat_part_color (part, color_id) VALUES
            ('3003', 4), ('3003', 0), ('6223', 33), ('6223', 114), ('6223', 4), ('3003a', 71)");
        $this->pdo->exec(
            "INSERT INTO cat_set (set_num, name, year, theme_id, num_parts) VALUES ('T-1', 'Trans', 1990, NULL, 3)"
        );
        $this->pdo->exec("INSERT INTO cat_inventory (set_num, part, color_id, is_spare, from_minifig, quantity) VALUES
            ('T-1', '6223', 33, 0, 0, 2), ('T-1', '3003', 4, 0, 0, 1)");
        $this->catalog = new CatalogRepository($this->pdo);
    }

    public function testColoursOfAllPartsWithTheSameBrickLinkNumber(): void
    {
        $colors = [];
        foreach ($this->catalog->colorsForPart('3003') as $color) {
            $colors[$color['name']] = $color['part'];
        }
        self::assertSame(
            ['Black' => '3003', 'Glitter Trans-Dark Pink' => '6223', 'Red' => '3003', 'Trans-Dark Blue' => '6223'],
            self::sorted($colors),
            'the chosen part keeps its own colours; the others come from 6223'
        );
        self::assertSame('6223', $this->catalog->partForColor('3003', 33));
        self::assertSame('6223', $this->catalog->partForColor('6223', 4), 'its own part when it has the colour');
        self::assertNull($this->catalog->partForColor('3003', 71), '3003a is another BrickLink number');
        self::assertSame(['3003a'], $this->catalog->siblingParts('3003a'));
        self::assertSame(['3001'], $this->catalog->siblingParts('3001'));

        $search = (new PartSearch($this->pdo, sys_get_temp_dir() . '/studbook-sib-unused'))->search('3003');
        self::assertSame(['3003', '3003a'], array_column($search['parts'], 'rb_num'), 'BrickLink 3003 listed once');
    }

    public function testEntryBoxAndSetDeltasStoreTheRightPart(): void
    {
        (new UserRepository($this->pdo))->create('owner', 'correct horse battery');
        $_SESSION = [];
        $app = new App(new Config([
            'APP_URL' => 'https://studbook.test',
            'APP_ENV' => 'production',
            'DB_HOST' => 'x',
            'DB_NAME' => 'x',
            'DB_USER' => 'x',
            'DB_PASSWORD' => '',
            'IMAGE_CACHE_PATH' => sys_get_temp_dir() . '/studbook-img-unused',
            'STORAGE_PATH' => sys_get_temp_dir() . '/studbook-sib-unused',
        ], dirname(__DIR__, 2)), $this->pdo);
        $post = static fn (string $path, array $data): Response => $app->handle(new Request(
            'POST',
            $path,
            post: $data + [Csrf::FIELD => Csrf::token()],
            server: ['REMOTE_ADDR' => '192.0.2.50']
        ));
        $get = static fn (string $path, array $query = []): Response => $app->handle(
            new Request('GET', $path, query: $query, server: ['REMOTE_ADDR' => '192.0.2.50'])
        );
        $post('/login', ['username' => 'owner', 'password' => 'correct horse battery']);
        [$id] = $this->owned->createCollection('Mine', false, 'Inbox');
        $box = $this->queries->inboxId($id);

        $part = json_decode($get('/b/' . $box . '/entry/part', ['part' => '3003'])->body, true);
        self::assertContains('Trans-Dark Blue', array_column($part['colors'], 'name'));
        $add = $get('/b/' . $box . '/add', ['part' => '3003'])->body;
        self::assertStringContainsString('Glitter Trans-Dark Pink', $add);
        self::assertStringContainsString('part=6223&amp;color=114', $add, 'the picture of the stored part');

        $post('/b/' . $box . '/entry', ['part' => '3003', 'color' => '33', 'qty' => '2']);
        $post('/b/' . $box . '/lots', ['part' => '3003', 'color' => '114', 'qty' => '1']);
        $post('/b/' . $box . '/lots', ['part' => '3003', 'color' => '4', 'qty' => '5']);
        $lots = array_map(
            static fn (array $l): string => $l['part'] . '/' . $l['color_id'] . '=' . $l['qty'],
            $this->queries->lots($box)
        );
        sort($lots);
        self::assertSame(['3003/4=5', '6223/114=1', '6223/33=2'], $lots);
        self::assertSame(422, $post('/b/' . $box . '/entry', ['part' => '3003', 'color' => '71'])->status);

        $sets = new SetService($this->batches, $this->queries, $this->catalog);
        $setId = $sets->addSet($id, 'T-1', 'built', 'locked', null)['ids'][0];
        $form = $get('/s/' . $setId . '/delta', ['kind' => 'missing', 'part' => '3003'])->body;
        self::assertStringContainsString('Trans-Dark Blue', $form, 'the transparent 6223 of the set counts as 3003');
        $post('/s/' . $setId . '/delta', ['part' => '3003', 'color' => '33', 'kind' => 'missing', 'qty' => '1']);
        $post('/s/' . $setId . '/delta', ['part' => '3003', 'color' => '114', 'kind' => 'extra', 'qty' => '1']);
        $deltas = array_map(
            static fn (array $d): string => $d['part'] . '/' . $d['color_id'] . '=' . $d['qty'],
            $this->queries->deltas($setId)
        );
        sort($deltas);
        self::assertSame(['6223/114=1', '6223/33=-1'], $deltas);
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>
     */
    private static function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }
}

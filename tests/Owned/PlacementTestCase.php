<?php

declare(strict_types=1);

namespace Studbook\Tests\Owned;

use Studbook\Owned\PlacementAdvisor;

/**
 * A typical box layout sorted by part type (`docs/placement-advice.md`):
 * one collection with its boxes and their labels, plus the catalogue parts
 * with categories and sizes.
 */
abstract class PlacementTestCase extends OwnedTestCase
{
    protected const TILES = 19;
    protected const PLATES = 14;
    protected const PLATES_SPECIAL = 9;
    protected const BRICKS = 11;
    protected const BRICKS_SPECIAL = 5;
    protected const SLOPES = 3;
    protected const TECHNIC_PINS = 53;
    protected const MINIFIG_HEADS = 59;

    protected PlacementAdvisor $advisor;
    protected int $collection;
    protected int $inbox;
    /** @var array<string, int> box ids by short name */
    protected array $boxes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('UPDATE cat_part SET width = 1, length = 1 WHERE rb_num = \'3024\'');
        $this->pdo->exec('UPDATE cat_part SET width = 2, length = 4 WHERE rb_num = \'3001\'');
        $this->pdo->exec('UPDATE cat_part SET width = 1, length = 2 WHERE rb_num = \'3794b\'');
        $this->pdo->exec('UPDATE cat_part SET width = 2, length = 4 WHERE rb_num = \'3001pr0001\'');
        $parts = [
            ['3070b', 'Tile 1 x 1', self::TILES, 1, 1],
            ['3069b', 'Tile 1 x 2', self::TILES, 1, 2],
            ['63864', 'Tile 1 x 3', self::TILES, 1, 3],
            ['2431', 'Tile 1 x 4', self::TILES, 1, 4],
            ['6636', 'Tile 1 x 6', self::TILES, 1, 6],
            ['3068b', 'Tile 2 x 2', self::TILES, 2, 2],
            ['87079', 'Tile 2 x 4', self::TILES, 2, 4],
            ['26603', 'Tile 2 x 3', self::TILES, 2, 3],
            ['3023', 'Plate 1 x 2', self::PLATES, 1, 2],
            ['3030', 'Plate 4 x 10', self::PLATES, 4, 10],
            ['3036', 'Plate 6 x 8', self::PLATES, 6, 8],
            ['3033', 'Plate 6 x 10', self::PLATES, 6, 10],
            ['3795', 'Plate 2 x 6', self::PLATES, 2, 6],
            ['3832', 'Plate 2 x 10', self::PLATES, 2, 10],
            ['2445', 'Plate 2 x 12', self::PLATES, 2, 12],
            ['3794a', 'Plate Special 1 x 2 with 1 Stud without Groove', self::PLATES_SPECIAL, 1, 2],
            ['32028', 'Plate Special 1 x 2 with Door Rail', self::PLATES_SPECIAL, 1, 2],
            ['11476', 'Plate Special 1 x 2 with Clip Horizontal on Side', self::PLATES_SPECIAL, 1, 2],
            ['63868', 'Plate Special 1 x 2 with Clip Horizontal on End', self::PLATES_SPECIAL, 1, 2],
            ['3005', 'Brick 1 x 1', self::BRICKS, 1, 1],
            ['2453b', 'Brick 1 x 1 x 5', self::BRICKS, 1, 1],
            ['4070', 'Brick Special 1 x 1 with Headlight', self::BRICKS_SPECIAL, 1, 1],
            ['87087', 'Brick Special 1 x 1 with Stud on Side', self::BRICKS_SPECIAL, 1, 1],
            ['4733', 'Brick Special 1 x 1 with Studs on 4 Sides', self::BRICKS_SPECIAL, 1, 1],
            ['30363', 'Slope 18° 4 x 2', self::SLOPES, 2, 4],
            ['3039', 'Slope 45° 2 x 2', self::SLOPES, 2, 2],
            ['32054', 'Technic Pin Long with Friction and Bush', self::TECHNIC_PINS, null, null],
            ['2780', 'Technic Pin with Friction', self::TECHNIC_PINS, null, null],
            ['3626c', 'Minifig Head', self::MINIFIG_HEADS, null, null],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO cat_part (rb_num, name, category_id, bl_num, width, length) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($parts as [$rb, $name, $category, $width, $length]) {
            $stmt->execute([$rb, $name, $category, $rb, $width, $length]);
        }
        $this->pdo->exec("INSERT INTO cat_part_category (id, name) VALUES
            (19, 'Tiles'), (14, 'Plates'), (9, 'Plates Special'), (11, 'Bricks'), (5, 'Bricks Special'),
            (3, 'Bricks Sloped'), (53, 'Technic Pins'), (59, 'Minifig Heads')");
        // 3794a and 3794b are mould variants; 3001pr0001 is a print of 3001.
        $this->pdo->exec("INSERT INTO cat_part_rel (rel_type, child, parent) VALUES
            ('M', '3794b', '3794a'), ('P', '3001pr0001', '3001')");
        $this->pdo->exec("INSERT INTO cat_part_canon (part, variant, print) VALUES
            ('3794a', '3794a', '3794a'), ('3794b', '3794a', '3794a'),
            ('3001', '3001', '3001'), ('3001pr0001', '3001pr0001', '3001')");

        [$this->collection] = $this->owned->createCollection('Mine', false, 'Inbox');
        $this->inbox = (int) $this->queries->inboxId($this->collection);
        $layout = [
            'tiles' => ['01 Tiles', 'large', ['3070b', '3069b', '63864', '2431', '6636', '3068b', '87079']],
            'small_plates' => ['02 Small plates', 'large', ['3794a', '3023']],
            'large_plates' => ['03 Large plates', 'large', ['3030', '3036']],
            'long_plates' => ['04 Plates 2 x 6 to 2 x 12', 'large', ['3795', '3832', '2445']],
            'small_bricks' => ['05 Small bricks', 'large', ['3005']],
            'large_bricks' => ['06 Large bricks', 'large', ['3001']],
            'slopes' => ['07 Slopes', 'large', ['3039']],
            'technic' => ['08 Technic', 'large', ['2780']],
            'studs_jar' => ['K06 Studs on side', 'jar', ['87087', '4733']],
            'clip_jar' => ['K07 Clip plates', 'jar', ['11476', '63868']],
            'misc' => ['09 Misc', 'large', []],
        ];
        foreach ($layout as $key => [$name, $type, $labels]) {
            [$id] = $this->owned->createBox($this->collection, $name, $type);
            if ($labels !== []) {
                $this->owned->setLabels($id, $labels);
            }
            $this->boxes[$key] = $id;
        }
        $this->advisor = new PlacementAdvisor($this->pdo);
    }

    /** @return list<string> short names of the advised boxes, best first */
    protected function advised(string $part, ?int $collection = null): array
    {
        $names = array_flip($this->boxes);

        return array_map(
            static fn (array $a): string => $names[$a['box_id']] ?? 'box ' . $a['box_id'],
            $this->advisor->advise($collection ?? $this->collection, $part)
        );
    }
}

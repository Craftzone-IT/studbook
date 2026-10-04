<?php

declare(strict_types=1);

namespace Studbook\Tests\Catalog;

use PDO;
use Studbook\Catalog\BrickLinkCatalog;
use Studbook\Catalog\CatalogImporter;
use Studbook\Catalog\ImportException;
use Studbook\Tests\DatabaseTestCase;

final class CatalogImporterTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->dir = CatalogFixtures::downloadFolder();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            CatalogFixtures::remove($this->dir);
        }
    }

    public function testImportsCatalogueWithBrickLinkNumbers(): void
    {
        $stats = $this->import();

        self::assertSame(6, $stats['counts']['cat_part']);
        self::assertSame(3, $stats['counts']['cat_set']);
        self::assertSame(
            ['total' => 7, 'matched' => 5, 'matched_api' => 0, 'unmatched' => ['Imaginary Sparkle']],
            $stats['colors']
        );
        self::assertSame(2, $stats['parts']['matched_exact']);
        self::assertSame(1, $stats['parts']['matched_alternate']);
        self::assertSame(3, $stats['parts']['unmatched']);
        self::assertSame(
            ['3626c', '973c01'],
            array_column($stats['parts']['unmatched_samples'], 'rb_num'),
            'prints are left out of the samples'
        );

        $part = $this->row("SELECT * FROM cat_part WHERE rb_num = '3794b'");
        self::assertSame('15573', $part['bl_num']);
        self::assertSame('alternate', $part['bl_match']);
        $brick = $this->row("SELECT * FROM cat_part WHERE rb_num = '3001'");
        self::assertSame([2, 4, 3], [(int) $brick['width'], (int) $brick['length'], (int) $brick['height_plates']]);
        self::assertSame(86, (int) $this->row('SELECT bl_id FROM cat_color WHERE rb_id = 71')['bl_id']);
        self::assertSame(0, (int) $this->row('SELECT bl_id FROM cat_color WHERE rb_id = 9999')['bl_id']);
        self::assertSame('Torso, Plain', $this->row("SELECT name FROM cat_part WHERE rb_num = '973c01'")['name']);
        self::assertSame(3, (int) $this->row('SELECT COUNT(*) AS n FROM cat_bl_part')['n']);
    }

    public function testFlattensInventoriesWithMinifigsSubSetsAndDefaultVersion(): void
    {
        $this->import();

        self::assertSame([
            ['3001', 4, 0, 0, 2],
            ['3024', 71, 0, 0, 4],
            ['3024', 71, 1, 0, 1],
            ['3626c', 0, 0, 1, 1],
            ['973c01', 0, 0, 1, 1],
        ], $this->inventory('1000-1'));

        // The pack contains the car twice, including its minifig.
        self::assertSame([
            ['3001', 4, 0, 0, 4],
            ['3024', 71, 0, 0, 8],
            ['3024', 71, 1, 0, 2],
            ['3626c', 0, 0, 1, 2],
            ['973c01', 0, 0, 1, 2],
        ], $this->inventory('1001-1'));

        self::assertCount(2, $this->inventory('fig-000001'));
        // 3001 is in 1000-1 and (through the car) in 1001-1; minifig inventories do not count.
        self::assertSame(2, (int) $this->row("SELECT popularity FROM cat_part WHERE rb_num = '3001'")['popularity']);
        self::assertSame(0, (int) $this->row("SELECT popularity FROM cat_part WHERE rb_num = '3794b'")['popularity']);
        self::assertSame([], $this->inventory('1002-1'));
    }

    public function testPartColoursComeFromElementsAndInventories(): void
    {
        $this->import();

        $rows = $this->pdo->query('SELECT part, color_id, img_url FROM cat_part_color ORDER BY part, color_id')
            ->fetchAll(PDO::FETCH_NUM);
        self::assertContains(['3001', 0, null], $rows);
        self::assertContains(['3001', 4, 'https://example.invalid/parts/3001-4.jpg'], $rows);
        self::assertContains(['3794b', 0, null], $rows);
        self::assertContains(['973c01', 0, null], $rows);
    }

    public function testReimportReplacesTablesAndLeavesNoStaging(): void
    {
        $this->import();
        $this->import();

        self::assertSame(6, (int) $this->row('SELECT COUNT(*) AS n FROM cat_part')['n']);
        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([], array_values(array_filter(
            $tables,
            static fn (string $t): bool => str_ends_with($t, '_new')
                || str_ends_with($t, '_old')
                || str_starts_with($t, 'imp_')
        )));
    }

    public function testRebrickableApiIdsComeFirst(): void
    {
        $stats = $this->import(null, [
            '3001pr0001' => ['3001pb001'],
            '3001' => ['3001'],
        ], [
            '1000' => ['ids' => [999], 'names' => ['Imaginary Sparkle']],
            '4' => ['ids' => [5], 'names' => ['Red']],
        ]);

        self::assertSame(2, $stats['parts']['matched_api']);
        self::assertSame(1, $stats['parts']['matched_exact'], '3024 still matches the BrickLink file');
        self::assertSame(1, $stats['parts']['matched_alternate']);
        $print = $this->row("SELECT bl_num, bl_match FROM cat_part WHERE rb_num = '3001pr0001'");
        self::assertSame(['bl_num' => '3001pb001', 'bl_match' => 'api'], $print);
        self::assertSame(2, $stats['colors']['matched_api']);
        self::assertSame([], $stats['colors']['unmatched']);
        self::assertSame(
            ['bl_id' => 999, 'bl_name' => 'Imaginary Sparkle'],
            array_map(
                fn ($v) => is_numeric($v) ? (int) $v : $v,
                $this->row('SELECT bl_id, bl_name FROM cat_color WHERE rb_id = 1000')
            )
        );
        $red = $this->row('SELECT bl_name FROM cat_color WHERE rb_id = 4');
        self::assertSame('Red', $red['bl_name'], 'the name from the BrickLink file wins');
    }

    public function testWorksWithoutBrickLinkFiles(): void
    {
        $stats = $this->import('/nonexistent');

        self::assertSame(0, $stats['parts']['matched_exact']);
        self::assertSame(6, $stats['counts']['cat_part']);
        self::assertNull($this->row("SELECT bl_num FROM cat_part WHERE rb_num = '3001'")['bl_num']);
    }

    public function testBrokenFileKeepsThePreviousCatalogue(): void
    {
        $this->import();
        file_put_contents($this->dir . '/sets.csv.gz', gzencode("wrong,header\n1,2\n"));

        try {
            $this->import();
            self::fail('Expected an ImportException');
        } catch (ImportException $e) {
            self::assertStringContainsString('missing column', $e->getMessage());
        }

        self::assertSame(3, (int) $this->row('SELECT COUNT(*) AS n FROM cat_set')['n']);
        self::assertSame([], $this->pdo->query("SHOW TABLES LIKE '%\\_new'")->fetchAll());
    }

    /**
     * @param array<string, list<string>> $apiParts
     * @param array<string, array{ids: list<int>, names: list<string>}> $apiColors
     * @return array<string, mixed>
     */
    private function import(?string $bricklinkDir = null, array $apiParts = [], array $apiColors = []): array
    {
        $bricklink = BrickLinkCatalog::load($bricklinkDir ?? dirname(__DIR__) . '/fixtures/bricklink');
        $importer = new CatalogImporter($this->pdo, static fn () => null);

        return $importer->import(CatalogFixtures::files($this->dir), $bricklink, $apiParts, $apiColors);
    }

    /** @return array<string, mixed> */
    private function row(string $sql): array
    {
        return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<list<int|string>> */
    private function inventory(string $set): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT part, color_id, is_spare, from_minifig, quantity FROM cat_inventory
             WHERE set_num = ? ORDER BY part, color_id, is_spare'
        );
        $stmt->execute([$set]);

        return $stmt->fetchAll(PDO::FETCH_NUM);
    }
}

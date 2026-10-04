<?php

declare(strict_types=1);

namespace Studbook\Tests\Camera;

use Studbook\Camera\LabelCandidates;
use Studbook\Catalog\CatalogRepository;
use Studbook\Tests\Owned\OwnedTestCase;

final class LabelCandidatesTest extends OwnedTestCase
{
    public function testOcrTextBecomesCheckedPartNumbers(): void
    {
        $text = "Bricks 2x4\n3001  3O24\n15573 l5573\n99999 3OO1\n3001pr0001 x";
        $candidates = (new LabelCandidates(new CatalogRepository($this->pdo)))->fromText($text);

        self::assertSame([
            ['3001', '3001', 'ok', '3001'],
            ['3O24', '3024', 'fixed', '3024'],
            ['15573', '3794b', 'ok', '15573'],
            ['99999', null, 'unknown', '99999'],
            ['3001pr0001', '3001pr0001', 'ok', '3001pr0001'],
        ], array_map(
            static fn (array $c): array => [$c['raw'], $c['rb_num'], $c['status'], $c['display']],
            $candidates
        ), 'look-alike letters are corrected, duplicates, words and sizes are dropped');
    }

    public function testNothingUsefulGivesNoCandidates(): void
    {
        $candidates = (new LabelCandidates(new CatalogRepository($this->pdo)))->fromText("Plates\n1x2 ---\n");
        self::assertSame([], $candidates);
    }
}

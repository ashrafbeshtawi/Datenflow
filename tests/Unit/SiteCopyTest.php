<?php

namespace App\Tests\Unit;

use App\Content\SiteCopy;
use PHPUnit\Framework\TestCase;

class SiteCopyTest extends TestCase
{
    public function testEnglishMirrorsGermanStructure(): void
    {
        $de = SiteCopy::for('de');
        $en = SiteCopy::for('en');

        self::assertSame($this->keyTree($de), $this->keyTree($en), 'DE and EN copy must have identical structure');
    }

    public function testUnknownLocaleFallsBackToGerman(): void
    {
        self::assertSame(SiteCopy::for('de'), SiteCopy::for('fr'));
    }

    /** Nested key structure (ignores leaf values). */
    private function keyTree(array $tree): array
    {
        $result = [];
        foreach ($tree as $key => $value) {
            $result[$key] = is_array($value) ? $this->keyTree($value) : true;
        }

        return $result;
    }
}

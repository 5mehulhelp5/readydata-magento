<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Category;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Category\PathParser;

class PathParserTest extends TestCase
{
    /**
     * @dataProvider parseDataProvider
     */
    public function testParse(string $reference, ?array $expected): void
    {
        self::assertSame($expected, (new PathParser())->parse($reference));
    }

    /**
     * @return array<string, array{string, ?array}>
     */
    public static function parseDataProvider(): array
    {
        return [
            'numeric id' => ['42', ['type' => 'id', 'id' => 42]],
            'numeric id padded' => [' 42 ', ['type' => 'id', 'id' => 42]],
            'simple path' => [
                'Default Category/Men/Shirts',
                ['type' => 'path', 'segments' => ['Default Category', 'Men', 'Shirts']],
            ],
            'name with digits is a path' => [
                '2024 Collection',
                ['type' => 'path', 'segments' => ['2024 Collection']],
            ],
            'segments trimmed' => [
                'Default Category / Men /Shirts ',
                ['type' => 'path', 'segments' => ['Default Category', 'Men', 'Shirts']],
            ],
            'leading, trailing and double separators dropped' => [
                '/A//B/',
                ['type' => 'path', 'segments' => ['A', 'B']],
            ],
            'single segment (root reference)' => [
                'Default Category',
                ['type' => 'path', 'segments' => ['Default Category']],
            ],
            'empty' => ['', null],
            'whitespace only' => ['   ', null],
            'bare separator' => ['/', null],
            'separators and whitespace only' => [' / / ', null],
            'negative number is a path' => [
                '-1',
                ['type' => 'path', 'segments' => ['-1']],
            ],
            'escaped slash is a literal slash in the name' => [
                'Default Category/Wo\/Men',
                ['type' => 'path', 'segments' => ['Default Category', 'Wo/Men']],
            ],
            'escaped slash in a deep path' => [
                'Default Category/Wo\/Men/Shirts',
                ['type' => 'path', 'segments' => ['Default Category', 'Wo/Men', 'Shirts']],
            ],
            'escaped backslash before a separator splits' => [
                'A\\\\/B',
                ['type' => 'path', 'segments' => ['A\\', 'B']],
            ],
            'trailing lone backslash is a literal backslash' => [
                'A\\',
                ['type' => 'path', 'segments' => ['A\\']],
            ],
            'escape before an ordinary character yields that character' => [
                'A\B',
                ['type' => 'path', 'segments' => ['AB']],
            ],
            'escaped digits are a path, not an id' => [
                '\42',
                ['type' => 'path', 'segments' => ['42']],
            ],
            'escaped whitespace decodes then trims away' => [
                'A/\ /B',
                ['type' => 'path', 'segments' => ['A', 'B']],
            ],
            'escaped and unescaped slash differ' => [
                'a\/b',
                ['type' => 'path', 'segments' => ['a/b']],
            ],
        ];
    }

    /**
     * @dataProvider buildKeyDataProvider
     * @param string[] $segments
     */
    public function testBuildKeyIsCanonicalAndRoundTrips(array $segments, string $expectedKey): void
    {
        self::assertSame($expectedKey, PathParser::buildKey($segments));
        self::assertSame(
            ['type' => 'path', 'segments' => $segments],
            (new PathParser())->parse($expectedKey)
        );
    }

    /**
     * @return array<string, array{string[], string}>
     */
    public static function buildKeyDataProvider(): array
    {
        return [
            'plain segments' => [['Default Category', 'Men'], 'Default Category/Men'],
            'slash in a name' => [['Default Category', 'Wo/Men'], 'Default Category/Wo\/Men'],
            'backslash in a name' => [['A\\', 'B'], 'A\\\\/B'],
            'collision-free vs deeper path' => [['a/b'], 'a\/b'],
        ];
    }
}

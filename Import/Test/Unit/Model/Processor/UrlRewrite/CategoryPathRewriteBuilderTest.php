<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Processor\UrlRewrite;

use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Processor\UrlRewrite\CategoryPathRewriteBuilder;
use ReadyData\Import\Model\ResourceModel\Category;

class CategoryPathRewriteBuilderTest extends TestCase
{
    public function testGeneratesCategoryAndAnchorRewrites(): void
    {
        $categoryResource = $this->createMock(Category::class);
        // Category 5 (level 3) under anchor 3 (level 2) under store root 2 (level 1).
        $categoryResource->method('getAncestry')->willReturn([
            5 => ['level' => 3, 'ancestors' => [2, 3]],
        ]);
        $categoryResource->method('getExistingByIds')->willReturn([
            5 => ['entity_id' => 5, 'parent_id' => 3, 'level' => 3, 'path' => '1/2/3/5'],
            3 => ['entity_id' => 3, 'parent_id' => 2, 'level' => 2, 'path' => '1/2/3'],
            2 => ['entity_id' => 2, 'parent_id' => 1, 'level' => 1, 'path' => '1/2'],
        ]);
        $categoryResource->method('getUrlPaths')->willReturn([
            5 => 'men/tops',
            3 => 'men',
            2 => '',
        ]);
        $categoryResource->method('getIsAnchor')->willReturn([
            3 => true,
            2 => false,
        ]);

        $builder = new CategoryPathRewriteBuilder($categoryResource);
        $result = $builder->build([42 => [5]], [42 => 'white-shirt'], 1, '.html');

        self::assertArrayHasKey(42, $result);
        $byPath = [];
        foreach ($result[42] as $candidate) {
            $byPath[$candidate['request_path']] = $candidate;
        }

        // Assigned category 5.
        self::assertArrayHasKey('men/tops/white-shirt.html', $byPath);
        self::assertSame(
            'catalog/product/view/id/42/category/5',
            $byPath['men/tops/white-shirt.html']['target_path']
        );
        // Anchor ancestor 3 (is_anchor, level >= 2).
        self::assertArrayHasKey('men/white-shirt.html', $byPath);
        self::assertSame(5, $byPath['men/tops/white-shirt.html']['category_id']);
        self::assertSame(3, $byPath['men/white-shirt.html']['category_id']);
        // Store root 2 is not an anchor -> no rewrite.
        self::assertCount(2, $result[42]);
    }
}

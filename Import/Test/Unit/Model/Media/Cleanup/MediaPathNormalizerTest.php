<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Test\Unit\Model\Media\Cleanup;

use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Framework\File\Uploader;
use PHPUnit\Framework\TestCase;
use ReadyData\Import\Model\Media\Cleanup\MediaPathNormalizer;

/**
 * The highest-value tests in this feature. Every number the orphan report
 * prints depends on the disk's idea of a path and the database's agreeing, and
 * when they disagree nothing errors — the report simply calls every referenced
 * file an orphan.
 */
class MediaPathNormalizerTest extends TestCase
{
    private function normalizer(string $basePath = 'catalog/product'): MediaPathNormalizer
    {
        $mediaConfig = $this->createMock(MediaConfig::class);
        $mediaConfig->method('getBaseMediaPath')->willReturn($basePath);

        return new MediaPathNormalizer($mediaConfig);
    }

    public function testMediaRelativePathBecomesTheGalleryStoredForm(): void
    {
        self::assertSame('/a/b/x.jpg', $this->normalizer()->fromMediaRelative('catalog/product/a/b/x.jpg'));
    }

    public function testALeadingSlashIsTolerated(): void
    {
        self::assertSame('/a/b/x.jpg', $this->normalizer()->fromMediaRelative('/catalog/product/a/b/x.jpg'));
    }

    /**
     * getAbsolutePath() concatenation can double a separator, and "/a//b/x.jpg"
     * would never match the stored "/a/b/x.jpg".
     */
    public function testDuplicateSlashesAreCollapsed(): void
    {
        self::assertSame('/a/b/x.jpg', $this->normalizer()->fromMediaRelative('catalog//product//a//b//x.jpg'));
    }

    public function testBackslashesAreNormalised(): void
    {
        self::assertSame('/a/b/x.jpg', $this->normalizer()->fromMediaRelative('catalog\\product\\a\\b\\x.jpg'));
    }

    public function testAPathOutsideTheBaseIsRejected(): void
    {
        self::assertNull($this->normalizer()->fromMediaRelative('catalog/category/x.jpg'));
    }

    /**
     * A prefix test alone would accept this and hand back "X/a/b/x.jpg",
     * comparing an unrelated directory's files against the product gallery.
     */
    public function testADirectoryWhoseNameMerelyStartsWithTheBaseIsRejected(): void
    {
        self::assertNull($this->normalizer()->fromMediaRelative('catalog/productX/a/b/x.jpg'));
    }

    public function testTheBasePathItselfIsRejected(): void
    {
        self::assertNull($this->normalizer()->fromMediaRelative('catalog/product'));
        self::assertNull($this->normalizer()->fromMediaRelative('catalog/product/'));
    }

    public function testEmptyAndNullByteArePathsAreRejected(): void
    {
        self::assertNull($this->normalizer()->fromMediaRelative(''));
        self::assertNull($this->normalizer()->fromMediaRelative("catalog/product/a/b/x\0.jpg"));
    }

    /**
     * getDispersionPath() maps a leading dot to an underscore, so a dotfile
     * disperses to "/_/h" — a real stored path this must not mangle.
     */
    public function testLeadingDotDispersionSurvives(): void
    {
        self::assertSame('/_/h/.hidden.jpg', $this->normalizer()->fromMediaRelative('catalog/product/_/h/.hidden.jpg'));
    }

    public function testAPathTooLongForTheReferenceColumnsIsFlagged(): void
    {
        $normalizer = $this->normalizer();

        self::assertTrue($normalizer->exceedsColumnLimit('/a/b/' . str_repeat('z', 260) . '.jpg'));
        self::assertFalse($normalizer->exceedsColumnLimit('/a/b/x.jpg'));
    }

    /**
     * The offset the content-link SQL slices at. Hardcoding 15 would work on a
     * default store and silently cut every path in the wrong place on any
     * other, producing a source that matches nothing.
     */
    public function testBasePathLengthFollowsTheConfiguredBasePath(): void
    {
        self::assertSame(15, $this->normalizer()->basePathLength());
        self::assertSame(14, $this->normalizer('media/products')->basePathLength());
        self::assertSame(
            '/a/b/x.jpg',
            $this->normalizer('media/products')->fromMediaRelative('media/products/a/b/x.jpg')
        );
    }

    /**
     * Informational, never a filter — but the report describes what it found
     * with it, so it has to mean what it says.
     */
    public function testDispersedShapeIsExactlyTwoSingleCharacterLevels(): void
    {
        $normalizer = $this->normalizer();

        self::assertTrue($normalizer->isDispersedShape('/a/b/x.jpg'));
        self::assertFalse($normalizer->isDispersedShape('/a/x.jpg'));
        self::assertFalse($normalizer->isDispersedShape('/a/b/c/x.jpg'));
        self::assertFalse($normalizer->isDispersedShape('/ab/c/x.jpg'));
    }

    /**
     * The test that binds the two conventions together.
     *
     * Core decides where an uploaded file lands; this class has to derive the
     * same string back from the resulting path. Asserting against
     * Uploader::getDispersionPath() itself — rather than against hand-written
     * expectations — is what makes it a real check: if core ever changes the
     * rule, this fails instead of the report quietly going wrong.
     *
     * @dataProvider fileNameProvider
     */
    public function testCanonicalFormReproducesCoreDispersion(string $fileName): void
    {
        $stored = Uploader::getDispersionPath($fileName) . '/' . $fileName;

        self::assertSame($stored, $this->normalizer()->fromMediaRelative('catalog/product' . $stored));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fileNameProvider(): array
    {
        return [
            'module-generated name' => ['hero_1a2b3c4d.jpg'],
            'two characters' => ['ab.png'],
            'leading dot' => ['.hidden.jpg'],
            'single character stem' => ['x.jpg'],
            'mixed case' => ['Zebra_9f.webp'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AssetMapper;
use Codeception\Test\Unit;
use Pimcore\Model\Asset\Image;

class AssetMapperTest extends Unit
{
    private AssetMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new AssetMapper('http://pimcore.local');
    }

    public function testPayloadContainsExpectedFields(): void
    {
        $image = $this->mockImage(id: 42, fileName: 'shoes.jpg', fullPath: '/products/shoes.jpg');

        $payload = $this->mapper->toShopwareMediaPayload($image);

        self::assertSame(md5('42'), $payload['id']);
        self::assertFalse($payload['private']);
        self::assertSame('shoes.jpg', $payload['title']);
    }

    public function testIdIsDeterministicMd5OfAssetId(): void
    {
        $image = $this->mockImage(id: 7, fileName: 'img.png', fullPath: '/img.png');

        $payload = $this->mapper->toShopwareMediaPayload($image);

        self::assertSame(md5('7'), $payload['id']);
    }

    public function testPublicUrlConcatenatesBaseUrlAndFullPath(): void
    {
        $image = $this->mockImage(id: 1, fileName: 'photo.jpg', fullPath: '/Sample/photo.jpg');

        $url = $this->mapper->getAssetPublicUrl($image);

        self::assertSame('http://pimcore.local/Sample/photo.jpg', $url);
    }

    public function testPublicUrlTrimsTrailingSlashFromBase(): void
    {
        $mapper = new AssetMapper('http://pimcore.local/');
        $image = $this->mockImage(id: 1, fileName: 'a.jpg', fullPath: '/a.jpg');

        $url = $mapper->getAssetPublicUrl($image);

        self::assertSame('http://pimcore.local/a.jpg', $url);
    }

    public function testThrowsWhenAssetIdIsNull(): void
    {
        $image = $this->createMock(Image::class);
        $image->method('getId')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);

        $this->mapper->toShopwareMediaPayload($image);
    }

    // ------------------------------------------------------------------

    private function mockImage(int $id, string $fileName, string $fullPath): Image
    {
        $image = $this->createMock(Image::class);
        $image->method('getId')->willReturn($id);
        $image->method('getFilename')->willReturn($fileName);
        $image->method('getFullPath')->willReturn($fullPath);
        return $image;
    }
}

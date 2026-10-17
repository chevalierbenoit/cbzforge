<?php

namespace App\Tests\Service;

use App\Entity\Setting;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class SettingsServiceTest extends TestCase
{
    public function testGetReturnsNullForUnknownKey(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('find')->with('unknown')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Setting::class)->willReturn($repo);

        $service = new SettingsService($em);
        $this->assertNull($service->get('unknown'));
    }

    public function testSetAndGet(): void
    {
        $existing = new Setting();
        $existing->setKey('existing');
        $existing->setValue('old');

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturnCallback(static function ($key) use ($existing) {
            return 'google_books_api_key' === $key ? null : $existing;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Setting::class)->willReturn($repo);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $service = new SettingsService($em);
        $service->set('google_books_api_key', 'my-key');
    }

    public function testHasReturnsTrueWhenKeyExists(): void
    {
        $setting = new Setting();
        $setting->setKey('exists');
        $setting->setValue('value');

        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('find')->with('exists')->willReturn($setting);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Setting::class)->willReturn($repo);

        $service = new SettingsService($em);
        $this->assertTrue($service->has('exists'));
    }

    public function testHasReturnsFalseWhenKeyMissing(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())->method('find')->with('missing')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Setting::class)->willReturn($repo);

        $service = new SettingsService($em);
        $this->assertFalse($service->has('missing'));
    }
}

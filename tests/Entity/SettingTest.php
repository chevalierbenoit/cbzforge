<?php

namespace App\Tests\Entity;

use App\Entity\Setting;
use PHPUnit\Framework\TestCase;

class SettingTest extends TestCase
{
    public function testGetterAndSetter(): void
    {
        $setting = new Setting();
        $setting->setKey('test_key');
        $setting->setValue('test_value');

        $this->assertSame('test_key', $setting->getKey());
        $this->assertSame('test_value', $setting->getValue());
    }

    public function testNullValue(): void
    {
        $setting = new Setting();
        $setting->setKey('empty');
        $setting->setValue(null);

        $this->assertNull($setting->getValue());
    }
}

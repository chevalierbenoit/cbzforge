<?php

namespace App\Tests\Entity;

use App\Entity\Library;
use PHPUnit\Framework\TestCase;

class LibraryTest extends TestCase
{
    public function testGetterAndSetter(): void
    {
        $library = new Library();

        $library->setName('Test');
        $this->assertSame('Test', $library->getName());

        $library->setPath('/some/path');
        $this->assertSame('/some/path', $library->getPath());
    }
}

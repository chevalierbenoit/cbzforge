<?php

namespace App\Tests\Dto;

use App\Dto\RenamePreview;
use PHPUnit\Framework\TestCase;

class RenamePreviewTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $preview = new RenamePreview(
            originalPath: '/lib/old.cbz',
            newPath: '/lib/Spawn/Spawn #1.cbz',
            newFilename: 'Spawn #1.cbz',
        );

        $this->assertSame('/lib/old.cbz', $preview->originalPath);
        $this->assertSame('/lib/Spawn/Spawn #1.cbz', $preview->newPath);
        $this->assertSame('Spawn #1.cbz', $preview->newFilename);
    }
}

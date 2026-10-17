<?php

namespace App\Tests\Service;

use App\Service\FileSystemHelper;
use PHPUnit\Framework\TestCase;

class FileSystemHelperTest extends TestCase
{
    public function testRrmdirRemovesDirectory(): void
    {
        $tmpDir = sys_get_temp_dir().'/fshelper-'.uniqid();
        mkdir($tmpDir.'/sub', 0777, true);
        touch($tmpDir.'/file1.txt');
        touch($tmpDir.'/sub/file2.txt');

        FileSystemHelper::rrmdir($tmpDir);

        $this->assertDirectoryDoesNotExist($tmpDir);
    }

    public function testRrmdirNoopForNonExistent(): void
    {
        FileSystemHelper::rrmdir('/tmp/nonexistent-'.uniqid());
        $this->assertTrue(true);
    }

    public function testAddDirToZipKeepsNaturalPageOrder(): void
    {
        $tmpDir = sys_get_temp_dir().'/fszip-'.uniqid();
        mkdir($tmpDir);
        foreach (['page10.jpg', 'page2.jpg', 'page1.jpg'] as $name) {
            file_put_contents($tmpDir.'/'.$name, 'data');
        }

        $zipPath = $tmpDir.'-out.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE));
        FileSystemHelper::addDirToZip($zip, $tmpDir);
        $zip->close();

        $zip->open($zipPath);
        $names = [];
        for ($i = 0; $i < $zip->count(); ++$i) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        FileSystemHelper::rrmdir($tmpDir);
        unlink($zipPath);

        $this->assertSame(['page1.jpg', 'page2.jpg', 'page10.jpg'], $names);
    }
}

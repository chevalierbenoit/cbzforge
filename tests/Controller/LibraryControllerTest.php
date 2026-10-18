<?php

namespace App\Tests\Controller;

use App\Entity\Library;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class LibraryControllerTest extends WebTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/libctrl-'.uniqid();
        mkdir($this->tmpDir.'/Serie A', 0777, true);
        touch($this->tmpDir.'/file.cbz');
        touch($this->tmpDir.'/readme.txt');
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testExploreListsEntries(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($this->tmpDir);
        $em->persist($library);
        $em->flush();

        $crawler = $client->request('GET', '/library/'.$library->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Serie A');
        $this->assertSelectorTextContains('body', 'file.cbz');
        $this->assertStringNotContainsString('readme.txt', $crawler->filter('body')->text());
    }

    public function testExploreShowsFoldersInGrid(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($this->tmpDir);
        $em->persist($library);
        $em->flush();

        $crawler = $client->request('GET', '/library/'.$library->getId());

        $folder = $crawler->filter('.file-grid .folder-card');
        $this->assertCount(1, $folder);
        $this->assertStringContainsString('Serie A', $folder->text());
        $this->assertStringEndsWith('path=Serie%20A', $folder->attr('href'));
    }

    public function testFolderTreeExpandsCurrentPath(): void
    {
        mkdir($this->tmpDir.'/Serie A/Tome 1', 0777, true);
        mkdir($this->tmpDir.'/Autre', 0777, true);

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($this->tmpDir);
        $em->persist($library);
        $em->flush();

        $crawler = $client->request('GET', '/library/'.$library->getId().'?path=Serie%20A');

        // The current folder is the active link, its siblings stay visible, its children are shown.
        $this->assertSame('Serie A', trim($crawler->filter('.tree-link.is-active')->text()));
        $this->assertStringContainsString('Autre', $crawler->filter('.explorer-tree')->text());
        $this->assertStringContainsString('Tome 1', $crawler->filter('.explorer-tree')->text());
    }

    public function testExploreWithSubPath(): void
    {
        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($this->tmpDir);
        $em->persist($library);
        $em->flush();

        $crawler = $client->request('GET', '/library/'.$library->getId().'?path=Serie%20A');

        $this->assertResponseIsSuccessful();
    }

    public function testExploreNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/library/99999');

        $this->assertResponseStatusCodeSame(404);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

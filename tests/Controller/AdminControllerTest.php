<?php

namespace App\Tests\Controller;

use App\Entity\Library;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminControllerTest extends WebTestCase
{
    use AdminClientTrait;

    public function testIndexListsLibraries(): void
    {
        $client = $this->createAdminClient();
        $crawler = $client->request('GET', '/admin/libraries');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Library management');
    }

    public function testAddLibraryWithInvalidPath(): void
    {
        $client = $this->createAdminClient();
        $crawler = $client->request('POST', '/admin/libraries/add', [
            'name' => 'Test',
            'path' => '/nonexistent/path',
        ]);

        $this->assertResponseRedirects('/admin/libraries');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'does not exist or is not a directory');
    }

    public function testAddLibrarySuccess(): void
    {
        $tmpDir = sys_get_temp_dir().'/admin-test-'.uniqid();
        mkdir($tmpDir, 0777, true);

        $client = $this->createAdminClient();
        $crawler = $client->request('POST', '/admin/libraries/add', [
            'name' => 'Ma Bibliothèque',
            'path' => $tmpDir,
        ]);

        $this->assertResponseRedirects('/admin/libraries');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Library added.');
        $this->assertSelectorTextContains('body', 'Ma Bibliothèque');

        rmdir($tmpDir);
    }

    public function testAddLibraryDuplicatePath(): void
    {
        $tmpDir = sys_get_temp_dir().'/admin-dup-'.uniqid();
        mkdir($tmpDir, 0777, true);

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Existing');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/admin/libraries/add', [
            'name' => 'Duplicate',
            'path' => $tmpDir,
        ]);

        $this->assertResponseRedirects('/admin/libraries');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'is already used by library');

        rmdir($tmpDir);
    }

    public function testEditLibraryGet(): void
    {
        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Edit Me');
        $library->setPath('/tmp');
        $em->persist($library);
        $em->flush();

        $crawler = $client->request('GET', '/admin/libraries/'.$library->getId().'/edit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Edit library');
    }

    public function testEditLibraryPost(): void
    {
        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Old Name');
        $library->setPath('/tmp');
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/admin/libraries/'.$library->getId().'/edit', [
            'name' => 'New Name',
            'path' => '/tmp',
        ]);

        $this->assertResponseRedirects('/admin/libraries');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Library updated.');
    }

    public function testEditLibraryInvalidPath(): void
    {
        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath('/tmp');
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/admin/libraries/'.$library->getId().'/edit', [
            'name' => 'Test',
            'path' => '/nonexistent',
        ]);

        $this->assertResponseRedirects('/admin/libraries/'.$library->getId().'/edit');
    }

    public function testEditLibraryNotFound(): void
    {
        $client = $this->createAdminClient();
        $client->request('GET', '/admin/libraries/99999/edit');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteLibrary(): void
    {
        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Delete Me');
        $library->setPath('/tmp');
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/admin/libraries/'.$library->getId().'/delete');

        $this->assertResponseRedirects('/admin/libraries');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Library deleted.');
    }

    public function testDeleteNonexistentLibrary(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/admin/libraries/99999/delete');

        $this->assertResponseRedirects('/admin/libraries');
    }

    public function testEditLibraryDuplicatePath(): void
    {
        $tmpDirA = sys_get_temp_dir().'/admin-dup-a-'.uniqid();
        $tmpDirB = sys_get_temp_dir().'/admin-dup-b-'.uniqid();
        mkdir($tmpDirA, 0777, true);
        mkdir($tmpDirB, 0777, true);

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');

        $libraryA = new Library();
        $libraryA->setName('Library A');
        $libraryA->setPath($tmpDirA);
        $em->persist($libraryA);
        $em->flush();

        $libraryB = new Library();
        $libraryB->setName('Library B');
        $libraryB->setPath($tmpDirB);
        $em->persist($libraryB);
        $em->flush();

        $client->request('POST', '/admin/libraries/'.$libraryB->getId().'/edit', [
            'name' => 'Library B',
            'path' => $tmpDirA,
        ]);

        $this->assertResponseRedirects('/admin/libraries/'.$libraryB->getId().'/edit');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'is already used by library');

        rmdir($tmpDirA);
        rmdir($tmpDirB);
    }

    public function testSettingsPostWithoutFake(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/admin/settings', []);

        $this->assertResponseRedirects('/admin/settings');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Settings saved.');
    }

    public function testSettingsGet(): void
    {
        $client = $this->createAdminClient();
        $crawler = $client->request('GET', '/admin/settings');

        $this->assertResponseIsSuccessful();
    }

    public function testAdminNavigationIsIdenticalOnEveryAdminPage(): void
    {
        $expected = [
            'Libraries',
            'Scrapers',
            'Renaming',
            'Conversion',
            'Task queue',
        ];

        $paths = [
            '/admin/libraries',
            '/admin/settings',
            '/admin/rename-settings',
            '/admin/conversion-settings',
            '/admin/tasks',
        ];

        $client = $this->createAdminClient();

        foreach ($paths as $path) {
            $crawler = $client->request('GET', $path);

            $this->assertResponseIsSuccessful();

            $items = $crawler->filter('.subnav .subnav-link')->each(
                static fn ($node) => trim(preg_replace('/\s+/', ' ', $node->text())),
            );

            $this->assertSame($expected, $items, \sprintf('Admin navigation differs on %s', $path));
        }
    }

    public function testSettingsPost(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/admin/settings', [
            'scraper_enabled_Fake' => '1',
        ]);

        $this->assertResponseRedirects('/admin/settings');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Settings saved.');
    }

    public function testSettingsPostWithDisabled(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/admin/settings', [
            'scraper_enabled_Fake' => '0',
        ]);

        $this->assertResponseRedirects('/admin/settings');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Settings saved.');
    }

    public function testSettingsPageDisablesUnconfiguredScraper(): void
    {
        $client = $this->createAdminClient();
        $crawler = $client->request('GET', '/admin/settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Not configured');
        $this->assertNotNull($crawler->filter('#toggle-Google_Books')->attr('disabled'));
        $this->assertNull($crawler->filter('#toggle-Bedetheque')->attr('disabled'));
    }

    public function testSettingsPostWithConfigField(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/admin/settings', [
            'scraper_enabled_Fake' => '1',
            'google_books_api_key' => 'test-key',
        ]);

        $this->assertResponseRedirects('/admin/settings');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Settings saved.');
    }
}

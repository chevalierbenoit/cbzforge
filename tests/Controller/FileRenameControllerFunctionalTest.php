<?php

namespace App\Tests\Controller;

use App\Entity\Library;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FileRenameControllerFunctionalTest extends WebTestCase
{
    use AdminClientTrait;

    public function testRenameFileLibraryNotFound(): void
    {
        $client = $this->createAdminClient();
        $client->request('POST', '/api/files/rename-file', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => 99999,
            'path' => 'test.cbz',
            'new_name' => 'newname',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Library not found', $data['error']);
    }

    public function testRenameFileConflict(): void
    {
        $tmpDir = sys_get_temp_dir().'/fnrn-'.uniqid();
        mkdir($tmpDir, 0777, true);
        touch($tmpDir.'/file.cbz');
        touch($tmpDir.'/existing.cbz');

        $client = $this->createAdminClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        $client->request('POST', '/api/files/rename-file', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'file.cbz',
            'new_name' => 'existing',
        ]));

        $this->assertResponseStatusCodeSame(409);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('A file with this name already exists', $data['error']);

        unlink($tmpDir.'/file.cbz');
        unlink($tmpDir.'/existing.cbz');
        rmdir($tmpDir);
    }
}

<?php

namespace App\Tests\Controller;

use App\Entity\Library;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ConvertControllerFunctionalTest extends WebTestCase
{
    public function testConvertEndpointLibraryNotFound(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/files/convert', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => 99999,
            'path' => 'test.cbr',
        ]));

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Library not found', $data['error']);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testConvertCbrToCbz(): void
    {
        $tmpDir = sys_get_temp_dir().'/conv-'.uniqid();
        mkdir($tmpDir, 0777, true);
        $cbrPath = $tmpDir.'/test.cbr';
        file_put_contents($cbrPath, 'fake-rar-data');

        $client = static::createClient();
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $library = new Library();
        $library->setName('Test');
        $library->setPath($tmpDir);
        $em->persist($library);
        $em->flush();

        // This will fail to extract RAR (no real unrar), but we just need to hit the endpoint
        $client->request('POST', '/api/files/convert', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'library_id' => $library->getId(),
            'path' => 'test.cbr',
        ]));

        // Should be 400 because unrar will fail
        $this->assertResponseStatusCodeSame(400);

        unlink($cbrPath);
        rmdir($tmpDir);
    }
}

<?php

namespace App\Tests\Controller;

use App\Controller\FileRenameController;
use App\Entity\Library;
use App\Service\LibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

class FileRenameControllerTest extends TestCase
{
    private function createController(
        ?EntityManagerInterface $em = null,
        ?LibraryService $libraryService = null,
        ?TranslatorInterface $translator = null,
    ): FileRenameController {
        $translator ??= $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn ($key) => $key);

        return new FileRenameController(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $libraryService ?? $this->createStub(LibraryService::class),
            $translator,
        );
    }

    public function testRenameRejectsMissingLibraryId(): void
    {
        $controller = $this->createController();
        $request = new Request(content: json_encode([
            'path' => 'test.cbz',
            'new_name' => 'newname',
        ]));
        $response = $controller->renameFile($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('error.library_id_required', $data['error']);
    }

    public function testRenameRejectsEmptyNewName(): void
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn(new Library());
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $controller = $this->createController($em, null);
        $request = new Request(content: json_encode([
            'library_id' => 1,
            'path' => 'test.cbz',
            'new_name' => '',
        ]));
        $response = $controller->renameFile($request);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('error.new_name_required', $data['error']);
    }

    public function testRenameSuccess(): void
    {
        $tmpDir = sys_get_temp_dir().'/inline_rename_'.uniqid();
        mkdir($tmpDir, 0777, true);
        touch($tmpDir.'/oldname.cbz');

        $library = new Library();

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($library);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $libraryService = $this->createStub(LibraryService::class);
        $libraryService->method('getFullPath')->willReturn($tmpDir.'/oldname.cbz');

        $controller = $this->createController($em, $libraryService);
        $request = new Request(content: json_encode([
            'library_id' => 1,
            'path' => 'oldname.cbz',
            'new_name' => 'newname',
        ]));
        $response = $controller->renameFile($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertStringEndsWith('newname.cbz', $data['path']);
        $this->assertFileExists($tmpDir.'/newname.cbz');
        $this->assertFileDoesNotExist($tmpDir.'/oldname.cbz');

        unlink($tmpDir.'/newname.cbz');
        rmdir($tmpDir);
    }

    #[\PHPUnit\Framework\Attributes\WithoutErrorHandler]
    public function testRenameFailure(): void
    {
        $tmpDir = sys_get_temp_dir().'/rename_fail_'.uniqid();
        mkdir($tmpDir, 0777, true);

        $library = new Library();

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($library);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $libraryService = $this->createStub(LibraryService::class);
        $libraryService->method('getFullPath')->willReturn($tmpDir.'/nonexistent.cbz');

        $controller = $this->createController($em, $libraryService);
        $request = new Request(content: json_encode([
            'library_id' => 1,
            'path' => 'nonexistent.cbz',
            'new_name' => 'newname',
        ]));
        $response = $controller->renameFile($request);
        $this->assertSame(500, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('error.rename_failed', $data['error']);

        rmdir($tmpDir);
    }

    public function testRenameWithSubdirectory(): void
    {
        $tmpDir = sys_get_temp_dir().'/inline_rename_'.uniqid();
        mkdir($tmpDir, 0777, true);
        touch($tmpDir.'/file.cbr');

        $library = new Library();

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($library);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $libraryService = $this->createStub(LibraryService::class);
        $libraryService->method('getFullPath')->willReturn($tmpDir.'/file.cbr');

        $controller = $this->createController($em, $libraryService);
        $request = new Request(content: json_encode([
            'library_id' => 1,
            'path' => 'sub/file.cbr',
            'new_name' => 'renamed',
        ]));
        $response = $controller->renameFile($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('sub/renamed.cbr', $data['path']);
        $this->assertFileExists($tmpDir.'/renamed.cbr');

        unlink($tmpDir.'/renamed.cbr');
        rmdir($tmpDir);
    }
}

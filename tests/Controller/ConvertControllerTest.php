<?php

namespace App\Tests\Controller;

use App\Controller\ConvertController;
use App\Entity\Library;
use App\Service\ConversionService;
use App\Service\LibraryService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConvertControllerTest extends TestCase
{
    private function createController(
        ?EntityManagerInterface $em = null,
        ?LibraryService $libraryService = null,
        ?ConversionService $conversionService = null,
        ?MessageBusInterface $bus = null,
        ?TranslatorInterface $translator = null,
    ): ConvertController {
        $translator ??= $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn ($key) => $key);

        return new ConvertController(
            $em ?? $this->createStub(EntityManagerInterface::class),
            $libraryService ?? $this->createStub(LibraryService::class),
            $conversionService ?? $this->createStub(ConversionService::class),
            $bus ?? $this->createStub(MessageBusInterface::class),
            $translator,
        );
    }

    public function testConvertRejectsMissingLibraryId(): void
    {
        $controller = $this->createController();
        $request = new Request(content: json_encode([
            'path' => 'test.cbr',
        ]));

        $response = $controller->convert($request);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('error.library_id_required', $data['error']);
    }

    public function testConvertRejectsNonConvertibleFile(): void
    {
        $conversion = $this->createStub(ConversionService::class);
        $conversion->method('needsConversion')->willReturn(false);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn(new Library());

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $controller = $this->createController($em, null, $conversion);
        $request = new Request(content: json_encode([
            'library_id' => 1,
            'path' => 'test.cbz',
        ]));

        $response = $controller->convert($request);
        $this->assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('error.file_already_cbz', $data['error']);
    }

    public function testConvertSuccess(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'convert_test_').'.cbr';
        touch($tmpFile);

        $library = new Library();

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($library);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $libraryService = $this->createStub(LibraryService::class);
        $libraryService->method('getFullPath')->willReturn($tmpFile);

        $conversion = $this->createStub(ConversionService::class);
        $conversion->method('needsConversion')->willReturn(true);
        $conversion->method('convert')->willReturn(preg_replace('/\.cbr$/', '.cbz', $tmpFile));

        $controller = $this->createController($em, $libraryService, $conversion);
        $request = new Request(content: json_encode([
            'library_id' => 1,
            'path' => 'subdir/test.cbr',
        ]));

        $response = $controller->convert($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('subdir/test.cbz', $data['path']);
        $this->assertFileDoesNotExist($tmpFile);
    }

    public function testConvertWithRootPathFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'convert_test_').'.epub';
        touch($tmpFile);

        $library = new Library();

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('find')->willReturn($library);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $libraryService = $this->createStub(LibraryService::class);
        $libraryService->method('getFullPath')->willReturn($tmpFile);

        $conversion = $this->createStub(ConversionService::class);
        $conversion->method('needsConversion')->willReturn(true);
        $conversion->method('convert')->willReturn(preg_replace('/\.epub$/', '.cbz', $tmpFile));

        $controller = $this->createController($em, $libraryService, $conversion);
        $request = new Request(content: json_encode([
            'library_id' => 1,
            'path' => 'file.epub',
        ]));

        $response = $controller->convert($request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('file.cbz', $data['path']);
        $this->assertFileDoesNotExist($tmpFile);
    }
}

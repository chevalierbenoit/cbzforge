<?php

namespace App\Controller;

use App\Entity\Library;
use App\Service\ScraperChain;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Assistant shown after the first login, until the user has either finished it or skipped it.
 * Step 1 adds a library, step 2 chooses the metadata sources. Every choice is recorded, so a
 * source the user did not pick is not silently enabled.
 */
class OnboardingController extends AbstractController
{
    public const DONE_KEY = 'onboarding.done';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
        private readonly ScraperChain $scraperChain,
    ) {
    }

    #[Route('/onboarding', name: 'app_onboarding')]
    public function start(): Response
    {
        if ($this->isDone()) {
            return $this->redirectToRoute('dashboard');
        }

        if ([] === $this->libraries()) {
            return $this->render('onboarding/library.html.twig');
        }

        return $this->redirectToRoute('app_onboarding_sources');
    }

    #[Route('/onboarding/library', name: 'app_onboarding_library', methods: ['POST'])]
    public function library(Request $request): Response
    {
        $this->assertCsrf($request);

        $name = trim((string) $request->request->get('name', ''));
        $path = trim((string) $request->request->get('path', ''));

        if ('' === $name || !is_dir($path)) {
            $this->addFlash('error', 'error.path_not_exist');

            return $this->redirectToRoute('app_onboarding');
        }

        if (null !== $this->em->getRepository(Library::class)->findOneBy(['path' => $path])) {
            $this->addFlash('error', 'error.path_already_used');

            return $this->redirectToRoute('app_onboarding');
        }

        $library = new Library();
        $library->setName($name);
        $library->setPath($path);
        $this->em->persist($library);
        $this->em->flush();

        return $this->redirectToRoute('app_onboarding_sources');
    }

    #[Route('/onboarding/sources', name: 'app_onboarding_sources', methods: ['GET', 'POST'])]
    public function sources(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request);

            foreach ($this->scraperChain->getScrapers() as $scraper) {
                $slug = str_replace(' ', '_', $scraper->getName());
                $enabled = (bool) $request->request->get('scraper_enabled_'.$slug);
                $this->settings->set('scraper.'.$scraper->getName().'.enabled', $enabled ? '1' : '0');
            }
            $this->markDone();

            $this->addFlash('success', 'onboarding.finished');

            return $this->redirectToRoute('dashboard');
        }

        $sources = [];
        foreach ($this->scraperChain->getScrapers() as $scraper) {
            $configured = $this->scraperChain->isConfigured($scraper, $this->settings);
            $sources[] = [
                'name' => $scraper->getName(),
                'fields' => $scraper->getConfigFields(),
                'configured' => $configured,
                // Sources that need no key are proposed ticked; the others wait for their key.
                'checked' => $configured,
            ];
        }

        return $this->render('onboarding/sources.html.twig', ['sources' => $sources]);
    }

    #[Route('/onboarding/skip', name: 'app_onboarding_skip', methods: ['POST'])]
    public function skip(Request $request): Response
    {
        $this->assertCsrf($request);
        $this->markDone();

        return $this->redirectToRoute('dashboard');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('submit', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function isDone(): bool
    {
        return '1' === $this->settings->get(self::DONE_KEY);
    }

    private function markDone(): void
    {
        $this->settings->set(self::DONE_KEY, '1');
    }

    /** @return Library[] */
    private function libraries(): array
    {
        return $this->em->getRepository(Library::class)->findAll();
    }
}

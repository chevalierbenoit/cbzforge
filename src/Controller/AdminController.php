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
use Symfony\Contracts\Translation\TranslatorInterface;

class AdminController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/libraries', name: 'admin_libraries')]
    public function index(): Response
    {
        $libraries = $this->em->getRepository(Library::class)->findAll();

        return $this->render('admin/libraries.html.twig', [
            'libraries' => $libraries,
        ]);
    }

    #[Route('/admin/libraries/add', name: 'admin_libraries_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $path = $request->request->get('path', '');

        if (!is_dir($path)) {
            $this->addFlash('error', $this->translator->trans('error.path_not_exist', ['%path%' => $path]));

            return $this->redirectToRoute('admin_libraries');
        }

        $existing = $this->em->getRepository(Library::class)->findOneBy(['path' => $path]);
        if ($existing) {
            $this->addFlash('error', $this->translator->trans('error.path_already_used', ['%path%' => $path, '%library%' => $existing->getName()]));

            return $this->redirectToRoute('admin_libraries');
        }

        $library = new Library();
        $library->setName($request->request->get('name', ''));
        $library->setPath($path);
        $this->em->persist($library);
        $this->em->flush();

        $this->addFlash('success', $this->translator->trans('admin.library_added'));

        return $this->redirectToRoute('admin_libraries');
    }

    #[Route('/admin/libraries/{id}/edit', name: 'admin_libraries_edit')]
    public function edit(int $id, Request $request): Response
    {
        $library = $this->em->getRepository(Library::class)->find($id);

        if (!$library) {
            throw $this->createNotFoundException($this->translator->trans('error.library_not_found'));
        }

        if ($request->isMethod('POST')) {
            $path = $request->request->get('path', '');

            if (!is_dir($path)) {
                $this->addFlash('error', $this->translator->trans('error.path_not_exist', ['%path%' => $path]));

                return $this->redirectToRoute('admin_libraries_edit', ['id' => $id]);
            }

            $existing = $this->em->getRepository(Library::class)->findOneBy(['path' => $path]);
            if ($existing && $existing->getId() !== $library->getId()) {
                $this->addFlash('error', $this->translator->trans('error.path_already_used', ['%path%' => $path, '%library%' => $existing->getName()]));

                return $this->redirectToRoute('admin_libraries_edit', ['id' => $id]);
            }

            $library->setName($request->request->get('name', ''));
            $library->setPath($path);
            $this->em->flush();

            $this->addFlash('success', $this->translator->trans('admin.library_updated'));

            return $this->redirectToRoute('admin_libraries');
        }

        return $this->render('admin/library_edit.html.twig', [
            'library' => $library,
        ]);
    }

    #[Route('/admin/libraries/{id}/delete', name: 'admin_libraries_delete', methods: ['POST'])]
    public function delete(int $id): Response
    {
        $library = $this->em->getRepository(Library::class)->find($id);
        if ($library) {
            $this->em->remove($library);
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('admin.library_deleted'));
        }

        return $this->redirectToRoute('admin_libraries');
    }

    #[Route('/admin/settings', name: 'admin_settings')]
    public function settings(Request $request, ScraperChain $scraperChain, SettingsService $settings): Response
    {
        if ($request->isMethod('POST')) {
            foreach ($scraperChain->getScrapers() as $scraper) {
                $name = $scraper->getName();
                $slug = str_replace(' ', '_', $name);
                $enabled = $request->request->get('scraper_enabled_'.$slug);
                $settings->set('scraper.'.$name.'.enabled', $enabled ? '1' : '0');

                foreach ($scraper->getConfigFields() as $field) {
                    $value = $request->request->get($field['key']);
                    if (null !== $value) {
                        $settings->set($field['key'], $value);
                    }
                }
            }
            $this->addFlash('success', $this->translator->trans('admin.settings_saved'));

            return $this->redirectToRoute('admin_settings');
        }

        $scrapers = [];
        foreach ($scraperChain->getScrapers() as $scraper) {
            $name = $scraper->getName();
            $fields = [];
            foreach ($scraper->getConfigFields() as $field) {
                $field['value'] = $settings->get($field['key']);
                $fields[] = $field;
            }
            $scrapers[] = [
                'name' => $name,
                'fields' => $fields,
                'enabled' => $scraperChain->isEnabled($scraper, $settings),
                'configured' => $scraperChain->isConfigured($scraper, $settings),
            ];
        }

        return $this->render('admin/settings.html.twig', [
            'scrapers' => $scrapers,
        ]);
    }
}

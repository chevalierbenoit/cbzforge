<?php

namespace App\Controller\Admin;

use App\Service\RenameTemplate;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class RenameSettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly RenameTemplate $renameTemplate,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/rename-settings', name: 'admin_rename_settings')]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $expression = $request->request->get('expression', '');
            $expression = trim($expression);
            if ('' === $expression) {
                $expression = '{serie}/{numero} - {titre}';
            }
            $this->settings->set('rename.format', $expression);
            $this->addFlash('success', $this->translator->trans('admin.rename_saved'));

            return $this->redirectToRoute('admin_rename_settings');
        }

        $currentExpression = $this->settings->get('rename.format');
        if (null === $currentExpression) {
            $currentExpression = '{serie}/{numero} - {titre}';
        }

        $preview = $this->renameTemplate->preview($currentExpression);

        return $this->render('admin/rename_settings.html.twig', [
            'expression' => $currentExpression,
            'preview' => $preview,
            'aliases' => $this->renameTemplate->getAvailableAliases(),
        ]);
    }

    #[Route('/admin/rename-template/preview', name: 'admin_rename_template_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $expression = $data['expression'] ?? '';

        if ('' === trim($expression)) {
            return $this->json(['rendered' => '']);
        }

        try {
            $rendered = $this->renameTemplate->preview($expression);

            return $this->json(['rendered' => $rendered]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }
}

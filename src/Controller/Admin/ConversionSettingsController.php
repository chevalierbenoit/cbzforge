<?php

namespace App\Controller\Admin;

use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConversionSettingsController extends AbstractController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/conversion-settings', name: 'admin_conversion_settings')]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $format = $request->request->get('pdf_format', 'jpeg');
            $dpi = $request->request->get('pdf_dpi', '150');

            if (!\in_array($format, ['jpeg', 'png'], true)) {
                $format = 'jpeg';
            }
            if (!\in_array($dpi, ['150', '300'], true)) {
                $dpi = '150';
            }

            $this->settings->set('conversion.pdf.format', $format);
            $this->settings->set('conversion.pdf.dpi', $dpi);
            $this->addFlash('success', $this->translator->trans('admin.conversion.saved'));

            return $this->redirectToRoute('admin_conversion_settings');
        }

        $currentFormat = $this->settings->get('conversion.pdf.format') ?? 'jpeg';
        $currentDpi = $this->settings->get('conversion.pdf.dpi') ?? '150';

        return $this->render('admin/conversion_settings.html.twig', [
            'pdf_format' => $currentFormat,
            'pdf_dpi' => $currentDpi,
        ]);
    }
}

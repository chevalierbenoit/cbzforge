<?php

namespace App\Controller;

use App\Entity\Library;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(EntityManagerInterface $em, SettingsService $settings): Response
    {
        if ('1' !== $settings->get(OnboardingController::DONE_KEY)) {
            return $this->redirectToRoute('app_onboarding');
        }

        $libraries = $em->getRepository(Library::class)->findAll();

        return $this->render('dashboard/index.html.twig', [
            'libraries' => $libraries,
        ]);
    }
}

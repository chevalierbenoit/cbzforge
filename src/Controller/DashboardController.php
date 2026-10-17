<?php

namespace App\Controller;

use App\Entity\Library;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $libraries = $em->getRepository(Library::class)->findAll();

        return $this->render('dashboard/index.html.twig', [
            'libraries' => $libraries,
        ]);
    }
}

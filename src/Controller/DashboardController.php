<?php

namespace App\Controller;

use App\Repository\UrlRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(UrlRepository $urlRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        
        $urls = $urlRepository->findBy(['user' => $user], ['createdAt' => 'DESC']);

        return $this->render('dashboard/index.html.twig', [
            'urls' => $urls,
        ]);
    }
}

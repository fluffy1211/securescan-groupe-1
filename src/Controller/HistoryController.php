<?php

namespace App\Controller;

use App\Repository\ScanJobRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HistoryController extends AbstractController
{
    #[Route('/history', name: 'app_history')]
    #[IsGranted('ROLE_USER')]
    public function index(ScanJobRepository $scanJobRepository): Response
    {
        $user = $this->getUser();
        $scanJobs = $scanJobRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->render('history/index.html.twig', [
            'scanJobs' => $scanJobs,
        ]);
    }
}

<?php

namespace App\Controller;

use App\Repository\ScanJobRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class HistoryController extends AbstractController
{
    #[Route('/history', name: 'app_history')]
    public function index(ScanJobRepository $repo): Response
    {
        $scans = $repo->findBy(['user' => $this->getUser(), 'status' => 'done'], ['createdAt' => 'DESC']);

        return $this->render('history/index.html.twig', [
            'scans' => $scans,
        ]);
    }
}

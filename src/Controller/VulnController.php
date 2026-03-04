<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Vulnerability;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VulnController extends AbstractController
{
    #[Route('/vuln/{id}', name: 'app_vuln_detail')]
    public function detail(Vulnerability $vuln): Response
    {
        $scanUser = $vuln->getScanJob()?->getUser();

        // Si le scan appartient à un utilisateur, seul ce dernier peut y accéder
        if ($scanUser !== null && $scanUser !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('vuln/detail.html.twig', [
            'vuln' => $vuln,
        ]);
    }
}

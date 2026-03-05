<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ScanJob;
use App\Enum\ScanStatus;
use App\Repository\ScanJobRepository;
use App\Service\AuditOrchestratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ScanController extends AbstractController
{
    #[Route('/scan/launch', name: 'app_scan_launch', methods: ['POST'])]
    public function launch(Request $request, EntityManagerInterface $em): Response
    {
        $repoUrl = trim((string) $request->request->get('repo', ''));

        if (!$repoUrl) {
            $this->addFlash('error', 'Veuillez saisir une URL de dépôt.');
            return $this->redirectToRoute('app_home');
        }

        if (!filter_var($repoUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $repoUrl)) {
            return $this->redirectToRoute('app_scan_error', [
                'repo' => $repoUrl,
                'msg'  => 'URL invalide. Veuillez saisir une URL Git valide (ex : https://github.com/organisation/projet.git).',
            ]);
        }

        $job = new ScanJob();
        $job->setRepoUrl($repoUrl);
        $job->setStatus(ScanStatus::PENDING);

        if ($this->getUser()) {
            $job->setUser($this->getUser());
        }

        $em->persist($job);
        $em->flush();

        return $this->redirectToRoute('app_scan_loading', ['id' => $job->getId()]);
    }

    #[Route('/scan/error', name: 'app_scan_error')]
    public function error(Request $request): Response
    {
        return $this->render('scan/loading.html.twig', [
            'job'            => null,
            'immediateError' => $request->query->get('msg', 'Entrée invalide.'),
            'errorRepo'      => $request->query->get('repo', ''),
        ]);
    }

    #[Route('/scan/{id}/loading', name: 'app_scan_loading')]
    public function loading(int $id, ScanJobRepository $repo): Response
    {
        $job = $repo->find($id);
        if (!$job) {
            $this->addFlash('error', 'Scan introuvable. Lancez une nouvelle analyse.');
            return $this->redirectToRoute('app_home');
        }

        if ($job->getStatus() === 'done') {
            return $this->redirectToRoute('app_dashboard', ['id' => $job->getId()]);
        }

        return $this->render('scan/loading.html.twig', ['job' => $job]);
    }

    #[Route('/scan/{id}/run', name: 'app_scan_run', methods: ['POST'])]
    public function run(int $id, ScanJobRepository $repo, AuditOrchestratorService $orchestrator, EntityManagerInterface $em): JsonResponse
    {
        $job = $repo->find($id);
        if (!$job) {
            return $this->json(['status' => 'failed', 'error' => 'Scan introuvable.'], Response::HTTP_NOT_FOUND);
        }

        if ($job->getStatus() === 'done') {
            return $this->json([
                'status'      => 'done',
                'redirectUrl' => $this->generateUrl('app_dashboard', ['id' => $job->getId()]),
            ]);
        }

        try {
            $orchestrator->audit($job);

            return $this->json([
                'status'      => 'done',
                'redirectUrl' => $this->generateUrl('app_dashboard', ['id' => $job->getId()]),
            ]);
        } catch (\Throwable $e) {
            // Remove the failed job so it does not pollute history
            try {
                $em->remove($job);
                $em->flush();
            } catch (\Throwable) {
                // best-effort cleanup
            }

            return $this->json([
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

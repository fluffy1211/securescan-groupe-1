<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ScanJob;
use App\Repository\ScanJobRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard/{id}', name: 'app_dashboard', requirements: ['id' => '\d+'])]
    public function index(ScanJob $job): Response
    {
        return $this->render('dashboard/index.html.twig', ['job' => $job]);
    }

    #[Route('/dashboard/{id}/export/json', name: 'app_dashboard_export_json', requirements: ['id' => '\d+'])]
    public function exportJson(ScanJob $job): JsonResponse
    {
        $vulns = [];
        foreach ($job->getVulnerabilities() as $vuln) {
            $vulns[] = [
                'id'            => $vuln->getId(),
                'tool'          => $vuln->getTool(),
                'title'         => $vuln->getTitle(),
                'severity'      => $vuln->getSeverity(),
                'file'          => $vuln->getFilePath(),
                'line'          => $vuln->getLineNumber(),
                'owasp'         => $vuln->getOwaspCategory(),
                'description'   => $vuln->getDescription(),
            ];
        }

        $data = [
            'scan' => [
                'id'          => $job->getId(),
                'repo'        => $job->getRepoUrl(),
                'score'       => $job->getGlobalScore(),
                'status'      => $job->getStatus(),
                'scanned_at'  => $job->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            ],
            'summary' => [
                'total'    => count($vulns),
                'error'    => count(array_filter($vulns, fn($v) => $v['severity'] === 'error')),
                'warning'  => count(array_filter($vulns, fn($v) => $v['severity'] === 'warning')),
                'info'     => count(array_filter($vulns, fn($v) => $v['severity'] === 'info')),
            ],
            'vulnerabilities' => $vulns,
        ];

        $response = new JsonResponse($data);
        $filename = 'securescan-' . $job->getId() . '-' . date('Ymd') . '.json';
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    #[Route('/dashboard/{id}/export/pdf', name: 'app_dashboard_export_pdf', requirements: ['id' => '\d+'])]
    public function exportPdf(ScanJob $job): Response
    {
        $html = $this->renderView('dashboard/export_pdf.html.twig', ['job' => $job]);

        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'securescan-' . $job->getId() . '-' . date('Ymd') . '.pdf';

        return new Response(
            $dompdf->output(),
            Response::HTTP_OK,
            [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }

    #[Route('/dashboard', name: 'app_dashboard_empty')]
    public function empty(ScanJobRepository $repo): Response
    {
        $last = $repo->findOneBy([], ['createdAt' => 'DESC']);

        if ($last) {
            return $this->redirectToRoute('app_dashboard', ['id' => $last->getId()]);
        }

        return $this->redirectToRoute('app_home');
    }
}

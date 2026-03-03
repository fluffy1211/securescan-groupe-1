<?php

namespace App\Command;

use App\Entity\ScanJob;
use App\Service\ScannerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scan',
    description: 'Scan a GitHub repository for vulnerabilities using Semgrep',
)]
/**
 * Commande console pour déclencher un scan de sécurité sur un dépôt GitHub.
 * Utilisation : php bin/console app:scan <url_du_depot>
 */
class ScanRepoCommand extends Command
{
    public function __construct(
        private ScannerService $scannerService,
        private EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('repoUrl', InputArgument::REQUIRED, 'The GitHub repository URL to scan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoUrl = $input->getArgument('repoUrl');

        // Création du ScanJob en base avec le statut initial 'pending'
        $job = new ScanJob();
        $job->setRepoUrl($repoUrl);
        $job->setStatus('pending');

        $this->em->persist($job);
        $this->em->flush();

        try {
            // Délégation au service : clone, analyse Semgrep, persistance des résultats
            $this->scannerService->scan($job);
        } catch (\Throwable $e) {
            $output->writeln(json_encode([
                'status' => 'failed',
                'job_id' => $job->getId(),
                'error'  => $e->getMessage(),
            ], JSON_PRETTY_PRINT));
            return Command::FAILURE;
        }

        // Résumé JSON des vulnérabilités persistées en base
        $vulns = [];
        foreach ($job->getVulnerabilities() as $vuln) {
            $vulns[] = [
                'id'             => $vuln->getId(),
                'severity'       => $vuln->getSeverity(),
                'file'           => $vuln->getFilePath(),
                'line'           => $vuln->getLineNumber(),
                'rule'           => $vuln->getTitle(),
                'description'    => $vuln->getDescription(),
                'owasp_top10'    => $vuln->isOwaspTop10(),
                'owasp_category' => $vuln->getOwaspCategory(),
            ];
        }

        $output->writeln(json_encode([
            'status'           => $job->getStatus(),
            'job_id'           => $job->getId(),
            'repo'             => $job->getRepoUrl(),
            'score'            => $job->getGlobalScore(),
            'total'            => count($vulns),
            'vulnerabilities'  => $vulns,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}

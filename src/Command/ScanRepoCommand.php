<?php

namespace App\Command;

use App\Entity\ScanJob;
use App\Enum\ScanStatus;
use App\Service\AuditOrchestratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:scan',
    description: 'Audit a GitHub repository for vulnerabilities',
)]
/**
 * Commande console pour déclencher un scan de sécurité sur un dépôt GitHub.
 * Utilisation : php bin/console app:scan <url_du_depot>
 */
class ScanRepoCommand extends Command
{
    public function __construct(
        private AuditOrchestratorService $orchestrator,
        private EntityManagerInterface $em,
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
        $job->setStatus(ScanStatus::PENDING);

        $this->em->persist($job);
        $this->em->flush();

        try {
            // Délégation à l'orchestrateur : clone, Semgrep, Composer audit, npm audit, score, persistance
            $this->orchestrator->audit($job);
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
                'tool'           => $vuln->getTool(),
                'severity'       => $vuln->getSeverity(),
                'file'           => $vuln->getFilePath(),
                'line'           => $vuln->getLineNumber(),
                'rule'           => $vuln->getTitle(),
                'description'    => $vuln->getDescription(),
                'owasp_top10'    => $vuln->isOwaspTop10(),
                'owasp_category' => $vuln->getOwaspCategory(),
            ];
        }

        // Affichage du résumé JSON dans la console
        $output->writeln(json_encode([
            'status'           => $job->getStatus()?->value,
            'job_id'           => $job->getId(),
            'repo'             => $job->getRepoUrl(),
            'score'            => $job->getGlobalScore(),
            'total'            => count($vulns),
            'vulnerabilities'  => $vulns,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}

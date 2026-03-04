<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScanJob;
use App\Enum\ScanStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Process\Process;

/**
 * Orchestre l'ensemble du pipeline d'audit de sécurité :
 *   1. Clone le dépôt une seule fois
 *   2. Délègue l'analyse à chaque ScannerInterface
 *   3. Calcule le score global à partir de toutes les vulnérabilités remontées
 *   4. Persiste l'état final et nettoie le répertoire temporaire
 *
 * Point d'entrée unique : audit(ScanJob $job)
 */
class AuditOrchestratorService
{
    /** Multiplicateur de pénalité pour les findings appartenant à l'OWASP Top 10. */
    private const OWASP_PENALTY_MULTIPLIER = 2;

    /**
     * @param iterable<ScannerInterface> $scanners
     */
    /**
     * @param EntityManagerInterface     $em       Gestionnaire Doctrine pour la persistance
     * @param iterable<ScannerInterface> $scanners Tous les scanners taggés 'app.scanner', injectés automatiquement
     * @param string                     $tmpDir   Répertoire temporaire pour le clone du dépôt
     */
    public function __construct(
        private EntityManagerInterface $em,
        #[AutowireIterator('app.scanner')]
        private iterable $scanners,
        private string $tmpDir = '/tmp',
    ) {}

    /**
     * Exécute l'audit complet d'un dépôt GitHub pour le ScanJob donné.
     * En cas d'erreur, le statut passe à 'failed' et l'exception est remontée.
     */
    public function audit(ScanJob $job): void
    {
        // Répertoire temporaire unique pour ce scan
        $cloneDir = $this->tmpDir . '/scanjob-' . uniqid();

        try {
            // Étape 1 : marquer le job comme en cours
            $job->setStatus(ScanStatus::RUNNING);
            $this->em->flush();

            // Étape 2 : clone superficiel du dépôt (--depth 1)
            $this->cloneRepo($job->getRepoUrl(), $cloneDir);

            // Étape 3 : exécuter chaque scanner (Semgrep, Composer, npm…)
            // Chacun crée et persiste ses propres entités Vulnerability dans le ScanJob
            foreach ($this->scanners as $scanner) {
                $scanner->run($job, $cloneDir);
            }

            // Étape 4 : calculer le score global (100 - pénalités)
            $job->setGlobalScore($this->computeScore($job));
            $job->setStatus(ScanStatus::DONE);
            $job->setFinishedAt(new \DateTimeImmutable());
        } catch (\Throwable $e) {
            // En cas d'erreur, on marque le job comme échoué
            $job->setStatus(ScanStatus::FAILED);
            $job->setFinishedAt(new \DateTimeImmutable());
            throw $e;
        } finally {
            // Étape 5 : persistance finale et nettoyage garanti (même en cas d'erreur)
            $this->em->flush();
            $this->cleanup($cloneDir);
        }
    }

    /**
     * Clone le dépôt GitHub en mode superficiel (--depth 1) pour limiter
     * le volume de données téléchargées et accélérer le scan.
     */
    private function cloneRepo(string $url, string $dir): void
    {
        $process = new Process(['git', 'clone', '--depth', '1', $url, $dir]);
        $process->setTimeout(120);
        $process->setEnv([
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS'         => 'echo',
            'HOME'                => '/tmp',
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('git clone failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * Calcule un score de sécurité sur 100 en déduisant des pénalités par vulnérabilité.
     * Les vulnérabilités OWASP Top 10 2025 sont pénalisées avec un multiplicateur x2.
     */
    private function computeScore(ScanJob $job): int
    {
        $penalties = [
            'error'   => 10,
            'warning' => 3,
            'info'    => 1,
        ];

        $deductions = 0;
        foreach ($job->getVulnerabilities() as $vuln) {
            // Pénalité de base selon la sévérité (error=10, warning=3, info=1)
            $penalty = $penalties[$vuln->getSeverity()] ?? 1;

            // Doublement de la pénalité si la vulnérabilité appartient à l'OWASP Top 10
            if ($vuln->isOwaspTop10()) {
                $penalty = (int) ceil($penalty * self::OWASP_PENALTY_MULTIPLIER);
            }
            $deductions += $penalty;
        }

        return max(0, 100 - $deductions);
    }

    /**
     * Supprime le répertoire temporaire utilisé pour le clone du dépôt.
     */
    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            $process = new Process(['rm', '-rf', $dir]);
            $process->run();
        }
    }
}

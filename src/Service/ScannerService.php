<?php

namespace App\Service;

use App\Entity\ScanJob;
use App\Entity\Vulnerability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

/**
 * Service principal chargé d'orchestrer l'analyse de sécurité d'un dépôt GitHub.
 * Il clone le dépôt, exécute Semgrep, parse les résultats et les persiste en base.
 */
class ScannerService
{
    /**
     * Référentiel OWASP Top 10 2025.
     * La correspondance se fait par rang (A01–A10), indépendamment de l'année du label Semgrep.
     */
    private const OWASP_TOP10_2025 = [
        'A01:2025' => 'Broken Access Control',
        'A02:2025' => 'Cryptographic Failures',
        'A03:2025' => 'Injection',
        'A04:2025' => 'Insecure Design',
        'A05:2025' => 'Security Misconfiguration',
        'A06:2025' => 'Vulnerable and Outdated Components',
        'A07:2025' => 'Identification and Authentication Failures',
        'A08:2025' => 'Software and Data Integrity Failures',
        'A09:2025' => 'Security Logging and Monitoring Failures',
        'A10:2025' => 'Server-Side Request Forgery',
    ];

    /** Multiplicateur de pénalité pour les findings appartenant à l'OWASP Top 10. */
    private const OWASP_PENALTY_MULTIPLIER = 2;

    public function __construct(
        private EntityManagerInterface $em,
        private string $tmpDir = '/tmp'
    ) {}

    /**
     * Point d'entrée principal : exécute toutes les étapes du scan pour un ScanJob donné.
     * En cas d'erreur, le statut passe à 'failed' et l'exception est remontée.
     */
    public function scan(ScanJob $job): void
    {
        // Répertoire temporaire unique pour ce job
        $cloneDir = $this->tmpDir . '/scanjob-' . uniqid();

        try {
            // Marquer le job comme en cours avant de commencer
            $job->setStatus('running');
            $this->em->flush();

            $this->cloneRepo($job->getRepoUrl(), $cloneDir);
            $results = $this->runSemgrep($cloneDir);
            $this->parseResults($job, $results, $cloneDir);

            // Calcul du score global une fois toutes les vulnérabilités parsées
            $job->setGlobalScore($this->computeScore($job));
            $job->setStatus('done');
            $job->setFinishedAt(new \DateTimeImmutable());
        } catch (\Throwable $e) {
            // En cas d'échec, on enregistre le statut avant de remonter l'exception
            $job->setStatus('failed');
            $job->setFinishedAt(new \DateTimeImmutable());
            throw $e;
        } finally {
            // Toujours persister l'état final et nettoyer le répertoire cloné
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
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException('git clone failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * Exécute Semgrep sur le répertoire cloné avec les règles "auto" (détection automatique
     * du langage) et retourne le tableau de résultats JSON brut.
     */
    private function runSemgrep(string $dir): array
    {
        $process = new Process([
            'semgrep', 'scan',
            '--config', 'auto',
            '--json',
            '--no-git-ignore',
            $dir,
        ]);
        $process->setTimeout(300);
        $process->run();

        // Semgrep retourne le code 1 quand il trouve des vulnérabilités — c'est normal
        $output = $process->getOutput();

        if (empty($output)) {
            throw new \RuntimeException('semgrep produced no output. stderr: ' . $process->getErrorOutput());
        }

        $decoded = json_decode($output, true);

        if (!isset($decoded['results'])) {
            throw new \RuntimeException('Format de sortie Semgrep inattendu.');
        }

        return $decoded['results'];
    }

    /**
     * Transforme chaque résultat Semgrep en entité Vulnerability et l'associe au ScanJob.
     * Détecte automatiquement si la vulnérabilité appartient à l'OWASP Top 10 2025.
     */
    private function parseResults(ScanJob $job, array $results, string $cloneDir): void
    {
        foreach ($results as $result) {
            $vulnerability = new Vulnerability();
            $vulnerability->setTool('semgrep');
            // L'identifiant de la règle Semgrep sert de titre (ex: php.lang.security.injection)
            $vulnerability->setTitle($result['check_id'] ?? 'Unknown');
            $vulnerability->setSeverity(
                strtolower($result['extra']['severity'] ?? 'unknown')
            );
            $vulnerability->setDescription(
                $result['extra']['message'] ?? null
            );

            // On retire le chemin du répertoire temporaire pour ne stocker que le chemin relatif
            $rawPath = $result['path'] ?? '';
            $vulnerability->setFilePath(
                str_starts_with($rawPath, $cloneDir)
                    ? substr($rawPath, strlen($cloneDir) + 1)
                    : $rawPath
            );

            $vulnerability->setLineNumber(
                $result['start']['line'] ?? $result['extra']['lines'] ?? null
            );

            // Récupération des métadonnées OWASP fournies par Semgrep
            $owasp = $result['extra']['metadata']['owasp'] ?? null;
            $owaspLabels = match (true) {
                is_array($owasp)  => $owasp,
                is_string($owasp) => [$owasp],
                default           => [],
            };

            if (!empty($owaspLabels)) {
                $normalized = $this->normalizeOwaspLabels($owaspLabels);
                // isOwaspTop10() est calculé automatiquement si owaspCategory est défini
                $vulnerability->setOwaspCategory(implode(', ', $normalized));
            }

            // On conserve la données brute complète pour usage futur (debug, export, etc.)
            $vulnerability->setRawData($result);

            $job->addVulnerability($vulnerability);
            $this->em->persist($vulnerability);
        }
    }

    /**
     * Calcule un score de sécurité sur 100 en déduisant des pénalités par vulnérabilité.
     * Les vulnérabilités OWASP Top 10 2025 sont pénalisées avec un multiplicateur x2.
     */
    private function computeScore(ScanJob $job): int
    {
        // Pénalités de base selon la sévérité
        $penalties = [
            'error'   => 10,
            'warning' => 3,
            'info'    => 1,
        ];

        $deductions = 0;
        foreach ($job->getVulnerabilities() as $vuln) {
            $penalty = $penalties[$vuln->getSeverity()] ?? 1;
            if ($vuln->isOwaspTop10()) {
                $penalty = (int) ceil($penalty * self::OWASP_PENALTY_MULTIPLIER);
            }
            $deductions += $penalty;
        }

        return max(0, 100 - $deductions);
    }

    /**
     * Convertit les labels OWASP bruts de Semgrep (2021 ou 2025) vers le format
     * standardisé 2025 : "A03:2025 — Injection".
     * Chaque label est dédupliqué par rang (A03, A01, etc.).
     *
     * @param string[] $labels
     * @return string[]
     */
    private function normalizeOwaspLabels(array $labels): array
    {
        // Clé = rang (ex: "A03"), valeur = libellé final formaté
        $normalized = [];

        foreach ($labels as $label) {
            $matched = false;
            foreach (self::OWASP_TOP10_2025 as $key => $name) {
                $rank = substr($key, 0, 3); // ex: "A03"
                if (str_starts_with(strtoupper(trim($label)), $rank)) {
                    // On force toujours l'année 2025 et on affiche rang + nom
                    $key2025 = $rank . ':2025';
                    $categoryName = self::OWASP_TOP10_2025[$key2025] ?? $name;
                    // Format final : "A03:2025 — Injection"
                    $normalized[$rank] = $key2025 . ' — ' . $categoryName;
                    $matched = true;
                    break;
                }
            }
            // Label inconnu : on le conserve tel quel
            if (!$matched) {
                $normalized[] = $label;
            }
        }

        return array_values($normalized);
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

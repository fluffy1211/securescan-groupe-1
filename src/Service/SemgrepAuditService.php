<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScanJob;
use App\Entity\Vulnerability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

/**
 * Scanner d'analyse statique du code source via Semgrep.
 *
 * Contrairement aux scanners de dépendances (Composer / npm), Semgrep analyse
 * le code source lui-même pour détecter des failles (injections, mauvaise crypto, etc.).
 * Il implémente directement ScannerInterface car son fonctionnement est très différent
 * de celui des scanners de paquets.
 */
class SemgrepAuditService implements ScannerInterface
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

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Point d'entrée : exécute Semgrep puis persiste les résultats dans le ScanJob.
     */
    public function run(ScanJob $job, string $cloneDir): void
    {
        $results = $this->runSemgrep($cloneDir);
        $this->parseResults($job, $results, $cloneDir);
    }

    /**
     * Lance `semgrep scan` en mode auto (détection automatique du langage)
     * et retourne le tableau de résultats brut.
     *
     * --no-git-ignore : inclut aussi les fichiers normalement ignorés par git.
     * --config auto    : utilise les règles communautaires adaptées au langage détecté.
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
     * Transforme chaque résultat Semgrep en entité Vulnerability.
     * Gère la conversion du chemin absolu temporaire en chemin relatif,
     * et la normalisation des labels OWASP vers le référentiel 2025.
     */
    private function parseResults(ScanJob $job, array $results, string $cloneDir): void
    {
        foreach ($results as $result) {
            $vuln = new Vulnerability();
            $vuln->setTool('semgrep');

            // L'identifiant de la règle Semgrep sert de titre (ex: "php.lang.security.injection")
            $vuln->setTitle($result['check_id'] ?? 'Unknown');
            $vuln->setSeverity(strtolower($result['extra']['severity'] ?? 'unknown'));
            $vuln->setDescription($result['extra']['message'] ?? null);

            // On retire le préfixe du répertoire temporaire pour ne stocker que le chemin relatif
            $rawPath = $result['path'] ?? '';
            $vuln->setFilePath(
                str_starts_with($rawPath, $cloneDir)
                    ? substr($rawPath, strlen($cloneDir) + 1)
                    : $rawPath
            );

            $vuln->setLineNumber($result['start']['line'] ?? $result['extra']['lines'] ?? null);

            // Semgrep peut fournir les labels OWASP sous forme de string ou de tableau
            $owasp = $result['extra']['metadata']['owasp'] ?? null;
            $owaspLabels = match (true) {
                is_array($owasp)  => $owasp,
                is_string($owasp) => [$owasp],
                default           => [],
            };

            // Normalisation vers le format "A03:2025 — Injection"
            if (!empty($owaspLabels)) {
                $vuln->setOwaspCategory(implode(', ', $this->normalizeOwaspLabels($owaspLabels)));
            }

            // Données brutes conservées pour debug / export
            $vuln->setRawData($result);

            $job->addVulnerability($vuln);
            $this->em->persist($vuln);
        }
    }

    /**
     * Convertit les labels OWASP bruts de Semgrep vers le format standardisé 2025.
     *
     * Semgrep peut renvoyer des labels 2021 (ex: "A03:2021") ou 2025 — cette méthode
     * extrait le rang (A01–A10) et force toujours le format "A03:2025 — Injection".
     * Les labels inconnus (hors Top 10) sont conservés tels quels.
     * Dédupliqués par rang grâce à la clé associative.
     *
     * @param  string[] $labels Labels bruts issus des métadonnées Semgrep
     * @return string[] Labels normalisés au format 2025
     */
    private function normalizeOwaspLabels(array $labels): array
    {
        $normalized = [];

        foreach ($labels as $label) {
            $matched = false;
            foreach (self::OWASP_TOP10_2025 as $key => $name) {
                // On compare uniquement le rang (ex: "A03") pour ignorer l'année d'origine
                $rank = substr($key, 0, 3);
                if (str_starts_with(strtoupper(trim($label)), $rank)) {
                    $normalized[$rank] = $rank . ':2025 — ' . $name;
                    $matched = true;
                    break;
                }
            }
            // Label inconnu (pas dans le Top 10) : on le garde tel quel
            if (!$matched) {
                $normalized[] = $label;
            }
        }

        return array_values($normalized);
    }
}

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
        'A01:2025' => 'Contrôle d\'accès défaillant',
        'A02:2025' => 'Défaillances cryptographiques',
        'A03:2025' => 'Injection',
        'A04:2025' => 'Conception non sécurisée',
        'A05:2025' => 'Mauvaise configuration de sécurité',
        'A06:2025' => 'Composants vulnérables et obsolètes',
        'A07:2025' => 'Identification et authentification de mauvaise qualité',
        'A08:2025' => 'Manque d\'intégrité des données et du logiciel',
        'A09:2025' => 'Carence des systèmes de contrôle et de journalisation',
        'A10:2025' => 'Falsification de requête côté serveur (SSRF)',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private DescriptionTranslatorService $translator,
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
            '--config', 'p/default',
            '--metrics', 'off',
            '--json',
            '--no-git-ignore',
            $dir,
        ]);
        $process->setTimeout(300);
        // PHP-FPM vide l'environnement (clear_env=yes par défaut).
        // On transmet explicitement les variables nécessaires à Semgrep.
        $process->setEnv([
            'HOME'                          => '/tmp',
            'PATH'                          => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'SEMGREP_RULES_CACHE_PATH'      => '/tmp/semgrep-cache',
            'SEMGREP_SEND_METRICS'          => 'off',
            'SEMGREP_ENABLE_VERSION_CHECK'  => 'false',
        ]);
        $process->run();

        $output = trim($process->getOutput());

        if (empty($output)) {
            // Semgrep n'a rien trouvé ou a échoué silencieusement → 0 findings
            return [];
        }

        $decoded = json_decode($output, true);

        if (!is_array($decoded) || !isset($decoded['results'])) {
            // JSON malformé → on ignore plutôt que de crasher tout le scan
            return [];
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

            $checkId = $result['check_id'] ?? 'Unknown';
            $vuln->setTitle($checkId);
            $vuln->setSeverity(strtolower($result['extra']['severity'] ?? 'unknown'));
            $vuln->setDescription(
                $this->translator->translate($result['extra']['message'] ?? null, $checkId)
            );

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

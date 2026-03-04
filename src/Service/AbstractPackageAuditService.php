<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScanJob;
use App\Entity\Vulnerability;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

/**
 * Classe abstraite commune pour les scanners de dépendances (Composer, npm, …).
 *
 * Elle factorise tout le cycle : vérifications → exécution de l'outil → parsing JSON
 * → création des entités Vulnerability → persistance Doctrine.
 *
 * Pour ajouter un nouveau scanner de paquets, il suffit d'étendre cette classe
 * et d'implémenter les 6 méthodes abstraites ci-dessous.
 */
abstract class AbstractPackageAuditService implements ScannerInterface
{
    /** Durée maximale (en secondes) accordée à la commande d'audit. */
    private const TIMEOUT = 60;

    /** Catégorie OWASP attribuée à toute vulnérabilité de dépendance. */
    private const OWASP_CATEGORY = 'A06:2025 — Composants vulnérables et obsolètes';

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /** Nom de l'outil tel qu'il sera stocké en base (ex: "composer_audit"). */
    abstract protected function getToolName(): string;

    /** Fichier manifeste requis (ex: "composer.json"). */
    abstract protected function getManifestFile(): string;

    /** Fichier lock requis (ex: "composer.lock"). */
    abstract protected function getLockFile(): string;

    /** Commande + arguments pour vérifier la disponibilité de l'outil. */
    abstract protected function getVersionCommand(): array;

    /** Commande + arguments pour exécuter l'audit (sans le cwd). */
    abstract protected function getAuditCommand(): array;

    /**
     * Normalise la sortie JSON brute en tableau plat d'advisories.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function normalize(array $data): array;

    // ──────────────────────────────────────────────────────────────
    //  Logique commune
    // ──────────────────────────────────────────────────────────────

    /**
     * Point d'entrée appelé par l'orchestrateur.
     * Lance le scan puis transforme chaque advisory en entité Vulnerability.
     * Si le projet ne correspond pas à ce scanner (ex: pas de composer.json),
     * la RuntimeException est attrapée et on passe silencieusement au suivant.
     */
    public function run(ScanJob $job, string $cloneDir): void
    {
        try {
            $advisories = $this->scan($cloneDir);
        } catch (\RuntimeException) {
            // Le dépôt ne contient pas les fichiers requis, ou l'outil est absent → on ignore
            return;
        }

        // Transformation de chaque advisory normalisé en entité Vulnerability
        foreach ($advisories as $advisory) {
            $vuln = new Vulnerability();
            $vuln->setTool($this->getToolName());
            $vuln->setTitle($advisory['title']);
            $vuln->setSeverity($advisory['severity']);

            // Description = CVE + URL séparés par " | ", ou null si aucun des deux
            $vuln->setDescription(implode(' | ', array_filter([
                $advisory['cve'],
                $advisory['url'],
            ])) ?: null);

            // Les vulnérabilités de dépendances pointent vers le fichier lock
            $vuln->setFilePath($this->getLockFile());
            $vuln->setLineNumber(null);

            // Toutes les dépendances vulnérables correspondent à la catégorie OWASP A06
            $vuln->setOwaspCategory(self::OWASP_CATEGORY);

            // On conserve les données brutes pour debug / export
            $vuln->setRawData($advisory);

            $job->addVulnerability($vuln);
            $this->em->persist($vuln);
        }
    }

    /**
     * Exécute le scan complet : vérifications préalables → appel CLI → parsing JSON → normalisation.
     *
     * @return array<int, array<string, mixed>> Tableau plat d'advisories normalisés
     *
     * @throws \RuntimeException Si les prérequis ne sont pas remplis ou si le parsing échoue
     */
    public function scan(string $path): array
    {
        // --- Vérifications préalables ---
        if (!is_dir($path)) {
            throw new \RuntimeException("Dossier introuvable : $path");
        }
        if (!file_exists($path . '/' . $this->getManifestFile())) {
            throw new \RuntimeException($this->getManifestFile() . ' introuvable');
        }
        if (!file_exists($path . '/' . $this->getLockFile())) {
            throw new \RuntimeException($this->getLockFile() . ' manquant');
        }
        if (!$this->isToolAvailable()) {
            throw new \RuntimeException($this->getToolName() . ' non disponible');
        }

        // --- Exécution de l'outil d'audit ---
        $output = $this->runAudit($path);
        if (trim($output) === '') {
            // Sortie vide = aucune vulnérabilité détectée
            return [];
        }

        // --- Parsing JSON de la sortie ---
        try {
            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Impossible de parser les résultats');
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Impossible de parser les résultats');
        }

        // --- Normalisation spécifique à chaque outil (Composer / npm) ---
        return $this->normalize($data);
    }

    /**
     * Vérifie que la CLI de l'outil (composer, npm…) est installée et accessible.
     */
    protected function isToolAvailable(): bool
    {
        $process = new Process($this->getVersionCommand());
        $process->setTimeout(10);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /**
     * Exécute la commande d'audit et retourne la sortie brute (stdout).
     * Note : un code de retour != 0 ne signifie pas forcément une erreur —
     * composer/npm retournent 1 quand des vulnérabilités sont trouvées.
     */
    protected function runAudit(string $path): string
    {
        $process = new Process($this->getAuditCommand(), $path);
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        return $process->getOutput();
    }
}

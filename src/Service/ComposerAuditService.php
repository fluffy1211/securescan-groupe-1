<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Scanner de dépendances PHP via `composer audit`.
 *
 * Hérite de AbstractPackageAuditService : seules la configuration de l'outil
 * et la normalisation du JSON de sortie sont implémentées ici.
 */
class ComposerAuditService extends AbstractPackageAuditService
{
    protected function getToolName(): string
    {
        return 'composer_audit';
    }

    protected function getManifestFile(): string
    {
        return 'composer.json';
    }

    protected function getLockFile(): string
    {
        return 'composer.lock';
    }

    protected function getVersionCommand(): array
    {
        return ['composer', '--version'];
    }

    protected function getAuditCommand(): array
    {
        return ['composer', 'audit', '--locked', '--format=json'];
    }

    /**
     * Normalise la sortie de `composer audit --format=json`.
     * Structure attendue : { "advisories": { "vendor/pkg": [ {...}, ... ] } }
     * Chaque advisory est aplati en tableau associatif uniforme.
     */
    protected function normalize(array $data): array
    {
        $advisories = $data['advisories'] ?? [];
        $result = [];

        foreach ($advisories as $packageName => $packageAdvisories) {
            if (!is_array($packageAdvisories)) {
                continue;
            }
            foreach ($packageAdvisories as $advisory) {
                $result[] = $this->normalizeAdvisory($packageName, $advisory);
            }
        }

        return $result;
    }

    /**
     * Transforme un advisory brut Composer en tableau normalisé.
     */
    private function normalizeAdvisory(string $packageName, array $advisory): array
    {
        $affectedVersions = $advisory['affectedVersions'] ?? '';
        $packageName      = $advisory['packageName'] ?? $packageName;
        $reportedAt       = $this->parseDate($advisory['reportedAt'] ?? null);

        return [
            'tool'        => 'composer_audit',
            'package'     => $packageName,
            'version'     => $affectedVersions,
            'cve'         => $advisory['cve'] ?? null,
            'title'       => $advisory['title'] ?? '',
            'severity'    => strtolower((string) ($advisory['severity'] ?? 'unknown')),
            'url'         => $advisory['link'] ?? $advisory['url'] ?? null,
            'reported_at' => $reportedAt,
            'owasp'       => null,
            'fix'         => $this->buildFixCommand($packageName, $affectedVersions),
        ];
    }

    /**
     * Construit une commande de correctif à partir de la plage de versions affectées.
     * Exemple : ">=5.4.0,<5.4.47" → "composer require vendor/pkg:^5.4.47"
     * Retourne null si on ne peut pas extraire une version sûre.
     */
    private function buildFixCommand(string $package, string $affectedVersions): ?string
    {
        // On extrait la borne supérieure (le "<" ou "<=") comme version minimale sûre
        if (preg_match('/<\s*=?\s*v?(\d+(?:\.\d+){1,3})/i', $affectedVersions, $matches) !== 1) {
            return null;
        }

        return sprintf('composer require %s:^%s', $package, $matches[1]);
    }

    /**
     * Parse une date brute (format variable) en format ISO "Y-m-d".
     * Retourne null si la date est absente ou invalide.
     */
    private function parseDate(?string $rawDate): ?string
    {
        if ($rawDate === null || $rawDate === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($rawDate))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}

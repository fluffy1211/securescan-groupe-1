<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

class ComposerAuditService
{
    private const TIMEOUT = 60;

    /**
     * Lance composer audit sur le dossier $path et retourne les advisories normalisés.
     *
     * @return array<int, array{
     *   tool: string,
     *   package: string,
     *   version: string,
     *   cve: string|null,
     *   title: string,
     *   severity: string,
     *   url: string|null,
     *   reported_at: string|null,
     *   owasp: null,
     *   fix: string|null
     * }>
     *
     * @throws \RuntimeException
     */
    public function scan(string $path): array
    {
        if (!is_dir($path)) {
            throw new \RuntimeException("Dossier introuvable : $path");
        }

        if (!file_exists($path . '/composer.json')) {
            throw new \RuntimeException('Aucun composer.json trouvé');
        }

        if (!file_exists($path . '/composer.lock')) {
            throw new \RuntimeException('composer.lock manquant');
        }

        if (!$this->isComposerAvailable()) {
            throw new \RuntimeException('Composer non disponible');
        }

        $output = $this->runAudit($path);
        if (trim($output) === '') {
            return [];
        }

        try {
            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Impossible de parser les résultats');
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Impossible de parser les résultats');
        }

        return $this->normalize($data);
    }

    protected function isComposerAvailable(): bool
    {
        $process = new Process(['composer', '--version']);
        $process->setTimeout(10);
        $process->run();

        return $process->getExitCode() === 0;
    }

    /**
     * Exécute composer audit --format=json et retourne le stdout brut.
     * Exit code 1 signifie "vulnérabilités trouvées" — ce n'est PAS une erreur.
     */
    protected function runAudit(string $path): string
    {
        $process = new Process(
            ['composer', 'audit', '--format=json'],
            $path,
        );
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        $output = $process->getOutput();
        // Important: ne jamais se baser sur isSuccessful() ici.
        // composer audit retourne 1 quand des vulnérabilités sont trouvées.

        return $output;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalize(array $data): array
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
     * @return array<string, mixed>
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
            'url'         => $advisory['url'] ?? null,
            'reported_at' => $reportedAt,
            'owasp'       => null,
            'fix'         => $this->buildFixCommand($packageName, $affectedVersions),
        ];
    }

    /**
     * Extrait la borne supérieure de affectedVersions pour construire la commande fix.
     * Ex : ">=5.4.0,<5.4.47" → "composer require vendor/pkg:^5.4.47"
     */
    private function buildFixCommand(string $package, string $affectedVersions): ?string
    {
        $safeVersion = $this->extractSafeVersion($affectedVersions);
        if ($safeVersion !== null) {
            return sprintf('composer require %s:^%s', $package, $safeVersion);
        }

        return null;
    }

    private function extractSafeVersion(string $affectedVersions): ?string
    {
        // Exemples gérés: ">=5.4.0,<5.4.47", "<=2.8.52", "<1.2.3"
        if (preg_match('/<\s*=?\s*v?(\d+(?:\.\d+){1,3})/i', $affectedVersions, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

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

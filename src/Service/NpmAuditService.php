<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;

/**
 * Scanner de dépendances JavaScript via `npm audit`.
 *
 * Hérite de AbstractPackageAuditService : seules la configuration de l'outil
 * et la normalisation du JSON de sortie sont implémentées ici.
 */
class NpmAuditService extends AbstractPackageAuditService
{
    protected function getToolName(): string
    {
        return 'npm_audit';
    }

    protected function getManifestFile(): string
    {
        return 'package.json';
    }

    protected function getLockFile(): string
    {
        return 'package-lock.json';
    }

    protected function getVersionCommand(): array
    {
        return ['npm', '--version'];
    }

    protected function getAuditCommand(): array
    {
        return ['npm', 'audit', '--json'];
    }

    /**
     * Surcharge : génère package-lock.json si absent avant l'audit.
     * Certains projets (ex: dvna) n'ont que package.json sans lockfile committé.
     * `npm install --package-lock-only` crée le lockfile sans installer les modules.
     */
    public function scan(string $path): array
    {
        if (!is_dir($path)) {
            throw new \RuntimeException("Dossier introuvable : $path");
        }
        if (!file_exists($path . '/' . $this->getManifestFile())) {
            throw new \RuntimeException($this->getManifestFile() . ' introuvable');
        }
        if (!$this->isToolAvailable()) {
            throw new \RuntimeException($this->getToolName() . ' non disponible');
        }

        // Génère package-lock.json si manquant (sans installer les node_modules)
        if (!file_exists($path . '/' . $this->getLockFile())) {
            $install = new Process(
                ['npm', 'install', '--package-lock-only', '--ignore-scripts'],
                $path
            );
            $install->setTimeout(120);
            $install->run();

            if (!file_exists($path . '/' . $this->getLockFile())) {
                throw new \RuntimeException('Impossible de générer package-lock.json');
            }
        }

        return parent::scan($path);
    }

    /**
     * Normalise la sortie de `npm audit --json`.
     * Structure attendue : { "vulnerabilities": { "pkg": { via: [...], ... } } }
     *
     * Chaque entrée `via` peut être un objet advisory (tableau) ou une simple string
     * de propagation (dépendance transitive) — on ne traite que les tableaux.
     */
    protected function normalize(array $data): array
    {
        $vulnerabilities = $data['vulnerabilities'] ?? [];
        $result = [];

        foreach ($vulnerabilities as $packageName => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($entry['via'] ?? [] as $via) {
                // Les `via` de type string sont des propagations, pas des advisories directs
                if (!is_array($via)) {
                    continue;
                }
                $result[] = $this->normalizeAdvisory($packageName, $entry, $via);
            }
        }

        return $result;
    }

    /**
     * Transforme un advisory brut npm en tableau normalisé.
     * La sévérité est prise en priorité depuis l'advisory `via`, sinon depuis l'entrée parente.
     */
    private function normalizeAdvisory(string $packageName, array $entry, array $via): array
    {
        $severity = strtolower((string) ($via['severity'] ?? $entry['severity'] ?? 'unknown'));

        return [
            'tool'        => 'npm_audit',
            'package'     => $packageName,
            'version'     => $entry['range'] ?? '',
            'cve'         => $via['cve'][0] ?? $via['cve'] ?? null,
            'title'       => $via['title'] ?? $via['name'] ?? $packageName,
            'severity'    => $severity,
            'url'         => $via['url'] ?? null,
            'reported_at' => null,
            'owasp'       => null,
            'fix'         => $this->buildFixCommand($packageName, $entry),
        ];
    }

    /**
     * Construit la commande de correctif si npm indique qu'un fix est disponible.
     * `fixAvailable` peut être `true` (fix direct) ou un objet (fix via un parent).
     */
    private function buildFixCommand(string $package, array $entry): ?string
    {
        $fixAvailable = $entry['fixAvailable'] ?? false;
        if ($fixAvailable === true || is_array($fixAvailable)) {
            return sprintf('npm install %s', $package);
        }

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ScanJob;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contrat commun pour tous les outils d'analyse de sécurité.
 * Chaque implémentation reçoit un ScanJob à enrichir et le chemin
 * du dépôt déjà cloné, puis persiste ses propres entités Vulnerability.
 */
#[AutoconfigureTag('app.scanner')]
interface ScannerInterface
{
    /**
     * Exécute l'analyse sur le répertoire cloné et persiste les Vulnerability
     * résultantes dans le ScanJob donné.
     *
     * @param ScanJob $job      Le job courant auquel rattacher les vulnérabilités
     * @param string  $cloneDir Chemin absolu du dépôt cloné (lecture seule)
     */
    public function run(ScanJob $job, string $cloneDir): void;
}

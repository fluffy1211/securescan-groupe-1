<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Repository\ScanJobRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Fournisseur d'état pour GET /api/scan_jobs (collection).
 *
 * Retourne uniquement les jobs de scan appartenant à l'utilisateur authentifié,
 * triés du plus récent au plus ancien.
 */
final class ScanJobCollectionProvider implements ProviderInterface
{
    public function __construct(
        private ScanJobRepository $repository,
        private Security $security,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        // Aucune collection accessible sans authentification
        $user = $this->security->getUser();
        if ($user === null) {
            return [];
        }

        // Retourne uniquement les scans de l'utilisateur connecté, du plus récent au plus ancien
        return $this->repository->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }
}

<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ScanJob;
use App\Enum\ScanStatus;
use App\Service\AuditOrchestratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Processeur d'état pour POST /api/scan_jobs.
 *
 * - Associe l'utilisateur authentifié au job si connecté (les scans anonymes sont autorisés)
 * - Persiste le job avec le statut 'pending'
 * - Déclenche le pipeline d'audit complet de manière synchrone
 */
final class ScanJobProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditOrchestratorService $orchestrator,
        private Security $security,
    ) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ScanJob
    {
        /** @var ScanJob $data */
        // Lie l'utilisateur authentifié au job (null si scan anonyme)
        $user = $this->security->getUser();
        if ($user !== null) {
            $data->setUser($user);
        }

        $data->setStatus(ScanStatus::PENDING);

        $this->em->persist($data);
        $this->em->flush();

        $this->orchestrator->audit($data);

        return $data;
    }
}

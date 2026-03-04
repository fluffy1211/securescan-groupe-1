<?php

namespace App\Entity;

use App\Repository\ScanJobRepository;
use App\State\ScanJobCollectionProvider;
use App\State\ScanJobProcessor;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        // Tout le monde (connecté ou non) peut soumettre un scan
        new Post(
            processor: ScanJobProcessor::class,
        ),
        // Seul le propriétaire du job peut consulter son résultat (dashboard)
        new Get(
            security: "is_granted('ROLE_USER') and object.getUser() === user",
        ),
        // Seuls les utilisateurs authentifiés voient leur historique
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            provider: ScanJobCollectionProvider::class,
        ),
    ],
    normalizationContext: ['groups' => ['scan_job:read']],
    denormalizationContext: ['groups' => ['scan_job:write']],
)]
#[ORM\Entity(repositoryClass: ScanJobRepository::class)]
class ScanJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['scan_job:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 200)]
    #[Groups(['scan_job:read', 'scan_job:write'])]
    private ?string $repoUrl = null;

    #[ORM\Column(length: 255)]
    #[Groups(['scan_job:read'])]
    private ?string $status = null;

    #[ORM\Column]
    #[Groups(['scan_job:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['scan_job:read'])]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['scan_job:read'])]
    private ?int $globalScore = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\OneToMany(targetEntity: Vulnerability::class, mappedBy: 'scanJob', cascade: ['persist', 'remove'])]
    #[Groups(['scan_job:read'])]
    private Collection $vulnerabilities;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->vulnerabilities = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRepoUrl(): ?string
    {
        return $this->repoUrl;
    }

    public function setRepoUrl(string $repoUrl): static
    {
        $this->repoUrl = $repoUrl;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;
        return $this;
    }

    public function getGlobalScore(): ?int
    {
        return $this->globalScore;
    }

    public function setGlobalScore(?int $globalScore): static
    {
        $this->globalScore = $globalScore;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return Collection<int, Vulnerability>
     */
    public function getVulnerabilities(): Collection
    {
        return $this->vulnerabilities;
    }

    public function addVulnerability(Vulnerability $vulnerability): static
    {
        if (!$this->vulnerabilities->contains($vulnerability)) {
            $this->vulnerabilities->add($vulnerability);
            $vulnerability->setScanJob($this);
        }
        return $this;
    }

    public function removeVulnerability(Vulnerability $vulnerability): static
    {
        if ($this->vulnerabilities->removeElement($vulnerability)) {
            if ($vulnerability->getScanJob() === $this) {
                $vulnerability->setScanJob(null);
            }
        }
        return $this;
    }
}
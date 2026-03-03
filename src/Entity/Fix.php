<?php

namespace App\Entity;

use App\Repository\FixRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FixRepository::class)]
class Fix
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $originalCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fixedCode = null;

    #[ORM\Column]
    private ?bool $applied = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $branchName = null;

    #[ORM\ManyToOne(targetEntity: Vulnerability::class, inversedBy: 'fixes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vulnerability $vulnerability = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOriginalCode(): ?string
    {
        return $this->originalCode;
    }

    public function setOriginalCode(?string $originalCode): static
    {
        $this->originalCode = $originalCode;

        return $this;
    }

    public function getFixedCode(): ?string
    {
        return $this->fixedCode;
    }

    public function setFixedCode(?string $fixedCode): static
    {
        $this->fixedCode = $fixedCode;

        return $this;
    }

    public function isApplied(): ?bool
    {
        return $this->applied;
    }

    public function setApplied(bool $applied): static
    {
        $this->applied = $applied;

        return $this;
    }

    public function getBranchName(): ?string
    {
        return $this->branchName;
    }

    public function setBranchName(?string $branchName): static
    {
        $this->branchName = $branchName;

        return $this;
    }

    public function getVulnerability(): ?Vulnerability
    {
        return $this->vulnerability;
    }

    public function setVulnerability(?Vulnerability $vulnerability): static
    {
        $this->vulnerability = $vulnerability;

        return $this;
    }
}

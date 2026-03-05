<?php

namespace App\Entity;

use App\Repository\UserRepository;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Groups(['user:read'])]
    private ?string $email = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\OneToMany(targetEntity: ScanJob::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $scanJobs;

    public function __construct()
    {
        $this->scanJobs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function __serialize(): array
    {
        return [
            'id'       => $this->id,
            'email'    => $this->email,
            'roles'    => $this->roles,
            'password' => $this->password,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id       = $data['id'];
        $this->email    = $data['email'];
        $this->roles    = $data['roles'];
        $this->password = $data['password'];
        $this->scanJobs = new ArrayCollection();
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * @return Collection<int, ScanJob>
     */
    public function getScanJobs(): Collection
    {
        return $this->scanJobs;
    }

    public function addScanJob(ScanJob $scanJob): static
    {
        if (!$this->scanJobs->contains($scanJob)) {
            $this->scanJobs->add($scanJob);
            $scanJob->setUser($this);
        }
        return $this;
    }

    public function removeScanJob(ScanJob $scanJob): static
    {
        if ($this->scanJobs->removeElement($scanJob)) {
            if ($scanJob->getUser() === $this) {
                $scanJob->setUser(null);
            }
        }
        return $this;
    }
}

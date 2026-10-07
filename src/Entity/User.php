<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'wave_user')]
#[ORM\UniqueConstraint(name: 'uniq_wave_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 70, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $countryCode = null;

    #[ORM\Column(length: 5, options: ['default' => 'bs'])]
    private string $preferredLocale = 'bs';

    #[ORM\Column(length: 255)]
    private string $password;

    #[ORM\Column(length: 30)]
    private string $role;

    #[ORM\Column(options: ['default' => true])]
    private bool $emailVerified = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $approved = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $moderator = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $admin = false;

    #[ORM\Column(name: 'hide_my_account', options: ['default' => false])]
    private bool $hideMyAccount = false;

    #[ORM\OneToOne(mappedBy: 'owner', targetEntity: Creator::class, cascade: ['persist', 'remove'])]
    private ?Creator $creator = null;

    #[ORM\OneToOne(mappedBy: 'owner', targetEntity: Company::class, cascade: ['persist', 'remove'])]
    private ?Company $company = null;

    public function __construct(string $email, string $role)
    {
        $this->email = mb_strtolower(trim($email));
        $this->role = $role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): void
    {
        $this->city = $city;
    }

    public function getCountryCode(): ?string
    {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): void
    {
        $this->countryCode = $countryCode;
    }

    public function getPreferredLocale(): string
    {
        return $this->preferredLocale;
    }

    public function setPreferredLocale(string $preferredLocale): void
    {
        $this->preferredLocale = $preferredLocale;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = [$this->role];
        if ($this->moderator || $this->admin) {
            $roles[] = 'ROLE_MODERATOR';
        }
        if ($this->admin) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    public function setModerator(bool $moderator): void
    {
        $this->moderator = $moderator;
    }

    public function setAdmin(bool $admin): void
    {
        $this->admin = $admin;
    }

    public function isHideMyAccount(): bool
    {
        return $this->hideMyAccount;
    }

    public function setHideMyAccount(bool $hideMyAccount): void
    {
        $this->hideMyAccount = $hideMyAccount;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): void
    {
        $this->emailVerified = $emailVerified;
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function hasCompleteProfile(): bool
    {
        if (trim($this->phone ?? '') === ''
            || trim($this->city ?? '') === ''
            || preg_match('/^[A-Z]{2}$/', $this->countryCode ?? '') !== 1
        ) {
            return false;
        }

        if ($this->hasRole('ROLE_CREATOR')) {
            $creator = $this->creator;

            return $creator instanceof Creator
                && mb_strlen(trim($creator->getDisplayName())) >= 2
                && array_filter($creator->getCategories(), static fn (string $category): bool => trim($category) !== '') !== [];
        }

        if ($this->hasRole('ROLE_COMPANY')) {
            $company = $this->company;

            return $company instanceof Company
                && mb_strlen(trim($company->getName())) >= 2
                && mb_strlen(trim($company->getIndustry())) >= 2;
        }

        return false;
    }

    public function setApproved(bool $approved): void
    {
        $this->approved = $approved;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function eraseCredentials(): void
    {
    }

    public function getCreator(): ?Creator
    {
        return $this->creator;
    }

    public function setCreator(?Creator $creator): void
    {
        $this->creator = $creator;
        $creator?->setOwner($this);
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): void
    {
        $this->company = $company;
        $company?->setOwner($this);
    }
}

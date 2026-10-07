<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'company_industry')]
#[ORM\UniqueConstraint(name: 'uniq_company_industry', columns: ['company_id', 'value'])]
#[ORM\Index(name: 'idx_company_industry_value', columns: ['value', 'company_id'])]
class CompanyIndustry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'industrySelections', targetEntity: Company::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Company $company,
        #[ORM\Column(length: 100)]
        private string $value,
    ) {
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

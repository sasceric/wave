<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'directory_index')]
#[ORM\Index(name: 'idx_directory_kind_entity', columns: ['kind', 'entity_id'])]
#[ORM\Index(name: 'idx_directory_indexed', columns: ['indexed_at'])]
class DirectoryIndex
{
    #[ORM\Id]
    #[ORM\Column(length: 60)]
    private string $id;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column]
    private int $entityId;

    #[ORM\Column(type: 'text')]
    private string $searchText;

    #[ORM\Column(type: 'text')]
    private string $platformKeys;

    #[ORM\Column(type: 'text')]
    private string $tagText;

    #[ORM\Column(nullable: true)]
    private ?int $availableCampaignCount;

    #[ORM\Column]
    private \DateTimeImmutable $indexedAt;
}

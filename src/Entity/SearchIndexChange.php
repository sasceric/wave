<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Transactional safety fence: pending documents are excluded until Meilisearch confirms indexing. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'search_index_change')]
class SearchIndexChange
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;
    #[ORM\Column(length: 20)]
    private string $kind;
    #[ORM\Column]
    private int $entityId;
    #[ORM\Column(length: 32)]
    private string $revision;
}

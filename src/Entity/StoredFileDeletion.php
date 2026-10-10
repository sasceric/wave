<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable cleanup instructions deliberately survive their original owner. */
#[ORM\Entity]
#[ORM\Table(name: 'stored_file_deletion')]
class StoredFileDeletion
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $id;

    #[ORM\Column(length: 16)]
    private string $kind;

    #[ORM\Column(length: 500)]
    private string $path;

    public function __construct(string $kind, string $path)
    {
        $this->id = hash('sha256', $kind.':'.$path);
        $this->kind = $kind;
        $this->path = $path;
    }
}

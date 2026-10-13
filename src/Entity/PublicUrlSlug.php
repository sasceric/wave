<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Permanent reservations protect old links even after redirect rules are deleted. */
#[ORM\Entity]
#[ORM\Table(name: 'public_url_slug')]
class PublicUrlSlug
{
    #[ORM\Id]
    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Id]
    #[ORM\Column(length: 120)]
    private string $slug;

    public function __construct(string $kind, string $slug) { $this->kind = $kind; $this->slug = $slug; }
}

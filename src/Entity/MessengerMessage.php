<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'messenger_messages')]
#[ORM\Index(name: 'idx_messenger_receive', columns: ['queue_name', 'available_at', 'delivered_at', 'id'])]
class MessengerMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'text')]
    private string $headers;

    #[ORM\Column(length: 190)]
    private string $queueName;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $availableAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt;
}

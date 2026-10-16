<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Campaign;
use Doctrine\ORM\EntityManagerInterface;

final class CampaignApplicationCounter
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** Call once after inserting a genuine application, in the same transaction. */
    public function increment(Campaign $campaign): void
    {
        $db = $this->entityManager->getConnection();
        if (!$db->isTransactionActive() || $campaign->getId() === null) {
            throw new \LogicException('An application counter increment requires a persisted campaign and an active transaction.');
        }

        $db->executeStatement('UPDATE campaign SET application_count = application_count + 1 WHERE id = ?', [$campaign->getId()]);
        // Keep the application response consistent with the committed total.
        $this->entityManager->refresh($campaign);
    }
}

<?php

namespace App\Account;

use App\Entity\{Campaign, Application, Company, Creator, User};
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\{OnFlushEventArgs, PostFlushEventArgs};

#[AsDoctrineListener(event: 'onFlush')]
#[AsDoctrineListener(event: 'postFlush')]
final class AccountActivationListener
{
    private array $pending = [];
    public function onFlush(OnFlushEventArgs $event): void
    {
        $this->pending = [];
        $work = $event->getObjectManager()->getUnitOfWork();
        $insertions = $work->getScheduledEntityInsertions();
        foreach ([...$insertions, ...$work->getScheduledEntityUpdates()] as $entity) {
            $user = match (true) {
                $entity instanceof User => $entity,
                $entity instanceof Creator, $entity instanceof Company => $entity->getOwner(),
                $entity instanceof Application => $entity->getCreator()->getOwner(),
                $entity instanceof Campaign => $entity->getCompany()->getOwner(),
                default => null,
            };
            if (!$user || $user->isDeleted() || $user->isPresentationDemo() || $user->hasRole('ROLE_ADMIN') || $user->hasRole('ROLE_MODERATOR')) continue;
            $key = spl_object_id($user);
            $this->pending[$key] ??= ['user' => $user, 'registered' => false, 'first_action' => false];
            if ($entity instanceof User && in_array($entity, $insertions, true)) $this->pending[$key]['registered'] = true;
            if ((($entity instanceof Application && $entity->getStatus() === 'pending') || $entity instanceof Campaign) && in_array($entity, $insertions, true)) $this->pending[$key]['first_action'] = true;
        }
    }
    public function postFlush(PostFlushEventArgs $event): void
    {
        $pending = $this->pending; $this->pending = [];
        $db = $event->getObjectManager()->getConnection();
        foreach ($pending as $row) {
            $user = $row['user']; $id = $user->getId();
            if ($id === null) continue;
            // Do not invent historic registration times for accounts that predate measurement.
            if (!$row['registered'] && !$db->fetchOne("SELECT user_id FROM account_milestone WHERE user_id = ? AND stage = 'registered'", [$id])) continue;
            $stages = ['registered' => $row['registered'], 'verified' => $user->isEmailVerified(), 'completed' => $user->hasCompleteProfile(), 'approved' => $user->isApproved(), 'first_action' => $row['first_action']];
            foreach ($stages as $stage => $reached) {
                if ($reached) $db->executeStatement('INSERT INTO account_milestone (user_id, stage, occurred_at) VALUES (?, ?, ?) ON CONFLICT (user_id, stage) DO NOTHING', [$id, $stage, gmdate('Y-m-d H:i:s')]);
            }
        }
    }
}

<?php

namespace App\EventSubscriber;

use App\Background\JobDispatcher;
use App\Entity\Campaign;
use App\Entity\Company;
use App\Entity\CompanyIndustry;
use App\Entity\Creator;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class IndexChangesSubscriber
{
    private array $changed = [];

    public function __construct(private readonly JobDispatcher $jobs, #[Autowire('%wave.queue.enabled%')] private readonly bool $enabled)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        if (!$this->enabled) {
            return;
        }
        $work = $event->getObjectManager()->getUnitOfWork();
        // Drop stale projections in the domain transaction; readers fall back to source data until indexing completes.
        $db = $event->getObjectManager()->getConnection();
        $keys = [];
        foreach ([...$work->getScheduledEntityInsertions(), ...$work->getScheduledEntityUpdates(), ...$work->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof CompanyIndustry) {
                $entity = $entity->getCompany();
            }
            if ($entity instanceof Creator || $entity instanceof Company || $entity instanceof Campaign) {
                $kind = $entity instanceof Creator ? 'creator' : ($entity instanceof Company ? 'company' : 'campaign');
                if ($entity->getId() !== null) {
                    $keys[] = $kind . ':' . $entity->getId();
                }
            }
            if ($entity instanceof User) {
                foreach ([$entity->getCreator(), $entity->getCompany()] as $profile) {
                    if ($profile !== null && $profile->getId() !== null) {
                        $keys[] = ($profile instanceof Creator ? 'creator:' : 'company:') . $profile->getId();
                    }
                }
            }
            if ($entity instanceof Campaign && $entity->getCompany()->getId() !== null) {
                $keys[] = 'company:' . $entity->getCompany()->getId();
            }
        }
        $keys = array_unique($keys);
        sort($keys);
        foreach ($keys as $key) {
            $db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', ['wave.index.' . $key]);
            $db->delete('directory_index', ['id' => $key]);
        }
        foreach ([...$work->getScheduledEntityInsertions(), ...$work->getScheduledEntityUpdates(), ...$work->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof CompanyIndustry) {
                $entity = $entity->getCompany();
            }
            if ($entity instanceof Creator || $entity instanceof Company || $entity instanceof Campaign) {
                $this->changed[spl_object_id($entity)] = $entity;
            }
            if ($entity instanceof Campaign) {
                $this->changed[spl_object_id($entity->getCompany())] = $entity->getCompany();
            }
            if ($entity instanceof User) {
                foreach ([$entity->getCreator(), $entity->getCompany()] as $profile) {
                    if ($profile !== null) {
                        $this->changed[spl_object_id($profile)] = $profile;
                    }
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        $changed = $this->changed;
        $this->changed = [];
        $groups = [];
        foreach ($changed as $entity) {
            $kind = $entity instanceof Creator ? 'Creator' : ($entity instanceof Company ? 'Company' : 'Campaign');
            if ($entity->getId() !== null) {
                $groups[$kind][] = $entity->getId();
            }
        }
        foreach ($groups as $kind => $ids) {
            foreach (array_chunk(array_unique($ids), 100) as $chunk) {
                $this->jobs->enqueue($kind . 'IndexingMessage', ['ids' => $chunk]);
            }
        }
        if ($groups !== []) {
            $this->jobs->enqueue('SitemapGenerateTask', [], 'sitemap:' . intdiv(time(), 300), (300 - time() % 300) * 1000);
        }
    }
}

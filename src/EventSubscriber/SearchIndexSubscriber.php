<?php

namespace App\EventSubscriber;

use App\Background\JobDispatcher;
use App\Entity\{Campaign, Company, CompanyIndustry, Creator, MarketplaceCategory, User};
use App\Search\{Meilisearch, SearchIndexer};
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\{OnFlushEventArgs, PostFlushEventArgs};
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class SearchIndexSubscriber
{
    private array $changed = [];
    private bool $catalog = false;
    private array $dependentCampaigns = [];
    public function __construct(private readonly Meilisearch $meili, private readonly SearchIndexer $indexer, private readonly JobDispatcher $jobs) {}
    public function onFlush(OnFlushEventArgs $event): void
    {
        if (!$this->meili->enabled) return;
        $work = $event->getObjectManager()->getUnitOfWork();
        foreach ([...$work->getScheduledEntityInsertions(), ...$work->getScheduledEntityUpdates(), ...$work->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof MarketplaceCategory) $this->catalog = true;
            if ($entity instanceof CompanyIndustry) $entity = $entity->getCompany();
            if ($entity instanceof User) {
                foreach ([$entity->getCreator(), $entity->getCompany()] as $profile) if ($profile) $this->remember($profile);
            }
            if ($entity instanceof Creator || $entity instanceof Company || $entity instanceof Campaign) $this->remember($entity);
            if ($entity instanceof Company && $entity->getId() !== null) {
                array_push($this->dependentCampaigns, ...array_map(intval(...), $event->getObjectManager()->getConnection()->fetchFirstColumn('SELECT id FROM campaign WHERE company_id = ?', [$entity->getId()])));
            }
            if ($entity instanceof Creator && $entity->isAgencyProfile() && $entity->getOwner()?->getCompany()) $this->remember($entity->getOwner()->getCompany());
        }
    }
    private function remember(Creator|Company|Campaign $entity): void { $this->changed[spl_object_id($entity)] = [$entity, $entity->getId()]; }
    public function postFlush(PostFlushEventArgs $event): void
    {
        $changed = $this->changed; $this->changed = [];
        $groups = $this->dependentCampaigns ? ['campaign' => $this->dependentCampaigns] : [];
        $this->dependentCampaigns = [];
        foreach ($changed as [$entity, $oldId]) {
            $kind = $entity instanceof Creator ? 'creator' : ($entity instanceof Company ? 'company' : 'campaign');
            $id = $oldId ?? $entity->getId();
            if ($id !== null) $groups[$kind][] = $id;
            if ($entity instanceof Company && $id !== null) {
                $groups['campaign'] ??= [];
                array_push($groups['campaign'], ...array_map(intval(...), $event->getObjectManager()->getConnection()->fetchFirstColumn('SELECT id FROM campaign WHERE company_id = ?', [$id])));
            }
        }
        $event->getObjectManager()->getConnection()->transactional(function () use ($groups): void {
            ksort($groups);
            foreach ($groups as $kind => $ids) {
                $ids = array_values(array_unique($ids)); sort($ids, SORT_STRING);
                foreach ($ids as $id) $this->indexer->mark($kind, $id);
                foreach (array_chunk($ids, 100) as $chunk) $this->jobs->enqueue('SearchIndexBatchMessage', ['kind' => $kind, 'ids' => $chunk]);
            }
            if ($this->catalog) { $this->catalog = false; $this->jobs->enqueue('SearchReconcileTask'); }
        });
    }
}

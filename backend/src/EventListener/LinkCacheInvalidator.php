<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Cache\LinkCache;
use App\Entity\Link;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps the Redis slug → destinationUrl cache in sync with the database.
 * Runs on every Link write, so it covers API Platform PATCH/DELETE, console
 * commands, and any future writer. Safety TTL on the cache handles edge
 * cases (rollbacks, missed events).
 */
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final class LinkCacheInvalidator
{
    public function __construct(private readonly LinkCache $cache)
    {
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof Link) {
            return;
        }

        // Warm with the new value instead of just invalidating: the next
        // /r/{slug} request after an edit is the most likely one (link owner
        // testing the change), so we save it a DB round-trip.
        $this->cache->warm($entity);
    }

    public function preRemove(PreRemoveEventArgs $event): void
    {
        $entity = $event->getObject();
        if (!$entity instanceof Link) {
            return;
        }

        $slug = $entity->getSlug();
        if (null === $slug) {
            return;
        }

        $this->cache->invalidate($slug);
    }
}

<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Link;
use App\Entity\User;
use App\Service\SlugGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * @implements ProcessorInterface<Link, Link>
 */
final class LinkProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: PersistProcessor::class)]
        private readonly ProcessorInterface $persistProcessor,
        private readonly SlugGenerator $slugGenerator,
        private readonly Security $security,
        #[Autowire(service: 'limiter.link_creation')]
        private readonly RateLimiterFactoryInterface $linkCreationLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Link
    {
        if ($operation instanceof Post) {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new \LogicException('A user must be authenticated to create a link.');
            }

            // Per-user limiter, keyed on the immutable user id.
            $limit = $this->linkCreationLimiter
                ->create((string) $user->getId())
                ->consume(1);
            if (!$limit->isAccepted()) {
                $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());
                throw new TooManyRequestsHttpException(
                    $retryAfterSeconds,
                    'Too many link creations. Please slow down.',
                );
            }

            $data->setOwner($user);
            if (null === $data->getSlug()) {
                $data->setSlug($this->slugGenerator->generateUnique());
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}

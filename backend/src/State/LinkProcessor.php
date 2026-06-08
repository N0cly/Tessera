<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Doctrine\Common\State\PersistProcessor;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Link;
use App\Entity\User;
use App\Repository\LinkRepository;
use App\Service\DemoWorkspaceSeeder;
use App\Service\FeatureFlags;
use App\Service\PlanCatalog;
use App\Service\SlugGenerator;
use App\Service\SubscriptionManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly SubscriptionManager $subscriptions,
        private readonly PlanCatalog $plans,
        private readonly LinkRepository $links,
        private readonly TranslatorInterface $translator,
        private readonly FeatureFlags $flags,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Link
    {
        if ($operation instanceof Post) {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                throw new \LogicException('A user must be authenticated to create a link.');
            }

            // Code limit. In demo mode it's the per-session quota (abuse
            // guardrail); otherwise the plan's allowance (CLAUDE.md rules 14/19).
            if ($this->flags->isDemoMode()) {
                // Apply the quota ON TOP of the seeded template links, so the
                // visitor can always create their full demoLinkQuota() allowance
                // and the seed never eats into it (the count is over ALL the
                // synthetic user's links, seeded included).
                $codeLimit = DemoWorkspaceSeeder::templateSize() + $this->flags->demoLinkQuota();
                $limitMessageKey = 'link.demo_quota';
            } else {
                $codeLimit = $this->plans->codeLimitFor($this->subscriptions->getOrCreate($user));
                $limitMessageKey = 'link.limit_reached';
            }
            if (null !== $codeLimit && $this->links->countForOwner($user) >= $codeLimit) {
                throw new HttpException(
                    402,
                    $this->translator->trans($limitMessageKey, ['%count%' => $codeLimit]),
                );
            }

            // Per-user limiter, keyed on the immutable user id.
            $limit = $this->linkCreationLimiter
                ->create((string) $user->getId())
                ->consume(1);
            if (!$limit->isAccepted()) {
                $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());
                throw new TooManyRequestsHttpException(
                    $retryAfterSeconds,
                    $this->translator->trans('link.rate_limited'),
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

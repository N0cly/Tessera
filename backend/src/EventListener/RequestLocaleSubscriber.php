<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\LocaleResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sets the request + translator locale per request so validation/API error
 * messages and any translated system content come out in the right language:
 * an authenticated user's stored `locale` wins, otherwise the visitor's
 * Accept-Language (e.g. the scanner of a lapsed code), falling back to English.
 *
 * Runs at priority 6 — after the firewall (priority 8) so the authenticated
 * user is already available, and before the controller/validation.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class RequestLocaleSubscriber
{
    public function __construct(
        private readonly Security $security,
        private readonly LocaleResolver $resolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $user = $this->security->getUser();
        $locale = $user instanceof User
            ? $this->resolver->normalize($user->getLocale())
            : $this->resolver->fromAcceptLanguage($request);

        $request->setLocale($locale);
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }
    }
}

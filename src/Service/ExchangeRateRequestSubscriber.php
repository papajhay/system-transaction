<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ExchangeRateRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly DailyExchangeRateInitializer $initializer,
        private readonly LoggerInterface $logger,
        private readonly string $environment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', -100],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->environment !== 'dev') {
            return;
        }

        try {
            $this->initializer->initialize();
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to initialize today\'s exchange rates.', [
                'exception' => $exception,
            ]);
        }
    }
}

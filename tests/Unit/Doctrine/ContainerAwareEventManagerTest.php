<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\ContainerAwareEventManager;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ContainerAwareEventManagerTest extends TestCase
{
    public function testOnFlushListenersAddedWhileLazyListenersAreInitializedRemainDispatchable(): void
    {
        $first = new OnFlushListener();
        $lazy = new OnFlushListener();
        $addedDuringInitialization = new OnFlushListener();
        $eventManager = null;

        $container = new ServiceLocator([
            'lazy_on_flush_listener' => static function () use (&$eventManager, $lazy, $addedDuringInitialization): OnFlushListener {
                $eventManager->addEventListener('onFlush', $addedDuringInitialization);

                return $lazy;
            },
        ]);

        $eventManager = new ContainerAwareEventManager($container);
        $eventManager->addEventListener('onFlush', $first);
        $eventManager->addEventListener('onFlush', 'lazy_on_flush_listener');

        $eventManager->dispatchEvent('onFlush');

        self::assertSame(1, $first->calls);
        self::assertSame(1, $lazy->calls);
        self::assertSame(1, $addedDuringInitialization->calls);
        self::assertCount(3, $eventManager->getListeners('onFlush'));
    }
}

final class OnFlushListener
{
    public int $calls = 0;

    public function onFlush(): void
    {
        ++$this->calls;
    }
}

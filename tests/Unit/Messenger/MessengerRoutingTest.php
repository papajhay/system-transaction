<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Message\ExecuteTransferMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MessengerRoutingTest extends KernelTestCase
{
    public function testTransferMessagesUseTheTransferTransport(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $container->get(MessageBusInterface::class)->dispatch(new ExecuteTransferMessage('TRF-1'));

        $transfer = $container->get('messenger.transport.transfer');
        $async = $container->get('messenger.transport.async');

        self::assertInstanceOf(InMemoryTransport::class, $transfer);
        self::assertInstanceOf(InMemoryTransport::class, $async);
        self::assertCount(1, $transfer->getSent());
        self::assertInstanceOf(ExecuteTransferMessage::class, $transfer->getSent()[0]->getMessage());
        self::assertCount(0, $async->getSent());
    }
}

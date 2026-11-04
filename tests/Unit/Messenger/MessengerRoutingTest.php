<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messenger;

use App\Message\ExecuteTransferMessage;
use App\Message\ExecuteDepositMessage;
use App\Message\ExecuteWithdrawalMessage;
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

    public function testWithdrawalMessagesUseOnlyTheWithdrawalTransport(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $container->get(MessageBusInterface::class)->dispatch(new ExecuteWithdrawalMessage('WIT-1'));

        $withdrawal = $container->get('messenger.transport.withdrawal');
        $transfer = $container->get('messenger.transport.transfer');
        $async = $container->get('messenger.transport.async');

        self::assertInstanceOf(InMemoryTransport::class, $withdrawal);
        self::assertCount(1, $withdrawal->getSent());
        self::assertInstanceOf(ExecuteWithdrawalMessage::class, $withdrawal->getSent()[0]->getMessage());
        self::assertCount(0, $transfer->getSent());
        self::assertCount(0, $async->getSent());
    }

    public function testDepositMessagesUseOnlyTheDepositTransport(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $container->get(MessageBusInterface::class)->dispatch(new ExecuteDepositMessage('DEP-1'));

        $deposit = $container->get('messenger.transport.deposit');
        $transfer = $container->get('messenger.transport.transfer');
        $withdrawal = $container->get('messenger.transport.withdrawal');
        $async = $container->get('messenger.transport.async');

        self::assertInstanceOf(InMemoryTransport::class, $deposit);
        self::assertCount(1, $deposit->getSent());
        self::assertInstanceOf(ExecuteDepositMessage::class, $deposit->getSent()[0]->getMessage());
        self::assertCount(0, $transfer->getSent());
        self::assertCount(0, $withdrawal->getSent());
        self::assertCount(0, $async->getSent());
    }
}

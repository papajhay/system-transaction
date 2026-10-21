<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Message\MonitorPendingTransfersMessage;
use App\Message\GenerateDailyTransactionReportMessage;
use App\Scheduler\PendingTransferScheduleProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class PendingTransferScheduleProviderTest extends TestCase
{
    public function testScheduleRunsEveryFiveMinutes(): void
    {
        $schedule = (new PendingTransferScheduleProvider('02:00', 'UTC'))->getSchedule();
        $recurringMessage = $schedule
            ->getRecurringMessages()[0];
        $trigger = $recurringMessage->getTrigger();
        $run = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertSame('every 300 seconds', (string) $trigger);
        self::assertSame('2026-01-01 12:05:00', $trigger->getNextRunDate($run)?->format('Y-m-d H:i:s'));
        $messages = $recurringMessage->getMessages(new MessageContext(
            'default',
            $recurringMessage->getId(),
            $trigger,
            $run,
        ));

        self::assertInstanceOf(MonitorPendingTransfersMessage::class, iterator_to_array($messages)[0]);

        $daily = $schedule->getRecurringMessages()[1];
        self::assertSame('0 2 * * *', (string) $daily->getTrigger());
        self::assertInstanceOf(GenerateDailyTransactionReportMessage::class,
            $daily->getMessages(new MessageContext('default', $daily->getId(), $daily->getTrigger(), $run))[0] ?? null,
        );
    }
}

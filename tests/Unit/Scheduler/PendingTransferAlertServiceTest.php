<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Dto\PendingTransferInfo;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Service\PendingTransferAlertService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class PendingTransferAlertServiceTest extends TestCase
{
    public function testItDeduplicatesAlertsDuringTheCooldown(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(
            static function (RawMessage $message): bool {
                self::assertInstanceOf(Email::class, $message);
                self::assertStringContainsString('REF-001', $message->getTextBody());
                self::assertStringContainsString('transfer', $message->getTextBody());
                self::assertStringContainsString('elapsed 7200s', $message->getTextBody());
                self::assertStringContainsString('threshold 3600s', $message->getTextBody());

                return true;
            },
        ));
        $service = new PendingTransferAlertService(
            $mailer,
            new ArrayAdapter(),
            $this->createMock(LoggerInterface::class),
            'admin@example.com',
            'transactions@example.com',
            86400,
        );
        $transfer = $this->transferInfo();

        $service->notify([$transfer]);
        $service->notify([$transfer]);
    }

    public function testDeliveryFailureIsContainedAndLogged(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('transport unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Pending transfer alert delivery failed.',
            self::arrayHasKey('exception_class'),
        );

        (new PendingTransferAlertService(
            $mailer,
            new ArrayAdapter(),
            $logger,
            'admin@example.com',
            'transactions@example.com',
            86400,
        ))->notify([$this->transferInfo()]);

        self::assertTrue(true);
    }

    private function transferInfo(): PendingTransferInfo
    {
        return new PendingTransferInfo(
            'REF-001',
            TypeTransfer::TRANSFER,
            StatusTransfer::PENDING,
            new DateTimeImmutable('2026-01-01 10:00:00'),
            7200,
            3600,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduler;

use App\Dto\PendingTransferInfo;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use App\Service\PendingTransferAutoExecutor;
use App\Service\PendingTransferExecutionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class PendingTransferAutoExecutorTest extends TestCase
{
    public function testItDispatchesEachTransferTypeToItsExistingExecutor(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $deposit = $this->createMock(PendingTransferExecutionService::class);
        $withdrawal = $this->createMock(PendingTransferExecutionService::class);
        $transferExecutor = $this->createMock(PendingTransferExecutionService::class);
        $transfer = (new Transfer())->setToken('token-1');

        $registry->expects(self::exactly(3))->method('getRepository')->willReturn($this->createTransferRepository($transfer));
        $registry->method('getManagerForClass')->willReturn($this->openEntityManager());
        $deposit->expects(self::once())->method('execute')->with('token-1')->willReturn([]);
        $withdrawal->expects(self::once())->method('execute')->with('token-1')->willReturn([]);
        $transferExecutor->expects(self::once())->method('execute')->with('token-1')->willReturn([]);

        $service = new PendingTransferAutoExecutor(
            $registry,
            $deposit,
            $withdrawal,
            $transferExecutor,
            new NullLogger(),
        );

        $service->execute([
            $this->pending(TypeTransfer::DEPOSIT, 'DEP-1'),
            $this->pending(TypeTransfer::WITHDRAWAL, 'WIT-1'),
            $this->pending(TypeTransfer::TRANSFER, 'TRF-1'),
        ]);
    }

    public function testOneFailureDoesNotPreventTheNextTransfer(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $deposit = $this->createMock(PendingTransferExecutionService::class);
        $withdrawal = $this->createMock(PendingTransferExecutionService::class);
        $transferExecutor = $this->createMock(PendingTransferExecutionService::class);
        $registry->method('getRepository')->willReturn($this->createTransferRepository((new Transfer())->setToken('token')));
        $registry->method('getManagerForClass')->willReturn($this->openEntityManager());
        $deposit->expects(self::once())->method('execute')->willThrowException(new \RuntimeException('invalid account'));
        $withdrawal->expects(self::once())->method('execute')->with('token')->willReturn([]);

        (new PendingTransferAutoExecutor(
            $registry,
            $deposit,
            $withdrawal,
            $transferExecutor,
            new NullLogger(),
        ))->execute([
            $this->pending(TypeTransfer::DEPOSIT, 'DEP-1'),
            $this->pending(TypeTransfer::WITHDRAWAL, 'WIT-1'),
        ]);
    }

    public function testItSkipsACompletedTransferOnRedelivery(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $completed = (new Transfer())
            ->setToken('token')
            ->setStatus(StatusTransfer::COMPLETED);
        $registry->method('getRepository')->willReturn($this->createTransferRepository($completed));
        $registry->method('getManagerForClass')->willReturn($this->openEntityManager());
        $deposit = $this->createMock(PendingTransferExecutionService::class);
        $deposit->expects(self::never())->method('execute');

        (new PendingTransferAutoExecutor(
            $registry,
            $deposit,
            $this->createMock(PendingTransferExecutionService::class),
            $this->createMock(PendingTransferExecutionService::class),
            new NullLogger(),
        ))->execute([$this->pending(TypeTransfer::DEPOSIT, 'DEP-1')]);
    }

    public function testItLogsTheBusinessFailureAndContinues(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $logger = $this->createMock(LoggerInterface::class);
        $loggedContext = null;
        $repository = $this->createTransferRepository((new Transfer())->setToken('token'));
        $registry->method('getRepository')->willReturn($repository);
        $registry->method('getManagerForClass')->willReturn($this->openEntityManager());
        $deposit = $this->createMock(PendingTransferExecutionService::class);
        $deposit->method('execute')->willThrowException(new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Insufficient balance'));
        $logger->expects(self::once())->method('error')->willReturnCallback(
            static function (string $message, array $context) use (&$loggedContext): void {
                $loggedContext = $context;
            },
        );

        (new PendingTransferAutoExecutor($registry, $deposit, $this->createMock(PendingTransferExecutionService::class), $this->createMock(PendingTransferExecutionService::class), $logger))
            ->execute([$this->pending(TypeTransfer::DEPOSIT, 'DEP-1')]);

        self::assertIsArray($loggedContext);
        self::assertSame('Insufficient balance', $loggedContext['exception_message']);
        self::assertSame('DEP-1', $loggedContext['reference']);
        self::assertSame('deposit', $loggedContext['type']);
    }

    public function testItResetsAClosedManagerBeforeContinuing(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $replacement = $this->openEntityManager();
        $registry->expects(self::once())->method('resetManager')->willReturn($replacement);
        $registry->method('getManagerForClass')->willReturnOnConsecutiveCalls($closed, $closed, $replacement);
        $registry->method('getRepository')->willReturn($this->createTransferRepository((new Transfer())->setToken('token')));
        $deposit = $this->createMock(PendingTransferExecutionService::class);
        $deposit->method('execute')->willThrowException(new \RuntimeException('flush failed'));
        $withdrawal = $this->createMock(PendingTransferExecutionService::class);
        $withdrawal->expects(self::once())->method('execute')->with('token');

        (new PendingTransferAutoExecutor($registry, $deposit, $withdrawal, $this->createMock(PendingTransferExecutionService::class), new NullLogger()))
            ->execute([$this->pending(TypeTransfer::DEPOSIT, 'DEP-1'), $this->pending(TypeTransfer::WITHDRAWAL, 'WIT-1')]);
    }

    private function createTransferRepository(Transfer $transfer): object
    {
        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($transfer);
        return $repository;
    }

    private function openEntityManager(): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn(true);
        return $manager;
    }

    private function pending(TypeTransfer $type, string $reference): PendingTransferInfo
    {
        return new PendingTransferInfo(
            $reference,
            $type,
            StatusTransfer::PENDING,
            new DateTimeImmutable('2026-01-01 00:00:00'),
            60,
            60,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PendingTransferInfo;
use App\Repository\TransferRepository;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

class PendingTransferMonitor
{
    public function __construct(
        private readonly TransferRepository $transferRepository,
        private readonly LoggerInterface $logger,
        private readonly int $thresholdSeconds,
    ) {
        if ($thresholdSeconds <= 0) {
            throw new InvalidArgumentException('TRANSFER_PENDING_THRESHOLD_SECONDS must be greater than zero.');
        }
    }

    /**
     * @return list<PendingTransferInfo>
     */
    public function monitor(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $cutoff = $now->sub(new DateInterval(sprintf('PT%dS', $this->thresholdSeconds)));
        $transfers = $this->transferRepository->findPendingOlderThan($cutoff);
        $overdue = [];

        foreach ($transfers as $transfer) {
            $overdue[] = new PendingTransferInfo(
                reference: $transfer->getReference(),
                type: $transfer->getType(),
                status: $transfer->getStatus(),
                createdAt: $transfer->getCreatedAt(),
                elapsedSeconds: max(0, $now->getTimestamp() - $transfer->getCreatedAt()->getTimestamp()),
                thresholdSeconds: $this->thresholdSeconds,
            );
        }

        $count = count($overdue);

        $this->logger->log($count > 0 ? 'warning' : 'info', $count > 0
            ? 'Pending transfer monitoring found transfers beyond the configured threshold.'
            : 'Pending transfer monitoring completed with no overdue transfers.', [
                'count' => $count,
                'threshold_seconds' => $this->thresholdSeconds,
                'transfers' => array_map(static fn (PendingTransferInfo $item): array => [
                    'reference' => $item->reference,
                    'type' => $item->type->value,
                    'status' => $item->status->value,
                    'created_at' => $item->createdAt->format(DateTimeImmutable::ATOM),
                    'elapsed_seconds' => $item->elapsedSeconds,
                    'threshold_seconds' => $item->thresholdSeconds,
                ], $overdue),
            ]);

        return $overdue;
    }

    public function thresholdSeconds(): int
    {
        return $this->thresholdSeconds;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

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

    public function monitor(?DateTimeImmutable $now = null): int
    {
        $now ??= new DateTimeImmutable();
        $cutoff = $now->sub(new DateInterval(sprintf('PT%dS', $this->thresholdSeconds)));
        $transfers = $this->transferRepository->findPendingOlderThan($cutoff);
        $count = count($transfers);

        if ($count > 0) {
            $this->logger->warning('Pending transfer monitoring found transfers beyond the configured threshold.', [
                'count' => $count,
                'threshold_seconds' => $this->thresholdSeconds,
            ]);
        } else {
            $this->logger->info('Pending transfer monitoring completed with no overdue transfers.', [
                'threshold_seconds' => $this->thresholdSeconds,
            ]);
        }

        return $count;
    }
}

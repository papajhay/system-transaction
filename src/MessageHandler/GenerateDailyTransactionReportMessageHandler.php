<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GenerateDailyTransactionReportMessage;
use App\Service\DailyTransactionReportGenerator;
use App\Service\DailyTransactionReportWriter;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class GenerateDailyTransactionReportMessageHandler
{
    public function __construct(
        private readonly DailyTransactionReportGenerator $generator,
        private readonly DailyTransactionReportWriter $writer,
        private readonly LoggerInterface $logger,
        private readonly string $timezone,
    ) {
        new DateTimeZone($timezone);
    }

    public function __invoke(GenerateDailyTransactionReportMessage $message): void
    {
        try {
            $report = $this->generator->generate((new DateTimeImmutable('now', new DateTimeZone($this->timezone)))->modify('-1 day'));
            $path = $this->writer->write($report);
            $this->logger->info('Daily transaction report generated.', ['path' => $path, 'row_count' => count($report->rows)]);
        } catch (Throwable $exception) {
            $this->logger->error('Daily transaction report generation failed.', ['exception_class' => $exception::class]);
        }
    }
}

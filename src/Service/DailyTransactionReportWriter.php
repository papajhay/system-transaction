<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeZone;
use RuntimeException;

class DailyTransactionReportWriter
{
    public function __construct(
        private readonly string $projectDirectory,
        private readonly string $outputDirectory,
        private readonly string $timezone,
    ) {
        new DateTimeZone($timezone);
    }

    public function write(DailyTransactionReport $report): string
    {
        $directory = str_starts_with($this->outputDirectory, '/')
            ? $this->outputDirectory
            : $this->projectDirectory.'/'.trim($this->outputDirectory, '/');
        $publicDirectory = rtrim($this->projectDirectory, '/').'/public';
        if ($directory === $publicDirectory || str_starts_with($directory, $publicDirectory.'/')) {
            throw new RuntimeException('Daily reports must not be written inside the public directory.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the daily report directory.');
        }

        $filename = sprintf('transactions_%s.csv', $report->periodStart->format('Y-m-d'));
        $path = $directory.'/'.$filename;
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the daily report file.');
        }

        try {
            \fputcsv($handle, ['period_start', 'period_end', 'generated_at', 'type', 'currency', 'count', 'total_amount', 'total_fees']);
            foreach ($report->rows as $row) {
                \fputcsv($handle, [
                    $report->periodStart->format(DATE_ATOM),
                    $report->periodEnd->format(DATE_ATOM),
                    $report->generatedAt->format(DATE_ATOM),
                    $row['type'],
                    $row['currency'],
                    $row['count'],
                    $row['total_amount'],
                    $row['total_fees'],
                ]);
            }
        } finally {
            fclose($handle);
        }

        if (!chmod($path, 0600)) {
            throw new RuntimeException('Unable to secure the daily report file.');
        }

        return $path;
    }
}

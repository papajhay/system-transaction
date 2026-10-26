<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\MonitorPendingTransfersMessage;
use App\Message\GenerateDailyTransactionReportMessage;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('default')]
final class PendingTransferScheduleProvider implements ScheduleProviderInterface
{
    private readonly string $dailyReportExpression;
    private readonly DateTimeZone $timezone;

    public function __construct(string $dailyReportTime, string $timezone)
    {
        $this->timezone = new DateTimeZone($timezone);
        $parsed = DateTimeImmutable::createFromFormat('!H:i', $dailyReportTime, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('DAILY_REPORT_TIME must use the HH:MM format.');
        }

        $this->dailyReportExpression = sprintf('%d %d * * *', (int) $parsed->format('i'), (int) $parsed->format('H'));
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            // Surveillance et tentative d'exécution automatique des transferts pending toutes les 30 secondes.
            RecurringMessage::every(10, new MonitorPendingTransfersMessage()),
            // Rapport quotidien : conserve l'heure configurée.
            RecurringMessage::cron("$this->dailyReportExpression", new GenerateDailyTransactionReportMessage(), $this->timezone),
        );
    }
}
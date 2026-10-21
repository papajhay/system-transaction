<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PendingTransferInfo;
use DateTimeImmutable;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;
use Throwable;

final class PendingTransferAlertService
{
    /** @var list<string> */
    private array $recipients;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        string $recipients,
        private readonly string $fromAddress,
        private readonly int $cooldownSeconds,
    ) {
        $this->recipients = array_values(array_filter(array_map('trim', explode(',', $recipients))));

        if ($this->cooldownSeconds <= 0) {
            throw new \InvalidArgumentException('PENDING_TRANSFER_ALERT_COOLDOWN_SECONDS must be greater than zero.');
        }
        foreach ($this->recipients as $recipient) {
            if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('PENDING_TRANSFER_ALERT_RECIPIENTS must contain valid email addresses.');
            }
        }
        if (filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('REPORT_FROM_ADDRESS must be a valid email address.');
        }
    }

    /**
     * @param list<PendingTransferInfo> $transfers
     */
    public function notify(array $transfers): void
    {
        if ($transfers === [] || $this->recipients === []) {
            return;
        }

        $newTransfers = [];
        foreach ($transfers as $transfer) {
            $key = $this->cacheKey($transfer);
            if ($this->cache->get($key, static fn (ItemInterface $item): ?bool => null) === null) {
                $newTransfers[$key] = $transfer;
            }
        }

        if ($newTransfers === []) {
            return;
        }

        $body = "Pending transfers exceeded the configured threshold:\n\n";
        foreach ($newTransfers as $transfer) {
            $body .= sprintf(
                "%s | %s | %s | created %s | elapsed %ds | threshold %ds\n",
                $transfer->reference,
                $transfer->type->value,
                $transfer->status->value,
                $transfer->createdAt->format(DateTimeImmutable::ATOM),
                $transfer->elapsedSeconds,
                $transfer->thresholdSeconds,
            );
        }

        try {
            $this->mailer->send(
                (new Email())
                    ->from($this->fromAddress)
                    ->to(...$this->recipients)
                    ->subject('Pending transfer alert')
                    ->text($body),
            );

            foreach ($newTransfers as $key => $transfer) {
                $this->cache->delete($key);
                $this->cache->get($key, function (ItemInterface $item): bool {
                    $item->expiresAfter($this->cooldownSeconds);

                    return true;
                });
            }
        } catch (Throwable $exception) {
            $this->logger->error('Pending transfer alert delivery failed.', [
                'exception_class' => $exception::class,
                'transfer_count' => count($newTransfers),
            ]);
        }
    }

    private function cacheKey(PendingTransferInfo $transfer): string
    {
        return 'pending_transfer_alert_'.hash('sha256', $transfer->reference);
    }
}

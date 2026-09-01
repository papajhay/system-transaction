<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatusTransfer;
use App\Enum\TypeTransfer;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'transfer')]
class Transfer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $token;

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $reference;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'sender_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $senderAccount = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'receiver_account_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Account $receiverAccount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'currency_id', referencedColumnName: 'id', nullable: false)]
    private ?Currency $currency = null;

    #[ORM\Column(name: 'received_amount', type: Types::DECIMAL, precision: 20, scale: 2)]
    private string $receivedAmount;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'received_currency_id', referencedColumnName: 'id', nullable: false)]
    private ?Currency $receivedCurrency = null;

    #[ORM\Column(name: 'exchange_rate', type: Types::DECIMAL, precision: 20, scale: 10)]
    private string $exchangeRate;

    #[ORM\Column(type: Types::STRING, enumType: TypeTransfer::class)]
    private TypeTransfer $type = TypeTransfer::TRANSFER;

    #[ORM\Column(type: Types::STRING, enumType: StatusTransfer::class)]
    private StatusTransfer $status = StatusTransfer::PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'processed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getSenderAccount(): ?Account
    {
        return $this->senderAccount;
    }

    public function setSenderAccount(?Account $senderAccount): self
    {
        $this->senderAccount = $senderAccount;

        return $this;
    }

    public function getReceiverAccount(): ?Account
    {
        return $this->receiverAccount;
    }

    public function setReceiverAccount(?Account $receiverAccount): self
    {
        $this->receiverAccount = $receiverAccount;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): ?Currency
    {
        return $this->currency;
    }

    public function setCurrency(?Currency $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getReceivedAmount(): string
    {
        return $this->receivedAmount;
    }

    public function setReceivedAmount(string $receivedAmount): self
    {
        $this->receivedAmount = $receivedAmount;

        return $this;
    }

    public function getReceivedCurrency(): ?Currency
    {
        return $this->receivedCurrency;
    }

    public function setReceivedCurrency(?Currency $receivedCurrency): self
    {
        $this->receivedCurrency = $receivedCurrency;

        return $this;
    }

    public function getExchangeRate(): string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(string $exchangeRate): self
    {
        $this->exchangeRate = $exchangeRate;

        return $this;
    }

    public function getType(): TypeTransfer
    {
        return $this->type;
    }

    public function setType(TypeTransfer $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): StatusTransfer
    {
        return $this->status;
    }

    public function setStatus(StatusTransfer $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?DateTimeImmutable $processedAt): self
    {
        $this->processedAt = $processedAt;

        return $this;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function markCompleted(): self
    {
        $this->status = StatusTransfer::COMPLETED;
        $this->processedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function __toString(): string
    {
        return $this->getReference();
    }
}

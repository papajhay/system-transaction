<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExchangeRateRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ExchangeRateRepository::class)]
#[ORM\Table(name: 'exchange_rate')]
class ExchangeRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'base_currency_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Currency $baseCurrency = null;

    #[ORM\ManyToOne(targetEntity: Currency::class)]
    #[ORM\JoinColumn(name: 'target_currency_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Currency $targetCurrency = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 10)]
    #[Assert\Positive(message: 'The exchange rate must be greater than zero.')]
    private string $rate;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBaseCurrency(): ?Currency
    {
        return $this->baseCurrency;
    }

    public function setBaseCurrency(?Currency $baseCurrency): self
    {
        $this->baseCurrency = $baseCurrency;

        return $this;
    }

    public function getTargetCurrency(): ?Currency
    {
        return $this->targetCurrency;
    }

    public function setTargetCurrency(?Currency $targetCurrency): self
    {
        $this->targetCurrency = $targetCurrency;

        return $this;
    }

    #[Assert\Callback]
    public function validateCurrencies(ExecutionContextInterface $context): void
    {
        if (null !== $this->baseCurrency
            && null !== $this->targetCurrency
            && ($this->baseCurrency === $this->targetCurrency
                || (null !== $this->baseCurrency->getId()
                    && $this->baseCurrency->getId() === $this->targetCurrency->getId()))
        ) {
            $context
                ->buildViolation('The base and target currencies must be different.')
                ->atPath('targetCurrency')
                ->addViolation();
        }
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): self
    {
        $this->rate = $rate;

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
}
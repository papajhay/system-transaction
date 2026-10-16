<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fee;
use App\Enum\TypeFee;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class FeeCalculator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%transfer_default_fee_rate%')]
        private readonly float $legacyTransferDefaultRate,
    )
    {
    }

    /** @return array{amount: int, type: TypeFee, rate: ?float} */
    public function calculate(
        int $amountCents,
        ?Fee $associatedFee = null,
        bool $useLegacyTransferDefault = false,
        ?TypeFee $selectedType = null,
    ): array
    {
        if ($associatedFee instanceof Fee) {
            return $this->fromFee($associatedFee, $amountCents);
        }

        /** @var list<Fee> $configurations */
        $configurations = $this->entityManager
            ->getRepository(Fee::class)
            ->findBy(['transfer' => null], ['id' => 'ASC']);

        if ($selectedType !== null) {
            $matching = array_values(array_filter(
                $configurations,
                static fn (Fee $configuration): bool => $configuration->getType() === $selectedType,
            ));
            if (count($matching) === 1) {
                return $this->fromFee($matching[0], $amountCents);
            }
            if (count($matching) > 1) {
                throw new BadRequestHttpException(sprintf(
                    'Multiple global %s fee configurations are available.',
                    $selectedType->name,
                ));
            }
        }

        if (count($configurations) === 1) {
            return $this->fromFee($configurations[0], $amountCents);
        }
        if ($configurations !== []) {
            throw new BadRequestHttpException(
                'Multiple global fee configurations are available and no fee type was selected.',
            );
        }

        if ($useLegacyTransferDefault) {
            return $this->percentage($this->legacyTransferDefaultRate, $amountCents);
        }

        return ['amount' => 0, 'type' => TypeFee::FREE_CHARGED, 'rate' => null];
    }

    /** @return array{amount: int, type: TypeFee, rate: ?float} */
    private function fromFee(Fee $fee, int $amountCents): array
    {
        return match ($fee->getType()) {
            TypeFee::FEE_CHARGED_FIXED => [
                'amount' => $this->fixedAmount($fee->getAmount()),
                'type' => TypeFee::FEE_CHARGED_FIXED,
                'rate' => null,
            ],
            TypeFee::FEE_CHARGED_RATE => $this->percentage($fee->getRate(), $amountCents),
            TypeFee::FREE_CHARGED => [
                'amount' => 0,
                'type' => TypeFee::FREE_CHARGED,
                'rate' => null,
            ],
        };
    }

    /** @return array{amount: int, type: TypeFee, rate: ?float} */
    private function percentage(?float $rate, int $amountCents): array
    {
        if ($rate === null || !is_finite($rate) || $rate < 0 || $rate > 100) {
            throw new BadRequestHttpException('The percentage fee rate is invalid.');
        }

        return [
            'amount' => (int) round($amountCents * $rate / 100, 0, PHP_ROUND_HALF_UP),
            'type' => TypeFee::FEE_CHARGED_RATE,
            'rate' => $rate,
        ];
    }

    private function fixedAmount(float $amount): int
    {
        if (!is_finite($amount) || $amount <= 0) {
            throw new BadRequestHttpException('The fixed fee amount must be greater than zero.');
        }

        $cents = $this->cents((string) $amount);
        if ($cents <= 0) {
            throw new BadRequestHttpException('The fixed fee amount must be at least one cent.');
        }

        return $cents;
    }

    private function cents(string $amount): int
    {
        if (!preg_match('/^\s*(\d+)(?:\.(\d{1,6}))?\s*$/', $amount, $matches)) {
            return 0;
        }

        $fraction = str_pad($matches[2] ?? '', 3, '0');
        $cents = (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            ++$cents;
        }

        return ((int) $matches[1] * 100) + $cents;
    }
}

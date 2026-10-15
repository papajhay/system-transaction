<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Fee;
use App\Enum\TypeFee;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class FeeCalculator
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return array{amount: int, type: TypeFee, rate: ?float} */
    public function calculate(int $amountCents, ?Fee $associatedFee = null): array
    {
        if ($associatedFee instanceof Fee) {
            return $this->fromFee($associatedFee, $amountCents);
        }

        /** @var list<Fee> $configurations */
        $configurations = $this->entityManager
            ->getRepository(Fee::class)
            ->findBy(['transfer' => null], ['id' => 'ASC']);

        foreach ($configurations as $configuration) {
            if ($configuration->getType() === TypeFee::FEE_CHARGED_FIXED) {
                return $this->fromFee($configuration, $amountCents);
            }
        }
        foreach ($configurations as $configuration) {
            if ($configuration->getType() === TypeFee::FEE_CHARGED_RATE) {
                return $this->fromFee($configuration, $amountCents);
            }
        }

        return ['amount' => 0, 'type' => TypeFee::FREE_CHARGED, 'rate' => null];
    }

    /** @return array{amount: int, type: TypeFee, rate: ?float} */
    private function fromFee(Fee $fee, int $amountCents): array
    {
        return match ($fee->getType()) {
            TypeFee::FEE_CHARGED_FIXED => [
                'amount' => $this->cents((string) $fee->getAmount()),
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
        if ($rate === null || $rate < 0) {
            throw new BadRequestHttpException('The percentage fee rate is invalid.');
        }

        return [
            'amount' => (int) round($amountCents * $rate / 100, 0, PHP_ROUND_HALF_UP),
            'type' => TypeFee::FEE_CHARGED_RATE,
            'rate' => $rate,
        ];
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

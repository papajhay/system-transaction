<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\TypeFee;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TypeFeeType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => TypeFee::cases(),
            'choice_label' => static fn (TypeFee $feeType): string => match ($feeType) {
                TypeFee::FEE_CHARGED_FIXED => 'Fixed fee',
                TypeFee::FEE_CHARGED_RATE => 'Fee rate',
                TypeFee::FREE_CHARGED => 'Fee free',
            },
            'choice_value' => static fn (?TypeFee $feeType): ?string => $feeType?->value,
            'required' => true,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}

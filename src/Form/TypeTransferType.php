<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\TypeTransfer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TypeTransferType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choices' => TypeTransfer::cases(),
            'choice_label' => static fn (TypeTransfer $type): string => ucfirst($type->value),
            'choice_value' => static fn (?TypeTransfer $type): ?string => $type?->value,
            'required' => true,
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use Symfony\Component\Form\AbstractType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\NotNull;

final class TransferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('type', ChoiceType::class, [
            'choices' => TypeTransfer::cases(),
            'choice_label' => static fn (TypeTransfer $type): string => ucfirst($type->value),
            'choice_value' => static fn (?TypeTransfer $type): ?string => $type?->value,
            'required' => true,
        ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            $type = $data instanceof Transfer ? $data->getType() : null;

            $this->configureConditionalFields($event->getForm(), $type);

            if ($data instanceof Transfer && $data->getFeeType() !== TypeFee::FREE_CHARGED) {
                $this->configureFeeAmountField($event->getForm(), $data->getFeeType(), (float) $data->getAmount());
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $submittedData = $event->getData();
            $submittedType = is_array($submittedData) ? ($submittedData['type'] ?? null) : null;
            $type = is_string($submittedType) ? TypeTransfer::tryFrom($submittedType) : null;

            $this->configureConditionalFields($event->getForm(), $type);

            $transfer = $event->getForm()->getData();
            $submittedFeeType = is_array($submittedData) ? ($submittedData['feeType'] ?? null) : null;
            $feeType = is_string($submittedFeeType) ? TypeFee::tryFrom($submittedFeeType) : null;
            $amount = is_array($submittedData) ? ($submittedData['amount'] ?? null) : null;

            if ($feeType instanceof TypeFee && is_numeric($amount)) {
                $rate = $this->feeRate($feeType);
                $feeAmount = round((float) $amount * $rate / 100, 2, PHP_ROUND_HALF_UP);

                $this->configureFeeAmountField($event->getForm(), $feeType, (float) $amount);

                if (is_array($submittedData)) {
                    $submittedData['feeAmount'] = $feeAmount;
                    $event->setData($submittedData);
                }

                if ($transfer instanceof Transfer) {
                    $transfer->setFeeType($feeType)
                        ->setFeeRate($rate)
                        ->setFeeAmount($feeAmount);
                }
            } elseif ($transfer instanceof Transfer && $feeType === TypeFee::FREE_CHARGED) {
                $transfer->setFeeRate(0.0)->setFeeAmount(0.0);
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();

            if (!$form->has('from_account_number') || !$form->has('to_account_number')) {
                return;
            }

            $from = $form->get('from_account_number')->getData();
            $to = $form->get('to_account_number')->getData();

            if ($from instanceof Account && $to instanceof Account && $from->getId() === $to->getId()) {
                $form->get('to_account_number')->addError(
                    new FormError('The source and destination accounts must be different.')
                );
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transfer::class,
            'allow_extra_fields' => true,
        ]);

        $resolver->setDefined('entityDto');
    }

    private function configureConditionalFields(FormInterface $form, ?TypeTransfer $type): void
    {
        foreach (['account_number', 'from_account_number', 'to_account_number', 'amount', 'description', 'feeType', 'feeAmount'] as $field) {
            if ($form->has($field)) {
                $form->remove($field);
            }
        }

        if ($type === null) {
            return;
        }

        if ($type === TypeTransfer::TRANSFER) {
            $this->addAccountField($form, 'from_account_number', 'From account number');
            $this->addAccountField($form, 'to_account_number', 'To account number');
        } else {
            $this->addAccountField($form, 'account_number', 'Account number');
        }

        $form->add('amount', NumberType::class, [
            'mapped' => false,
            'required' => true,
            'scale' => 2,
            'constraints' => [new Positive()],
        ]);

        $form->add('description', TextareaType::class, [
            'mapped' => false,
            'required' => false,
            'empty_data' => null,
            'constraints' => [new Length(max: 255)],
        ]);

        $form->add('feeType', ChoiceType::class, [
            'label' => 'Fee type',
            'choices' => [
                'Fixed fee' => TypeFee::FEE_CHARGED_FIXED,
                'No fee' => TypeFee::FREE_CHARGED,
                'Fee rate' => TypeFee::FEE_CHARGED_RATE,
            ],
            'choice_value' => static fn (?TypeFee $feeType): ?string => $feeType?->value,
            'required' => true,
        ]);
    }

    private function addAccountField(FormInterface $form, string $name, string $label): void
    {
        if (in_array($name, ['from_account_number', 'to_account_number'], true)) {
            $form->add($name, EntityType::class, [
                'class' => Account::class,
                'choice_label' => 'accountNumber',
                'label' => $label,
                'mapped' => false,
                'required' => true,
                'constraints' => [new NotNull()],
            ]);

            return;
        }

        $form->add($name, TextType::class, [
            'label' => $label,
            'mapped' => false,
            'required' => true,
        ]);
    }

    private function configureFeeAmountField(FormInterface $form, TypeFee $feeType, float $amount): void
    {
        if ($form->has('feeAmount')) {
            $form->remove('feeAmount');
        }

        if ($feeType === TypeFee::FREE_CHARGED) {
            return;
        }

        $form->add('feeAmount', NumberType::class, [
            'label' => 'Fee amount',
            'required' => true,
            'scale' => 2,
            'attr' => ['readonly' => true],
            'constraints' => [new PositiveOrZero()],
            'data' => round($amount * $this->feeRate($feeType) / 100, 2, PHP_ROUND_HALF_UP),
        ]);
    }

    private function feeRate(TypeFee $feeType): float
    {
        return match ($feeType) {
            TypeFee::FEE_CHARGED_FIXED => 0.01,
            TypeFee::FREE_CHARGED => 0.0,
            TypeFee::FEE_CHARGED_RATE => 20.0,
        };
    }
}

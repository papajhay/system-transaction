<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Account;
use App\Entity\Transfer;
use App\Enum\TypeFee;
use App\Enum\TypeTransfer;
use App\Form\TypeTransferType;
use Symfony\Component\Form\AbstractType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class TransferType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $submittedData = null;
        $dynamicTransferForm = (bool) ($options['dynamic_transfer_form'] ?? false);

        $builder->add('type', TypeTransferType::class, [
            'attr' => $dynamicTransferForm ? [
                'data-controller' => 'transfer-type',
                'data-action' => 'change->transfer-type#change',
            ] : [],
        ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($dynamicTransferForm): void {
            $data = $event->getData();
            $type = $data instanceof Transfer ? $data->getType() : null;

            $this->configureConditionalFields(
                $event->getForm(),
                $type,
                $data instanceof Transfer ? $data : null,
                $dynamicTransferForm,
            );
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use (&$submittedData): void {
            $data = $event->getData();
            $submittedType = is_array($data) ? ($data['type'] ?? null) : null;
            $type = is_string($submittedType) ? TypeTransfer::tryFrom($submittedType) : null;

            if (is_array($data) && $type !== null && $type !== TypeTransfer::TRANSFER) {
                $accountNumber = $data['account_number'] ?? null;

                if ($accountNumber === null || $accountNumber === '') {
                    $data['account_number'] = $type === TypeTransfer::DEPOSIT
                        ? ($data['to_account_number'] ?? null)
                        : ($data['from_account_number'] ?? null);

                    $event->setData($data);
                }
            }

            $submittedData = is_array($data) ? $data : null;

            $this->configureConditionalFields($event->getForm(), $type);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use (&$submittedData): void {
            $form = $event->getForm();

            if ($form->has('from_account_number') && $form->has('to_account_number')) {
                $from = $form->get('from_account_number')->getData();
                $to = $form->get('to_account_number')->getData();

                if (
                    $from instanceof Account
                    && $to instanceof Account
                    && ($from === $to || ($from->getId() !== null && $from->getId() === $to->getId()))
                ) {
                    $form->get('to_account_number')->addError(
                        new FormError('The source and destination accounts must be different.')
                    );
                }
            }

            $feeType = $form->has('feeType') ? $form->get('feeType')->getData() : null;
            $submittedRate = is_array($submittedData) ? ($submittedData['feeRate'] ?? null) : null;

            if (
                $feeType === TypeFee::FEE_CHARGED_RATE
                && ($submittedRate === null || $submittedRate === '')
                && $form->has('feeRate')
            ) {
                $form->get('feeRate')->addError(
                    new FormError('This value is required for a fee rate.')
                );
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transfer::class,
            'allow_extra_fields' => true,
            'dynamic_transfer_form' => false,
        ]);

        $resolver->setDefined('entityDto');
    }

    private function configureConditionalFields(
        FormInterface $form,
        ?TypeTransfer $type,
        ?Transfer $transfer = null,
        bool $renderAllAccountFields = false,
    ): void
    {
        foreach ([
            'account_number',
            'from_account_number',
            'to_account_number',
            'amount',
            'description',
            'feeType',
            'feeAmount',
            'feeRate',
        ] as $field) {
            if ($form->has($field)) {
                $form->remove($field);
            }
        }

        if ($type === null) {
            return;
        }

        if ($renderAllAccountFields) {
            $this->addAccountField(
                $form,
                'account_number',
                'Account number',
                $type === TypeTransfer::DEPOSIT || $type === TypeTransfer::WITHDRAWAL,
            );
            $this->addAccountField(
                $form,
                'from_account_number',
                'From account number',
                $type === TypeTransfer::TRANSFER,
            );
            $this->addAccountField(
                $form,
                'to_account_number',
                'To account number',
                $type === TypeTransfer::TRANSFER,
            );
        } else {
            switch ($type) {
                case TypeTransfer::TRANSFER:
                    $this->addAccountField($form, 'from_account_number', 'From account number');
                    $this->addAccountField($form, 'to_account_number', 'To account number');
                    break;

                case TypeTransfer::DEPOSIT:
                case TypeTransfer::WITHDRAWAL:
                    $this->addAccountField($form, 'account_number', 'Account number');
                    break;

                default:
                    return;
            }
        }

        $form->add('amount', NumberType::class, [
            'mapped' => false,
            'required' => true,
            'scale' => 2,
            'constraints' => [new NotNull(), new Positive()],
        ]);

        $form->add('description', TextareaType::class, [
            'mapped' => false,
            'required' => false,
            'empty_data' => null,
            'constraints' => [new Length(max: 255)],
        ]);

        $form->add('feeType', TypeFeeType::class, [
            'label' => 'Fee type',
            'required' => true,
            'attr' => [
                'data-controller' => 'fee-type',
                'data-action' => 'change->fee-type#change',
            ],
        ]);

        $this->addFeeFields($form, $transfer);
    }

    private function addAccountField(
        FormInterface $form,
        string $name,
        string $label,
        bool $enabled = true,
    ): void
    {
        $form->add($name, EntityType::class, [
            'class' => Account::class,
            'choice_label' => 'accountNumber',
            'label' => $label,
            'mapped' => false,
            'required' => true,
            'disabled' => !$enabled,
            'constraints' => [new NotNull()],
            'row_attr' => [
                'data-transfer-account' => $name,
                'hidden' => !$enabled,
            ],
        ]);
    }

    private function addFeeFields(FormInterface $form, ?Transfer $transfer): void
    {
        $form->add('feeAmount', NumberType::class, [
            'label' => 'Fixed fee amount',
            'mapped' => true,
            'required' => false,
            'empty_data' => '0',
            'scale' => 2,
            'constraints' => [
                new Callback(function (mixed $value, ExecutionContextInterface $context) use ($form): void {
                    $transfer = $form->getData();
                    if (!$transfer instanceof Transfer || $transfer->getFeeType() !== TypeFee::FEE_CHARGED_FIXED) {
                        return;
                    }

                    if ($value === null || !is_numeric($value) || (float) $value <= 0) {
                        $context->buildViolation('This value should be positive.')->addViolation();
                    }
                }),
            ],
            'data' => $transfer?->getFeeAmount() ?? 0.0,
            'row_attr' => [
                'data-fee-field' => 'amount',
                'hidden' => true,
            ],
        ]);

        $form->add('feeRate', NumberType::class, [
            'label' => 'Fee rate (%)',
            'mapped' => true,
            'required' => false,
            'empty_data' => '0',
            'scale' => 2,
            'constraints' => [
                new Callback(function (mixed $value, ExecutionContextInterface $context) use ($form): void {
                    $transfer = $form->getData();
                    if (!$transfer instanceof Transfer || $transfer->getFeeType() !== TypeFee::FEE_CHARGED_RATE) {
                        return;
                    }

                    if ($value === null || $value === '') {
                        $context->buildViolation('This value is required for a fee rate.')->addViolation();
                        return;
                    }

                    if (!is_numeric($value) || (float) $value < 0 || (float) $value > 100) {
                        $context->buildViolation('This value should be between 0 and 100.')->addViolation();
                    }
                }),
            ],
            'data' => $transfer?->getFeeRate() ?? 0.0,
            'row_attr' => [
                'data-fee-field' => 'rate',
                'hidden' => true,
            ],
        ]);
    }

}

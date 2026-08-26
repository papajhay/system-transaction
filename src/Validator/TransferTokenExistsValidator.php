<?php
declare(strict_types=1);
namespace App\Validator;
use App\Entity\Transfer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
final class TransferTokenExistsValidator extends ConstraintValidator
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}
    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($value === null || $value === '') return;
        if ($this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $value]) === null) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}

<?php
declare(strict_types=1);
namespace App\Validator;
use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
final class AccountExistsValidator extends ConstraintValidator
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}
    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($value === null || $value === '') return;
        if ($this->entityManager->getRepository(Account::class)->findOneBy(['accountNumber' => $value]) === null) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}

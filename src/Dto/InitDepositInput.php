<?php
declare(strict_types=1);
namespace App\Dto;
use App\Validator\AccountExists;
use Symfony\Component\Validator\Constraints as Assert;
final class InitDepositInput
{
    #[Assert\NotBlank]
    #[Assert\Type(type: 'string')]
    #[AccountExists]
    public ?string $account_number = null;
    #[Assert\NotBlank]
    #[Assert\Type(type: 'numeric')]
    #[Assert\Positive]
    public float|string|null $amount = null;
    #[Assert\Length(max: 255)]
    public ?string $description = null;
}
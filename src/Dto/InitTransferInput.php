<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\AccountExists;
use Symfony\Component\Validator\Constraints as Assert;

final class InitTransferInput
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[AccountExists]
    public ?string $from_account_number = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[AccountExists]
    public ?string $to_account_number = null;

    #[Assert\NotBlank]
    #[Assert\Type('numeric')]
    #[Assert\Positive]
    public float|string|null $amount = null;

    #[Assert\Length(max: 255)]
    public ?string $description = null;
}

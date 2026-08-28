<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\AccountExists;
use Symfony\Component\Validator\Constraints as Assert;

final class InitMultiTransferInput
{
    /** @var list<array<string, mixed>> */
    #[Assert\NotBlank]
    #[Assert\Type('array')]
    #[Assert\Count(min: 2)]
    #[Assert\All([
        new Assert\Collection(fields: [
            'from_account_number' => [new Assert\NotBlank(), new Assert\Type('string'), new AccountExists()],
            'to_account_number' => [new Assert\NotBlank(), new Assert\Type('string'), new AccountExists()],
            'amount' => [new Assert\NotBlank(), new Assert\Type('numeric'), new Assert\Positive()],
            'description' => [new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)])],
        ], allowExtraFields: false),
    ])]
    public array $transfers = [];
}

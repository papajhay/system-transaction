<?php

declare(strict_types=1);

namespace App\Dto;

use App\Validator\TransferTokenExists;
use Symfony\Component\Validator\Constraints as Assert;

final class ExecuteMultiTransferInput
{
    /** @var list<string> */
    #[Assert\NotBlank]
    #[Assert\Type('array')]
    #[Assert\All([new Assert\NotBlank(), new Assert\Type('string'), new TransferTokenExists()])]
    public array $tokens = [];
}

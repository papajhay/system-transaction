<?php
declare(strict_types=1);
namespace App\Dto;
use App\Validator\TransferTokenExists;
use Symfony\Component\Validator\Constraints as Assert;
final class ExecuteDepositInput
{
    #[Assert\NotBlank]
    #[Assert\Type(type: 'string')]
    #[TransferTokenExists]
    public ?string $token = null;
}
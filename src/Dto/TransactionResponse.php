<?php
declare(strict_types=1);
namespace App\Dto;
use Symfony\Component\Serializer\Annotation\SerializedName;
final class TransactionResponse
{
    public function __construct(
        #[SerializedName('status_code')] public readonly int $statusCode,
        public readonly string $message,
        public readonly mixed $data,
    ) {}
}

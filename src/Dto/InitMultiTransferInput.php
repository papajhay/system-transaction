<?php

declare(strict_types=1);

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use App\Validator\AccountExists;
use Symfony\Component\Validator\Constraints as Assert;

final class InitMultiTransferInput
{
    #[ApiProperty(
        openapiContext: [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'from_account_number' => [
                        'type' => 'string',
                        'example' => 'd31767d8-544f-4e3f-bdee-fb12a718ac80',
                    ],
                    'to_account_number' => [
                        'type' => 'string',
                        'example' => 'd31767d8-544f-4e3f-bdee-fb12a718ac81',
                    ],
                    'amount' => [
                        'type' => 'number',
                        'format' => 'float',
                        'example' => 1000,
                    ],
                    'description' => [
                        'type' => 'string',
                        'example' => "Transfer d'argent",
                    ],
                ],
                'required' => [
                    'from_account_number',
                    'to_account_number',
                    'amount',
                ],
                'additionalProperties' => false,
            ],
        ],
    )]
    /** @var list<array<string, mixed>> */
    #[Assert\NotBlank]
    #[Assert\Type('array')]
    #[Assert\Count(min: 2)]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'from_account_number' => [
                    new Assert\NotBlank(),
                    new Assert\Type('string'),
                    new AccountExists(),
                ],
                'to_account_number' => [
                    new Assert\NotBlank(),
                    new Assert\Type('string'),
                    new AccountExists(),
                ],
                'amount' => [
                    new Assert\NotBlank(),
                    new Assert\Type('numeric'),
                    new Assert\Positive(),
                ],
                'description' => [
                    new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(max: 255),
                    ]),
                ],
            ],
            allowExtraFields: false,
        ),
    ])]
    public array $transfers = [];
} 
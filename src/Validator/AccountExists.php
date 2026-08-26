<?php
declare(strict_types=1);
namespace App\Validator;
use Symfony\Component\Validator\Constraint;
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AccountExists extends Constraint { public string $message = 'The account does not exist.'; }

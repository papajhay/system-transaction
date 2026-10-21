<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class TransferExecutionOptions
{
    public static function addOptions(object $command, string $type): void
    {
        $command
            ->addOption('reference', 'r', InputOption::VALUE_REQUIRED, sprintf('Identify the pending %s by reference.', $type))
            ->addOption('token', 'k', InputOption::VALUE_REQUIRED, sprintf('Identify the pending %s by token.', $type))
            ->addOption('time', 't', InputOption::VALUE_REQUIRED, 'Wait this many non-negative seconds before execution.');
    }

    /** @return array{identifier: string, delay: int} */
    public static function resolve(InputInterface $input, string $type): array
    {
        $candidates = [];
        $argument = trim((string) $input->getArgument('token-or-reference'));
        if ($argument !== '') {
            $candidates[] = $argument;
        }

        foreach (['reference', 'token'] as $option) {
            $value = $input->getOption($option);
            if ($value !== null) {
                $value = trim((string) $value);
                if ($value === '') {
                    throw new InvalidArgumentException(sprintf('The --%s option must not be empty.', $option));
                }
                $candidates[] = $value;
            }
        }

        if (count($candidates) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Provide exactly one %s identifier: the positional argument, --reference, or --token.',
                $type,
            ));
        }

        $rawDelay = $input->getOption('time');
        if ($rawDelay === null) {
            return ['identifier' => $candidates[0], 'delay' => 0];
        }

        $rawDelay = (string) $rawDelay;
        if (!preg_match('/^\d+$/', $rawDelay) || filter_var($rawDelay, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new InvalidArgumentException('The --time option must be a non-negative integer number of seconds.');
        }

        return ['identifier' => $candidates[0], 'delay' => (int) $rawDelay];
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DepositExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:deposit:execute', description: 'Execute a pending deposit by token or reference.')]
final class ExecuteDepositCommand extends Command
{
    public function __construct(private readonly DepositExecutor $depositExecutor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('token-or-reference', InputArgument::REQUIRED, 'The deposit token or reference.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = trim((string) $input->getArgument('token-or-reference'));
        if ($identifier === '') {
            $io->error('A deposit token or reference is required.');
            return Command::INVALID;
        }

        try {
            $result = $this->depositExecutor->execute($identifier);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Deposit %s executed: credited %s, fee %s.',
            $result['reference'],
            $result['credited_amount'],
            $result['fee'],
        ));
        return Command::SUCCESS;
    }
}

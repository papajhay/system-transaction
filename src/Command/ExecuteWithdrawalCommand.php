<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WithdrawalExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:withdrawal:execute', description: 'Execute a pending withdrawal by token or reference.')]
final class ExecuteWithdrawalCommand extends Command
{
    public function __construct(private readonly WithdrawalExecutor $withdrawalExecutor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('token-or-reference', InputArgument::REQUIRED, 'The withdrawal token or reference.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = trim((string) $input->getArgument('token-or-reference'));
        if ($identifier === '') {
            $io->error('A withdrawal token or reference is required.');
            return Command::INVALID;
        }

        try {
            $result = $this->withdrawalExecutor->execute($identifier);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Withdrawal %s executed: debited %s, fee %s.',
            $result['reference'],
            $result['debited_amount'],
            $result['fee'],
        ));
        return Command::SUCCESS;
    }
}

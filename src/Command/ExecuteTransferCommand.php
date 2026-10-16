<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TransferExecutor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:transfer:execute', description: 'Execute a pending account transfer by token or reference.')]
final class ExecuteTransferCommand extends Command
{
    public function __construct(private readonly TransferExecutor $transferExecutor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('token-or-reference', InputArgument::REQUIRED, 'The transfer token or reference.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = trim((string) $input->getArgument('token-or-reference'));
        if ($identifier === '') {
            $io->error('A transfer token or reference is required.');
            return Command::INVALID;
        }

        try {
            $result = $this->transferExecutor->execute($identifier);
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Transfer %s executed: amount %s, fee %s.',
            $result['reference'],
            $result['amount'],
            $result['fee'],
        ));
        return Command::SUCCESS;
    }
}

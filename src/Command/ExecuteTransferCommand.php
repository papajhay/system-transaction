<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\TransferExecutor;
use App\Service\NativeTransferExecutionDelay;
use App\Service\TransferExecutionDelay;
use App\Service\TransferExecutionOptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use InvalidArgumentException;

#[AsCommand(name: 'app:transfer:execute', description: 'Execute one pending account transfer by reference or token, optionally after a delay.')]
final class ExecuteTransferCommand extends Command
{
    public function __construct(
        private readonly TransferExecutor $transferExecutor,
        ?TransferExecutionDelay $delay = null,
    )
    {
        $this->delay = $delay ?? new NativeTransferExecutionDelay();
        parent::__construct();
    }

    private readonly TransferExecutionDelay $delay;

    protected function configure(): void
    {
        $this->addArgument('token-or-reference', InputArgument::OPTIONAL, 'Legacy positional transfer token or reference.');
        TransferExecutionOptions::addOptions($this, 'transfer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $options = TransferExecutionOptions::resolve($input, 'transfer');
            $this->delay->wait($options['delay']);
            $result = $this->transferExecutor->execute($options['identifier']);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
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

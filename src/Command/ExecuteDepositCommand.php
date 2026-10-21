<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\DepositExecutor;
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

#[AsCommand(name: 'app:deposit:execute', description: 'Execute one pending deposit by reference or token, optionally after a delay.')]
final class ExecuteDepositCommand extends Command
{
    public function __construct(
        private readonly DepositExecutor $depositExecutor,
        ?TransferExecutionDelay $delay = null,
    )
    {
        $this->delay = $delay ?? new NativeTransferExecutionDelay();
        parent::__construct();
    }

    private readonly TransferExecutionDelay $delay;

    protected function configure(): void
    {
        $this->addArgument('token-or-reference', InputArgument::OPTIONAL, 'Legacy positional deposit token or reference.');
        TransferExecutionOptions::addOptions($this, 'deposit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $options = TransferExecutionOptions::resolve($input, 'deposit');
            $this->delay->wait($options['delay']);
            $result = $this->depositExecutor->execute($options['identifier']);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
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

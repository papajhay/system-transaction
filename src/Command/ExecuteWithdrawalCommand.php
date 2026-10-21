<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WithdrawalExecutor;
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

#[AsCommand(name: 'app:withdrawal:execute', description: 'Execute one pending withdrawal by reference or token, optionally after a delay.')]
final class ExecuteWithdrawalCommand extends Command
{
    public function __construct(
        private readonly WithdrawalExecutor $withdrawalExecutor,
        ?TransferExecutionDelay $delay = null,
    )
    {
        $this->delay = $delay ?? new NativeTransferExecutionDelay();
        parent::__construct();
    }

    private readonly TransferExecutionDelay $delay;

    protected function configure(): void
    {
        $this->addArgument('token-or-reference', InputArgument::OPTIONAL, 'Legacy positional withdrawal token or reference.');
        TransferExecutionOptions::addOptions($this, 'withdrawal');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $options = TransferExecutionOptions::resolve($input, 'withdrawal');
            $this->delay->wait($options['delay']);
            $result = $this->withdrawalExecutor->execute($options['identifier']);
        } catch (InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            return Command::INVALID;
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

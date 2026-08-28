<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ExecuteMultiTransferInput;
use App\Dto\ExecuteTransferInput;
use App\Dto\InitMultiTransferInput;
use App\Dto\InitTransferInput;
use App\Dto\TransactionResponse;
use App\Entity\Account;
use App\Entity\Fee;
use App\Entity\Operation as LedgerOperation;
use App\Entity\Transfer;
use App\Enum\StatusTransfer;
use App\Enum\TypeFee;
use App\Enum\TypeOperation;
use App\Enum\TypeTransfer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

final class TransferProcessor implements ProcessorInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TransactionResponse
    {
        return match (true) {
            $data instanceof InitTransferInput => $this->initialize($data),
            $data instanceof InitMultiTransferInput => $this->initializeMany($data),
            $data instanceof ExecuteTransferInput => $this->execute($data->token),
            $data instanceof ExecuteMultiTransferInput => $this->executeMany($data->tokens),
            default => throw new \LogicException('Unsupported transfer input.'),
        };
    }

    private function initialize(InitTransferInput $input): TransactionResponse
    {
        $transfer = $this->create(
            $input->from_account_number,
            $input->to_account_number,
            $input->amount,
            $input->description
        );
        $this->entityManager->flush();
        return new TransactionResponse(
            201,
            'Transfer initialized successfully',
            ['token' => $transfer->getToken(), 'reference' => $transfer->getReference()]);
    }

    /** @var string */
    private string $lastReference;

    private function create(string $from, string $to, float|string|null $amount, ?string $description): Transfer
    {
        $sender = $this->account($from);
        $receiver = $this->account($to);
        $value = (float) $amount; $fee = $value * 0.1;
        if ((float) $sender->getBalance() < $value + $fee) throw new BadRequestHttpException('Insufficient balance');
        $now = new DateTimeImmutable();
        $transfer = (new Transfer())
            ->setToken(Uuid::v4()->toRfc4122())
            ->setReference('TRF-'.strtoupper(bin2hex(random_bytes(5))))
            ->setSenderAccount($sender)
            ->setReceiverAccount($receiver)
            ->setAmount(number_format($value, 6, '.', ''))
            ->setCurrency($sender->getCurrency())
            ->setType(TypeTransfer::TRANSFER)
            ->setStatus(StatusTransfer::PENDING)
            ->setDescription($description)
            ->setExpiresAt($now->modify('+10 minutes'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $this->entityManager->persist($transfer);
        $this->lastReference = $transfer->getReference();
        return $transfer;
    }

    private function initializeMany(InitMultiTransferInput $input): TransactionResponse
    {
        $created = [];
        foreach ($input->transfers as $item) { 
            $transfer = $this->create($item['from_account_number'], 
            $item['to_account_number'],
            $item['amount'], $item['description'] ?? null); 
            $created[] = ['token' => $transfer->getToken(), 'reference' => $transfer->getReference()]; }
            $this->entityManager->flush();
        return new TransactionResponse(201, 'Multi-transfer initialized successfully', $created);
    }

    private function execute(string $token): TransactionResponse
    {
        $transfer = 
                 $this->findPending($token);
                 $this->apply($transfer);
                 $this->entityManager->flush();
        return new TransactionResponse(
            201,
            'Transfer executed successfully',
            $this->output($transfer));
    }

    private function executeMany(array $tokens): TransactionResponse
    {
        $result = [];
        foreach ($tokens as $token) {
             $transfer =
                   $this->findPending($token);
                   $this->apply($transfer);
             $result[] = $this->output($transfer);
  
             }
        $this->entityManager->flush();
        return new TransactionResponse(
            201, 
            
            'Multi transfer executed successfully',
             $result
        );
    }

    private function findPending(string $token): Transfer
    {
        $transfer = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $token]);
        if (!$transfer || $transfer->getStatus() !== StatusTransfer::PENDING || ($transfer->getExpiresAt() && $transfer->getExpiresAt() < new DateTimeImmutable())) throw new BadRequestHttpException('Transfer has already been processed or expired.');
        return $transfer;
    }

    private function apply(Transfer $transfer): void
    {
        $sender = $transfer->getSenderAccount();
        $receiver = $transfer->getReceiverAccount();
        $amount = (float) $transfer->getAmount();
        $fee = $amount * 0.1;
        if (!$sender || !$receiver || (float) $sender->getBalance() < $amount + $fee) throw new BadRequestHttpException('Insufficient balance');
        $system = $this->entityManager
              ->getRepository(Account::class)
              ->findOneBy(['currency' => $transfer->getCurrency(), 'type' => \App\Enum\TypeAccount::SYSTEM]);
        $now = new DateTimeImmutable();
        
        $senderBefore = $sender->getBalance();
        $receiverBefore = $receiver->getBalance();
        if ($sender === $receiver) {
            $sender
                ->setBalance(number_format((float) $senderBefore - $fee, 2, '.', ''))
                ->setUpdatedAt($now);
        } else {
            $sender
               ->setBalance(number_format((float) $senderBefore - $amount - $fee, 2, '.', ''))
               ->setUpdatedAt($now);
            $receiver
               ->setBalance(number_format((float) $receiverBefore + $amount, 2, '.', ''))
               ->setUpdatedAt($now);
        }
        if ($system) $system->setBalance(number_format((float) $system->getBalance() + $fee, 2, '.', ''))
                            ->setUpdatedAt($now);
        foreach ([[$sender, TypeOperation::DEBIT, $amount + $fee, $senderBefore, $sender->getBalance()],
                [$receiver, TypeOperation::CREDIT, $amount, $receiverBefore, $receiver->getBalance()]] as [$account, $type, $operationAmount, $before, $after]) 
                $this->entityManager->persist((new LedgerOperation())
                    ->setAccount($account)
                    ->setTransfer($transfer)
                    ->setType($type)
                    ->setAmount(number_format($operationAmount, 6, '.', ''))
                    ->setBalanceBefore($before)
                    ->setBalanceAfter($after)
                    ->setCreatedAt($now)
                    ->setUpdatedAt($now));
        if ($system) $this->entityManager->persist((new LedgerOperation())
            ->setAccount($system)
            ->setTransfer($transfer)
            ->setType(TypeOperation::CREDIT)
            ->setAmount(number_format($fee, 6, '.', ''))
            ->setBalanceBefore(number_format((float) $system->getBalance() - $fee, 2, '.', ''))
            ->setBalanceAfter($system->getBalance())
            ->setCreatedAt($now)
            ->setUpdatedAt($now));
        $this->entityManager->persist((new Fee())
            ->setTransfer($transfer)
            ->setType(TypeFee::FEE_CHARGED)
            ->setAmount(number_format($fee, 2, '.', ''))
            ->setCreatedAt($now)
            ->setUpdatedAt($now));
        $transfer->markCompleted();
    }

    private function account(?string $number): Account { 
        $account =
          $this->entityManager
          ->getRepository(Account::class)
          ->findOneBy(['accountNumber' => $number]); 
          if (!$account) throw new BadRequestHttpException('The account does not exist.'); 
          return $account; 
    }
   
    private function output(Transfer $t): array { 
        return [
            'token' => $t->getToken(), 
            'reference' => $t->getReference(), 
            'status' => $t->getStatus()->value, 
            'amount' => $t->getAmount(), 
            'type' => $t->getType()->value, 
            'description' => $t->getDescription()];
    }
}
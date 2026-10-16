<?php
declare(strict_types=1);
namespace App\State;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\{ExecuteMultiTransferInput,ExecuteTransferInput,InitMultiTransferInput,InitTransferInput,TransactionResponse};
use App\Entity\{Account,Transfer};
use App\Enum\{StatusTransfer,TypeTransfer};
use App\Service\OnDemandExchangeRateService;
use App\Service\FeeCalculator;
use App\Service\TransferExecutor;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

final class TransferProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly OnDemandExchangeRateService $exchangeRates,
        private readonly FeeCalculator $feeCalculator,
        private readonly TransferExecutor $transferExecutor,
    ) {}
 
    public function process(mixed $data,Operation $operation,array $uriVariables=[],array $context=[]): TransactionResponse 
    { 
        return match(true) { 
            $data instanceof InitTransferInput=>$this->initialize($data),
            $data instanceof InitMultiTransferInput=>$this->initializeMany($data),
            $data instanceof ExecuteTransferInput=>$this->execute($data->token),
            $data instanceof ExecuteMultiTransferInput=>$this->executeMany($data->tokens),
            default=>throw new \LogicException('Unsupported transfer input.')
         };
   }
  
    private function initialize(InitTransferInput $input): TransactionResponse 
    { 
        $t=$this->entityManager->getConnection()->transactional(function()use($input):Transfer
        {
            $t=$this->create($input->from_account_number,$input->to_account_number,
            $input->amount,$input->description);
            $this->entityManager->flush();return $t;});
            return new TransactionResponse(
                201,'Transfer initialized successfully',
                [
                    'token'=>$t->getToken(),
                    'reference'=>$t->getReference()
                ]
            );
    }
         
    private function create(string $from,string $to,float|string|null $amount,?string $description): Transfer 
    { 
        $sender=$this->account($from);
        $receiver=$this->account($to);
        $value=round((float)$amount,2,PHP_ROUND_HALF_UP);
        $cross = $sender->getCurrency()?->getId() !== $receiver->getCurrency()?->getId();
        $fee = $cross
            ? 0.0
            : $this->feeCalculator->calculate((int) round($value * 100), null, true)['amount'] / 100;
        $debitAmount=$this->money($value+$fee);
        if(bccomp(bcsub($sender->getBalance(),$debitAmount,2),'0.00',2)<0)throw new BadRequestHttpException('Insufficient balance');
        $rate=$this->rate($sender,$receiver);
        $now=new DateTimeImmutable();
        $t=(
            new Transfer())
            ->setToken(Uuid::v4()->toRfc4122())
            ->setReference('TRF-'.strtoupper(bin2hex(random_bytes(5))))
            ->setSenderAccount($sender)->setReceiverAccount($receiver)
            ->setAmount(number_format($value,6,'.',''))
            ->setCurrency($sender->getCurrency())
            ->setReceivedAmount($this->money($value*$rate))
            ->setReceivedCurrency($receiver->getCurrency())
            ->setExchangeRate(number_format($rate,10,'.',''))
            ->setType(TypeTransfer::TRANSFER)
            ->setStatus(StatusTransfer::PENDING)
            ->setDescription($description)
            ->setExpiresAt($now->modify('+10 minutes'))
            ->setCreatedAt($now)
            ->setUpdatedAt($now);
        $this->entityManager->persist($t);
    
        return $t;
    }
    
    private function initializeMany(InitMultiTransferInput $input): TransactionResponse 
    { 
        $created=$this->entityManager
                      ->getConnection()
                      ->transactional(function()use($input):array{$created=[];
       foreach($input->transfers as $item){
        $t=$this->create($item['from_account_number'],
        $item['to_account_number'],
        $item['amount'],
        $item['description']??null);
        $created[]=[
            'token'=>$t->getToken(),
            'reference'=>$t->getReference()];
       }
       $this->entityManager->flush();
       
       return $created;
       });
       
       return new TransactionResponse(201,'Multi-transfer initialized successfully',$created); 
    }
    
    private function execute(string $token): TransactionResponse 
    { 
        // Preserve the established API behavior for same-account transfers;
        // the dedicated command applies stricter validation by default.
        $result = $this->transferExecutor->execute($token, true);
        $t = $this->entityManager->getRepository(Transfer::class)->findOneBy(['token' => $result['token']]);

        if (!$t instanceof Transfer) {
            throw new \LogicException('Executed transfer could not be reloaded.');
        }

        return new TransactionResponse(201,'Transfer executed successfully',$this->output($t)); 
    }
    
    private function executeMany(array $tokens): TransactionResponse 
    { 
        $ts=$this->entityManager->getConnection()->transactional(function()use($tokens):array{$out=[];
        foreach($tokens as $token){
            $t=$this->findPending($token,true);
            $this->apply($t);$out[]=$t;
        }
        $this->entityManager->flush();
        
        return $out;
        });
        
        return new TransactionResponse(
            201,
            'Multi transfer executed successfully',
            array_map(fn(Transfer $t):array=>$this->output($t),$ts)
        ); 
    }
    
    private function findPending(string $token,bool $lock=false):Transfer 
    { 
        $repo=$this->entityManager->getRepository(Transfer::class);
        $t=$lock?$repo
                   ->createQueryBuilder('t')
                   ->andWhere('t.token = :token')
                   ->setParameter('token',$token)->getQuery()
                   ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                   ->getOneOrNullResult():$repo->findOneBy(['token'=>$token]);
        if(!$t||$t->getStatus()!==StatusTransfer::PENDING||($t->getExpiresAt()&&$t->getExpiresAt()<new DateTimeImmutable()))throw new BadRequestHttpException('Transfer has already been processed or expired.');
        
        return $t;
    }
    
    private function apply(Transfer $t): void
    {
        // Multi-transfer execution uses the same fee, account, locking, and
        // ledger rules as the dedicated transfer command.
        $this->transferExecutor->apply($t, true);
    }
    
    private function account(?string $number):Account
    {
        $a=$this->entityManager->getRepository(Account::class)->findOneBy(['accountNumber'=>$number]);
        if(!$a)throw new BadRequestHttpException('The account does not exist.');
        
        return $a;
    }
    
    private function money(float $amount):string
    {
        return number_format($amount,2,'.','');
    }
    
    //private function rate(Account $sender,Account $receiver):float
    //{
    //    if($sender->getCurrency()?->getId()===$receiver->getCurrency()?->getId())return 1.;
    //    $r=$this->exchangeRates->getExchangeRateFromAndToCurrency($sender->getCurrency()->getId(),$receiver->getCurrency()->getId());
    //    if(!$r)throw new BadRequestHttpException('Exchange rate not found for the given currencies.');
        
    //    return (float)$r->getRate();
    //}

    private function rate(Account $sender, Account $receiver): float
    {
        $senderCurrency = $sender->getCurrency();
        $receiverCurrency = $receiver->getCurrency();

        if (!$senderCurrency || !$receiverCurrency) {
            throw new BadRequestHttpException('The transfer accounts must have a currency.');
        }

        if ($senderCurrency->getId() === $receiverCurrency->getId()) {
            return 1.0;
        }

        $exchangeRate = $this->exchangeRates->getRate($senderCurrency, $receiverCurrency);

        if ($exchangeRate) {
            $rate = (float) $exchangeRate->getRate();
            if ($rate > 0) {
                return $rate;
            }
        }

        // Use the inverse rate when only the opposite direction is configured.
        $reverseExchangeRate = $this->exchangeRates->getRate($receiverCurrency, $senderCurrency);

        if ($reverseExchangeRate && (float) $reverseExchangeRate->getRate() > 0) {
            return 1 / (float) $reverseExchangeRate->getRate();
        }

        if (!$exchangeRate && !$reverseExchangeRate) {
            throw new BadRequestHttpException('Exchange rate not found for the given currencies.');
        }

        throw new BadRequestHttpException('The configured exchange rate must be greater than zero.');
    }


    private function output(Transfer $t):array
    {
        return [
            'token' => $t->getToken(),
            'reference' => $t->getReference(),
            'status' => $t->getStatus()->value,
            'amount' => $t->getAmount(),
            'type' => $t->getType()->value,
            'description' => $t->getDescription()
        ];
    }
}

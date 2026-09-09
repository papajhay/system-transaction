<?php
declare(strict_types=1);
namespace App\State;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\{ExecuteMultiTransferInput,ExecuteTransferInput,InitMultiTransferInput,InitTransferInput,TransactionResponse};
use App\Entity\{Account,Conversion,Fee,Operation as LedgerOperation,Transfer};
use App\Enum\{StatusTransfer,TypeAccount,TypeFee,TypeOperation,TypeTransfer};
use App\Repository\ExchangeRateRepository;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Uid\Uuid;

final class TransferProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ExchangeRateRepository $exchangeRates
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
        $fee = $cross ? 0.0 : $this->feeDetails($value)['amount'];
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
        $t=$this->entityManager->getConnection()->transactional(function()use($token):Transfer
        {
            $t=$this->findPending($token,true);
            $this->apply($t);
            $this->entityManager->flush();
            
            return $t;
        });
    
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
    
    private function apply(Transfer $t):void 
    { 
        $sender=$t->getSenderAccount();
        $receiver=$t->getReceiverAccount();
        if(!$sender||!$receiver)throw new BadRequestHttpException('The transfer accounts do not exist.');
        $this->entityManager->lock($sender,LockMode::PESSIMISTIC_WRITE);$this->entityManager->lock($receiver,LockMode::PESSIMISTIC_WRITE);
        $amount=(float)$t->getAmount();
        $cross=$sender->getCurrency()?->getId()!==$receiver->getCurrency()?->getId();
        $feeDetails = $cross ? $this->freeFeeDetails() : $this->feeDetails($amount);
        $fee = $feeDetails['amount'];
        $debitAmount=$this->money($amount+$fee);
        $balanceAfterDebit=bcsub($sender->getBalance(),$debitAmount,2);
        if(bccomp($balanceAfterDebit,'0.00',2)<0)throw new BadRequestHttpException('Insufficient balance');
            $now=new DateTimeImmutable();
            if(!$cross){$system=$this->system($sender);
            $this->entityManager->lock($system,LockMode::PESSIMISTIC_WRITE);
            $this->move($sender,$receiver,$amount,$fee,$t,$now);
            $this->credit($system,$fee,$t,$now);
            $this->entityManager->persist(
                (new Fee())->setTransfer($t)
                           ->setType($feeDetails['type'])
                           ->setName($feeDetails['name'])
                           ->setRate($feeDetails['rate'])
                           ->setAmount($fee)
                           ->setCreatedAt($now)
                           ->setUpdatedAt($now));
        }else{
            $source=$this->system($sender);
            $target=$this->system($receiver);
            $this->entityManager->lock($source,LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->lock($target,LockMode::PESSIMISTIC_WRITE);
            $converted=(float)$t->getReceivedAmount();
            $this->move($sender,$source,$amount,0,$t,$now);
            $this->move($source,$target,$amount,0,$t,$now);
            $this->move($target,$receiver,$converted,0,$t,$now);
            $this->entityManager->persist(
                (new Conversion())
                      ->setTransfer($t)
                      ->setFromCurrency($sender->getCurrency())
                      ->setToCurrency($receiver->getCurrency())
                      ->setExchangeRate(number_format((float)$t->getExchangeRate(),6,'.',''))
                      ->setSourceAmount($this->money($amount))
                      ->setTargetAmount($this->money($converted))
                      ->setCreatedAt($now)->setUpdatedAt($now));
            }
        $t->markCompleted();
    }
    
    private function move(Account $from,Account $to,float $amount,float $fee,Transfer $t,DateTimeImmutable $now):void
    {
        $this->debit($from,$amount+$fee,$t,$now);
        $this->credit($to,$amount,$t,$now);
    }
    
    private function debit(Account $a,float $amount,Transfer $t,DateTimeImmutable $now):void
    {
        $before=$a->getBalance();
        $a->setBalance(bcsub($before,$this->money($amount),2))
          ->setUpdatedAt($now);
        $this->operation($a,TypeOperation::DEBIT,$amount,$before,$a->getBalance(),$t,$now);
    }
    
    private function credit(Account $a,float $amount,Transfer $t,DateTimeImmutable $now):void
    {
        $before=$a->getBalance();
        $a->setBalance($this->money((float)$before+$amount))
          ->setUpdatedAt($now);
        $this->operation($a,TypeOperation::CREDIT,$amount,$before,$a->getBalance(),$t,$now);
    }
    
    private function operation(Account $a,TypeOperation $type,float $amount,string $before,string $after,Transfer $t,DateTimeImmutable $now):void
    {
        $this->entityManager->persist(
             (new LedgerOperation())
                  ->setAccount($a)
                  ->setTransfer($t)
                  ->setType($type)
                  ->setAmount(number_format($amount,6,'.',''))
                  ->setBalanceBefore($before)
                  ->setBalanceAfter($after)
                  ->setCreatedAt($now)
                  ->setUpdatedAt($now));
   }
  
    private function system(Account $a):Account
    {
        $s=$this->entityManager->getRepository(Account::class)->findOneBy(
            [
              'currency'=>$a->getCurrency(),
              'type'=>TypeAccount::SYSTEM
            ]
        );
        if(!$s)throw new BadRequestHttpException('System account does not exist.');
        
        return $s;
    }
    
    
    private function account(?string $number):Account
    {
        $a=$this->entityManager->getRepository(Account::class)->findOneBy(['accountNumber'=>$number]);
        if(!$a)throw new BadRequestHttpException('The account does not exist.');
        
        return $a;
    }
    
    /** @return array{amount: float, type: TypeFee, name: ?string, rate: ?float} */
    private function feeDetails(float $amount): array
    {
        /** @var list<Fee> $configurations */
        $configurations = $this->entityManager
            ->getRepository(Fee::class)
            ->findBy(['transfer' => null], ['id' => 'ASC']);

        // Keep the fee that was already in effect when no fee configuration
        // exists. This preserves existing installations while allowing the
        // new fee configuration records to take precedence.
        if ($configurations === []) {
            return [
                'amount' => round($amount * 0.1, 2, PHP_ROUND_HALF_UP),
                'type' => TypeFee::FEE_CHARGED_FIXED,
                'name' => 'legacy',
                'rate' => null,
            ];
        }

        foreach ($configurations as $configuration) {
            if (
                $configuration->getType() === TypeFee::FEE_CHARGED_FIXED
                && $this->matchesTier($configuration->getName(), $amount)
            ) {
                return $this->withVat(
                    $configuration->getAmount(),
                    TypeFee::FEE_CHARGED_FIXED,
                    $configuration,
                );
            }
        }

        foreach ($configurations as $configuration) {
            if ($configuration->getType() === TypeFee::FEE_CHARGED_RATE) {
                $rate = $configuration->getRate() ?? 0.01;

                return $this->withVat(
                    $amount * $rate / 100,
                    TypeFee::FEE_CHARGED_RATE,
                    $configuration,
                    $rate,
                );
            }
        }

        foreach ($configurations as $configuration) {
            if ($configuration->getType() === TypeFee::FREE_CHARGED) {
                return $this->freeFeeDetails($configuration);
            }
        }

        return $this->freeFeeDetails();
    }

    /** @return array{amount: float, type: TypeFee, name: ?string, rate: ?float} */
    private function withVat(
        float $amount,
        TypeFee $type,
        Fee $configuration,
        ?float $rate = null,
    ): array {
        return [
            'amount' => round($amount * 1.2, 2, PHP_ROUND_HALF_UP),
            'type' => $type,
            'name' => $configuration->getName(),
            'rate' => $rate ?? $configuration->getRate(),
        ];
    }

    /** @return array{amount: float, type: TypeFee, name: ?string, rate: ?float} */
    private function freeFeeDetails(?Fee $configuration = null): array
    {
        return [
            'amount' => 0.0,
            'type' => TypeFee::FREE_CHARGED,
            'name' => $configuration?->getName(),
            'rate' => 0.0,
        ];
    }

    private function matchesTier(?string $name, float $amount): bool
    {
        if ($name === null || trim($name) === '') {
            return true;
        }

        preg_match_all('/\d[\d\s,.]*/', $name, $matches);
        $bounds = array_values(array_filter(
            array_map(
                fn (string $value): ?float => $this->parseTierNumber($value),
                $matches[0],
            ),
            static fn (?float $value): bool => $value !== null,
        ));

        return match (count($bounds)) {
            0 => false,
            1 => $amount >= $bounds[0],
            default => $amount >= $bounds[0] && $amount <= $bounds[1],
        };
    }

    private function parseTierNumber(string $value): ?float
    {
        $value = str_replace([' ', "\u{00A0}"], '', trim($value, " \t\n\r\0\x0B"));
        if ($value === '') {
            return null;
        }

        $commaPosition = strrpos($value, ',');
        $dotPosition = strrpos($value, '.');

        if ($commaPosition !== false && $dotPosition !== false) {
            // The last separator is the decimal separator when both are present.
            $decimalSeparator = $commaPosition > $dotPosition ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousandsSeparator, '', $value);
            $value = str_replace($decimalSeparator, '.', $value);
        } elseif ($commaPosition !== false) {
            $value = preg_match('/,\d{3}$/', $value) === 1
                ? str_replace(',', '', $value)
                : str_replace(',', '.', $value);
        } elseif ($dotPosition !== false && preg_match('/\.\d{3}$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
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

        $exchangeRate = $this->exchangeRates->getExchangeRateFromAndToCurrency(
            $senderCurrency->getId(),
            $receiverCurrency->getId()
        );

        if ($exchangeRate) {
            $rate = (float) $exchangeRate->getRate();
            if ($rate > 0) {
                return $rate;
            }
        }

        // Use the inverse rate when only the opposite direction is configured.
        $reverseExchangeRate = $this->exchangeRates->getExchangeRateFromAndToCurrency(
            $receiverCurrency->getId(),
            $senderCurrency->getId()
        );

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

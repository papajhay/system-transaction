<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\AccountValidationService;
use App\Service\TransferBalanceValidationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AccountValidationController extends AbstractController
{
    #[Route('/admin/transfer/validate-account', name: 'admin_transfer_validate_account', methods: ['POST'])]
    public function __invoke(Request $request, AccountValidationService $accounts): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $accountNumber = is_array($payload) ? ($payload['account_number'] ?? null) : null;
        $result = $accounts->validateAccountNumber($accountNumber);

        return $this->json(['valid' => $result['valid'], 'code' => $result['code']]);
    }

    #[Route('/admin/transfer/validate-balance', name: 'admin_transfer_validate_balance', methods: ['POST'])]
    public function balance(Request $request, TransferBalanceValidationService $balance): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        return $this->json($balance->validate(is_array($payload) ? $payload : []));
    }
}

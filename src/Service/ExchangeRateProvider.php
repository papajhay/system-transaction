<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Currency;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExchangeRateProvider
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiUrl,
        private readonly string $apiKey,
    ) {
    }

    /** @return array<string, string> */
    public function getRatesForBaseCurrency(Currency $baseCurrency): array
    {
        if ($this->apiKey === '' || $this->apiKey === 'change-me') {
            throw new \RuntimeException('EXCHANGE_RATES_API_KEY is not configured.');
        }

        $url = strtr($this->apiUrl, [
            '{api_key}' => rawurlencode($this->apiKey),
            '{base}' => rawurlencode(strtoupper($baseCurrency->getCode())),
        ]);

        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 30,
        ]);

        $payload = $response->toArray(false);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf(
                'Exchange-rate API returned HTTP %d for base currency %s.',
                $response->getStatusCode(),
                $baseCurrency->getCode(),
            ));
        }

        if (($payload['result'] ?? null) !== 'success' || !isset($payload['conversion_rates']) || !is_array($payload['conversion_rates'])) {
            throw new \RuntimeException(sprintf(
                'Exchange-rate API returned an invalid response for base currency %s.',
                $baseCurrency->getCode(),
            ));
        }

        $rates = [];
        foreach ($payload['conversion_rates'] as $currencyCode => $rate) {
            if ((is_string($rate) || is_int($rate) || is_float($rate)) && is_numeric((string) $rate)) {
                $rates[strtoupper((string) $currencyCode)] = (string) $rate;
            }
        }

        return $rates;
    }
}

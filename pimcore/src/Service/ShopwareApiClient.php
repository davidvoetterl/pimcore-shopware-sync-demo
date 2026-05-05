<?php

declare(strict_types=1);

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class ShopwareApiClient
{
    private readonly ClientInterface $http;
    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'http_errors' => false,
            'timeout' => 30,
        ]);
    }

    public function request(string $method, string $endpoint, array $payload = []): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ];

        if ($payload !== []) {
            $options['json'] = $payload;
        }

        $response = $this->http->request($method, ltrim($endpoint, '/'), $options);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new \RuntimeException(sprintf(
                'Shopware API error (%d) on %s %s: %s',
                $status,
                $method,
                $endpoint,
                $body,
            ));
        }

        if ($body === '' || $status === 204) {
            return [];
        }

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && $this->tokenExpiresAt !== null && $this->tokenExpiresAt > time() + 30) {
            return $this->accessToken;
        }

        $response = $this->http->request('POST', 'api/oauth/token', [
            'json' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 400) {
            throw new \RuntimeException(sprintf(
                'Shopware OAuth token request failed (%d): %s',
                $status,
                $body,
            ));
        }

        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + (int) $data['expires_in'];

        return $this->accessToken;
    }
}

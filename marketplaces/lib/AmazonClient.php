<?php

declare(strict_types=1);

use Saloon\Exceptions\Request\ClientException;
use SellingPartnerApi\Enums\Endpoint;
use SellingPartnerApi\Seller\SellerConnector;
use SellingPartnerApi\SellingPartnerApi;

class AmazonClient
{
    public readonly SellerConnector $connector;
    public readonly string $marketplaceId;
    public readonly string $sellerId;
    public readonly bool $sandbox;
    public readonly string $account;

    /**
     * @param string $account  Account key matching the .env suffix convention.
     *                         'IGE' reads AMAZON_SPAPI_SELLER_ID (no suffix).
     *                         Any other value reads AMAZON_SPAPI_SELLER_ID_{ACCOUNT}
     *                         and AMAZON_SPAPI_REFRESH_TOKEN_{ACCOUNT}, falling back
     *                         to the base refresh token when no account-specific one
     *                         is set — IGE's developer app authenticates on behalf of
     *                         other sellers via their refresh token.
     */
    public function __construct(string $account = 'IGE')
    {
        $this->account = strtoupper($account);

        $suffix = $this->account === 'IGE' ? '' : '_' . $this->account;

        $this->sellerId      = $_ENV['AMAZON_SPAPI_SELLER_ID' . $suffix]     ?? '';
        $this->marketplaceId = $_ENV['AMAZON_SPAPI_MARKETPLACE_ID']           ?? 'ATVPDKIKX0DER';
        $this->sandbox       = filter_var(
            $_ENV['AMAZON_SPAPI_SANDBOX'] ?? 'true',
            FILTER_VALIDATE_BOOLEAN,
        );

        $refreshToken = $_ENV['AMAZON_SPAPI_REFRESH_TOKEN' . $suffix]
                     ?? $_ENV['AMAZON_SPAPI_REFRESH_TOKEN']
                     ?? '';

        $this->connector = SellingPartnerApi::seller(
            clientId:     $_ENV['AMAZON_SPAPI_CLIENT_ID']     ?? '',
            clientSecret: $_ENV['AMAZON_SPAPI_CLIENT_SECRET'] ?? '',
            refreshToken: $refreshToken,
            endpoint:     Endpoint::byRegion($_ENV['AMAZON_SPAPI_REGION'] ?? 'NA', $this->sandbox),
        );
    }

    /**
     * Lightweight probe — mirrors Usurper's BaseApi::testConnection().
     * Returns true on success or 429 (rate-limited = credentials valid).
     *
     * @return true|string[]
     */
    public function testConnection(): true|array
    {
        try {
            $this->connector
                ->catalogItemsV20220401()
                ->searchCatalogItems(
                    marketplaceIds: [$this->marketplaceId],
                    keywords:       ['test'],
                    pageSize:       1,
                );

            return true;
        } catch (ClientException $e) {
            if ($e->getStatus() === 429) {
                return true;
            }
            return [$e->getStatus() . ': ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
    }
}

<?php

declare(strict_types=1);

/**
 * Shared Listings Items PATCH helpers, used by both patch_listings.php (Phase 8
 * write-back) and restore_listings.php (Phase 10 restore). Extracted so the
 * patch envelope / submit / result-row logic lives in one place.
 *
 * Callers are responsible for require-ing the SDK bootstrap plus
 * lib/AmazonRateLimits.php and lib/AmazonOperationIds.php before use (this
 * class references AmazonRateLimits / AmazonOperationIds from the global
 * namespace, matching the project's require-based, no-autoloader convention).
 */

use SellingPartnerApi\Seller\ListingsItemsV20210801\Dto\ListingsItemPatchRequest;
use SellingPartnerApi\Seller\ListingsItemsV20210801\Dto\PatchOperation;

final class AmazonPatch
{
    /**
     * CSV column order shared by patch/restore result files.
     */
    public const RESULT_COLUMNS = [
        'sku', 'asin', 'product_type', 'attribute_count', 'status',
        'submission_id', 'issues_count', 'issues_summary',
    ];

    /**
     * Format a draft attribute value into SP-API patch value slots.
     *
     * SP-API expects each attribute as an array of slot objects:
     *   [{"value": "Disney", "marketplace_id": "ATVPDKIKX0DER"}]
     * Array-valued attributes (e.g. bullet_point) produce one slot per item.
     *
     * A value that is ALREADY a list of slot objects (associative arrays) —
     * e.g. a structured compliance value like california_proposition_65 —
     * passes through unchanged rather than being re-wrapped.
     */
    public static function formatPatchValue(string|array $value, string $marketplaceId): array
    {
        if (is_string($value)) {
            return [['value' => $value, 'marketplace_id' => $marketplaceId]];
        }
        // Already SP-API-shaped (list of objects)? Pass through.
        if (is_array($value[0] ?? null)) {
            return array_values($value);
        }
        // List of scalars → one slot per item.
        return array_values(array_map(
            fn($v) => ['value' => $v, 'marketplace_id' => $marketplaceId],
            $value,
        ));
    }

    /**
     * Build a single replace PatchOperation for one attribute.
     */
    public static function replaceOp(string $attr, string|array $value, string $marketplaceId): PatchOperation
    {
        return new PatchOperation(
            op:    'replace',
            path:  '/attributes/' . $attr,
            value: self::formatPatchValue($value, $marketplaceId),
        );
    }

    /**
     * The attribute name carried by a replace/... PatchOperation path.
     */
    public static function opAttr(PatchOperation $op): string
    {
        return substr($op->path, strlen('/attributes/'));
    }

    /**
     * Assemble the patch request envelope for a product type.
     */
    public static function buildPatchRequest(string $productType, array $patchOps): ListingsItemPatchRequest
    {
        return new ListingsItemPatchRequest(
            productType: $productType,
            patches:     $patchOps,
        );
    }

    /**
     * Submit a patch (with retry/backoff + throttle) and normalize the result.
     * Returns ['status','submission_id','issues','issues_count','issues_summary'].
     */
    public static function submitPatch(
        $listingsApi,
        string $sellerId,
        string $sku,
        ListingsItemPatchRequest $req,
        string $marketplaceId,
    ): array {
        $result = AmazonRateLimits::retryWithBackoff(
            fn() => $listingsApi->patchListingsItem(
                sellerId:                 $sellerId,
                sku:                      $sku,
                listingsItemPatchRequest: $req,
                marketplaceIds:           [$marketplaceId],
                includedData:             ['issues'],
            )->json(),
            AmazonOperationIds::PATCH_LISTINGS_ITEM,
        );

        AmazonRateLimits::throttle(AmazonOperationIds::PATCH_LISTINGS_ITEM);

        $issues = $result['issues'] ?? [];

        return [
            'status'         => $result['status']       ?? 'UNKNOWN',
            'submission_id'  => $result['submissionId']  ?? '',
            'issues'         => $issues,
            'issues_count'   => count($issues),
            'issues_summary' => self::summarizeIssues($issues),
        ];
    }

    /**
     * Condense an issues[] array into a short one-line summary (first 3).
     */
    public static function summarizeIssues(array $issues): string
    {
        $messages = array_map(
            fn($issue) => ($issue['code'] ?? '') . ': ' . ($issue['message'] ?? ''),
            $issues,
        );
        $summary = implode(' | ', array_slice($messages, 0, 3));
        if (count($messages) > 3) {
            $summary .= ' (+' . (count($messages) - 3) . ' more)';
        }
        return $summary;
    }

    /**
     * Build a normalized result row for the results CSV.
     */
    public static function resultRow(
        string $sku,
        string $asin,
        string $productType,
        int $attributeCount,
        string $status,
        string $submissionId = '',
        int $issuesCount = 0,
        string $issuesSummary = '',
    ): array {
        return [
            'sku'             => $sku,
            'asin'            => $asin,
            'product_type'    => $productType,
            'attribute_count' => $attributeCount,
            'status'          => $status,
            'submission_id'   => $submissionId,
            'issues_count'    => $issuesCount,
            'issues_summary'  => $issuesSummary,
        ];
    }
}

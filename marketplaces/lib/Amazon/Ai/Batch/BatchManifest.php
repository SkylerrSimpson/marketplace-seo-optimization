<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Batch;

/**
 * The on-disk record of one submitted batch run: which provider batch ids are in
 * flight, the model behind each, and the SKU -> positional custom_id map needed
 * to reassemble results. Written at submit time (BatchRunner's onSubmit hook) and
 * deleted once results are assembled, so its mere presence means "batches were
 * submitted but not yet collected" — the signal --resume and --cancel act on.
 *
 * One manifest per account, overwritten each submit: a new run supersedes any
 * stale one. Batch ids are ephemeral server-side handles, so this file is the
 * only thing standing between an interrupted run and an un-cancelable, un-
 * collectable batch that still bills.
 */
final class BatchManifest
{
    private const FILENAME = 'batch-manifest.json';

    /**
     * @param array<string,array{batch_id:string,model:string}> $providers     pid => {batch_id, model}
     * @param array<string,string>                              $skuToCustomId sku => custom_id
     *        (real SKU -> the positional, batch-safe id it was submitted under)
     */
    public function __construct(
        public readonly string $account,
        public readonly string $createdAt,
        public readonly int $pollInterval,
        public readonly array $providers,
        public readonly array $skuToCustomId,
    ) {
    }

    public static function path(string $dataDir): string
    {
        return $dataDir . '/' . self::FILENAME;
    }

    public function save(string $dataDir): void
    {
        file_put_contents(self::path($dataDir), json_encode([
            'account'          => $this->account,
            'created_at'       => $this->createdAt,
            'poll_interval'    => $this->pollInterval,
            'providers'        => $this->providers,
            'sku_to_custom_id' => $this->skuToCustomId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function load(string $dataDir): ?self
    {
        $path = self::path($dataDir);
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !is_array($data['providers'] ?? null)) {
            return null;
        }

        // Both the current key and the legacy 'custom_id_to_sku' (which was
        // misnamed — it always stored sku -> custom_id) carry the same mapping.
        $map = $data['sku_to_custom_id'] ?? $data['custom_id_to_sku'] ?? null;

        return new self(
            (string) ($data['account'] ?? ''),
            (string) ($data['created_at'] ?? ''),
            (int) ($data['poll_interval'] ?? 30),
            $data['providers'],
            is_array($map) ? $map : [],
        );
    }

    public static function delete(string $dataDir): void
    {
        @unlink(self::path($dataDir));
    }

    /** @return array<string,string> pid => batchId */
    public function batchIds(): array
    {
        return array_map(static fn (array $p): string => (string) $p['batch_id'], $this->providers);
    }

    /** @return array<string,string> pid => model */
    public function models(): array
    {
        return array_map(static fn (array $p): string => (string) $p['model'], $this->providers);
    }
}

<?php

declare(strict_types=1);

namespace App\Message;

final class SyncAssetMessage
{
    public function __construct(
        public readonly int $assetId,
        public readonly Operation $operation = Operation::Upsert,
        /**
         * Captured at dispatch time — the handler needs it for deletes
         * because the Pimcore asset is gone by the time the worker runs.
         */
        public readonly ?string $fileName = null,
    ) {
    }
}

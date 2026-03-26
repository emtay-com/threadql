<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\Tenant;
use JsonSerializable;

class TenantCollectionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param array<int, Tenant> $tenants
     */
    public function __construct(
        private readonly array $tenants
    ) {
    }

    /**
     * Serialize the payload to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => array_map(fn (Tenant $tenant) => (new TenantPayload($tenant))->toArray(), $this->tenants),
            'meta' => [
                'total' => count($this->tenants),
            ],
        ];
    }
}

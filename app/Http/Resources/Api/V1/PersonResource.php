<?php

namespace App\Http\Resources\Api\V1;

use App\Core\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A public account for /api/v1/users (#640): only what a linked APES app needs
 * to match a person. Never a password, two-factor detail, address, phone
 * number or support needs.
 *
 * @mixin User
 */
class PersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'services' => $this->serviceSelections->pluck('sub_core_key')->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

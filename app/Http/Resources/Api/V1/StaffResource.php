<?php

namespace App\Http\Resources\Api\V1;

use App\Core\Accounts\User;
use App\Support\DirectoryGroupPrefix;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member for /api/v1/staff (#640): name, work email, job title,
 * team (department) and their myapesaccount.* groups (divisions). Never a
 * password, two-factor detail, phone number or home address.
 *
 * @mixin User
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $prefix = DirectoryGroupPrefix::prefix();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'job_title' => $this->staffProfile?->job_title,
            'team' => $this->staffProfile?->team,
            'groups' => array_values(array_filter(
                array_map('strval', (array) $this->ldap_groups),
                static fn (string $group): bool => str_starts_with($group, $prefix),
            )),
        ];
    }
}

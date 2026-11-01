<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserMiniResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (is_null($this->resource)) {
            return ['deleted' => true, 'isUser' => true];
        }

        return [
            'id' => $this->id,
            'fullName' => $this->name,
            'username' => $this->username,
            'gender' => $this->gender,
            'country' => $this->country,
            // SCRUM-357: a date of birth is for the user themselves and admins -- this resource is
            // returned by the user search to anyone signed in.
            'dob' => $this->when(
                (bool) $request->user()?->is($this->resource) || self::viewerIsAdmin($request),
                fn () => $this->dob
            ),
            'isUser' => true,
        ];
    }

    // Once per request, not per row -- isAdmin() is a query, and this resource renders lists.
    private static function viewerIsAdmin(Request $request): bool
    {
        if (! $request->attributes->has('viewerIsAdmin')) {
            $request->attributes->set('viewerIsAdmin', (bool) $request->user()?->isAdmin());
        }

        return $request->attributes->get('viewerIsAdmin');
    }
}

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

    // Once per request and viewer, not per row -- isAdmin() is a query, and this resource renders
    // lists. Keyed by the viewer (SCRUM-372) so a request reused for another viewer can't carry it.
    private static function viewerIsAdmin(Request $request): bool
    {
        $viewer = $request->user();
        if (! $viewer) {
            return false;
        }

        $key = "viewerIsAdmin:{$viewer->id}";
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, (bool) $viewer->isAdmin());
        }

        return $request->attributes->get($key);
    }
}

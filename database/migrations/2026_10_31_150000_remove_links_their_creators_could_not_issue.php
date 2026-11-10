<?php

use App\Actions\Link\EnsureUserCanCreateLinkForAction;
use App\Enums\LinkStateEnum;
use App\Models\Link;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

// SCRUM-350: links created before link creation was authorized whose creator could never have
// issued them (a guardianship link for someone else, a counsellor link for someone else's
// therapy, a discussion link for a discussion they don't manage). The use-time check already makes
// them unusable; this removes them. Any that were already USED (inactive) may have granted access
// -- they're logged with what they targeted, for a manual audit of guardianships, therapy
// counsellors and discussion members. Not reversible.
return new class extends Migration
{
    public function up(): void
    {
        Link::query()->with(['addedby', 'for'])->lazyById()->each(function (Link $link) {
            if (EnsureUserCanCreateLinkForAction::creatorMayStillIssue($link)) {
                return;
            }

            if ($link->state !== LinkStateEnum::active->value) {
                Log::warning('SCRUM-350: removing a USED link its creator could not have issued -- audit what it granted.', [
                    'link_id' => $link->id,
                    'type' => $link->type,
                    'addedby' => [$link->addedby_type, $link->addedby_id],
                    'for' => [$link->for_type, $link->for_id],
                    'to' => [$link->to_type, $link->to_id],
                    'updated_at' => (string) $link->updated_at,
                ]);
            }

            $link->delete();
        });
    }

    public function down(): void
    {
        //
    }
};

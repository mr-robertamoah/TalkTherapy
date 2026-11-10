<?php

use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // SCRUM-73 (security review): the rest of the sender_anonymous snapshot
    // (2026_11_08_100001). A group message's recipient, and the member who last changed a group
    // session's status (its updatedby), were masked only by the live rule -- so rejoining as named
    // unmasked them on what happened while they were anonymous. Each is now a snapshot taken when
    // it is written (CreateMessageAction, ChangeSessionStatusAction); the resources mask when the
    // snapshot OR the live rule says so.
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('recipient_anonymous')->default(false)->after('sender_anonymous');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->boolean('updatedby_anonymous')->default(false)->after('updatedby_id');
        });

        // Backfill as sender_anonymous was (there is no production): whoever's group is anonymous,
        // or whose own membership row is anonymous, right now.
        DB::table('messages')
            ->where('to_type', User::class)
            ->where('for_type', Session::class)
            ->whereExists(function (Builder $query) {
                $query->select(DB::raw(1))
                    ->from('sessions')
                    ->join('group_therapies', 'group_therapies.id', '=', 'sessions.for_id')
                    ->whereColumn('sessions.id', 'messages.for_id')
                    ->where('sessions.for_type', GroupTherapy::class)
                    ->where(fn (Builder $query) => $this->anonymousIn($query, 'messages.to_id'));
            })
            ->update(['recipient_anonymous' => true]);

        DB::table('sessions')
            ->where('updatedby_type', User::class)
            ->where('for_type', GroupTherapy::class)
            ->whereExists(function (Builder $query) {
                $query->select(DB::raw(1))
                    ->from('group_therapies')
                    ->whereColumn('group_therapies.id', 'sessions.for_id')
                    ->where(fn (Builder $query) => $this->anonymousIn($query, 'sessions.updatedby_id'));
            })
            ->update(['updatedby_anonymous' => true]);
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('updatedby_anonymous');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('recipient_anonymous');
        });
    }

    // The group (joined as group_therapies) is anonymous, or $userColumn's membership row in it is.
    private function anonymousIn(Builder $query, string $userColumn): void
    {
        $query->where('group_therapies.anonymous', true)
            ->orWhereExists(function (Builder $query) use ($userColumn) {
                $query->select(DB::raw(1))
                    ->from('group_therapy_user')
                    ->whereColumn('group_therapy_user.group_therapy_id', 'group_therapies.id')
                    ->whereColumn('group_therapy_user.user_id', $userColumn)
                    ->where('group_therapy_user.anonymous', true);
            });
    }
};

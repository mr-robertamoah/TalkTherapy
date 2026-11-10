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
    // SCRUM-73 (the user's decision): a group message sent anonymously stays anonymous forever, even
    // after its sender leaves and rejoins as named -- the snapshot taken when it is sent
    // (CreateMessageAction). MessageResource shows the sender as masked when this flag OR the live
    // rule says so, so a message is never unmasked.
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('sender_anonymous')->default(false)->after('confidential');
        });

        // Backfill (there is no production): every existing group message from a user whose group is
        // anonymous, or whose own membership row is anonymous, right now.
        DB::table('messages')
            ->where('from_type', User::class)
            ->where('for_type', Session::class)
            ->whereExists(function (Builder $query) {
                $query->select(DB::raw(1))
                    ->from('sessions')
                    ->join('group_therapies', 'group_therapies.id', '=', 'sessions.for_id')
                    ->whereColumn('sessions.id', 'messages.for_id')
                    ->where('sessions.for_type', GroupTherapy::class)
                    ->where(function (Builder $query) {
                        $query->where('group_therapies.anonymous', true)
                            ->orWhereExists(function (Builder $query) {
                                $query->select(DB::raw(1))
                                    ->from('group_therapy_user')
                                    ->whereColumn('group_therapy_user.group_therapy_id', 'group_therapies.id')
                                    ->whereColumn('group_therapy_user.user_id', 'messages.from_id')
                                    ->where('group_therapy_user.anonymous', true);
                            });
                    });
            })
            ->update(['sender_anonymous' => true]);
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('sender_anonymous');
        });
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory,
        SoftDeletes;

    protected $fillable = ['content', 'type', 'confidential', 'status', 'message_id', 'therapy_topic_id', 'deleted_for'];

    public function from()
    {
        // withTrashed: see Therapy::counsellor() -- the sender (User or Counsellor) may have
        // since deleted their account.
        return $this->morphTo()->withTrashed();
    }

    public function to()
    {
        // withTrashed: see from() above.
        return $this->morphTo()->withTrashed();
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function therapyTopic()
    {
        return $this->belongsTo(TherapyTopic::class);
    }

    public function replying()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function replies()
    {
        return $this->hasMany(Message::class, 'message_id');
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    // SCRUM-203/TT-2.3b: a counsellor's own private note(s) on this message -- never eager-loaded
    // for a client viewer (see MessageService's own comment on scoping this to the requesting
    // counsellor), so a non-counsellor's message list never even queries this table.
    public function notes()
    {
        return $this->hasMany(MessageNote::class);
    }

    // Whether $user sent THIS message or it was addressed to them (as a user, or through their
    // counsellor account). SCRUM-195: this used to run $this->query()->whereTo()->orWhereFrom()
    // ->exists() -- a fresh, unscoped query over the whole table -- so it was true for anyone who
    // had ever sent or received ANY message, which made every confidential message readable to
    // almost everyone and let them subscribe to any messages.{id} channel.
    public function isParty(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->morphRefersTo($this->from_type, $this->from_id, $user)
            || $this->morphRefersTo($this->to_type, $this->to_id, $user);
    }

    // The recipient as a USER id, whichever kind of account the message was addressed to -- the
    // frontend compares it against the viewer's own user id. SCRUM-195: previously derived from the
    // SENDER's type, which gave a counsellor id for a counsellor-to-counsellor message.
    public function recipientUserId(): ?int
    {
        return match ($this->to_type) {
            User::class => (int) $this->to_id,
            Counsellor::class => $this->to?->user_id,
            default => null,
        };
    }

    // Whether this from/to morph is $user, directly or through their counsellor account.
    private function morphRefersTo(?string $type, $id, User $user): bool
    {
        return match ($type) {
            User::class => (int) $id === $user->id,
            Counsellor::class => $user->counsellor !== null && (int) $id === $user->counsellor->id,
            default => false,
        };
    }

    public function isNotParty(?User $user)
    {
        // No special-case for a null $user here: isParty(null) already correctly returns
        // false, so this correctly evaluates to true ("not a party") for a guest. The previous
        // version's own `if (! $user) return false;` incorrectly treated a guest as IS a party,
        // which meant MessageResource's confidential-message guard never applied to guests.
        return ! $this->isParty($user);
    }

    public function scopeWhereTo($query, User $user)
    {
        $counsellor = $user->counsellor;

        return $query->where(function ($query) use ($user, $counsellor) {
            $query->where(function ($query) use ($user) {
                $query
                    ->where('to_type', $user::class)
                    ->where('to_id', $user->id);
            });

            $query->when($counsellor, function ($query) use ($counsellor) {
                $query->orWhere(function ($query) use ($counsellor) {
                    $query
                        ->where('to_type', $counsellor::class)
                        ->where('to_id', $counsellor->id);
                });
            });
        });
    }

    public function scopeWhereFrom($query, User $user)
    {
        $counsellor = $user->counsellor;

        return $query->where(function ($query) use ($user, $counsellor) {
            $query->where(function ($query) use ($user) {
                $query
                    ->where('from_type', $user::class)
                    ->where('from_id', $user->id);
            });

            $query->when($counsellor, function ($query) use ($counsellor) {
                $query->orWhere(function ($query) use ($counsellor) {
                    $query
                        ->where('from_type', $counsellor::class)
                        ->where('from_id', $counsellor->id);
                });
            });
        });
    }

    public function scopeWhereLike($query, string $like)
    {
        return $query->where('content', 'LIKE', "%{$like}%");
    }

    public function scopeWhereTherapyTopicId($query, string|int|null $topicId)
    {
        if (! $topicId) {
            return $query;
        }

        return $query->where('therapy_topic_id', $topicId);
    }

    public function scopeWhereReplyId($query, string|int|null $replyId)
    {
        if (! $replyId) {
            return $query;
        }

        return $query->where('message_id', $replyId);
    }
}

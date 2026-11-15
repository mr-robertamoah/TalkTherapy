<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// SCRUM-36 (TT-5.1): one entry of a person's private journal. Only its writer ever reads it -- every lookup goes through
// $user->journalEntries() (a 404 for anyone else, administrators included); there is no admin, guardian, counsellor or
// organisation path, and it is never added to AdminContentAccess::RESOURCES. Title and content are encrypted at rest;
// never log, broadcast or notify them. Searched only by their owner, in memory after decryption (JournalController::
// search, SCRUM-440) -- never in SQL, never indexed. Deleted for good: with the account when it is closed (User::booted).
class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'content', 'written_on'];

    protected $casts = [
        'title' => 'encrypted',
        'content' => 'encrypted',
        'written_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

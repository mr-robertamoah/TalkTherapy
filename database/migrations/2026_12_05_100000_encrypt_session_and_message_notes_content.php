<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCRUM-437: counsellors' session and message notes encrypted at rest (the models' `encrypted` cast). The column
// widens to mediumText (a 5,000-character note in a multi-byte script is about 36 KB once encrypted). Existing rows --
// soft-deleted ones too -- are encrypted in chunks, read raw (not through the models); a value that already decrypts
// is left alone, so a run that stopped halfway can simply run again. down() decrypts with the same APP_KEY (or
// APP_PREVIOUS_KEYS) and narrows the column back.
return new class extends Migration
{
    private const TABLES = ['session_notes', 'message_notes'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->mediumText('content')->change());

            $this->rewrite($table, fn (string $content) => $this->decrypts($content) ? null : Crypt::encryptString($content));
        }
    }

    public function down(): void
    {
        // Devops review: under another APP_KEY nothing decrypts, and rolling back would quietly leave ciphertext for the
        // old code to show. Refuse instead.
        foreach (self::TABLES as $table) {
            $undecryptable = 0;
            DB::table($table)->select(['id', 'content'])->orderBy('id')->chunkById(1000, function ($rows) use (&$undecryptable) {
                foreach ($rows as $row) {
                    $undecryptable += $row->content !== null && ! $this->decrypts($row->content) ? 1 : 0;
                }
            });

            if ($undecryptable > 0) {
                throw new RuntimeException("{$undecryptable} {$table} rows don't decrypt with APP_KEY / APP_PREVIOUS_KEYS -- refusing to roll back.");
            }
        }

        foreach (self::TABLES as $table) {
            $this->rewrite($table, fn (string $content) => $this->decrypts($content) ? Crypt::decryptString($content) : null);

            Schema::table($table, fn (Blueprint $t) => $t->text('content')->change());
        }
    }

    // $change returns the new value, or null to leave the row as it is.
    private function rewrite(string $table, callable $change): void
    {
        DB::table($table)->select(['id', 'content'])->orderBy('id')->chunkById(500, function ($rows) use ($table, $change) {
            DB::transaction(function () use ($rows, $table, $change) {
                foreach ($rows as $row) {
                    if ($row->content !== null && ($new = $change($row->content)) !== null) {
                        DB::table($table)->where('id', $row->id)->update(['content' => $new]);
                    }
                }
            });
        });
    }

    private function decrypts(string $content): bool
    {
        try {
            Crypt::decryptString($content);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};

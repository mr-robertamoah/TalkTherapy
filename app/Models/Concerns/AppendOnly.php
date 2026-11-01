<?php

namespace App\Models\Concerns;

use LogicException;

// An audit model whose rows can be created but never changed or deleted through Eloquent. The
// database enforces the same with triggers (see the *_append_only migrations).
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(class_basename(static::class).' records are append-only.'));
        static::deleting(fn () => throw new LogicException(class_basename(static::class).' records are append-only.'));
    }
}

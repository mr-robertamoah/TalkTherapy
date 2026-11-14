<?php

namespace App\Support;

// SCRUM-424 (QA): whether a person is at a terminal -- not just "prompts are on" (`-n` off): a script piping "yes" into a
// command has prompts but no terminal. A class so tests can stand in for the terminal.
class Terminal
{
    public function attached(): bool
    {
        return defined('STDIN') && function_exists('stream_isatty') && stream_isatty(STDIN);
    }
}

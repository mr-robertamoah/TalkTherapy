<?php

namespace App\Enums;

use App\Support\OutcomeMeasures\Gad7;
use App\Support\OutcomeMeasures\OutcomeMeasure;
use App\Support\OutcomeMeasures\Phq9;

// SCRUM-37 (TT-5.2): the questionnaires a counsellor can send.
enum OutcomeMeasureEnum: string
{
    case phq9 = 'phq9';
    case gad7 = 'gad7';

    public function definition(): OutcomeMeasure
    {
        return match ($this) {
            self::phq9 => new Phq9,
            self::gad7 => new Gad7,
        };
    }
}

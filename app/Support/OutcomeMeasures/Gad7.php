<?php

namespace App\Support\OutcomeMeasures;

// GAD-7 (Generalized Anxiety Disorder 7-item scale): the published items, word for word.
class Gad7 extends OutcomeMeasure
{
    public function key(): string
    {
        return 'gad7';
    }

    public function version(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'GAD-7';
    }

    public function preamble(): string
    {
        return 'Over the last 2 weeks, how often have you been bothered by the following problems?';
    }

    public function items(): array
    {
        return [
            'Feeling nervous, anxious, or on edge',
            'Not being able to stop or control worrying',
            'Worrying too much about different things',
            'Trouble relaxing',
            'Being so restless that it is hard to sit still',
            'Becoming easily annoyed or irritable',
            'Feeling afraid, as if something awful might happen',
        ];
    }

    public function bands(): array
    {
        return [
            ['max' => 4, 'level' => 'minimal'],
            ['max' => 9, 'level' => 'mild'],
            ['max' => 14, 'level' => 'moderate'],
            ['max' => 21, 'level' => 'severe'],
        ];
    }
}

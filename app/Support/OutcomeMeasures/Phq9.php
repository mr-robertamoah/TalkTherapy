<?php

namespace App\Support\OutcomeMeasures;

// PHQ-9 (Patient Health Questionnaire): the published items, word for word. Item 9 (thoughts of being better off dead
// or of self-harm) answered above "Not at all" needs the counsellor's attention (SCRUM-37, AnswerCheckInAction).
class Phq9 extends OutcomeMeasure
{
    public function key(): string
    {
        return 'phq9';
    }

    public function version(): int
    {
        return 1;
    }

    public function title(): string
    {
        return 'PHQ-9';
    }

    public function preamble(): string
    {
        return 'Over the last 2 weeks, how often have you been bothered by any of the following problems?';
    }

    public function items(): array
    {
        return [
            'Little interest or pleasure in doing things',
            'Feeling down, depressed, or hopeless',
            'Trouble falling or staying asleep, or sleeping too much',
            'Feeling tired or having little energy',
            'Poor appetite or overeating',
            'Feeling bad about yourself — or that you are a failure or have let yourself or your family down',
            'Trouble concentrating on things, such as reading the newspaper or watching television',
            'Moving or speaking so slowly that other people could have noticed? Or the opposite — being so fidgety or restless that you have been moving around a lot more than usual',
            'Thoughts that you would be better off dead or of hurting yourself in some way',
        ];
    }

    public function bands(): array
    {
        return [
            ['max' => 4, 'level' => 'minimal'],
            ['max' => 9, 'level' => 'mild'],
            ['max' => 14, 'level' => 'moderate'],
            ['max' => 19, 'level' => 'moderately severe'],
            ['max' => 27, 'level' => 'severe'],
        ];
    }

    public function attentionItem(): ?int
    {
        return 8;
    }
}

<?php

namespace App\Support\OutcomeMeasures;

// SCRUM-37 (TT-5.2): a published questionnaire, defined in code -- never edited in the app, since changing a word makes
// its scores mean something else. A check-in stores the version it was answered with.
abstract class OutcomeMeasure
{
    public const OPTIONS = [
        0 => 'Not at all',
        1 => 'Several days',
        2 => 'More than half the days',
        3 => 'Nearly every day',
    ];

    public const ATTRIBUTION = 'Developed by Drs. Robert L. Spitzer, Janet B.W. Williams, Kurt Kroenke and colleagues, with an educational grant from Pfizer Inc. No permission required to reproduce, translate, display or distribute.';

    abstract public function key(): string;

    abstract public function version(): int;

    abstract public function title(): string;

    abstract public function preamble(): string;

    /** @return list<string> the scored items, in order */
    abstract public function items(): array;

    /** @return list<array{max: int, level: string}> symptom levels, lowest first */
    abstract public function bands(): array;

    // The item (0-based) whose answer above 0 needs the counsellor's attention -- none by default.
    public function attentionItem(): ?int
    {
        return null;
    }

    public function maxScore(): int
    {
        return count($this->items()) * 3;
    }

    // $answers: one 0-3 per item, in order (checked before).
    public function score(array $answers): int
    {
        return array_sum(array_map('intval', $answers));
    }

    public function band(int $score): string
    {
        foreach ($this->bands() as $band) {
            if ($score <= $band['max']) {
                return $band['level'];
            }
        }

        return collect($this->bands())->last()['level'];
    }

    public function needsAttention(array $answers): bool
    {
        $item = $this->attentionItem();

        return $item !== null && (int) ($answers[$item] ?? 0) > 0;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'version' => $this->version(),
            'title' => $this->title(),
            'preamble' => $this->preamble(),
            'items' => $this->items(),
            'options' => collect(self::OPTIONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'maxScore' => $this->maxScore(),
            'bands' => $this->bands(),
            'attentionItem' => $this->attentionItem(),
            'attribution' => self::ATTRIBUTION,
        ];
    }
}

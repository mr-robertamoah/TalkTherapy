<?php 

namespace App\Traits;

trait EnumTrait
{
    public static function values(): array
    {
        return array_map(function ($case)
        {
            return $case->value;
        }, self::cases());
    }

    public static function getValueOf(string $name) : ?string
    {
        foreach (self::cases() as $case)
        {
            if (strtolower($case->name) == strtolower($name)) return $case->value;
        }

        return null;
    }
}
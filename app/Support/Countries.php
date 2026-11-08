<?php

namespace App\Support;

// SCRUM-33: the one country list (resources/data/countries.json, generated from the ISO 3166 dataset
// by `npm run countries:generate`), shared with the Vue CountrySelect so the server and the picker
// agree. Each entry: ISO alpha-2 `code`, the `name` shown, and other English `aliases`. users.country
// holds a name, so a saved name -- the shown one or an alias, any case -- resolves to a code.
final class Countries
{
    private static ?array $all = null;

    private static ?array $byName = null;

    /** @return list<array{code: string, name: string, aliases: list<string>}> */
    public static function all(): array
    {
        return self::$all ??= json_decode((string) file_get_contents(resource_path('data/countries.json')), true) ?: [];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }

    public static function nameOf(?string $code): ?string
    {
        foreach (self::all() as $country) {
            if ($country['code'] === $code) {
                return $country['name'];
            }
        }

        return null;
    }

    // The ISO code for a country name (its shown name or an alias, any case), or null.
    public static function codeFor(?string $name): ?string
    {
        $wanted = mb_strtolower(trim((string) $name));
        if ($wanted === '') {
            return null;
        }

        if (self::$byName === null) {
            self::$byName = [];
            foreach (self::all() as $country) {
                foreach ([$country['name'], ...$country['aliases']] as $known) {
                    self::$byName[mb_strtolower($known)] = $country['code'];
                }
            }
        }

        return self::$byName[$wanted] ?? null;
    }
}

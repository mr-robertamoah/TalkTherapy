<?php

// SCRUM-479 (TT-9.3a, SCRUM-53): the interface languages the app can be shown in -- never "languages", which are the
// ones counsellors speak (the Language model). Mirrors config/currencies.php: `supported` is everything the code knows
// about; `enabled` is what this deployment offers (ENABLED_LOCALES), so a language whose translations aren't ready
// stays off without a code change. English is the default and always enabled (SetLocale falls back to it).
//
// `label` is each language's name in itself (a switcher shows it to someone who may not read English). `intl` is the
// tag the browser's Intl APIs need for dates and numbers when the app code isn't one (Twi is `tw` here, `ak` to Intl);
// null means the code itself. `safety_reviewed` / `legal_reviewed`: whether a fluent counsellor has reviewed the
// safety wording / a lawyer the legal pages in this language -- until then those stay English (enforced later,
// SCRUM-53's later slices).
$supported = [
    'en' => ['label' => 'English', 'intl' => null, 'safety_reviewed' => true, 'legal_reviewed' => true],
    'tw' => ['label' => 'Twi', 'intl' => 'ak', 'safety_reviewed' => false, 'legal_reviewed' => false],
    'fr' => ['label' => 'Français', 'intl' => 'fr', 'safety_reviewed' => false, 'legal_reviewed' => false],
];

return [
    'supported' => $supported,

    // Lowercased and trimmed once, here; array_filter drops empty entries (a stray comma), and anything not in
    // `supported` is dropped, so Rule::in() and SetLocale only ever see a known code. English is always first.
    'enabled' => array_values(array_unique(['en', ...array_filter(
        array_map(fn (string $locale) => strtolower(trim($locale)), explode(',', env('ENABLED_LOCALES') ?: 'en')),
        fn (string $locale) => $locale !== '' && array_key_exists($locale, $supported),
    )])),
];

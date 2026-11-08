// SCRUM-33: writes resources/data/countries.json -- the one country list the server
// (App\Support\Countries) and the UI (CountrySelect) share -- from the ISO 3166 dataset in
// i18n-iso-countries (English). Run after upgrading that package: `npm run countries:generate`.
// Each entry: ISO alpha-2 code, the name shown, and every other English spelling as aliases (so a
// name saved earlier, e.g. "United States of America", still matches).
import { readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'

const require = createRequire(import.meta.url)
const { countries } = require('i18n-iso-countries/langs/en.json')

// Everyday names where the dataset's first form is a long official one.
const SHOWN = {
    US: 'United States', TW: 'Taiwan', RU: 'Russia', IR: 'Iran', TZ: 'Tanzania', PS: 'Palestine',
    MK: 'North Macedonia', MD: 'Moldova', FM: 'Micronesia', LA: 'Laos', SY: 'Syria', BN: 'Brunei',
    VA: 'Vatican City', BO: 'Bolivia', VE: 'Venezuela',
}

const list = Object.entries(countries).map(([code, value]) => {
    const names = Array.isArray(value) ? value : [value]
    const name = SHOWN[code] ?? names[0]
    // Two-letter forms ("US", "UK") are left out: they'd match too loosely.
    const aliases = [...new Set(names)].filter((alias) => alias !== name && alias.replace(/\./g, '').length > 2)

    return { code, name, aliases }
}).sort((a, b) => a.name.localeCompare(b.name, 'en'))

// An alias two countries share (e.g. "Congo") or that is another country's name points nowhere.
const seen = new Map()
for (const { name, aliases } of list) for (const n of [name, ...aliases]) seen.set(n.toLowerCase(), (seen.get(n.toLowerCase()) ?? 0) + 1)
for (const entry of list) entry.aliases = entry.aliases.filter((alias) => seen.get(alias.toLowerCase()) === 1)

const target = new URL('../resources/data/countries.json', import.meta.url)
writeFileSync(target, JSON.stringify(list, null, 1) + '\n')
console.log(`Wrote ${list.length} countries to resources/data/countries.json`)

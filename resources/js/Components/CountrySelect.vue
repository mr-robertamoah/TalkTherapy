<script setup>
// SCRUM-33 (the user's request): one searchable country picker for every form -- the bundled ISO list
// (resources/data/countries.json, shared with App\Support\Countries), type to filter (other spellings
// match too, e.g. "Ivory Coast"). The value is the country's name (what users.country has always
// held), or its ISO code with by="code". A name saved before this list existed is still shown, so
// editing a profile never silently drops it.
import { computed } from 'vue'
import countries from '../../data/countries.json'

const props = defineProps({
    modelValue: { type: String, default: '' },
    by: { type: String, default: 'name' }, // 'name' | 'code'
    inputId: { type: String, default: 'country' },
    placeholder: { type: String, default: 'Search for a country' },
    clearable: { type: Boolean, default: true },
    // The search box's name for screen readers (vue-select's own default is "Search for option").
    ariaLabel: { type: String, default: 'Country' },
})

const emit = defineEmits(['update:modelValue'])

const byCode = computed(() => props.by === 'code')
const known = (value) => countries.some((country) => country[props.by] === value)

const options = computed(() => (props.modelValue && !known(props.modelValue))
    ? [{ code: props.modelValue, name: props.modelValue, aliases: [] }, ...countries]
    : countries)

const selected = computed({
    get: () => props.modelValue || null,
    set: (value) => emit('update:modelValue', value ?? ''),
})

function filterBy(option, label, search) {
    return `${option.name} ${option.aliases.join(' ')} ${byCode.value ? option.code : ''}`
        .toLowerCase().includes(search.trim().toLowerCase())
}
</script>

<template>
    <v-select
        v-model="selected"
        :options="options"
        label="name"
        :reduce="(country) => country[by]"
        :input-id="inputId"
        :placeholder="placeholder"
        :clearable="clearable"
        :filter-by="filterBy"
        class="country-select"
    >
        <template #search="{ attributes, events }">
            <input class="vs__search" v-bind="attributes" :aria-label="ariaLabel" v-on="events" />
        </template>
        <template #no-options>No country matches.</template>
    </v-select>
</template>

<style>
@import "vue-select/dist/vue-select.css";

.country-select .vs__dropdown-toggle {
    min-height: 42px;
    border-color: rgb(209 213 219);
    border-radius: 0.375rem;
    background: #fff;
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
}

.country-select.vs--open .vs__dropdown-toggle {
    border-color: rgb(99 102 241);
}
</style>

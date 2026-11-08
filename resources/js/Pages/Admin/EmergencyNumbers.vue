<script setup>
import { computed, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import CountrySelect from '@/Components/CountrySelect.vue'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import DangerButton from '@/Components/DangerButton.vue'
import MiniModal from '@/Components/MiniModal.vue'
import useAlert from '@/Composables/useAlert'

defineOptions({ layout: AdminLayout })

// SCRUM-33: the emergency numbers the "I need help now" dialog shows, per country. Every number is
// verified by a named person against a source before it's added; it's shown as soon as it's saved.
// Every administrator sees this page; only a super administrator changes it (each change is kept).

const props = defineProps({
    numbers: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    reviewMonths: { type: Number, default: 6 },
})

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const filter = ref('')
const editing = ref(null) // null | 'new' | a number's id
const removing = ref(null)

const blank = { countryCode: 'GH', label: '', number: '', notes: '', sourceUrl: '', verifiedBy: '', verifiedAt: '', position: 0 }
const form = useForm({ ...blank })
const removeForm = useForm({})

const today = new Date().toISOString().slice(0, 10)

const grouped = computed(() => {
    const wanted = filter.value.trim().toLowerCase()
    const groups = {}
    for (const number of props.numbers) {
        if (wanted && !`${number.country} ${number.countryCode}`.toLowerCase().includes(wanted)) continue
        ;(groups[number.countryCode] ??= { code: number.countryCode, name: number.country, numbers: [] }).numbers.push(number)
    }
    return Object.values(groups).sort((a, b) => a.name.localeCompare(b.name))
})

function startNew() {
    form.defaults({ ...blank })
    form.reset()
    form.clearErrors()
    editing.value = 'new'
}

function startEdit(number) {
    form.defaults({
        countryCode: number.countryCode, label: number.label, number: number.number, notes: number.notes ?? '',
        sourceUrl: number.sourceUrl, verifiedBy: number.verifiedBy, verifiedAt: number.verifiedAt, position: number.position,
    })
    form.reset()
    form.clearErrors()
    editing.value = number.id
}

function save() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            setSuccessAlertData({ message: editing.value === 'new' ? 'Emergency number added.' : 'Emergency number saved.' })
            editing.value = null
        },
        onError: (errors) => { if (errors.alert) setFailedAlertData({ message: errors.alert }) },
    }

    if (editing.value === 'new') form.post(route('admin.emergency_numbers.store'), options)
    else form.post(route('admin.emergency_numbers.update', { number: editing.value }), options)
}

function remove() {
    removeForm.post(route('admin.emergency_numbers.delete', { number: removing.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            setSuccessAlertData({ message: 'Emergency number removed.' })
            removing.value = null
        },
        onError: (errors) => setFailedAlertData({ message: errors.alert ?? 'It couldn\'t be removed.' }),
    })
}
</script>

<template>
    <Head title="Emergency numbers" />

    <div class="my-8 mx-auto w-full max-w-5xl space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Emergency numbers</h1>
            <div class="mt-2 h-1 w-16 bg-blue-600"></div>
            <p class="mt-2 text-sm text-gray-600">
                What the "I need help now" dialog shows, for the person's country. Add a number only after checking it
                against an official source -- it is shown as soon as it's saved. A country without one is told to
                contact its local emergency services. Numbers are due a fresh check every {{ reviewMonths }} months.
                <template v-if="!canManage"> Only a super administrator can change them.</template>
            </p>
        </div>

        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="w-full sm:max-w-xs">
                <InputLabel for="number-filter" value="Find a country" />
                <TextInput id="number-filter" v-model="filter" type="search" class="mt-1 block w-full" placeholder="e.g. Ghana" />
            </div>
            <PrimaryButton v-if="canManage" type="button" @click="startNew">Add a number</PrimaryButton>
        </div>

        <p v-if="!numbers.length" class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            There are no emergency numbers yet, so the dialog tells everyone to contact their local emergency services.
        </p>

        <section v-for="group in grouped" :key="group.code" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-6" :aria-labelledby="`country-${group.code}`">
            <h2 :id="`country-${group.code}`" class="text-lg font-semibold text-gray-900">{{ group.name }} <span class="text-sm font-normal text-gray-500">({{ group.code }})</span></h2>
            <ul class="mt-3 divide-y divide-gray-100">
                <li v-for="number in group.numbers" :key="number.id" class="flex flex-wrap items-start justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <div class="font-medium text-gray-900">{{ number.label }}: <span class="tabular-nums">{{ number.number }}</span></div>
                        <div v-if="number.notes" class="text-sm text-gray-600">{{ number.notes }}</div>
                        <div class="mt-1 text-xs text-gray-600">
                            Verified {{ number.verifiedAt }} by {{ number.verifiedBy }} ·
                            <a :href="number.sourceUrl" target="_blank" rel="noopener noreferrer" class="text-blue-700 underline">source</a>
                            <span v-if="number.reviewDue" class="ml-2 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 font-medium text-amber-800">Review due</span>
                        </div>
                    </div>
                    <div v-if="canManage" class="flex shrink-0 gap-2">
                        <SecondaryButton type="button" @click="startEdit(number)">Edit</SecondaryButton>
                        <DangerButton type="button" @click="removing = number">Remove</DangerButton>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <MiniModal :show="editing !== null" labelledby="number-form-title" @close="editing = null">
        <form class="space-y-4" @submit.prevent="save">
            <h2 id="number-form-title" class="text-lg font-semibold text-gray-900">{{ editing === 'new' ? 'Add an emergency number' : 'Edit emergency number' }}</h2>

            <div>
                <InputLabel for="number-country" value="Country" />
                <CountrySelect v-model="form.countryCode" by="code" input-id="number-country" :clearable="false" class="mt-1" />
                <InputError class="mt-1" :message="form.errors.countryCode" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="number-label" value="Label (e.g. Police, Ambulance)" />
                    <TextInput id="number-label" v-model="form.label" class="mt-1 block w-full" maxlength="100" required />
                    <InputError class="mt-1" :message="form.errors.label" />
                </div>
                <div>
                    <InputLabel for="number-number" value="Number" />
                    <TextInput id="number-number" v-model="form.number" type="tel" class="mt-1 block w-full" maxlength="30" required />
                    <InputError class="mt-1" :message="form.errors.number" />
                </div>
            </div>
            <div>
                <InputLabel for="number-notes" value="Notes (optional, e.g. free from any phone, 24 hours)" />
                <TextInput id="number-notes" v-model="form.notes" class="mt-1 block w-full" maxlength="200" />
                <InputError class="mt-1" :message="form.errors.notes" />
            </div>
            <div>
                <InputLabel for="number-source" value="Official source (link)" />
                <TextInput id="number-source" v-model="form.sourceUrl" type="url" class="mt-1 block w-full" maxlength="500" required />
                <InputError class="mt-1" :message="form.errors.sourceUrl" />
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="sm:col-span-1">
                    <InputLabel for="number-verified-by" value="Verified by" />
                    <TextInput id="number-verified-by" v-model="form.verifiedBy" class="mt-1 block w-full" maxlength="120" required />
                    <InputError class="mt-1" :message="form.errors.verifiedBy" />
                </div>
                <div>
                    <InputLabel for="number-verified-at" value="Verified on" />
                    <TextInput id="number-verified-at" v-model="form.verifiedAt" type="date" :max="today" class="mt-1 block w-full" required />
                    <InputError class="mt-1" :message="form.errors.verifiedAt" />
                </div>
                <div>
                    <InputLabel for="number-position" value="Order" />
                    <TextInput id="number-position" v-model="form.position" type="number" min="0" max="99" class="mt-1 block w-full" />
                    <InputError class="mt-1" :message="form.errors.position" />
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <SecondaryButton type="button" :disabled="form.processing" @click="editing = null">Cancel</SecondaryButton>
                <PrimaryButton :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save' }}</PrimaryButton>
            </div>
        </form>
    </MiniModal>

    <MiniModal :show="!!removing" labelledby="number-remove-title" @close="removing = null">
        <div v-if="removing" class="space-y-4">
            <h2 id="number-remove-title" class="text-lg font-semibold text-gray-900">Remove {{ removing.label }} ({{ removing.number }})?</h2>
            <p class="text-sm text-gray-600">It stops being shown straight away. Its history is kept.</p>
            <div class="flex justify-end gap-2">
                <SecondaryButton type="button" :disabled="removeForm.processing" @click="removing = null">Cancel</SecondaryButton>
                <DangerButton type="button" :disabled="removeForm.processing" @click="remove">Remove</DangerButton>
            </div>
        </div>
    </MiniModal>

    <Alert :show="alertData.show" :type="alertData.type" :message="alertData.message" :time="alertData.time" @close="clearAlertData" />
</template>

<template>
    <section aria-labelledby="guardianship-title">
        <header>
            <h2 id="guardianship-title" class="text-lg font-medium text-gray-900">Guardianship</h2>
            <p class="mt-1 text-sm text-gray-600">
                A guardian is an adult who looks out for someone under 18 on TalkTherapy. Their ward's therapies and
                organisations wait for a guardian, and the guardian is told about them.
            </p>
        </header>

        <p v-if="loading" class="mt-4 text-sm text-gray-600" role="status">Loading guardianship…</p>
        <div v-else-if="loadError" class="mt-4">
            <InputError role="alert" :message="loadError" />
            <SecondaryButton class="mt-2" type="button" @click="getGuardians">Try again</SecondaryButton>
        </div>

        <template v-else>
            <p class="mt-3 text-sm text-green-800" role="status">{{ status }}</p>

            <!-- Whoever has guardians sees them first; a minor never sees wards (only adults can be guardians). -->
            <div v-for="group in groups" :key="group.key" class="mt-4">
                <h3 :id="`guardianship-${group.key}`" tabindex="-1" class="text-sm font-semibold text-gray-900 focus:outline-none">
                    {{ group.title }}
                </h3>

                <ul v-if="group.rows.length" role="list" :aria-labelledby="`guardianship-${group.key}`" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200">
                    <li v-for="row in group.rows" :key="row.id" class="flex flex-wrap items-center gap-3 p-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-800" aria-hidden="true">
                            {{ initials(row.person) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-gray-900 [overflow-wrap:anywhere]">
                                {{ row.person.deleted ? 'A deleted account' : (row.person.fullName || row.person.username) }}
                            </p>
                            <p class="text-xs text-gray-500 [overflow-wrap:anywhere]">
                                <template v-if="!row.person.deleted">@{{ row.person.username }} · </template>{{ group.key === 'wards' ? 'Your ward' : 'Your guardian' }} since {{ day(row.since) }}
                            </p>
                        </div>
                        <SecondaryButton
                            v-if="group.key === 'wards'"
                            type="button"
                            class="min-h-[44px]"
                            :aria-label="`Remove ${name(row.person)} as your ward`"
                            @click="confirming = row"
                        >Remove</SecondaryButton>
                    </li>
                </ul>

                <p v-else class="mt-2 text-sm text-gray-600">{{ group.empty }}</p>
                <p v-if="group.key === 'guardians' && group.rows.length" class="mt-2 text-xs text-gray-500">
                    Only a guardian, or the TalkTherapy team, can end a guardianship.
                </p>
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <PrimaryButton type="button" @click="() => showModal('add')">Add a guardian</PrimaryButton>
                <p class="text-xs text-gray-500">Send a request to an adult who has an account, or share a guardianship link.</p>
            </div>
        </template>

        <!-- Removing a ward: who, and what follows. -->
        <MiniModal :show="!!confirming" max-width="md" labelledby="guardianship-remove-title" @close="closeConfirm">
            <div class="p-6">
                <h3 id="guardianship-remove-title" class="text-lg font-semibold text-gray-900">Remove {{ confirming ? name(confirming.person) : '' }} as your ward?</h3>
                <p class="mt-2 text-sm text-gray-700">
                    They'll be told. If you're their only guardian and they're under 18, their therapies and organisations
                    pause until they have a guardian again.
                </p>
                <InputError class="mt-2" role="alert" :message="removeError" />
                <div class="mt-6 flex justify-end gap-3">
                    <SecondaryButton type="button" :disabled="removing" @click="closeConfirm">Cancel</SecondaryButton>
                    <DangerButton type="button" :disabled="removing" @click="remove">{{ removing ? 'Removing…' : 'Remove' }}</DangerButton>
                </div>
            </div>
        </MiniModal>

        <AddGuardianModal
            :show="modalData.show && modalData.type == 'add'"
            @close="closeModal"
        />
    </section>
</template>

<script setup>
// SCRUM-439 (the user's TODO): guardians and wards as plain lists in the profile card -- who, since when, and what can
// be done. Only a guardian (or the team) ends a guardianship (EnsureCanUpdateGuardianshipAction), and the ward is told.
// Whether a guardianship is paused isn't shown (the user's decision: it could reveal a guardian's suspension).
import useAuth from '@/Composables/useAuth';
import { computed, nextTick, onBeforeMount, ref } from 'vue';
import useModal from '@/Composables/useModal';
import AddGuardianModal from './AddGuardianModal.vue';
import PrimaryButton from './PrimaryButton.vue';
import SecondaryButton from './SecondaryButton.vue';
import DangerButton from './DangerButton.vue';
import InputError from './InputError.vue';
import MiniModal from './MiniModal.vue';
import { usePage } from '@inertiajs/vue3';

const { goToLogin } = useAuth()
const { modalData, showModal, closeModal } = useModal()

const isAdult = computed(() => usePage().props.auth.user?.isAdult !== false)

const loading = ref(true)
const loadError = ref('')
const status = ref('')
const guardianship = ref([])

onBeforeMount(getGuardians)

const wards = computed(() => guardianship.value.filter((g) => g.isWard).map((g) => ({ id: g.id, person: g.ward ?? {}, since: g.since })))
const guardians = computed(() => guardianship.value.filter((g) => !g.isWard).map((g) => ({ id: g.id, person: g.guardian ?? {}, since: g.since })))

const groups = computed(() => {
    const guardianGroup = {
        key: 'guardians',
        title: 'Your guardians',
        rows: guardians.value,
        empty: isAdult.value
            ? 'You have no guardians.'
            : "You don't have a guardian yet. Until you do, your therapies and organisations wait. Add a guardian -- an adult you trust who has an account.",
    }
    const wardGroup = {
        key: 'wards',
        title: 'Your wards',
        rows: wards.value,
        empty: 'You have no wards. Someone under 18 can make you their guardian by sending you a request or a guardianship link.',
    }

    if (!isAdult.value) return [guardianGroup]

    return guardians.value.length ? [guardianGroup, wardGroup] : [wardGroup]
})

async function getGuardians() {
    loading.value = true
    loadError.value = ''

    try {
        const res = await axios.get(route('api.users.guardianship'))
        guardianship.value = [...res.data.data]
    } catch (err) {
        goToLogin(err)
        loadError.value = err.response?.data?.message ?? 'Your guardianship details could not be loaded.'
    } finally {
        loading.value = false
    }
}

// --- removing a ward ---
const confirming = ref(null)
const removing = ref(false)
const removeError = ref('')

function closeConfirm() {
    if (removing.value) return
    confirming.value = null
    removeError.value = ''
}

async function remove() {
    removing.value = true
    removeError.value = ''
    try {
        await axios.delete(route('api.guardianship.delete', { guardianshipId: confirming.value.id }))
        const who = name(confirming.value.person)
        guardianship.value = guardianship.value.filter((g) => g.id !== confirming.value.id)
        confirming.value = null
        status.value = `${who} is no longer your ward.`
        nextTick(() => document.getElementById('guardianship-wards')?.focus())
    } catch (err) {
        removeError.value = err.response?.data?.message ?? 'It could not be removed. Please try again.'
    } finally {
        removing.value = false
    }
}

function name(person) {
    return person?.deleted ? 'a deleted account' : (person?.fullName || `@${person?.username}`)
}

function initials(person) {
    if (person?.deleted) return '?'
    const parts = (person?.fullName || person?.username || '?').trim().split(/\s+/)
    return parts.slice(0, 2).map((p) => p[0]?.toUpperCase() ?? '').join('')
}

function day(value) {
    return value ? new Date(`${value}T00:00:00Z`).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' }) : ''
}
</script>

<script setup>
// SCRUM-39 (TT-5.4d): how long the person's notifications are kept before they're deleted -- the platform
// default, or one of the options. An unread safety notice is never deleted.
import { computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import InputError from '@/Components/InputError.vue'
import InputLabel from '@/Components/InputLabel.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

const props = defineProps({
    retention: { type: Object, required: true }, // { days, defaultDays, options }
})

const form = useForm({ days: props.retention.days ?? '' })

function describe(days) {
    if (days === 365) return '1 year'
    return `${days} days`
}

const defaultLabel = computed(() => (props.retention.defaultDays ? `The default (${describe(props.retention.defaultDays)})` : 'The default'))

// The same number as the default, chosen here, stays even if the default changes later (review).
function optionLabel(days) {
    return days === props.retention.defaultDays ? `${describe(days)} (stays even if the default changes)` : describe(days)
}

function save() {
    form.transform((data) => ({ days: data.days === '' ? null : Number(data.days) }))
        .post(route('notifications.retention'), { preserveScroll: true })
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">Notifications</h2>
            <p class="mt-1 text-sm text-gray-600">
                Choose how long your notifications, and your counsellor profile's, are kept. Older ones are deleted
                for good. A "check in" support message from the TalkTherapy team is kept until you dismiss it.
            </p>
        </header>

        <form class="mt-6 space-y-4" @submit.prevent="save">
            <div>
                <InputLabel for="notification_retention" value="Keep my notifications for" />
                <select
                    id="notification_retention"
                    v-model="form.days"
                    class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                >
                    <option value="">{{ defaultLabel }}</option>
                    <option v-for="days in retention.options" :key="days" :value="days">{{ optionLabel(days) }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.days" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
                <p v-if="form.recentlySuccessful" class="text-sm text-gray-600" role="status">Saved.</p>
            </div>
        </form>
    </section>
</template>

<script setup>
// SCRUM-40 (TT-5.5): which kinds of notification the person wants, by email and in the app (the bell) -- each one on
// unless they switch it off. Safety, security and account notices aren't here: those are always sent.
import { computed } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import InputError from '@/Components/InputError.vue'

const props = defineProps({
    categories: { type: Array, required: true }, // [{ key, label, description, mail, database }]
})

const page = usePage()
const emailVerified = computed(() => !!page.props.auth.user?.email && !!page.props.auth.user?.emailVerifiedAt)

const form = useForm({
    channels: Object.fromEntries(props.categories.map((c) => [c.key, { mail: c.mail, database: c.database }])),
})

const isCounsellor = computed(() => props.categories.some((c) => ['discussions', 'organisations'].includes(c.key)))
const bothOff = computed(() => props.categories.filter((c) => !form.channels[c.key].mail && !form.channels[c.key].database).map((c) => c.label))

function save() {
    form.post(route('notifications.channels'), { preserveScroll: true })
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">What you're told about</h2>
            <p class="mt-1 text-sm text-gray-600">
                Choose which notifications you get by email and in the app{{ isCounsellor ? ', for you and your counsellor profile' : '' }}.
                Notices about your safety, your account's security, suspensions, guardianship (and your wards) and urgent
                help are always sent both ways. "In the app" covers the bell and pop-up reminders.
            </p>
            <p v-if="!emailVerified" class="mt-2 text-sm text-amber-900">
                You'll only get emails once your account has a verified email address.
            </p>
        </header>

        <form class="mt-6" @submit.prevent="save">
            <table class="w-full text-sm">
                <caption class="sr-only">Notifications by email and in the app</caption>
                <thead>
                    <tr class="text-left text-gray-600 border-b border-gray-200">
                        <th scope="col" class="py-2 pr-3 font-medium">Kind</th>
                        <th scope="col" class="py-2 px-2 font-medium text-center w-16">Email</th>
                        <th scope="col" class="py-2 pl-2 font-medium text-center w-16">In app</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="category in categories" :key="category.key" class="border-b border-gray-100 align-top">
                        <th scope="row" class="py-3 pr-3 text-left font-normal">
                            <span class="block font-medium text-gray-900">{{ category.label }}</span>
                            <span :id="`channels-${category.key}-about`" class="block text-xs text-gray-500">{{ category.description }}</span>
                        </th>
                        <td class="py-3 px-2 text-center">
                            <input
                                v-model="form.channels[category.key].mail"
                                type="checkbox"
                                class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                :aria-label="`${category.label} by email`"
                                :aria-describedby="`channels-${category.key}-about`"
                            />
                        </td>
                        <td class="py-3 pl-2 text-center">
                            <input
                                v-model="form.channels[category.key].database"
                                type="checkbox"
                                class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                :aria-label="`${category.label} in the app`"
                                :aria-describedby="`channels-${category.key}-about`"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="text-sm text-amber-900" :class="{ 'mt-3': bothOff.length }" role="status">
                <template v-if="bothOff.length">You won't hear about {{ bothOff.join(', ').toLowerCase() }} at all -- not by email, not in the app.</template>
            </p>
            <InputError class="mt-2" :message="form.errors.channels" />

            <div class="mt-4 flex items-center gap-4">
                <PrimaryButton type="submit" :disabled="form.processing">Save</PrimaryButton>
                <p v-if="form.recentlySuccessful" class="text-sm text-gray-600" role="status">Saved.</p>
            </div>
        </form>
    </section>
</template>

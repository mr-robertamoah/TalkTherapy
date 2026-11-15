<script setup>
// SCRUM-387: the old /administrator "users > testimonials" tab as its own page -- the same
// api.testimonials list with "get more" paging.
import { Head } from '@inertiajs/vue3'
import { onMounted, ref } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import Alert from '@/Components/Alert.vue'
import FormLoader from '@/Components/FormLoader.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import AdminTable from '@/Components/Admin/AdminTable.vue'
import AdminTestimonialRow from '@/Components/Admin/AdminTestimonialRow.vue'
import useAlert from '@/Composables/useAlert'

defineOptions({ layout: AdminLayout })

const { alertData, clearAlertData, setFailedAlertData, setSuccessAlertData } = useAlert()

const testimonials = ref([])
const nextPage = ref(1)
const loading = ref(false)

async function getTestimonials() {
    if (!nextPage.value || loading.value) return

    loading.value = true
    await axios.get(route('api.testimonials', { page: nextPage.value }))
        .then((res) => {
            testimonials.value = nextPage.value > 1 ? [...testimonials.value, ...res.data.data] : [...res.data.data]
            nextPage.value = res.data?.links?.next ? nextPage.value + 1 : 0
        })
        .catch(() => {
            setFailedAlertData({ message: 'Something unfortunate happened. Please try again later.' })
        })
        .finally(() => {
            loading.value = false
        })
}

onMounted(getTestimonials)

// SCRUM-430: a table.
const columns = [
    { label: 'By' },
    { label: 'Testimonial' },
    { label: 'On the site' },
    { label: 'Actions', srOnly: true },
]

function updated(testimonial) {
    const index = testimonials.value.findIndex((t) => t.id === testimonial.id)
    if (index !== -1) testimonials.value.splice(index, 1, testimonial)
    setSuccessAlertData({ message: testimonial.use ? 'The testimonial is now shown on the site.' : 'The testimonial is no longer shown on the site.' })
}
</script>

<template>
    <Head title="Testimonials" />

    <div class="my-8 w-full max-w-7xl mx-auto space-y-6 px-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Testimonials</h1>
            <div class="w-16 h-1 bg-blue-600 mt-2"></div>
            <p class="mt-2 text-sm text-gray-600">What people have written about TalkTherapy.</p>
        </div>

        <div class="relative space-y-4">
            <FormLoader class="mx-auto" :show="loading" :text="'getting testimonials'" />

            <AdminTable :columns="columns" caption="Testimonials" :empty="!loading && testimonials.length === 0" empty-text="No testimonials yet.">
                <AdminTestimonialRow
                    v-for="testimonial in testimonials"
                    :key="testimonial.id"
                    :testimonial="testimonial"
                    @updated="updated"
                    @failed="(message) => setFailedAlertData({ message })"
                />
            </AdminTable>

            <div v-if="nextPage && testimonials.length && !loading" class="flex justify-center pt-2">
                <PrimaryButton @click="getTestimonials">get more</PrimaryButton>
            </div>
        </div>
    </div>

    <Alert
        :show="alertData.show"
        :type="alertData.type"
        :message="alertData.message"
        :time="alertData.time"
        @close="clearAlertData"
    />
</template>

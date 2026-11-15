<script setup>
// SCRUM-430: one testimonial in the admin list (was TestimonialComponent, used only here). "Mark"
// shows it on the site, "unmark" stops showing it.
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import { computed, ref } from 'vue'

const props = defineProps({
    testimonial: { type: Object, required: true },
})

const emits = defineEmits(['updated', 'failed'])

const saving = ref(false)
const who = computed(() => `@${props.testimonial.addedby?.username ?? 'someone'}`)

async function toggle() {
    if (saving.value) return
    saving.value = true

    await axios.post(route('api.testimonials.mark', { testimonialId: props.testimonial.id }), { use: props.testimonial.use ? 0 : 1 })
        .then((res) => emits('updated', res.data.testimonial))
        .catch((err) => emits('failed', err.response?.data?.message ?? 'Something unfortunate happened. Please try again later.'))
        .finally(() => { saving.value = false })
}
</script>

<template>
    <AdminRow>
        <AdminCell label="By">
            <div class="font-medium text-gray-900">{{ testimonial.addedby?.fullName ?? testimonial.addedby?.name }}</div>
            <div class="text-xs text-gray-500">{{ who }}<template v-if="testimonial.by"> · {{ testimonial.by }}</template></div>
        </AdminCell>
        <AdminCell label="Testimonial">
            <p class="text-gray-700 whitespace-pre-line line-clamp-4 md:max-w-xl" :title="testimonial.content">{{ testimonial.content }}</p>
        </AdminCell>
        <AdminCell label="On the site" half>
            <AdminStatusPill :label="testimonial.use ? 'shown' : 'not shown'" :tone="testimonial.use ? 'green' : 'gray'" />
        </AdminCell>
        <AdminCell actions>
            <AdminActionButton :disabled="saving" :aria-label="`${testimonial.use ? 'Stop showing' : 'Show on site'}: the testimonial of ${who}`" @click="toggle">
                {{ testimonial.use ? 'Stop showing' : 'Show on site' }}
            </AdminActionButton>
        </AdminCell>
    </AdminRow>
</template>

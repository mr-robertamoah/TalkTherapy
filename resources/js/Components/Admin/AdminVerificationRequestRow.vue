<script setup>
// SCRUM-430: one counsellor verification request in the admin list (was the VerificationRequest
// card). "Review" opens the licence review modal, which answers it.
import AdminRow from '@/Components/Admin/AdminRow.vue'
import AdminCell from '@/Components/Admin/AdminCell.vue'
import AdminStatusPill from '@/Components/Admin/AdminStatusPill.vue'
import AdminActionButton from '@/Components/Admin/AdminActionButton.vue'
import Avatar from '@/Components/Avatar.vue'
import VerificationRequestModal from '@/Components/VerificationRequestModal.vue'
import { computed, ref } from 'vue'

const props = defineProps({
    request: { type: Object, required: true },
})

const emits = defineEmits(['onResponse'])

const view = ref(false)
const who = computed(() => props.request.counsellor.username ? `@${props.request.counsellor.username}` : props.request.counsellor.name)
const status = computed(() => ({
    PENDING: { label: 'waiting', tone: 'amber' },
    ACCEPTED: { label: 'accepted', tone: 'green' },
    REJECTED: { label: 'rejected', tone: 'red' },
}[props.request.status] ?? { label: (props.request.status ?? '').toLowerCase(), tone: 'gray' }))
</script>

<template>
    <AdminRow>
        <AdminCell label="Counsellor">
            <div class="flex items-center gap-3 min-w-0">
                <Avatar class="shrink-0" aria-hidden="true" :avatar-text="'...'" :size="32" :src="request.counsellor.avatar ?? ''" />
                <div class="min-w-0">
                    <div class="font-medium text-gray-900 capitalize truncate">{{ request.counsellor.name }}</div>
                    <div v-if="request.counsellor.username" class="text-xs text-gray-500 truncate">@{{ request.counsellor.username }}</div>
                </div>
            </div>
        </AdminCell>
        <AdminCell label="Status" half>
            <AdminStatusPill :label="status.label" :tone="status.tone" />
        </AdminCell>
        <AdminCell label="Sent" half>
            <span class="whitespace-nowrap">{{ request.createdAt }}</span>
        </AdminCell>
        <AdminCell actions>
            <AdminActionButton :tone="request.status === 'PENDING' ? 'primary' : 'default'" :aria-label="`${request.status === 'PENDING' ? 'Review' : 'View'}: the verification request of ${who}`" @click="view = true">
                {{ request.status === 'PENDING' ? 'Review' : 'View' }}
            </AdminActionButton>
        </AdminCell>
    </AdminRow>

    <VerificationRequestModal
        :show="view"
        :request="request"
        @close="() => view = false"
        @on-response="(response) => emits('onResponse', response)"
    />
</template>

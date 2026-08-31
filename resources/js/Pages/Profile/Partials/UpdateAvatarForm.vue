<script setup>
import ImageUploadField from '@/Components/ImageUploadField.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

// Avatar has its own dedicated self-service endpoint (SCRUM-182/TT-10.6), decoupled from the
// other profile fields UpdateProfileInformationForm.vue manages -- so unlike that combined form,
// this one auto-submits as soon as a file is picked or removed, rather than needing its own
// separate "save" button for a single-field change.

const currentUser = computed(() => usePage().props.auth.user)

const form = useForm({
    avatar: null,
    deleteAvatar: false,
})

function submit() {
    // Guards against a fast remove-then-restore (or similar) sequence firing a second request
    // before the first's response comes back and updates existingUrl/removed -- without this,
    // the later action would silently resolve to a no-op once the earlier request completes
    // (caught in TT-10.7's review). ImageUploadField's own :disabled="form.processing" below is
    // the primary guard (it stops the click from ever changing form.avatar/deleteAvatar in the
    // first place); this is defense-in-depth for any path that could still reach submit().
    if (form.processing) return

    form.post(route('profile.avatar.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('avatar', 'deleteAvatar'),
    })
}

watch(() => form.avatar, (value) => {
    if (value) submit()
})

watch(() => form.deleteAvatar, (value) => {
    if (value) submit()
})
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <ImageUploadField
            v-model="form.avatar"
            v-model:removed="form.deleteAvatar"
            :existing-url="currentUser?.avatar ?? ''"
            shape="circle"
            :size="100"
            label="avatar"
            input-id="user-avatar"
            :error="form.errors.avatar"
            :disabled="form.processing"
            dark
        />
        <p v-if="form.processing" class="text-xs text-white/80">saving...</p>
    </div>
</template>

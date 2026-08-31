<script setup>
import { computed, ref, watch } from "vue";
import Avatar from "./Avatar.vue";
import CameraIcon from "@/Icons/CameraIcon.vue";
import InputError from "./InputError.vue";

// Shared upload control used by every image-upload surface in the app (counsellor
// avatar/cover, organization logo, user avatar - SCRUM-182/TT-10) so they share one
// look and interaction instead of three bespoke implementations. A slot's state is
// fully described by `modelValue` (a newly staged File, or null) and `removed`
// (whether an existing image should be deleted on submit) - picking a new file always
// supersedes a pending removal. Note: clearing a just-staged file (the "x" badge while
// modelValue is set) only cancels that selection - it does not re-mark a prior
// existingUrl for removal even if the user had removed it right before staging the
// replacement. Treated as an acceptable simplification rather than a 3-state undo
// stack; re-removing the original after cancelling a replacement is one extra click.

const props = defineProps({
    modelValue: {
        type: File,
        default: null,
    },
    removed: {
        type: Boolean,
        default: false,
    },
    existingUrl: {
        type: String,
        default: '',
    },
    shape: {
        type: String,
        default: 'rect', // 'rect' | 'circle'
    },
    size: {
        type: Number,
        default: 120, // only used when shape === 'circle'
    },
    label: {
        type: String,
        required: true, // e.g. 'avatar', 'cover image', 'logo'
    },
    error: {
        type: String,
        default: '',
    },
    accept: {
        type: String,
        default: 'image/*',
    },
    inputId: {
        type: String,
        required: true,
    },
    emptyText: {
        type: String,
        default: '', // shape === 'rect' only; shape === 'circle' uses Avatar.vue's own fallback text
    },
})

const emit = defineEmits(['update:modelValue', 'update:removed'])

const input = ref(null)
let objectUrl = null

const previewUrl = computed(() => {
    if (objectUrl) {
        URL.revokeObjectURL(objectUrl)
        objectUrl = null
    }

    if (props.modelValue) {
        objectUrl = URL.createObjectURL(props.modelValue)
        return objectUrl
    }

    if (props.removed) return ''

    return props.existingUrl
})

const canRemoveOrRestore = computed(() => !!props.existingUrl && !props.modelValue)

const changeLabel = computed(() => previewUrl.value ? `change ${props.label}` : `add ${props.label}`)

function triggerFileInput() {
    input.value?.click()
}

function onFileSelected(e) {
    if (!e.target.files?.length) return

    emit('update:modelValue', e.target.files[0])
    emit('update:removed', false)
}

function toggleRemoveOrRestore() {
    if (props.modelValue) {
        if (input.value) input.value.value = ''
        emit('update:modelValue', null)
        return
    }

    emit('update:removed', !props.removed)
}
</script>

<template>
    <div class="h-full flex flex-col">
        <div
            class="group relative overflow-hidden bg-gray-100 ring-1 ring-gray-200"
            :class="shape === 'circle' ? 'rounded-full shrink-0 self-start' : 'rounded-lg w-full flex-1 min-h-0'"
        >
            <Avatar
                v-if="shape === 'circle'"
                :size="size"
                :src="previewUrl ?? ''"
                :alt="label"
                :avatar-text="label"
            />
            <template v-else>
                <img
                    v-if="previewUrl"
                    :src="previewUrl"
                    :alt="label"
                    class="w-full h-full object-cover"
                >
                <div v-else class="text-sm w-full h-full flex items-center justify-center text-gray-400">
                    {{ emptyText || `no ${label}` }}
                </div>
            </template>

            <button
                type="button"
                :title="changeLabel"
                :aria-label="changeLabel"
                @click="triggerFileInput"
                class="camera-overlay absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/60 text-white/0 group-hover:text-white transition-colors duration-150"
                :class="shape === 'circle' ? 'rounded-full' : 'rounded-lg'"
            >
                <CameraIcon class="w-6 h-6" />
            </button>

            <button
                v-if="canRemoveOrRestore || modelValue"
                type="button"
                :title="modelValue ? `remove ${label}` : (removed ? `restore ${label}` : `remove ${label}`)"
                :aria-label="modelValue ? `remove ${label}` : (removed ? `restore ${label}` : `remove ${label}`)"
                @click="toggleRemoveOrRestore"
                class="absolute top-1.5 right-1.5 z-10 flex items-center justify-center w-6 h-6 rounded-full text-white text-sm shadow leading-none transition-colors duration-150"
                :class="removed ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'"
            >{{ removed && !modelValue ? '↺' : '×' }}</button>
        </div>

        <button
            type="button"
            @click="triggerFileInput"
            class="mt-2 block text-xs sm:text-sm text-gray-600 hover:text-gray-900 underline underline-offset-2"
        >{{ changeLabel }}</button>

        <InputError v-if="error" class="mt-1" :message="error" />

        <input
            ref="input"
            type="file"
            :id="inputId"
            :name="inputId"
            class="hidden"
            :accept="accept"
            @change="onFileSelected"
        >
    </div>
</template>

<style scoped>
/* CameraIcon.vue's SVG path has a hardcoded (non-currentColor) fill, so the
   text-white/0 -> group-hover:text-white opacity trick above doesn't reach it
   without this override. */
.camera-overlay :deep(svg) {
    fill: currentColor;
}
</style>

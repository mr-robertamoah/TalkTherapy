<script setup>
import { computed, ref, watch } from "vue";
import Avatar from "./Avatar.vue";
import CameraIcon from "@/Icons/CameraIcon.vue";
import InputError from "./InputError.vue";
import { ALLOWED_MIME_TYPES, MAX_SIZE_KB } from "@/Constants/imageUploadLimits";

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
    dark: {
        type: Boolean,
        default: false, // true when placed on a dark/saturated background (e.g. a gradient hero)
    },
    disabled: {
        type: Boolean,
        default: false, // true while the consuming form has a submission in flight -- without
        // this, a fast remove-then-restore (or similar) click sequence can fire a second request
        // before the first's response updates `existingUrl`/`removed`, silently dropping the
        // user's later action once the earlier request resolves (caught in TT-10.7's review).
    },
})

const emit = defineEmits(['update:modelValue', 'update:removed'])

const input = ref(null)
const clientError = ref('')
const displayError = computed(() => clientError.value || props.error)
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
    if (props.disabled) return

    input.value?.click()
}

function onFileSelected(e) {
    if (!e.target.files?.length) return

    const file = e.target.files[0]

    if (!ALLOWED_MIME_TYPES.includes(file.type)) {
        clientError.value = `${props.label} must be a jpg, png, or webp image.`
        if (input.value) input.value.value = ''
        return
    }

    if (file.size > MAX_SIZE_KB * 1024) {
        clientError.value = `${props.label} must be smaller than ${MAX_SIZE_KB / 1024}MB.`
        if (input.value) input.value.value = ''
        return
    }

    clientError.value = ''
    emit('update:modelValue', file)
    emit('update:removed', false)
}

function toggleRemoveOrRestore() {
    if (props.disabled) return

    clientError.value = ''

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
                <div v-else class="text-sm w-full h-full flex items-center justify-center" :class="dark ? 'text-white/70' : 'text-gray-400'">
                    {{ emptyText || `no ${label}` }}
                </div>
            </template>

            <button
                type="button"
                :disabled="disabled"
                :title="changeLabel"
                :aria-label="changeLabel"
                @click="triggerFileInput"
                class="camera-overlay absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/60 text-white/0 group-hover:text-white transition-colors duration-150 disabled:cursor-not-allowed"
                :class="shape === 'circle' ? 'rounded-full' : 'rounded-lg'"
            >
                <CameraIcon class="w-6 h-6" />
            </button>

            <button
                v-if="canRemoveOrRestore || modelValue"
                type="button"
                :disabled="disabled"
                :title="modelValue ? `remove ${label}` : (removed ? `restore ${label}` : `remove ${label}`)"
                :aria-label="modelValue ? `remove ${label}` : (removed ? `restore ${label}` : `remove ${label}`)"
                @click="toggleRemoveOrRestore"
                class="absolute top-1.5 right-1.5 z-10 flex items-center justify-center w-6 h-6 rounded-full text-white text-sm shadow leading-none transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-50"
                :class="removed ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'"
            >{{ removed && !modelValue ? '↺' : '×' }}</button>
        </div>

        <button
            type="button"
            :disabled="disabled"
            @click="triggerFileInput"
            class="mt-2 block text-xs sm:text-sm underline underline-offset-2 disabled:cursor-not-allowed disabled:no-underline"
            :class="[dark ? 'text-white/90 hover:text-white' : 'text-gray-600 hover:text-gray-900', disabled ? 'opacity-50' : '']"
        >{{ changeLabel }}</button>

        <InputError v-if="displayError" class="mt-1" :message="displayError" />

        <input
            :disabled="disabled"
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

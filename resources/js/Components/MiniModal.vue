<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import useFocusTrap from '@/Composables/useFocusTrap';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
    // SCRUM-392: the dialog's name for screen readers -- the id of its heading, or a label.
    labelledby: {
        type: String,
        default: '',
    },
    label: {
        type: String,
        default: '',
    },
});

const emit = defineEmits(['close']);

// SCRUM-394: focus stays inside while it's open, and returns to the opener when it closes.
const dialog = ref(null);
const { isTop } = useFocusTrap(dialog, () => props.show);

watch(
    () => props.show,
    () => {
        if (props.show) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = null;
        }
    }
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const closeOnEscape = (e) => {
    // Only the dialog on top closes (SCRUM-394 review: one Escape used to close every open dialog).
    if (e.key === 'Escape' && props.show && isTop()) {
        close();
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = null;
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <Teleport to="body">
        <Transition leave-active-class="duration-200">
            <div v-show="show" class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50 w-full h-full flex items-center justify-center" scroll-region>
                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0"
                    enter-to-class="opacity-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-show="show" class="fixed inset-0 transform transition-all" @click="close">
                        <div class="absolute inset-0 bg-gray-500 opacity-75" />
                    </div>
                </Transition>

                <Transition
                    enter-active-class="ease-out duration-300"
                    enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-200"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                >
                    <div
                        v-show="show"
                        class="mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all focus:outline-none sm:mx-auto w-[80%] sm:w-[70%] md:w-[50%] p-6"
                        :class="maxWidthClass"
                        ref="dialog"
                        tabindex="-1"
                        role="dialog"
                        aria-modal="true"
                        :aria-labelledby="labelledby || undefined"
                        :aria-label="labelledby ? undefined : (label || undefined)"
                    >
                        <slot v-if="show" />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

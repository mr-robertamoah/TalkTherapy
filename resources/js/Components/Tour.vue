<template>
    <div
        class="w-full"
        v-bind="$attrs"
        :id="`tour-${tour.id}`"
    >
        <div 
            :style="computedStyles"
            class="z-50 bg-white shadow-xl border border-gray-200 text-gray-800 p-6 rounded-xl w-full sm:w-[320px] mx-auto relative backdrop-blur-sm"
        >
            <!-- Arrow -->
            <div 
                class="absolute w-4 h-4 bg-white border transform rotate-45"
                :class="arrowClasses"
            ></div>
            
            <!-- Header -->
            <div class="mb-4">
                <div class="text-xs font-medium text-blue-600 uppercase tracking-wide mb-2">{{ tourName }}</div>
                <div class="text-lg font-bold text-gray-900">{{ tour.name }}</div>
                <div class="w-12 h-1 bg-blue-600 rounded-full mt-2"></div>
            </div>
            
            <!-- Content -->
            <div class="text-sm text-gray-700 leading-relaxed mb-4">{{ tour.description }}</div>

            <!-- Messages -->
            <div v-if="tour.messages?.length" class="mb-4">
                <template
                    v-for="(msg, idx) in tour.messages"
                    :key="idx"
                >
                    <div
                        v-if="!msg.condition ||  validateMessage(msg, user)"
                        class="text-xs text-blue-700 bg-blue-50 px-3 py-2 rounded-lg mb-2 border-l-4 border-blue-400"
                    >{{ msg.message }}</div>
                </template>
            </div>
            
            <!-- Actions -->
            <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                <button
                    @click="clickedCancel"
                    class="text-sm px-4 py-2 text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-lg transition-colors font-medium"
                >
                    Cancel
                </button>
                <div class="flex items-center space-x-2">
                    <button
                        v-if="hasPrevious"
                        @click="clickedPrevious"
                        class="text-sm px-4 py-2 text-blue-600 hover:text-blue-800 hover:bg-blue-50 rounded-lg transition-colors font-medium flex items-center space-x-1"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                        <span>Previous</span>
                    </button>
                    <button
                        v-if="hasNext"
                        @click="clickedNext"
                        class="text-sm px-4 py-2 bg-blue-600 text-white hover:bg-blue-700 rounded-lg transition-colors font-medium flex items-center space-x-1"
                    >
                        <span>Next</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="bg-black/20 backdrop-blur-sm fixed inset-0 z-40 transition-opacity"
        v-on:click="() => clickedCancel()"
    ></div>
</template>
  
<script setup>
    import useGuidedTours from '@/Composables/useGuidedTours';
import { computed, createApp, onMounted } from 'vue';
  
    const { CONDITION_CALLABLES, CONDITION_NAMES } = useGuidedTours()

    const props = defineProps({
        position: {
            type: String,
            default: 'top',
            validator: value => ['top', 'bottom', 'left', 'right'].includes(value)
        },
        tour: {
            default: null
        },
        tourName: {
            type: String,
            default: null
        },
        hasNext: {
            type: Boolean,
            default: true
        },
        hasPrevious: {
            type: Boolean,
            default: true
        },
        nextCallable: {
            type: Function,
            default: () => null
        },
        previousCallable: {
            type: Function,
            default: () => null
        },
        cancelCallable: {
            type: Function,
            default: () => null
        },
        user: {
            type: Object,
            required: null
        }
    });

    onMounted(()=> {
        if (!props.tour.scroll) return

        const tourElement = document.getElementById(`tour-${props.tour.id}`)

        if (tourElement)
            tourElement.scrollIntoView({ smooth: true })
    })
  
    const computedStyles = computed(() => {
        const styles = {
            position: 'absolute'
        };
    
        switch (props.position) {
        case 'top':
            styles.top = '-10px';
            styles.left = '50%';
            styles.transform = 'translateX(-50%)';
            break;
        case 'bottom':
            styles.bottom = '-10px';
            styles.left = '50%';
            styles.transform = 'translateX(-50%)';
            break;
        case 'left':
            styles.left = '-10px';
            styles.top = '50%';
            styles.transform = 'translateY(-50%)';
            break;
        case 'right':
            styles.right = '-10px';
            styles.top = '50%';
            styles.transform = 'translateY(-50%)';
            break;
        }
    
        return styles;
    });

    const arrowClasses = computed(() => {
        switch (props.position) {
        case 'top':
            return 'bottom-[-8px] left-1/2 -translate-x-1/2 border-t-gray-200 border-l-gray-200';
        case 'bottom':
            return 'top-[-8px] left-1/2 -translate-x-1/2 border-b-gray-200 border-r-gray-200';
        case 'left':
            return 'right-[-8px] top-1/2 -translate-y-1/2 border-t-gray-200 border-l-gray-200';
        case 'right':
            return 'left-[-8px] top-1/2 -translate-y-1/2 border-b-gray-200 border-r-gray-200';
        default:
            return 'bottom-[-8px] left-1/2 -translate-x-1/2 border-t-gray-200 border-l-gray-200';
        }
    });

    function clickedCancel() {
        if (!props.cancelCallable) return
        
        props.cancelCallable()
    }

    function clickedNext() {
        if (!props.nextCallable) return

        props.nextCallable()
    }

    function clickedPrevious() {
        if (!props.previousCallable) return

        props.previousCallable()
    }

    function validateMessage(msg, data) {
        if (!Object.values(CONDITION_NAMES).includes(msg.condition)) return false

        return CONDITION_CALLABLES[msg.condition](data)
    }
</script>
  
<style scoped>

</style>
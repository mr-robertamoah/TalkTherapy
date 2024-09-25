<template>
    <div
        class="w-full"
        v-bind="$attrs"
        :id="`tour-${tour.id}`"
    >
        <div 
            :style="computedStyles"
            class="z-50 bg-white shadow shadow-neutral-800 text-neutral-700 p-4 rounded w-full sm:w-[80%] mx-auto max-w-sm">
            <div class="mb-4 text-xs text-gray-600">{{ tourName }}</div>
            
            <div class="text-base font-bold text-center">{{ tour.name }}</div>
            <hr class="mx-5 mt-2 mb-4">
            <div class="text-sm text-pretty w-[90%] mx-auto mb-5">{{ tour.description }}</div>

            <div v-if="tour.messages?.length">
                <template
                    v-for="(msg, idx) in tour.messages"
                    :key="idx"
                >
                    <div
                        v-if="!msg.condition ||  validateMessage(msg, user)"
                        class="text-xs text-gray-600 px-2 pb-2"
                    >{{ msg.message }}</div>
                </template>
            </div>
            <hr class="mt-2 mb-4">
            <div
                class="flex items-center w-full sm:w-[80%] mx-auto"
                :class="[(hasNext || hasPrevious) ? 'justify-between' : 'justify-start']"
            >
                <div
                    class="text-sm py-1 px-2 rounded text-gray-600 cursor-pointer hover:bg-gray-200 transition duration-100"
                    v-on:click="() => clickedCancel()">cancel</div>
                <div class="flex items-center gap-5">
                    <div
                        v-if="hasPrevious"
                        class="text-sm py-1 px-2 rounded text-gray-600 cursor-pointer hover:bg-gray-200 transition duration-100"
                        v-on:click="() => clickedPrevious()">previous</div>
                    <div
                        v-if="hasNext"
                        class="text-sm py-1 px-2 rounded text-gray-600 cursor-pointer hover:bg-gray-200 transition duration-100"
                        v-on:click="() => clickedNext()">next</div>
                </div>
            </div>
        </div>
    </div>

    <div
        class="bg-transparent fixed top-0 bottom-0 left-0 right-0 z-40"
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
            styles.top = '0';
            styles.left = '50%';
            styles.transform = 'translateX(-50%)';
            break;
        case 'bottom':
            styles.bottom = '0';
            styles.left = '50%';
            styles.transform = 'translateX(-50%)';
            break;
        case 'left':
            styles.left = '0';
            styles.top = '50%';
            styles.transform = 'translateY(-50%)';
            break;
        case 'right':
            styles.right = '0';
            styles.top = '50%';
            styles.transform = 'translateY(-50%)';
            break;
        }
    
        return styles;
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
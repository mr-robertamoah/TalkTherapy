<template>
    <div class="hidden">
        start tour
    </div>
</template>

<script setup>
    import { createApp, ref, watch } from 'vue';
    import Tour from './Tour.vue';


    let emits = defineEmits(['endTour'])

    let currentTour = ref(null)
    let tourAppInstance = ref(null);
    let tourIdx = ref(0);

    let props = defineProps({
        start: {
            type: Boolean,
            default: false
        },
        tours: {
            type: Object,
            default: null
        },
        user: {
            type: Object,
            required: null
        }
    })

    watch(() => props.start, () => {
        if (props.start)
            startTour()
    })

    function startTour() {
        currentTour.value = props.tours.howToSteps[tourIdx.value]
        showTour()
    }

    function showTour() {
        const targetElement = document.getElementById(currentTour.value.elementId);
        
        if (targetElement && currentTour.value) {
            tourAppInstance.value = createApp(Tour, getTourProps());
            
            tourAppInstance.value.mount(targetElement);
        }
    }

    function getTourProps() {
        return {
            position: 'top',
            tour: currentTour.value,
            tourName: props.tours.name,
            hasNext: tourIdx.value + 1 < props.tours.howToSteps.length,
            hasPrevious: tourIdx.value > 0,
            nextCallable: () => {
                mountNext()
            },
            previousCallable: () => {
                mountPrevious()
            },
            cancelCallable: () => {
                unmountTour()
                endTour()
            },
        }
    }

    function mountNext() {
        if (!tourAppInstance.value) return

        tourIdx.value = props.tours.howToSteps.findIndex((value) => value.id == currentTour.value.id)
        unmountTour()
        
        if (tourIdx.value + 1 > props.tours.howToSteps.length)
            return endTour()

        tourIdx.value += 1
        currentTour.value = props.tours.howToSteps[tourIdx.value]
        showTour()
    }

    function mountPrevious() {
        if (!tourAppInstance.value) return

        tourIdx.value = props.tours.howToSteps.findIndex((value) => value.id == currentTour.value.id)
        unmountTour()

        if (tourIdx.value - 1 < 0)
            return endTour()

        tourIdx.value -= 1
        currentTour.value = props.tours.howToSteps[tourIdx.value]
        showTour()
    }

    function unmountTour() {
        if (!tourAppInstance.value) return

        currentTour.value = null
        tourAppInstance.value.unmount()
        tourAppInstance.value = null
    }

    function endTour() {
        tourIdx.value = 0
        currentTour.value = null
        tourAppInstance.value = null
        emits('endTour')
    }
</script>

<style lang="scss" scoped>

</style>
<template>
    <div
        :style="computedStyles"
        class="z-50 w-full"
        v-bind="$attrs"
    >
        <div class="bg-white shadow shadow-neutral-800 text-neutral-700 p-4 rounded w-full sm:w-[80%] mx-auto max-w-sm">
            <div class="mb-4 text-xs text-gray-600">{{ tourName }}</div>
            
            <div class="text-base font-bold text-center">{{ tour.name }}</div>
            <hr class="mx-5 mt-2 mb-4">
            <div class="text-sm text-justify mb-5">{{ tour.description }}</div>

            <div
                class="flex items-center w-full sm:w-[80%] mx-auto"
                :class="[hasNext ? 'justify-between' : 'justify-start']"
            >
                <div
                    class="text-sm py-1 px-2 rounded text-gray-600 cursor-pointer hover:bg-gray-200 transition duration-100"
                    v-on:click="() => clickedCancel()">cancel</div>
                <div
                    v-if="hasNext"
                    class="text-sm py-1 px-2 rounded text-gray-600 cursor-pointer hover:bg-gray-200 transition duration-100"
                    v-on:click="() => clickedNext()">next</div>
            </div>
        </div>
    </div>

    <div
        class="bg-transparent fixed top-0 bottom-0 left-0 right-0 z-40"
        v-on:click="() => clickedCancel()"
    ></div>
</template>
  
<script setup>
    import { computed, createApp } from 'vue';
    import PrimaryButton from './PrimaryButton.vue';
  
    const props = defineProps({
        position: {
        type: String,
        default: 'top', // default position
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
        nextCallable: {
            type: Function,
            default: () => null
        },
        cancelCallable: {
            type: Function,
            default: () => null
        },
    });
  
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
</script>
  
<style scoped>

</style>
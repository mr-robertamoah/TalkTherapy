<script setup>
import { watch } from 'vue';

const props = defineProps({
    show: false,
    time: 1000,
    message: '',
    type: '',
})

const emit = defineEmits(['close'])

watch(
    () => props.show,
    () => {
        setTimeout(() => {
            emit('close')
        }, props.time)
    }
)

function clickedClose() {
    emit('close')
}

</script>

<template>
    <div
        class="transition duration-100 ease-in-out w-full mt-12 fixed top-0 z-[1000] flex justify-center items-center p-2"
        :class="[show ? 'opacity-100 translate-y-0 visible' : 'translate-y-5 opacity-25 invisible']"
    >
        <div
            class="flex justify-center relative items-center min-w-[300px] sm:w-[80%] md:w-[60%] mx-auto rounded-lg min-h-[50px]"
            :class="[type == 'failed' ? 'bg-red-600 text-red-300' : 'bg-green-600 text-green-300']"
        >
            <div>{{ message }}</div>
            <div 
                @click="clickedClose"
                class="absolute -top-2 -right-2 w-7 h-7 leading-none text-sm rounded-full p-2 cursor-pointer flex justify-center items-center border-2"
                :class="[type == 'failed' ? 'bg-red-300 border-red-700 text-red-700' : 'bg-green-300 border-green-700 text-green-700']"
            >x</div>
        </div>
    </div>
</template>
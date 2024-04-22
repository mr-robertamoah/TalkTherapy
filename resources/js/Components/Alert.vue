<script setup>
import { watch } from 'vue';

const emits = defineEmits(['clicked', 'close'])

const props = defineProps({
    show: false,
    time: 3000,
    message: '',
    type: '',
    clickedData: {},
})

watch(
    () => props.show,
    () => {
        setTimeout(() => {
            emits('close')
        }, props.time)
    }
)

function clickedClose() {
    emits('close')
}

</script>

<template>
    <Teleport to="body">
        <div
            class="transition duration-100 cursor-pointer ease-in-out w-full mt-12 fixed top-0 z-[1000] flex justify-center items-center p-4   "
            :class="[show ? 'opacity-100 translate-y-0 visible' : 'translate-y-5 opacity-25 invisible']"
            @click.self="() => {
                emits('clicked', clickedData)
            }"
        >
            <div
                class="flex justify-center relative items-center min-w-[300px] sm:w-[80%] md:w-[60%] mx-auto rounded-lg min-h-[50px] p-2"
                :class="[type == 'failed' ? 'bg-red-600 text-red-200' : 'bg-green-600 text-green-200']"
            >
                <div 
                    @click.self="() => {
                        emits('clicked', clickedData)
                    }"
                >{{ message }}</div>
                <div 
                    @click="clickedClose"
                    class="absolute -top-2 -right-2 w-7 h-7 leading-none text-sm rounded-full p-2 cursor-pointer flex justify-center items-center border-2"
                    :class="[type == 'failed' ? 'bg-red-300 border-red-700 text-red-700' : 'bg-green-300 border-green-700 text-green-700']"
                >x</div>
            </div>
        </div>
    </Teleport>
</template>
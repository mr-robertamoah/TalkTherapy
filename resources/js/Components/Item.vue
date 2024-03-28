<template>
    <div
        :title="item?.about ?? ''"
        @click="() => clickedItem(item)"
        class="capitalize rounded text-sm p-2 min-w-[100px] text-stone-700 bg-stone-300 select-none transition duration-75 cursor-pointer hover:bg-stone-600 hover:text-white text-center"
    >{{ item.name }}</div>
</template>

<script setup>

const emits = defineEmits(['click'])

defineProps({
    item: {
        default: null
    }
})

function clickedItem(item) {
    emits('click', item)
}
</script>
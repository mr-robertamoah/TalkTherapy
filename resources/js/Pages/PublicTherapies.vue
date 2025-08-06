<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import MiniTherapyComponent from '@/Components/MiniTherapyComponent.vue'
import { Head } from '@inertiajs/vue3'
import { onBeforeMount, ref } from 'vue'

const props = defineProps({
  therapies: { default: () => ({ data: [], links: {}, meta: {} }) }
})

const therapies = ref({ data: [], page: 1 })
const loading = ref(false)

onBeforeMount(() => {
  if (props.therapies?.data?.length) {
    therapies.value.data = [...props.therapies.data]
    updatePage(props.therapies)
  } else {
    getTherapies()
  }
})

function updatePage(res) {
  if (res.links?.next) therapies.value.page += 1
  else therapies.value.page = 0
}

async function getTherapies() {
  if (!therapies.value.page) return
  
  loading.value = true
  await axios.get(route('api.therapies.public', { page: therapies.value.page }))
    .then((res) => {
      if (therapies.value.page === 1) therapies.value.data = []
      
      therapies.value.data = [
        ...therapies.value.data,
        ...res.data.data
      ]
      
      updatePage(res.data)
    })
    .catch((err) => {
      console.log(err)
    })
    .finally(() => {
      loading.value = false
    })
}
</script>

<template>
  <Head title="Public Therapies" />

  <AuthenticatedLayout>
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Public Therapies
      </h2>
    </template>

    <div class="py-12">
      <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
          <div class="p-6">
            <div class="text-gray-900 text-lg font-semibold mb-6">
              Discover Available Therapy Sessions
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8">
              <MiniTherapyComponent
                v-for="therapy in therapies.data"
                :key="`${therapy.type || 'individual'}-${therapy.id}`"
                :therapy="therapy"
                class="w-full"
              />
            </div>

            <div v-if="!therapies.data.length && !loading" class="text-center text-gray-600 py-12">
              No public therapies available at the moment.
            </div>

            <div v-if="loading" class="text-center text-blue-600 py-8">
              Loading therapies...
            </div>

            <div v-if="therapies.page > 0 && !loading" class="text-center mt-8">
              <button
                @click="getTherapies"
                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
              >
                Load More
              </button>
            </div>

            <div v-if="therapies.page === 0 && therapies.data.length" class="text-center text-gray-500 mt-8">
              No more therapies to load
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
<template>
  <div class="bg-white p-6 w-full" id="therapy_details">
    <div class="text-gray-600 tracking-wide font-semibold mb-6 text-xl">Details</div>
    
    <!-- Main Details Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
      <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-lg border border-gray-200">
        <span class="text-sm font-medium text-gray-600">Payment Type</span>
        <p class="text-lg font-semibold text-gray-800 capitalize mt-1">{{ therapy.paymentType }}</p>
      </div>
      
      <div class="bg-gradient-to-br from-stone-50 to-stone-100 p-4 rounded-lg border border-stone-200">
        <span class="text-sm font-medium text-stone-600">Session Type</span>
        <p class="text-lg font-semibold text-gray-800 capitalize mt-1">{{ therapy.sessionType }}</p>
      </div>
      
      <div class="bg-gradient-to-br from-slate-50 to-slate-100 p-4 rounded-lg border border-slate-200">
        <span class="text-sm font-medium text-slate-600">Status</span>
        <p class="text-lg font-semibold text-gray-800 capitalize mt-1">{{ therapy.status }}</p>
      </div>
      
      <!-- Group therapy specific details -->
      <template v-if="therapyType === 'group'">
        <div class="bg-gradient-to-br from-zinc-50 to-zinc-100 p-4 rounded-lg border border-zinc-200">
          <span class="text-sm font-medium text-zinc-600">Max Sessions</span>
          <p class="text-lg font-semibold text-gray-800 mt-1">{{ therapy.maxSessions }}</p>
        </div>
        
        <div class="bg-gradient-to-br from-neutral-50 to-neutral-100 p-4 rounded-lg border border-neutral-200">
          <span class="text-sm font-medium text-neutral-600">Max Users</span>
          <p class="text-lg font-semibold text-gray-800 mt-1">{{ therapy.maxUsers }}</p>
        </div>
        
        <div class="bg-gradient-to-br from-gray-50 to-gray-100 p-4 rounded-lg border border-gray-200">
          <span class="text-sm font-medium text-gray-600">Max Counsellors</span>
          <p class="text-lg font-semibold text-gray-800 mt-1">{{ therapy.maxCounsellors }}</p>
        </div>
      </template>
    </div>

    <!-- Cases Section -->
    <div class="bg-gradient-to-br from-stone-50 to-stone-100 p-6 rounded-xl border border-stone-200">
      <div class="flex items-center mb-4">
        <div class="w-2 h-6 bg-stone-600 rounded-full mr-3"></div>
        <h3 class="text-lg font-semibold text-gray-800">Therapy Cases</h3>
      </div>
      
      <div class="flex flex-wrap gap-3">
        <template v-if="therapy.cases?.length">
          <div
            v-for="l in therapy.cases"
            :key="l.id"
            :title="l.about ?? ''"
            class="bg-white px-4 py-3 rounded-lg shadow-sm border border-stone-200 hover:shadow-md transition-all duration-200 cursor-pointer hover:border-stone-300"
          >
            <span class="text-gray-700 font-medium capitalize">{{ l.name }}</span>
            <p v-if="l.about" class="text-sm text-gray-500 mt-1">{{ l.about }}</p>
          </div>
        </template>
        <div v-else class="w-full text-center py-8 text-gray-500">
          No therapy cases have been added yet.
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import NameAndValue from '@/Components/NameAndValue.vue'

defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
})
</script>
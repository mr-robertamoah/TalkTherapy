<template>
  <div class="bg-white p-6 w-full" id="therapy_other_details">
    <div class="text-gray-600 tracking-wide font-semibold mb-6 text-xl">Other Details</div>
    
    <div class="bg-gradient-to-br from-gray-50 to-slate-100 p-6 rounded-xl border border-gray-200">
      <div class="flex items-center mb-4">
        <div class="w-2 h-6 bg-gray-600 rounded-full mr-3"></div>
        <h3 class="text-lg font-semibold text-gray-800">Configuration Settings</h3>
      </div>
      
      <div class="text-sm text-gray-600 mb-4 bg-gray-50 p-3 rounded-lg border border-gray-200">
        <span class="font-medium text-gray-700">Legend:</span> 
        <span class="inline-flex items-center ml-2">
          <span class="w-3 h-3 bg-green-500 rounded-full mr-1"></span> Enabled
        </span>
        <span class="inline-flex items-center ml-3">
          <span class="w-3 h-3 bg-red-500 rounded-full mr-1"></span> Disabled
        </span>
      </div>
      
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
          <div>
            <span class="font-medium text-gray-800 capitalize">Anonymous</span>
            <p class="text-sm text-gray-500">Hide user identity</p>
          </div>
          <div class="flex items-center">
            <span :class="therapy.anonymous ? 'bg-green-500' : 'bg-red-500'" class="w-4 h-4 rounded-full"></span>
          </div>
        </div>
        
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
          <div>
            <span class="font-medium text-gray-800 capitalize">In-Person Sessions</span>
            <p class="text-sm text-gray-500">Allow physical meetings</p>
          </div>
          <div class="flex items-center">
            <span :class="therapy.allowInPerson ? 'bg-green-500' : 'bg-red-500'" class="w-4 h-4 rounded-full"></span>
          </div>
        </div>
        
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
          <div>
            <span class="font-medium text-gray-800 capitalize">Public Therapy</span>
            <p class="text-sm text-gray-500">Visible to all users</p>
          </div>
          <div class="flex items-center">
            <span :class="therapy.public ? 'bg-green-500' : 'bg-red-500'" class="w-4 h-4 rounded-full"></span>
          </div>
        </div>
        
        <!-- Group therapy specific attributes -->
        <div v-if="therapyType === 'group'" class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 flex items-center justify-between">
          <div>
            <span class="font-medium text-gray-800 capitalize">Open Join</span>
            <p class="text-sm text-gray-500">Anyone can join without request</p>
          </div>
          <div class="flex items-center">
            <span :class="therapy.allowAnyone ? 'bg-green-500' : 'bg-red-500'" class="w-4 h-4 rounded-full"></span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import BooleanAttribute from '@/Components/BooleanAttribute.vue'

defineProps({
  therapy: { default: null },
  therapyType: { type: String, default: 'individual' },
})
</script>
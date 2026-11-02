<script setup lang="ts">
const open = defineModel<boolean>({ default: false })
</script>

<template>
  <button
    type="button"
    class="lg:hidden inline-flex items-center justify-center w-9 h-9 rounded-md text-neutral-700 hover:bg-neutral-100"
    :aria-expanded="open"
    aria-label="Menu"
    @click="open = !open"
  >
    <svg v-if="!open" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
    </svg>
    <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
    </svg>
  </button>
</template>

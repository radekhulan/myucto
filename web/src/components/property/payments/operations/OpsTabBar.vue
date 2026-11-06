<script setup lang="ts">
defineProps<{ tabs: Array<{ key: string; label: string }>; modelValue: string; disabled?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <div role="tablist" class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-neutral-200">
    <button v-for="tab in tabs" :key="tab.key" type="button" role="tab" :data-tab="tab.key" :aria-selected="modelValue === tab.key" :disabled="disabled"
      class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm transition-colors disabled:cursor-default disabled:opacity-50"
      :class="modelValue === tab.key ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
      @click="emit('update:modelValue', tab.key)">{{ tab.label }}</button>
  </div>
</template>

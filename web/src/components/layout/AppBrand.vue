<script setup lang="ts">
import { ref } from 'vue'

defineProps<{ textClass?: string }>()

const text = ref<HTMLElement | null>(null)
defineExpose({ text })
</script>

<template>
  <img src="/styles/logo.svg" alt="MyÚčto" class="w-7 h-7" />
  <span ref="text" class="text-sm font-semibold leading-tight select-none" :class="textClass">
    My<span class="text-primary-600">Účto</span><span class="text-neutral-400 font-normal">.cz</span>
  </span>
</template>

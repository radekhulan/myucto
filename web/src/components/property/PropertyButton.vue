<script setup lang="ts">
import { btnFilled, btnOutline, ICONS, type ActionIcon, type ActionVariant } from '@/components/ui/buttonStyles'
withDefaults(defineProps<{ icon: ActionIcon; variant?: ActionVariant; filled?: boolean; disabled?: boolean; submit?: boolean }>(), { variant: 'neutral', filled: false, disabled: false, submit: false })
</script>
<template>
  <button :type="submit ? 'submit' : 'button'" :class="filled ? btnFilled(variant) : btnOutline(variant)" :disabled="disabled">
    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS[icon]" /></svg><slot />
  </button>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Fraction } from '@/api/propertyRegistry'
defineProps<{ modelValue: Fraction; label: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: Fraction] }>()
const { t } = useI18n()
</script>
<template>
  <fieldset class="min-w-0"><legend class="text-sm">{{ label }}</legend><div class="flex items-center gap-2">
    <input :value="modelValue.num" inputmode="numeric" maxlength="18" class="h-9 min-w-0 w-28 rounded border border-neutral-300 bg-surface px-2" :aria-label="t('propertyRegistry.numerator')" @input="emit('update:modelValue', { ...modelValue, num: ($event.target as HTMLInputElement).value })">
    <span aria-hidden="true">/</span>
    <input :value="modelValue.den" inputmode="numeric" maxlength="18" class="h-9 min-w-0 w-28 rounded border border-neutral-300 bg-surface px-2" :aria-label="t('propertyRegistry.denominator')" @input="emit('update:modelValue', { ...modelValue, den: ($event.target as HTMLInputElement).value })">
  </div></fieldset>
</template>

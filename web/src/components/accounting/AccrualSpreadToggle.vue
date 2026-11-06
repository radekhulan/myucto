<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{ modelValue: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
const { t } = useI18n()
</script>

<template>
  <label class="inline-flex items-start gap-2 text-sm cursor-pointer" data-test="accrual-spread-toggle"
    :title="t('accounting.accrual_spread.toggle_hint')">
    <input type="checkbox" class="rounded border-neutral-300 mt-0.5" :checked="modelValue"
      @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked)" />
    <span>{{ t('accounting.accrual_spread.toggle') }}</span>
  </label>
</template>

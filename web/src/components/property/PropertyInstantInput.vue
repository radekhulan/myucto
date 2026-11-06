<script setup lang="ts">
import { reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DateInput from '@/components/ui/DateInput.vue'
import { propertyControl as control } from './propertyFormStyles'
import { fromLocalInstant, toLocalInstant } from './propertyInstant'

const props = withDefaults(defineProps<{ modelValue: string; label: string; required?: boolean }>(), { required: false })
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const { t } = useI18n()
const parts = reactive({ date: '', time: '' })
let emitted = ''
watch(() => props.modelValue, value => {
  if (value === emitted) return
  const local = toLocalInstant(value)
  parts.date = local.date
  parts.time = local.time
}, { immediate: true })
function change(part: 'date' | 'time', value: string) {
  parts[part] = value.slice(0, part === 'time' ? 5 : 10)
  emitted = parts.date && parts.time ? fromLocalInstant(parts.date, parts.time) : ''
  emit('update:modelValue', emitted)
}
</script>

<template>
  <fieldset class="space-y-1 min-w-0">
    <legend class="text-sm font-medium">{{ label }}</legend>
    <div class="flex min-w-0 flex-wrap items-center gap-2">
      <DateInput :model-value="parts.date" :class="[control, 'w-40']" :required="required || !!parts.time" :aria-label="label" @update:model-value="change('date', $event ?? '')" />
      <input type="time" step="60" :value="parts.time" :required="required || !!parts.date" :class="[control, 'w-28']" :aria-label="t('propertyRegistry.time')" @input="change('time', ($event.target as HTMLInputElement).value)">
    </div>
  </fieldset>
</template>

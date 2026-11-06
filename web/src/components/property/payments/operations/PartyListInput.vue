<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
const props = defineProps<{ supplier: number; modelValue: Array<number | null>; addLabel?: string }>()
const emit = defineEmits<{ 'update:modelValue': [value: Array<number | null>] }>()
const { t } = useI18n()
function set(index: number, value: number | null) { const next = [...props.modelValue]; next[index] = value; emit('update:modelValue', next) }
</script>

<template>
  <div class="space-y-2">
    <div v-for="(_, index) in modelValue" :key="index" class="flex flex-wrap items-center gap-2">
      <div class="min-w-60 flex-1"><PropertyPartyPicker :supplier-id="supplier" :model-value="modelValue[index] ?? null" @update:model-value="set(index, $event)" /></div>
      <PropertyButton icon="trash" variant="danger" :disabled="modelValue.length === 1" @click="emit('update:modelValue', modelValue.filter((__, i) => i !== index))">{{ t('propertyPayments.remove') }}</PropertyButton>
    </div>
    <PropertyButton icon="plus" @click="emit('update:modelValue', [...modelValue, null])">{{ addLabel ?? t('propertyPayments.addPerson') }}</PropertyButton>
  </div>
</template>

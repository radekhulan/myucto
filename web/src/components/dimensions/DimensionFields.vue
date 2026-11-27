<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionPicker from './DimensionPicker.vue'
import { useDimensions } from '@/composables/useDimensions'
import type { DimensionMap, DimensionType } from '@/api/dimensions'

/**
 * Sada výběrů dimenzí — jeden za každý typ, který se nabízí na dokladech. Model je
 * mapa typ → hodnota. Při vypnutých dimenzích se nevykreslí nic.
 *
 * `compact` je pro řádky položek a deníku: bez nadpisů polí, typ je v placeholderu.
 */
const props = withDefaults(defineProps<{
  modelValue: DimensionMap | null | undefined
  disabled?: boolean
  compact?: boolean
  teleport?: boolean
  /** Jen tyto typy (výchozí = všechny typy nabízené na dokladech). */
  types?: DimensionType[]
}>(), {
  disabled: false,
  compact: false,
  teleport: false,
  types: undefined,
})

const emit = defineEmits<{ 'update:modelValue': [value: DimensionMap] }>()

const { t } = useI18n()
const dims = useDimensions()

onMounted(() => { void dims.load() })

const shownTypes = computed(() => props.types ?? dims.documentTypes.value)

function update(typeId: number, valueId: number | null) {
  emit('update:modelValue', { ...(props.modelValue ?? {}), [typeId]: valueId })
}
</script>

<template>
  <div v-if="dims.enabled.value && shownTypes.length > 0"
       :class="compact ? 'flex flex-wrap gap-2' : 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3'"
       data-test="dimension-fields">
    <div v-for="type in shownTypes" :key="type.id" :class="compact ? 'min-w-[10rem] flex-1' : 'min-w-0'">
      <label v-if="!compact" class="block text-sm font-medium text-neutral-700 mb-1 truncate" :title="type.name">
        {{ type.name }}
        <span v-if="type.level === 'global'" class="ml-1 text-xs font-normal text-neutral-400">{{ t('dimensions.level_global_short') }}</span>
      </label>
      <DimensionPicker
        :type-id="type.id"
        :model-value="modelValue?.[type.id] ?? null"
        :disabled="disabled"
        :compact="compact"
        :teleport="teleport"
        :placeholder="compact ? type.name : undefined"
        :aria-label="type.name"
        @update:model-value="update(type.id, $event)"
      />
    </div>
  </div>
</template>

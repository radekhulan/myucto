<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionPicker from './DimensionPicker.vue'
import { useDimensions } from '@/composables/useDimensions'

/**
 * Filtr sestavy na hodnotu dimenze. Model = id hodnoty (nebo null) a příznak, zda
 * brát i podřízené hodnoty (výchozí ano — nadřízená hodnota = celá větev).
 * Ve filtru na víc hodnot ({@link DimensionReportFilters}) je jedním řádkem a příznak
 * podřízených nese společně pro všechny řádky (`hideDescendants`).
 */
const props = defineProps<{
  valueId: number | null
  descendants: boolean
  hideDescendants?: boolean
}>()

const emit = defineEmits<{
  'update:valueId': [value: number | null]
  'update:descendants': [value: boolean]
}>()

const { t } = useI18n()
const dims = useDimensions()
const typeId = ref<number | null>(null)

onMounted(() => { void dims.load() })

const activeTypes = computed(() => dims.types.value.filter(ty => ty.is_active))

// Vybraná hodnota určuje typ (např. filtr převzatý z URL).
watch([() => props.valueId, () => dims.valueById.value], () => {
  const value = props.valueId ? dims.valueById.value.get(props.valueId) : null
  if (value) typeId.value = value.type_id
}, { immediate: true })

function changeType(event: Event) {
  const v = (event.target as HTMLSelectElement).value
  typeId.value = v ? Number(v) : null
  emit('update:valueId', null)
}
</script>

<template>
  <div v-if="dims.enabled.value && activeTypes.length > 0" class="flex flex-wrap items-end gap-2" data-test="dimension-report-filter">
    <div>
      <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.filter_type') }}</label>
      <select :value="typeId ?? ''" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" @change="changeType">
        <option value="">{{ t('dimensions.filter_none') }}</option>
        <option v-for="type in activeTypes" :key="type.id" :value="type.id">{{ type.name }}</option>
      </select>
    </div>
    <div v-if="typeId" class="min-w-[14rem]">
      <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.filter_value') }}</label>
      <DimensionPicker :type-id="typeId" :model-value="valueId" @update:model-value="emit('update:valueId', $event)" />
    </div>
    <label v-if="valueId && !hideDescendants" class="flex items-center gap-2 h-10 text-sm text-neutral-700 whitespace-nowrap">
      <input type="checkbox" :checked="descendants" class="rounded border-neutral-300"
             @change="emit('update:descendants', ($event.target as HTMLInputElement).checked)" />
      {{ t('dimensions.filter_descendants') }}
    </label>
  </div>
</template>

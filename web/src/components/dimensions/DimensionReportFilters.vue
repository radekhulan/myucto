<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DimensionReportFilter from './DimensionReportFilter.vue'
import { useDimensions } from '@/composables/useDimensions'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { DIMENSION_FILTER_MAX_VALUES } from '@/utils/dimensionFilter'

/**
 * Filtr sestavy na víc hodnot dimenzí (např. Projekt = akce a zároveň Středisko =
 * dotace). Každý řádek vybírá jednu hodnotu; hodnoty různých typů musí platit
 * zároveň, hodnoty stejného typu stačí kterákoli. Příznak podřízených platí pro všechny.
 */
const props = defineProps<{
  valueIds: number[]
  descendants: boolean
}>()

const emit = defineEmits<{
  'update:valueIds': [value: number[]]
  'update:descendants': [value: boolean]
}>()

const { t } = useI18n()
const dims = useDimensions()
onMounted(() => { void dims.load() })

// Řádek bez hodnoty (rozpracovaný výběr) do modelu nepatří, drží se jen tady. Stálý
// klíč řádku: po odebrání jiného řádku si každý řádek ponechá svůj vybraný typ.
interface Row { key: number, valueId: number | null }
let nextKey = 0
const rows = ref<Row[]>([])
const row = (valueId: number | null): Row => ({ key: nextKey++, valueId })
const selectedIds = () => rows.value.map(r => r.valueId).filter((id): id is number => id !== null)

watch(() => props.valueIds, (ids) => {
  const selected = selectedIds()
  if (rows.value.length > 0 && selected.length === ids.length && selected.every((id, i) => id === ids[i])) return
  rows.value = ids.length > 0 ? ids.map(row) : [row(null)]
}, { immediate: true })

// Tlačítko je vidět hned (aby šla kombinace dimenzí objevit), použít jde až po výběru hodnoty v každém řádku.
const canAdd = computed(() => rows.value.every(r => r.valueId !== null))
const atLimit = computed(() => rows.value.length >= DIMENSION_FILTER_MAX_VALUES)

function setRow(index: number, valueId: number | null) {
  const target = rows.value[index]
  if (!target || target.valueId === valueId) return
  target.valueId = valueId
  emit('update:valueIds', selectedIds())
}

function addRow() {
  rows.value.push(row(null))
}

function removeRow(index: number) {
  const hadValue = rows.value[index]?.valueId != null
  rows.value.splice(index, 1)
  if (rows.value.length === 0) rows.value.push(row(null))
  if (hadValue) emit('update:valueIds', selectedIds())
}
</script>

<template>
  <div v-if="dims.enabled.value" class="flex flex-col gap-2" data-test="dimension-report-filters">
    <div v-for="(r, index) in rows" :key="r.key" class="flex flex-wrap items-end gap-2" data-test="dimension-report-filters-row">
      <DimensionReportFilter :value-id="r.valueId" :descendants="descendants" hide-descendants
        @update:value-id="setRow(index, $event)" />
      <button v-if="rows.length > 1" type="button" :class="btnOutline('danger')" class="whitespace-nowrap"
              :title="t('dimensions.filter_remove')" :aria-label="t('dimensions.filter_remove')"
              data-test="dimension-report-filters-remove" @click="removeRow(index)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
      </button>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <button v-if="!atLimit" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="!canAdd" :title="canAdd ? undefined : t('dimensions.filter_add_hint')"
              data-test="dimension-report-filters-add" @click="addRow">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('dimensions.filter_add') }}
      </button>
      <label v-if="valueIds.length > 0" class="flex items-center gap-2 h-10 text-sm text-neutral-700 whitespace-nowrap">
        <input type="checkbox" :checked="descendants" class="rounded border-neutral-300"
               @change="emit('update:descendants', ($event.target as HTMLInputElement).checked)" />
        {{ t('dimensions.filter_descendants') }}
      </label>
      <span v-if="valueIds.length > 1" class="text-xs text-neutral-500">{{ t('dimensions.filter_combined_hint') }}</span>
    </div>
  </div>
</template>

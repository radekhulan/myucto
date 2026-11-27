<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useDimensions } from '@/composables/useDimensions'
import type { DimensionSplits } from '@/api/dimensions'

/**
 * Dimenze řádku deníku / dokladu jako štítky „Typ: hodnota". Rozpad mezi víc
 * hodnot typu je jeden štítek „Typ: A 60 %, B 40 %".
 */
const props = defineProps<{
  dimensions: Record<number, number | null> | null | undefined
  splits?: DimensionSplits | null
  /** Štítky nepřetékají šířku rodiče: dlouhý text se zkrátí, celý je v `title`. */
  truncate?: boolean
}>()

const dims = useDimensions()
onMounted(() => { void dims.load() })

function percent(share: number): string {
  return `${Math.round(share * 10000) / 100} %`
}

const chips = computed(() => [
  ...Object.entries(props.dimensions ?? {})
    .filter(([, valueId]) => !!valueId)
    .map(([typeId, valueId]) => {
      const type = dims.typeById.value.get(Number(typeId))
      const value = dims.valueById.value.get(Number(valueId))
      return {
        key: `${typeId}-${valueId}`,
        type: type?.name ?? '',
        label: dims.valueLabel(Number(valueId)),
        closed: value ? !value.is_active : false,
        path: value ? dims.pathOf(value).join(' › ') : '',
      }
    }),
  ...Object.entries(props.splits ?? {})
    .filter(([, shares]) => (shares?.length ?? 0) > 0)
    .map(([typeId, shares]) => {
      const label = shares.map(s => `${dims.valueLabel(s.value_id)} ${percent(s.share)}`).join(', ')
      return {
        key: `${typeId}-split`,
        type: dims.typeById.value.get(Number(typeId))?.name ?? '',
        label,
        closed: false,
        path: '',
      }
    }),
])

function chipTitle(chip: { type: string; label: string; path: string }): string {
  const value = chip.path ? `${chip.path} › ${chip.label}` : chip.label
  // Zkrácený štítek musí v `title` nést i typ, jinak by z něj nešlo poznat, k čemu patří.
  return props.truncate && chip.type ? `${chip.type}: ${value}` : value
}
</script>

<template>
  <span v-if="dims.enabled.value && chips.length > 0" class="inline-flex flex-wrap gap-1"
        :class="{ 'max-w-full min-w-0': truncate }" data-test="dimension-chips">
    <span v-for="chip in chips" :key="chip.key"
          :title="chipTitle(chip)"
          class="inline-flex items-center gap-1 rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700 whitespace-nowrap"
          :class="{ 'opacity-60': chip.closed, 'max-w-full min-w-0': truncate }">
      <span v-if="chip.type" class="text-primary-500" :class="{ 'shrink-0': truncate }">{{ chip.type }}:</span><span :class="{ 'truncate min-w-0': truncate }">{{ chip.label }}</span>
    </span>
  </span>
</template>

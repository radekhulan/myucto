<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useDimensions } from '@/composables/useDimensions'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { dimensionValueIdsParam } from '@/utils/dimensionFilter'

const props = defineProps<{
  current: 'profit' | 'balance' | 'trial' | 'ledger'
  from: string
  to: string
  valueIds: number[]
  descendants?: boolean
}>()

const { t } = useI18n()
const dims = useDimensions()
onMounted(() => { void dims.load().catch(() => {}) })

// Výsledovka po dimenzi je po jednom typu: odkaz nese hodnotu jen u filtru na jedinou hodnotu.
const singleValueId = computed(() => props.valueIds.length === 1 ? props.valueIds[0] : null)
const typeId = computed(() => singleValueId.value ? dims.valueById.value.get(singleValueId.value)?.type_id : null)
const query = computed(() => ({
  from: props.from,
  to: props.to,
  ...(props.valueIds.length > 0 ? {
    dimension_value_id: dimensionValueIdsParam(props.valueIds) as string,
    dimension_descendants: props.descendants === false ? '0' : '1',
  } : {}),
}))
const links = computed(() => [
  { key: 'profit', name: 'accounting-dimension-profit', label: t('dimensions.reports_title'), query: {
    from: props.from, to: props.to,
    ...(typeId.value ? { type_id: String(typeId.value), value_id: String(singleValueId.value) } : {}),
  } },
  { key: 'balance', name: 'accounting-balance-sheet', label: t('accounting.balance_sheet.title'), query: query.value },
  { key: 'trial', name: 'accounting-trial-balance', label: t('accounting.trial_balance.title'), query: query.value },
  { key: 'ledger', name: 'accounting-general-ledger', label: t('accounting.general_ledger.title'), query: query.value },
].filter(link => link.key !== props.current && (link.key !== 'profit' || props.valueIds.length === 0 || typeId.value)))
</script>

<template>
  <div v-if="dims.enabled.value && from && to" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4" data-test="dimension-report-links">
    <div class="flex flex-wrap items-center gap-2">
      <span class="text-sm font-medium text-neutral-600 mr-1">{{ t('dimensions.other_reports') }}</span>
      <RouterLink v-for="link in links" :key="link.key" :to="{ name: link.name, query: link.query }" :class="btnOutline('neutral')" class="whitespace-nowrap">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
        {{ link.label }}
      </RouterLink>
    </div>
  </div>
</template>

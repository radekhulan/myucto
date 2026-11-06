<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ProfitDistribution504Preview, ResultDecision504Allocation, ResultDecision504Cell } from '@/api/closing'
import { formatMoney } from '@/composables/useFormat'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { resultDecisionResourceAccounts } from '@/utils/resultDecision504'
const props = defineProps<{ preview: ProfitDistribution504Preview; disabled: boolean }>()
const rows = defineModel<ResultDecision504Allocation[]>({ required: true })
const { t } = useI18n()
function remaining(key: string, cents: number) {
  return (Math.abs(cents) - rows.value.filter(r => r.cell_key === key).reduce((sum, r) => sum + Math.round(Number(r.amount) * 100), 0)) / 100
}
function resources(cell: ResultDecision504Cell, row: ResultDecision504Allocation) {
  const current = resultDecisionResourceAccounts(props.preview, cell, row.role)
  if (props.disabled && row.account_code && !current.some(resource => resource.account_code === row.account_code)) {
    return [...current, { ...cell, account_code: row.account_code, available_cents: 0, available: 0 }]
  }
  return current
}
function setResource(row: ResultDecision504Allocation, code: string) {
  if (code) row.account_code = code
  else delete row.account_code
}
</script>

<template>
  <fieldset :disabled="disabled" class="space-y-3">
    <div v-for="cell in props.preview.cells" :key="cell.key" class="border border-neutral-200 rounded-lg p-3 space-y-2">
      <p class="text-sm font-medium">{{ Object.values(cell.dimensions).map(id => props.preview.dimension_labels[id]).filter(Boolean).join(' · ') || t('accounting.result504.no_dimensions') }}</p>
      <p class="text-sm">{{ cell.available < 0 ? t('accounting.result504.loss') : t('accounting.result504.profit') }}: <strong class="font-mono">{{ formatMoney(Math.abs(cell.available)) }}</strong></p>
      <div v-for="(row, index) in rows" :key="index">
        <div v-if="row.cell_key === cell.key" class="flex flex-wrap items-end gap-2">
          <label class="text-sm">{{ t('accounting.result504.target') }}
            <select v-model="row.role" @change="delete row.account_code" class="block h-9 border border-neutral-300 rounded-md px-2 bg-surface">
              <option v-for="target in props.preview.target_roles" :key="target.role" :value="target.role">{{ t(`accounting.result504.roles.${target.role.split('.').at(-1)}`) }} ({{ target.account_code }})</option>
            </select>
          </label>
          <label v-if="cell.available_cents < 0 && row.role !== 'result.retained_profit'" class="text-sm">{{ t('accounting.result504.resource_account') }}
            <select :value="row.account_code ?? ''" data-testid="result-resource-account" class="block h-9 border border-neutral-300 rounded-md px-2 bg-surface" @change="setResource(row, ($event.target as HTMLSelectElement).value)">
              <option value="">{{ resources(cell, row).length === 1 ? t('accounting.result504.unique_resource', { account: resources(cell, row)[0]!.account_code }) : t('accounting.result504.choose_resource') }}</option>
              <option v-for="resource in resources(cell, row)" :key="resource.account_code" :value="resource.account_code">{{ resource.account_code }}{{ resource.available_cents > 0 ? ` (${formatMoney(resource.available)})` : '' }}</option>
            </select>
          </label>
          <label class="text-sm">{{ t('accounting.result504.amount') }}<input v-model.number="row.amount" type="number" min="0.01" step="0.01" class="block w-36 h-9 border border-neutral-300 rounded-md px-2 font-mono" /></label>
          <button type="button" :class="btnOutline('danger')" @click="rows.splice(index, 1)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('accounting.result504.remove') }}</button>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <button type="button" :class="btnOutline('neutral')" @click="rows.push({ cell_key: cell.key, role: 'result.retained_profit', amount: Math.max(0, remaining(cell.key, cell.available_cents)) })"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('accounting.result504.add') }}</button>
        <span class="text-sm" :class="remaining(cell.key, cell.available_cents) === 0 ? 'text-success-600' : 'text-warning-700'">{{ t('accounting.result504.remaining') }}: {{ formatMoney(remaining(cell.key, cell.available_cents)) }}</span>
      </div>
    </div>
  </fieldset>
</template>

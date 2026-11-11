<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { formInput as field, formLabel } from '@/components/ui/formStyles'
import type { AccountingPeriod } from '@/api/accounting'
import { membershipApi, type ReconciliationTotals } from '@/api/nonprofitMembership'
import { formatMoneyMinor } from '@/composables/useFormat'
import { errorBox, membershipError, formatDateRange } from './membershipFormat'

/** `dataVersion` se zvýší po každém zápisu v detailu člena; dřívější výsledek kontroly tím přestane platit. */
const props = defineProps<{ supplier: number; periods: AccountingPeriod[]; dataVersion?: number }>()
const { t, te } = useI18n()

const period = ref(0)
const data = ref<ReconciliationTotals | null>(null)
const busy = ref(false)
const error = ref('')
let ticket = 0
let alive = true

const money = (value: number | string) => formatMoneyMinor(Number(value))
const rows = computed(() => (data.value ? [
  ['claims', money(data.value.claims.cents)],
  ['operations', money(data.value.operations.cents)],
  ['receivable', money(data.value.ledger.receivable_cents)],
  ['revenue', money(data.value.ledger.revenue_cents)],
] as const : []))

function clear() { ticket++; data.value = null; error.value = ''; busy.value = false }

async function load() {
  if (!period.value || busy.value) return
  const mine = ++ticket, supplier = props.supplier, requested = period.value
  busy.value = true; error.value = ''; data.value = null
  try {
    const result = await membershipApi.reconciliation(supplier, requested)
    if (!alive || mine !== ticket || supplier !== props.supplier || requested !== period.value) return
    data.value = result
  } catch (e) {
    if (alive && mine === ticket) error.value = membershipError({t, te}, e)
  } finally {
    if (alive && mine === ticket) busy.value = false
  }
}

watch(() => period.value, clear)
watch(() => props.dataVersion, clear)
watch(() => props.supplier, () => { period.value = 0; clear() })
onBeforeUnmount(() => { alive = false; ticket++ })

const actions = computed<ActionItem[]>(() => [
  { key: 'reconcile', label: t('nonprofitMembership.reconcileLoad'), icon: 'cycle', disabled: busy.value || !period.value, disabledReason: t('nonprofitMembership.reconcileChoosePeriod'), run: () => { void load() } },
])
</script>

<template>
  <details class="rounded-lg border border-neutral-200 bg-surface p-4" data-section="reconciliation">
    <summary class="cursor-pointer font-semibold">{{ t('nonprofitMembership.reconcileTitle') }}</summary>
    <div class="mt-3 space-y-3">
      <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.reconcileHelp') }}</p>
      <label :class="[formLabel, 'max-w-lg']">{{ t('nonprofitMembership.accountingPeriod') }}
        <select v-model.number="period" :class="field" data-field="reconcile-period">
          <option :value="0">{{ t('nonprofitMembership.choose') }}</option>
          <option v-for="p in periods" :key="p.id" :value="p.id">{{ formatDateRange(p.starts_on, p.ends_on) }}</option>
        </select>
      </label>
      <ActionBar :actions="actions" />
      <p v-if="busy" role="status" class="text-sm text-neutral-500" data-section="reconcile-loading">{{ t('common.loading') }}</p>
      <p v-if="error" role="alert" :class="errorBox" data-section="reconcile-error">{{ error }}</p>
      <div v-if="data" class="space-y-2" data-section="reconcile-result">
        <p class="font-medium" :class="data.status === 'balanced' && data.proof_complete ? 'text-success-600' : 'text-warning-700'" data-field="reconcile-status">
          {{ t(data.status === 'balanced' && data.proof_complete ? 'nonprofitMembership.reconcileBalanced' : 'nonprofitMembership.reconcileMismatch') }}
        </p>
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div v-for="[key, value] in rows" :key="key"><dt class="text-sm text-neutral-500">{{ t(`nonprofitMembership.reconcile_${key}`) }}</dt><dd class="whitespace-nowrap tabular-nums" :data-field="`reconcile-${key}`">{{ value }}</dd></div>
        </dl>
        <p v-if="!data.proof_complete" class="text-sm text-warning-700">{{ t('nonprofitMembership.reconcileIncomplete') }}</p>
      </div>
    </div>
  </details>
</template>

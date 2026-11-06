<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PropertyObject } from '@/api/propertyRegistry'
import { propertyPaymentOperationsApi, type PropertyCard } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import { useBusyScope } from './busy'
import { failureMessage, moneyText, partyNames, propertyUnitLabel } from './format'
import { boxClass, inputClass } from './ui'
const props = defineProps<{ supplier: number; asOf: string; units: PropertyObject[]; initialUnitId?: number; initialKnownAt?: string }>()
const { t } = useI18n()
const scope = useBusyScope()
const knownAt = ref(props.initialKnownAt ?? new Date().toISOString())
const unitId = ref(props.initialUnitId ?? 0)
const clientId = ref<number | null>(null)
const card = ref<PropertyCard | null>(null)
const loading = ref(false)
const error = ref('')
const form = ref<HTMLFormElement | null>(null)
let epoch = 0
onBeforeUnmount(() => { epoch++; if (loading.value) scope.end() })
function clear() { epoch++; card.value = null; if (loading.value) { loading.value = false; scope.end() } error.value = '' }
watch(() => [props.supplier, props.asOf, knownAt.value, unitId.value, clientId.value], clear)
async function load() {
  if (loading.value || scope.busy.value || !form.value?.reportValidity()) return
  const ticket = ++epoch; const supplier = props.supplier; const asOf = props.asOf
  loading.value = true; scope.begin(); error.value = ''; card.value = null
  try {
    const result = await propertyPaymentOperationsApi.card(supplier, { as_of: asOf, known_at: knownAt.value, ...(unitId.value ? { unit_id: unitId.value } : {}), ...(clientId.value ? { client_id: clientId.value } : {}) })
    if (ticket === epoch && supplier === props.supplier && asOf === props.asOf) card.value = result
  } catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) } finally { if (ticket === epoch && loading.value) { loading.value = false; scope.end() } }
}
const unknown = () => t('propertyPayments.unknown')
const money = (value: string | null) => moneyText(value, unknown())
const totals = computed(() => card.value ? ([
  ['gross_receivable_cents', card.value.totals.gross_receivable_cents],
  ['gross_credit_cents', card.value.totals.gross_credit_cents],
  ['reserved_credit_cents', card.value.totals.reserved_credit_cents],
  ['available_credit_cents', card.value.totals.available_credit_cents],
  ['guarantee_exposure_cents', card.value.totals.guarantee_exposure_cents],
] as Array<[string, string | null]>) : [])
const actions = () => [{ key: 'card-load', label: t('propertyPayments.card.load'), icon: 'search', variant: 'primary', tier: 'primary', disabled: loading.value || scope.busy.value, run: () => { void load() } }] as ActionItem[]
</script>

<template>
  <section :class="boxClass" data-section="card">
    <h3 class="font-semibold">{{ t('propertyPayments.card.title') }}</h3>
    <p class="text-sm text-neutral-500">{{ t('propertyPayments.card.help') }}</p>
    <form ref="form" class="space-y-3" @submit.prevent="load">
      <fieldset :disabled="loading" class="flex flex-wrap items-end gap-3">
        <div data-field="card-known-at"><PropertyInstantInput v-model="knownAt" :label="t('propertyPayments.card.knownAt')" required /></div>
        <label class="min-w-48 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.alloc.unitFilter') }}<select v-model="unitId" :class="inputClass" data-field="card-unit"><option :value="0">{{ t('propertyPayments.alloc.allUnits') }}</option><option v-if="unitId && !units.some(u => u.id === unitId)" :value="unitId">{{ t('propertyPayments.unitNumber', { number: unitId }) }}</option><option v-for="u in units" :key="u.id" :value="u.id">{{ propertyUnitLabel(u, number => t('propertyPayments.unitNumber', { number })) }}</option></select></label>
        <div class="min-w-60" data-field="card-client"><span class="text-sm font-medium">{{ t('propertyPayments.card.person') }}</span><PropertyPartyPicker :supplier-id="supplier" :model-value="clientId" @update:model-value="clientId = $event" /></div>
      </fieldset>
      <ActionBar :actions="actions()" />
    </form>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <p v-if="loading">{{ t('propertyPayments.loading') }}</p>
    <div v-if="card" class="space-y-3" data-section="card-result">
      <p v-if="card.precision === 'day'" class="text-sm text-warning-600" data-flag="day-precision">{{ t('propertyPayments.card.dayPrecision') }}</p>
      <p class="text-sm text-warning-600" data-flag="coverage">{{ t('propertyPayments.card.coverage') }}<template v-if="!card.coverage.financial_complete"> {{ t('propertyPayments.card.financialIncomplete') }}</template><template v-if="card.coverage.service_settlements === 'pending'"> {{ t('propertyPayments.card.servicesMissing') }}</template><template v-if="card.coverage.opening_import !== 'verified_recorded_batches'"> {{ t('propertyPayments.card.openingMissing') }}</template></p>
      <dl class="grid gap-x-3 gap-y-1 text-sm sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]" data-section="card-totals">
        <template v-for="[key, value] in totals" :key="key"><dt>{{ t(`propertyPayments.card.totals.${key}`) }}</dt><dd :data-total="key" :data-unknown="value === null">{{ money(value) }}</dd></template>
      </dl>
      <p class="text-xs text-neutral-500">{{ t('propertyPayments.card.noNetting') }}</p>
      <div class="space-y-1">
        <h4 class="font-medium">{{ t('propertyPayments.card.claims') }}</h4>
        <article v-for="claim in card.claims" :key="claim.claim_key" class="space-y-1 border-t border-neutral-200 py-2 text-sm" data-row="card-claim">
          <div class="flex flex-wrap items-center gap-3"><span class="min-w-0 flex-1">{{ claim.unit_label }} · {{ claim.title_ref }} · {{ t('propertyPayments.dueShort') }} {{ claim.due_date }}</span><span data-claim-amount>{{ t('propertyPayments.card.grossClaim') }}: {{ money(claim.amount_cents) }}</span><span data-claim-person>{{ t('propertyPayments.card.personLiability') }}: {{ money(claim.person_liability_cents) }}</span></div>
          <p v-for="g in claim.groups" :key="g.group_key" class="text-xs" data-group>{{ t(`propertyPayments.role.${g.role}`) }}: {{ partyNames(g.members) }} · {{ t('propertyPayments.debt.cap') }}: {{ money(g.remaining_cap_cents) }}</p>
          <p v-if="claim.precision === 'day'" class="text-xs text-warning-600">{{ t('propertyPayments.card.dayPrecisionRow') }}</p>
          <p v-if="!claim.complete" class="text-xs text-warning-600">{{ t('propertyPayments.card.incompleteRow') }}</p>
        </article>
      </div>
      <div class="space-y-1">
        <h4 class="font-medium">{{ t('propertyPayments.card.credits') }}</h4>
        <article v-for="credit in card.credits" :key="credit.id" class="space-y-1 border-t border-neutral-200 py-2 text-sm" data-row="card-credit">
          <div class="flex flex-wrap items-center gap-3"><span class="min-w-0 flex-1">{{ credit.unit_label }} · {{ partyNames(credit.parties) }}</span><span data-credit-gross>{{ t('propertyPayments.card.totals.gross_credit_cents') }}: {{ money(credit.gross_credit_cents) }}</span><span>{{ t('propertyPayments.card.totals.reserved_credit_cents') }}: {{ money(credit.reserved_credit_cents) }}</span><span>{{ t('propertyPayments.card.totals.available_credit_cents') }}: {{ money(credit.available_credit_cents) }}</span></div>
          <p class="text-xs text-neutral-500">{{ t('propertyPayments.card.creditStays') }}</p>
          <p v-if="credit.precision === 'day'" class="text-xs text-warning-600">{{ t('propertyPayments.card.dayPrecisionRow') }}</p>
          <p v-if="!credit.complete" class="text-xs text-warning-600">{{ t('propertyPayments.card.incompleteRow') }}</p>
        </article>
      </div>
    </div>
  </section>
</template>

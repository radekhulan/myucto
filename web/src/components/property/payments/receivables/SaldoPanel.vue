<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyReceivablesApi as api, type SaldoPage } from '@/api/propertyReceivables'
import type { PropertyObject } from '@/api/propertyRegistry'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import CardPanel from '../operations/CardPanel.vue'
import LegalHistoryPanel from './LegalHistoryPanel.vue'
import { useBusyScope } from '../operations/busy'
import { failureMessage, moneyText } from '../operations/format'
import { inputClass } from '../operations/ui'
import { propertyNow } from '@/components/property/propertyInstant'
const props = defineProps<{ supplier: number; asOf: string; units: PropertyObject[] }>()
const { t } = useI18n(); const scope = useBusyScope(); const knownAt = ref(propertyNow()); const limit = ref(25)
const page = ref<SaldoPage | null>(null); const cursors = ref<Array<string | undefined>>([undefined]); const position = ref(0); const selected = ref<number | null>(null); const detail = ref<'card' | 'legal'>('card')
const loading = ref(false); const error = ref(''); const form = ref<HTMLFormElement | null>(null); let epoch = 0
const totalKeys = ['gross_receivable_cents', 'principal_receivable_cents', 'accessory_receivable_cents', 'gross_credit_cents', 'reserved_credit_cents', 'available_credit_cents', 'guarantee_exposure_cents'] as const
const agingKeys = ['not_due', '1_30', '31_90', '91_plus'] as const
function clear() { epoch++; page.value = null; selected.value = null; cursors.value = [undefined]; position.value = 0; error.value = ''; if (loading.value) { loading.value = false; scope.end() } }
watch(() => [props.supplier, props.asOf, knownAt.value, limit.value], clear); onBeforeUnmount(clear)
async function load(index = 0) {
  if (loading.value || scope.busy.value || !form.value?.reportValidity()) return
  const cursor = index === 0 ? undefined : cursors.value[index]; const ticket = ++epoch
  loading.value = true; scope.begin(); selected.value = null; error.value = ''
  try { const result = await api.saldo(props.supplier, { as_of: props.asOf, known_at: knownAt.value, limit: limit.value, ...(cursor ? { cursor } : {}) }); if (ticket === epoch) { page.value = result; position.value = index; cursors.value = cursors.value.slice(0, index + 1); if (result.next_cursor) cursors.value.push(result.next_cursor) } }
  catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) }
  finally { if (ticket === epoch) { loading.value = false; scope.end() } }
}
function open(id: number, kind: 'card' | 'legal') { selected.value = id; detail.value = kind }
const actions = computed<ActionItem[]>(() => [{ key: 'saldo-load', label: t('propertySaldo.load'), icon: 'search', variant: 'primary', tier: 'primary', disabled: loading.value || scope.busy.value, run: () => load() }])
</script>
<template>
  <section class="space-y-4" data-section="saldo">
    <h2 class="font-semibold">{{ t('propertySaldo.title') }}</h2><p class="text-warning-700" data-coverage>{{ t('propertySaldo.partial') }}</p><p class="text-sm">{{ t('propertySaldo.pagesHelp') }}</p>
    <form ref="form" class="space-y-3" @submit.prevent="load()"><fieldset :disabled="loading" class="flex flex-wrap items-end gap-3"><PropertyInstantInput v-model="knownAt" :label="t('propertyPayments.card.knownAt')" required /><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.pageSize') }}<select v-model="limit" :class="inputClass" data-field="saldo-limit"><option v-for="n in [25, 50, 100]" :key="n" :value="n">{{ n }}</option></select></label></fieldset><ActionBar :actions="actions" /></form>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p><p v-if="loading">{{ t('propertyPayments.loading') }}</p>
    <template v-if="page">
      <p data-page>{{ t('propertySaldo.page', { number: position + 1, count: page.items.length }) }}</p>
      <article v-for="u in page.items" :key="u.unit_id" class="space-y-3 rounded-lg border border-neutral-200 p-4" data-saldo-unit>
        <h3 class="font-semibold">{{ u.unit_code }}</h3>
        <p v-if="u.precision === 'day'" class="text-warning-700" data-day>{{ t('propertyPayments.card.dayPrecision') }}</p>
        <p v-if="!u.coverage.financial_complete || u.liability_review_count || u.uncertain_event_count" class="text-warning-700" data-review>{{ t('propertySaldo.review') }} {{ t('propertySaldo.reviewCounts', { uncertain: u.uncertain_event_count, legal: u.liability_review_count, negative: u.coverage.unresolved_negative_charge_line_count }) }}</p>
        <dl class="grid gap-x-3 gap-y-1 text-sm sm:grid-cols-2"><template v-for="key in totalKeys" :key="key"><dt>{{ key === 'principal_receivable_cents' || key === 'accessory_receivable_cents' ? t(`propertySaldo.totals.${key}`) : t(`propertyPayments.card.totals.${key}`) }}</dt><dd :data-total="key" :data-unknown="u.totals[key] === null">{{ moneyText(u.totals[key], t('propertyPayments.unknown')) }}</dd></template></dl>
        <dl class="grid gap-x-3 gap-y-1 text-sm sm:grid-cols-2"><template v-for="key in agingKeys" :key="key"><dt>{{ t(`propertySaldo.aging.${key}`) }}</dt><dd :data-aging="key">{{ moneyText(u.aging[key], t('propertyPayments.unknown')) }}</dd></template></dl>
        <p class="text-xs text-neutral-500">{{ t('propertyPayments.card.noNetting') }}</p>
        <div class="flex flex-wrap gap-2"><PropertyButton icon="search" :disabled="loading || scope.busy.value" :data-detail="u.unit_id" @click="open(u.unit_id, 'card')">{{ t('propertySaldo.detail') }}</PropertyButton><PropertyButton icon="search" :disabled="loading || scope.busy.value" :data-legal="u.unit_id" @click="open(u.unit_id, 'legal')">{{ t('propertySaldo.legalHistory') }}</PropertyButton></div>
      </article>
      <p v-if="!page.items.length">{{ t('propertySaldo.empty') }}</p>
      <div class="flex flex-wrap gap-2"><PropertyButton icon="chevron" :disabled="position === 0 || loading || scope.busy.value" data-action="saldo-previous" @click="load(position - 1)">{{ t('propertySaldo.previous') }}</PropertyButton><PropertyButton icon="chevron" :disabled="!page.next_cursor || loading || scope.busy.value" data-action="saldo-next" @click="load(position + 1)">{{ t('propertySaldo.next') }}</PropertyButton></div>
      <CardPanel v-if="selected && detail === 'card'" :key="`card-${selected}`" :supplier="supplier" :as-of="page.as_of" :units="units" :initial-unit-id="selected" :initial-known-at="page.known_at" />
      <LegalHistoryPanel v-if="selected && detail === 'legal'" :key="`legal-${selected}`" :supplier="supplier" :unit-id="selected" :as-of="page.as_of" :known-at="page.known_at" />
    </template>
  </section>
</template>

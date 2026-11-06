<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EetRecordBadge from '@/components/eet/EetRecordBadge.vue'
import EetPaymentStates from '@/components/eet/EetPaymentStates.vue'
import EetCorrectionDialog from '@/components/eet/EetCorrectionDialog.vue'
import { formBox } from '@/components/ui/formStyles'
import { eetApi, type EetRecordDetail } from '@/api/eet'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDateTime, formatMoney } from '@/composables/useFormat'

const { t, te } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()

const recordId = computed(() => Number(route.params.id))
const record = ref<EetRecordDetail | null>(null)
const loading = ref(false)
const loaded = ref(false)
const error = ref('')
const busy = ref<string | null>(null)
const correctionOpen = ref(false)

const canCorrect = computed(() => auth.canWrite('eet.correct'))
const canRetry = computed(() => canCorrect.value
  && (record.value?.status === 'retry_wait' || record.value?.status === 'action_required'))
const canCorrectRecord = computed(() => canCorrect.value && record.value?.kind === 'sale'
  && ['confirmed', 'confirmed_warning'].includes(record.value.status))

let generation = 0
async function load() {
  const gen = ++generation
  loading.value = true
  error.value = ''
  try {
    const r = await eetApi.record(recordId.value)
    if (gen === generation) record.value = r
  } catch (e) {
    if (gen === generation) { record.value = null; error.value = apiErrorMessage(e, t('eet.detail.load_failed')) }
  } finally {
    if (gen === generation) { loading.value = false; loaded.value = true }
  }
}
onMounted(() => { void load() })
onActivated(() => { if (loaded.value) void load() })
watch(recordId, () => { void load() })
onBeforeUnmount(() => { generation++ })

async function retry() {
  if (!record.value || busy.value) return
  busy.value = 'retry'
  try {
    const state = await eetApi.retryRecord(record.value.record_id)
    toast.success(t(state.status === 'confirmed' || state.status === 'confirmed_warning' ? 'eet.detail.retry_ok' : 'eet.detail.retry_sent'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.detail.retry_failed')))
  } finally {
    busy.value = null
  }
}

function onCorrectionDone() {
  void load()
}

const actions = computed<ActionItem[]>(() => [
  { key: 'retry', label: t('eet.detail.retry'), icon: 'send', tier: 'primary', variant: 'primary',
    show: canRetry.value, disabled: busy.value !== null, loading: busy.value === 'retry', run: () => { void retry() } },
  { key: 'correct', label: t('eet.detail.correct'), icon: 'uturn', tier: 'secondary', variant: 'warning',
    show: canCorrectRecord.value, disabled: busy.value !== null, run: () => { correctionOpen.value = true } },
  { key: 'list', label: t('eet.detail.back'), icon: 'table', tier: 'secondary', variant: 'neutral', to: { name: 'eet-records' } },
  { key: 'reload', label: t('eet.reload'), icon: 'cycle', tier: 'overflow', variant: 'neutral', loading: loading.value, run: () => { void load() } },
])

const currency = computed(() => record.value?.currency || 'CZK')
const sourceInvoices = computed(() => {
  const r = record.value
  if (r?.source_invoices?.length) return r.source_invoices
  const ids = new Set<number>()
  const ev = r?.payment_event
  if (ev?.invoice_id) ids.add(ev.invoice_id)
  for (const a of ev?.allocations ?? []) if (a.invoice_id) ids.add(a.invoice_id)
  return [...ids].map(id => ({ id, number: '' }))
})

function decisionLabel(v?: string | null): string {
  if (!v) return ''
  const key = `eet.detail.decisions.${v}`
  return te(key) ? t(key) : v
}
function reasonLabel(v?: string | null): string {
  if (!v) return ''
  const key = `eet.detail.reason_codes.${v}`
  return te(key) ? t(key) : v
}
const BADGE = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap'
const th = 'px-3 py-2 text-left font-medium whitespace-nowrap'
const label = 'text-neutral-500'

function outcomeLabel(o: string): string {
  const key = `eet.detail.outcomes.${o}`
  return te(key) ? t(key) : o
}
</script>

<template>
  <div class="pb-24">
    <div v-if="loading && !record" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="error" class="rounded-lg border border-danger-500/40 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</div>

    <template v-else-if="record">
      <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 class="flex flex-wrap items-center gap-2 text-2xl font-semibold">
            {{ t('eet.detail.title', { number: record.sequence_number ?? record.record_id }) }}
            <EetRecordBadge :status="record.status" />
            <span v-if="record.kind === 'correction'" :class="[BADGE, 'bg-neutral-100 text-neutral-600 ring-neutral-500/20']">{{ t('eet.records.kind_correction') }}</span>
            <span v-if="record.overdue" :class="[BADGE, 'bg-danger-50 text-danger-700 ring-danger-600/20']">{{ t('eet.records.overdue') }}</span>
          </h1>
          <p class="text-sm text-neutral-500 mt-0.5">{{ formatDateTime(record.sale_at) }}</p>
        </div>
        <ActionBar :actions="actions" />
      </div>

      <div v-if="record.environment === 'playground'" class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-test="eet-detail-playground">
        <strong>{{ t('eet.records.playground') }}</strong> - {{ t('eet.detail.playground_note') }}
      </div>
      <div v-if="record.status === 'retry_wait' || record.status === 'pending' || record.status === 'sending'"
        class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
        {{ t('eet.pay.eet_stored_waiting') }}
        <span v-if="record.next_attempt_at" class="block text-xs mt-1">{{ t('eet.detail.next_attempt', { at: formatDateTime(record.next_attempt_at) }) }}</span>
      </div>
      <div v-if="record.status === 'external'" class="mb-4 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-700" data-test="eet-detail-external">
        {{ t('eet.detail.external_note') }}
      </div>
      <div v-if="record.status === 'action_required'" class="mb-4 rounded-lg border border-danger-500/40 bg-danger-50 px-4 py-3 text-sm text-danger-700" data-test="eet-detail-action">
        <strong>{{ t('eet.state.action_required') }}</strong>
        <span v-if="record.action_code"> ({{ record.action_code }})</span>
        <span v-if="record.last_error?.message" class="block mt-1">{{ record.last_error.message }}</span>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
        <!-- Obsah tržby -->
        <section :class="formBox" data-test="eet-detail-content">
          <h2 class="text-base font-semibold">{{ t('eet.detail.content') }}</h2>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt :class="label">{{ t('eet.records.col_amount') }}</dt>
            <dd class="font-semibold" :class="Number(record.total_amount) < 0 ? 'text-danger-600' : ''">{{ formatMoney(Number(record.total_amount), currency) }}</dd>
            <template v-if="record.payment_event?.exchange_rate">
              <dt :class="label">{{ t('eet.detail.original_payment') }}</dt>
              <dd data-test="eet-detail-fx">
                {{ t('eet.detail.original_payment_value', {
                  amount: formatMoney(Number(record.payment_event.amount), record.payment_event.currency),
                  rate: record.payment_event.exchange_rate,
                  currency: record.payment_event.currency,
                }) }}
                <span v-if="record.payment_event.exchange_rate_source" class="block text-xs text-neutral-500">{{ record.payment_event.exchange_rate_source }}</span>
              </dd>
            </template>
            <template v-if="record.credit_topup_amount">
              <dt :class="label">{{ t('eet.detail.credit_topup') }}</dt><dd data-test="eet-detail-credit-topup">{{ formatMoney(Number(record.credit_topup_amount), currency) }}</dd>
            </template>
            <template v-if="record.credit_draw_amount">
              <dt :class="label">{{ t('eet.detail.credit_draw') }}</dt><dd data-test="eet-detail-credit-draw">{{ formatMoney(Number(record.credit_draw_amount), currency) }}</dd>
            </template>
            <dt :class="label">{{ t('eet.records.col_number') }}</dt><dd class="font-mono">{{ record.sequence_number ?? t('eet.records.none') }}</dd>
            <dt :class="label">{{ t('eet.detail.sale_at') }}</dt><dd>{{ formatDateTime(record.sale_at) }}</dd>
            <dt :class="label">EIČ</dt><dd class="font-mono break-all">{{ record.eic }}</dd>
            <template v-if="record.delegating_eic || record.multiple_delegators">
              <dt :class="label">{{ t('eet.detail.delegation') }}</dt>
              <dd data-test="eet-detail-delegation">
                {{ t(record.multiple_delegators ? 'eet.devices.delegation_modes.multiple' : 'eet.devices.delegation_modes.single') }}
                <span v-if="record.delegating_eic" class="font-mono">({{ t('eet.detail.delegating_eic') }} {{ record.delegating_eic }})</span>
              </dd>
            </template>
            <dt :class="label">{{ t('eet.detail.unit_device') }}</dt><dd>{{ record.unit_number ?? '-' }} / {{ record.device_code ?? '-' }}</dd>
            <dt :class="label">{{ t('eet.records.col_deadline') }}</dt><dd>{{ record.deadline_at ? formatDateTime(record.deadline_at) : '' }}</dd>
            <dt :class="label">{{ t('eet.detail.confirmed_at') }}</dt><dd>{{ record.confirmed_at ? formatDateTime(record.confirmed_at) : t('eet.records.none') }}</dd>
            <dt :class="label">POK</dt><dd class="font-mono text-xs break-all">{{ record.pok || t('eet.records.none') }}</dd>
          </dl>
          <div v-if="record.warnings.length" class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-xs text-warning-700">
            <div class="font-medium">{{ t('eet.detail.warnings') }}</div>
            <span v-for="(w, i) in record.warnings" :key="i" class="block">{{ w.code }}{{ w.message ? ` - ${w.message}` : '' }}</span>
          </div>
        </section>

        <!-- Důvod evidence -->
        <section :class="formBox" data-test="eet-detail-reason">
          <h2 class="text-base font-semibold">{{ t('eet.detail.reason') }}</h2>
          <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            <dt :class="label">{{ t('eet.detail.decision') }}</dt><dd>{{ decisionLabel(record.policy?.decision) }}</dd>
            <dt :class="label">{{ t('eet.detail.reason_code') }}</dt><dd class="break-all">{{ reasonLabel(record.policy?.reason_code) }}</dd>
            <dt :class="label">{{ t('eet.detail.policy_version') }}</dt><dd class="font-mono text-xs">{{ record.policy?.version ?? '' }}</dd>
            <template v-if="record.correction_reason">
              <dt :class="label">{{ t('eet.correction.reason') }}</dt><dd>{{ t(`eet.correction.reasons.${record.correction_reason}`) }}</dd>
            </template>
          </dl>
          <div v-if="sourceInvoices.length" class="pt-1 text-sm">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('eet.detail.sources') }}</h3>
            <div class="flex flex-wrap gap-x-3 gap-y-1 pt-1">
              <RouterLink v-for="inv in sourceInvoices" :key="inv.id" :to="`/invoices/${inv.id}`" class="text-primary-700 hover:underline whitespace-nowrap">
                {{ t('eet.detail.invoice_link', { number: inv.number || `#${inv.id}` }) }}
              </RouterLink>
            </div>
          </div>
        </section>
      </div>

      <!-- Stavy platby, je-li k dispozici -->
      <section v-if="record.payment_event" :class="[formBox, 'mt-4']" data-test="eet-detail-payment">
        <h2 class="text-base font-semibold">{{ t('eet.detail.payment') }}</h2>
        <EetPaymentStates :event="{ ...record.payment_event, eet: record }" />
      </section>

      <!-- Opravy a vazba na původní -->
      <section v-if="record.corrects_record_id || (record.corrections && record.corrections.length)" :class="[formBox, 'mt-4']" data-test="eet-detail-corrections">
        <h2 class="text-base font-semibold">{{ t('eet.detail.corrections') }}</h2>
        <p v-if="record.corrects_record_id" class="text-sm">
          {{ t('eet.detail.corrects') }}
          <RouterLink :to="{ name: 'eet-record-detail', params: { id: record.corrects_record_id } }" class="text-primary-700 hover:underline">#{{ record.corrects_record_id }}</RouterLink>
        </p>
        <ul v-if="record.corrections?.length" class="divide-y divide-neutral-100 text-sm">
          <li v-for="c in record.corrections" :key="c.record_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
            <RouterLink :to="{ name: 'eet-record-detail', params: { id: c.record_id } }" class="text-primary-700 hover:underline whitespace-nowrap">
              {{ c.sequence_number }} - {{ formatDateTime(c.sale_at) }}
            </RouterLink>
            <span class="flex flex-wrap items-center gap-2">
              <span v-if="c.correction_reason" class="text-xs text-neutral-600">{{ t(`eet.correction.reasons.${c.correction_reason}`) }}</span>
              <span class="text-danger-600">{{ formatMoney(Number(c.total_amount), c.currency || currency) }}</span>
              <EetRecordBadge :status="c.status" />
            </span>
          </li>
        </ul>
      </section>

      <!-- Historie pokusů -->
      <section :class="[formBox, 'mt-4']" data-test="eet-detail-attempts">
        <h2 class="text-base font-semibold">{{ t('eet.detail.attempts') }}</h2>
        <p v-if="!record.attempts?.length" class="text-sm text-neutral-500">{{ t('eet.detail.no_attempts') }}</p>
        <template v-else>
          <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th :class="th">#</th>
                  <th :class="th">{{ t('eet.detail.att_time') }}</th>
                  <th :class="th">UUID</th>
                  <th :class="th">{{ t('eet.detail.att_result') }}</th>
                  <th :class="th">HTTP</th>
                  <th :class="th">{{ t('eet.detail.att_code') }}</th>
                  <th :class="th">POK</th>
                  <th :class="th">{{ t('eet.detail.att_duration') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="a in record.attempts" :key="a.attempt_no" class="align-top">
                  <td class="px-3 py-2">{{ a.attempt_no }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(a.sent_at) }}</td>
                  <td class="px-3 py-2 font-mono text-xs break-all">{{ a.uuid_zpravy }}</td>
                  <td class="px-3 py-2">
                    {{ outcomeLabel(a.outcome) }}
                    <span v-if="a.test_flag" :class="[BADGE, 'ml-1 bg-warning-50 text-warning-700 ring-warning-600/20']">{{ t('eet.records.playground') }}</span>
                    <span v-if="a.verification" :class="[BADGE, 'ml-1 bg-neutral-100 text-neutral-600 ring-neutral-500/20']">{{ t('eet.detail.verification') }}</span>
                    <span v-if="a.error_message" class="block text-xs text-neutral-600">{{ a.error_message }}</span>
                  </td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ a.http_status ?? '' }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ a.error_code ?? '' }}</td>
                  <td class="px-3 py-2 font-mono text-xs break-all">{{ a.pok ?? '' }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ a.duration_ms != null ? `${a.duration_ms} ms` : '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="md:hidden divide-y divide-neutral-100">
            <div v-for="a in record.attempts" :key="`m-${a.attempt_no}`" class="py-2 space-y-1 text-sm">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium">#{{ a.attempt_no }} - {{ outcomeLabel(a.outcome) }}</span>
                <span class="text-xs text-neutral-600">{{ formatDateTime(a.sent_at) }}</span>
              </div>
              <div class="font-mono text-xs break-all text-neutral-600">{{ a.uuid_zpravy }}</div>
              <div v-if="a.error_message" class="text-xs text-neutral-600">{{ a.error_code ?? '' }} {{ a.error_message }}</div>
              <div v-if="a.pok" class="font-mono text-xs break-all">POK {{ a.pok }}</div>
              <div v-if="a.duration_ms != null" class="text-xs text-neutral-500">{{ a.duration_ms }} ms</div>
            </div>
          </div>
          <template v-for="a in record.attempts" :key="`x-${a.attempt_no}`">
            <details v-if="a.request_xml || a.response_xml" class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs">
              <summary class="cursor-pointer font-medium">{{ t('eet.detail.xml', { n: a.attempt_no }) }}</summary>
              <pre v-if="a.request_xml" class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-all">{{ a.request_xml }}</pre>
              <pre v-if="a.response_xml" class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-all">{{ a.response_xml }}</pre>
            </details>
          </template>
        </template>
      </section>

      <EetCorrectionDialog v-if="correctionOpen" :record="record" @close="correctionOpen = false; load()" @done="onCorrectionDone" />
    </template>
  </div>
</template>

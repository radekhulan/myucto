<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { eetApi, type EetCreditNoteRefundInfo, type EetInvoiceSummaryItem } from '@/api/eet'
import { formatDateTime, formatMoney } from '@/composables/useFormat'
import EetRecordBadge from './EetRecordBadge.vue'

/**
 * Box „EET" na detailu faktury: souhrn po jednotlivých platbách, ne jedno POK
 * za celý doklad. Stav zaplacení faktury zůstává vedle, v boxu Platby.
 */
const props = defineProps<{
  invoiceId: number
  /** Změna hodnoty vyvolá načtení (po přijetí platby, po obnově stránky). */
  refreshKey?: number
}>()

const emit = defineEmits<{ (e: 'loaded', items: EetInvoiceSummaryItem[]): void }>()

const { t } = useI18n()
const items = ref<EetInvoiceSummaryItem[]>([])
/** Dobropis: zbývající evidovaná část tržeb opravované faktury pro vrácení peněz. */
const refund = ref<EetCreditNoteRefundInfo | null>(null)
const loaded = ref(false)
let generation = 0

async function load() {
  const gen = ++generation
  try {
    const r = await eetApi.invoiceSummary(props.invoiceId)
    if (gen === generation) { items.value = r.items; refund.value = r.refund ?? null; emit('loaded', r.items) }
  } catch {
    if (gen === generation) { items.value = []; refund.value = null; emit('loaded', []) }
  } finally {
    if (gen === generation) loaded.value = true
  }
}
onMounted(() => { void load() })
onActivated(() => { if (loaded.value) void load() })
watch(() => [props.invoiceId, props.refreshKey], () => { void load() })

const counts = computed(() => {
  let confirmed = 0
  let waiting = 0
  let action = 0
  let none = 0
  let external = 0
  for (const it of items.value) {
    const s = it.eet_record?.status ?? it.eet?.status
    if (!s) none++
    else if (s === 'external') external++
    else if (s === 'confirmed' || s === 'confirmed_warning' || s === 'verification_ok') confirmed++
    else if (s === 'action_required') action++
    else waiting++
  }
  return { confirmed, waiting, action, none, external }
})

/** Text k vrácení peněz u dobropisu: kolik se nejvýš zaeviduje jako záporná tržba. */
const refundText = computed(() => {
  const r = refund.value
  if (!r || r.evidenced.records === 0) return null
  const remaining = Number(r.evidenced.remaining_czk)
  if (remaining <= 0) return t('eet.invoice.refund_done', { number: r.invoice_number })
  const amount = r.evidenced.remaining !== null && r.evidenced.currency
    ? formatMoney(Number(r.evidenced.remaining), r.evidenced.currency)
    : formatMoney(remaining, 'CZK')
  return t('eet.invoice.refund_info', { number: r.invoice_number, amount })
})

const summaryText = computed(() => {
  const c = counts.value
  const parts: string[] = []
  if (c.confirmed) parts.push(t('eet.invoice.sum_confirmed', { n: c.confirmed }))
  if (c.waiting) parts.push(t('eet.invoice.sum_waiting', { n: c.waiting }))
  if (c.action) parts.push(t('eet.invoice.sum_action', { n: c.action }))
  if (c.external) parts.push(t('eet.invoice.sum_external', { n: c.external }))
  if (c.none) parts.push(t('eet.invoice.sum_none', { n: c.none }))
  return parts.join(', ')
})

function moneyLabel(it: EetInvoiceSummaryItem): string {
  if (it.payment_order_status === 'failed') return t('eet.pay.money.failed')
  if (it.payment_order_status === 'cancelled') return t('eet.pay.money.cancelled')
  if (it.payment_order_status === 'initiated') return t('eet.pay.money.initiated')
  if (it.bank_verification_status === 'pending') return t('eet.pay.money.bank_pending')
  if (it.bank_verification_status === 'verified') return t('eet.pay.money.verified')
  return t('eet.pay.money.received')
}
</script>

<template>
  <section v-if="items.length > 0 || refundText" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden" data-test="eet-invoice-summary">
    <div class="px-5 py-3 border-b border-neutral-200 flex flex-wrap items-baseline justify-between gap-2">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('eet.invoice.title') }}</h3>
      <span class="text-xs text-neutral-600" data-test="eet-invoice-sum">{{ summaryText }}</span>
    </div>
    <p v-if="refundText" class="px-5 py-2 text-sm text-neutral-700 border-b border-neutral-100" data-test="eet-invoice-refund">{{ refundText }}</p>
    <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-4 py-2 text-left font-medium">{{ t('eet.invoice.col_time') }}</th>
            <th class="px-4 py-2 text-right font-medium">{{ t('eet.invoice.col_amount') }}</th>
            <th class="px-4 py-2 text-left font-medium">{{ t('eet.invoice.col_money') }}</th>
            <th class="px-4 py-2 text-left font-medium">{{ t('eet.invoice.col_eet') }}</th>
            <th class="px-4 py-2 text-left font-medium">POK</th>
            <th class="px-4 py-2"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="it in items" :key="it.payment_event_id">
            <td class="px-4 py-2 whitespace-nowrap">{{ formatDateTime(it.received_at || it.recorded_at) }}</td>
            <td class="px-4 py-2 text-right whitespace-nowrap">
              {{ formatMoney(Number(it.amount), it.currency) }}
              <span v-if="it.exchange_rate && it.amount_czk" class="block text-xs text-neutral-500">{{ t('eet.invoice.czk_in_eet', { amount: formatMoney(Number(it.amount_czk), 'CZK') }) }}</span>
            </td>
            <td class="px-4 py-2 text-xs whitespace-nowrap">{{ moneyLabel(it) }}</td>
            <td class="px-4 py-2"><EetRecordBadge :status="it.eet_record?.status ?? it.eet?.status" empty-key="eet.state.not_recorded" /></td>
            <td class="px-4 py-2 font-mono text-xs break-all">{{ it.eet_record?.pok ?? it.eet?.pok ?? '' }}</td>
            <td class="px-4 py-2 text-right">
              <RouterLink v-if="it.eet_record" :to="{ name: 'eet-record-detail', params: { id: it.eet_record.record_id } }"
                class="text-xs text-primary-700 hover:underline whitespace-nowrap">{{ t('eet.invoice.record_link') }}</RouterLink>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="md:hidden divide-y divide-neutral-100">
      <div v-for="it in items" :key="`m-${it.payment_event_id}`" class="px-4 py-3 space-y-1">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="font-medium">
            {{ formatMoney(Number(it.amount), it.currency) }}
            <span v-if="it.exchange_rate && it.amount_czk" class="text-xs font-normal text-neutral-500">({{ t('eet.invoice.czk_in_eet', { amount: formatMoney(Number(it.amount_czk), 'CZK') }) }})</span>
          </span>
          <EetRecordBadge :status="it.eet_record?.status ?? it.eet?.status" empty-key="eet.state.not_recorded" />
        </div>
        <div class="text-xs text-neutral-600">{{ formatDateTime(it.received_at || it.recorded_at) }} · {{ moneyLabel(it) }}</div>
        <div v-if="it.eet_record?.pok ?? it.eet?.pok" class="font-mono text-xs break-all">POK {{ it.eet_record?.pok ?? it.eet?.pok }}</div>
        <RouterLink v-if="it.eet_record" :to="{ name: 'eet-record-detail', params: { id: it.eet_record.record_id } }"
          class="text-xs text-primary-700 hover:underline">{{ t('eet.invoice.record_link') }}</RouterLink>
      </div>
    </div>
  </section>
</template>

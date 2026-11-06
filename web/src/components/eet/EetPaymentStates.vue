<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { EetPaymentEvent } from '@/api/eet'
import { formatMoney } from '@/composables/useFormat'
import EetRecordBadge from './EetRecordBadge.vue'

/**
 * Tři odděleně čtené stavy jedné platební události: peníze, účetní doklad a EET.
 * Zobrazení QR ani timeout EET nic z toho nemění za jiný stav.
 */
const props = defineProps<{ event: EetPaymentEvent }>()

const { t } = useI18n()

const BADGE = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap'
const OK = 'bg-success-50 text-success-700 ring-success-600/20'
const WARN = 'bg-warning-50 text-warning-700 ring-warning-600/20'
const BAD = 'bg-danger-50 text-danger-700 ring-danger-600/20'
const OFF = 'bg-neutral-100 text-neutral-600 ring-neutral-500/20'

/** Oprava / vratka: žádné přijetí peněz ani bankovní párování, jen případné samostatné zaúčtování vrácení. */
const isRefund = computed(() => props.event.kind === 'refund')

const money = computed(() => {
  const e = props.event
  if (isRefund.value) {
    return e.accounting_settlement_status === 'pending'
      ? { cls: WARN, label: t('eet.pay.money.refund_book') }
      : { cls: OFF, label: t('eet.pay.money.no_movement') }
  }
  if (e.payment_order_status === 'failed') return { cls: BAD, label: t('eet.pay.money.failed') }
  if (e.payment_order_status === 'cancelled') return { cls: OFF, label: t('eet.pay.money.cancelled') }
  if (e.payment_order_status === 'initiated') return { cls: OFF, label: t('eet.pay.money.initiated') }
  if (e.bank_verification_status === 'verified') return { cls: OK, label: t('eet.pay.money.verified') }
  if (e.bank_verification_status === 'pending') return { cls: WARN, label: t('eet.pay.money.bank_pending') }
  if (e.payment_order_status === 'operator_confirmed') return { cls: WARN, label: t('eet.pay.money.operator_confirmed') }
  return { cls: OK, label: t('eet.pay.money.received') }
})

const doc = computed(() => {
  const s = props.event.accounting_settlement_status
  if (s === 'settled') return { cls: OK, label: t('eet.pay.doc.settled') }
  if (s === 'pending') return { cls: WARN, label: t('eet.pay.doc.pending') }
  return { cls: OFF, label: t('eet.pay.doc.not_applicable') }
})

const eetNote = computed(() => {
  const e = props.event.eet
  if (!e) return ''
  if (e.status === 'retry_wait' || e.status === 'pending' || e.status === 'sending') return t('eet.pay.eet_waiting')
  if (e.status === 'action_required') return t('eet.pay.eet_action')
  return ''
})
</script>

<template>
  <dl class="space-y-2 text-sm" data-test="eet-payment-states">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <dt class="text-neutral-600">{{ t('eet.pay.state_money') }}</dt>
      <dd><span :class="[BADGE, money.cls]">{{ money.label }}</span></dd>
    </div>
    <div v-if="!isRefund" class="flex flex-wrap items-center justify-between gap-2">
      <dt class="text-neutral-600">{{ t('eet.pay.state_doc') }}</dt>
      <dd><span :class="[BADGE, doc.cls]">{{ doc.label }}</span></dd>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <dt class="text-neutral-600">{{ t('eet.pay.state_eet') }}</dt>
      <dd class="flex flex-wrap items-center gap-2">
        <EetRecordBadge :status="event.eet?.status" empty-key="eet.state.not_recorded" />
        <span v-if="event.eet?.pok" class="font-mono text-xs text-neutral-600 break-all">POK {{ event.eet.pok }}</span>
      </dd>
    </div>
    <p v-if="eetNote" class="text-xs text-neutral-600">{{ eetNote }}</p>
    <p v-if="event.eet?.status === 'confirmed_warning' && event.eet.warnings.length" class="text-xs text-warning-700">
      <span v-for="(w, i) in event.eet.warnings" :key="i" class="block">{{ w.message || w.code }}</span>
    </p>
    <p class="text-xs text-neutral-500">{{ formatMoney(Number(event.amount), event.currency) }}</p>
  </dl>
</template>

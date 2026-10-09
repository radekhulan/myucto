<script setup lang="ts">
/**
 * Platební kalendář (issue #140): z tohoto dokladu (první platba) založí další platby
 * se stejným číslem, každou se svou splatností a částkou. Platby se předvyplní měsíčně
 * od splatnosti vzoru, uživatel je upraví.
 */
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ invoiceId: number; dueDate: string; amount: number; currency: string }>()
const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const pageId = useId()

function addMonths(iso: string, months: number): string {
  const [y, m, d] = iso.split('-').map(Number)
  const target = new Date(Date.UTC(y, m - 1 + months, 1))
  const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate()
  target.setUTCDate(Math.min(d, lastDay))
  return target.toISOString().slice(0, 10)
}

const count = ref(11)
const rows = ref<Array<{ due_date: string; amount: number }>>([])
function generate() {
  const n = Math.max(1, Math.min(60, Math.floor(Number(count.value) || 1)))
  rows.value = Array.from({ length: n }, (_, i) => ({ due_date: addMonths(props.dueDate, i + 1), amount: props.amount }))
}
generate()

const total = computed(() => rows.value.reduce((s, r) => s + (Number(r.amount) || 0), 0))
const valid = computed(() => rows.value.length > 0 && rows.value.every(r => !!r.due_date && Number(r.amount) > 0))
const busy = ref(false)

function addRow() {
  const last = rows.value[rows.value.length - 1]
  rows.value.push({ due_date: addMonths(last?.due_date ?? props.dueDate, 1), amount: props.amount })
}

async function save() {
  busy.value = true
  try {
    const res = await purchaseInvoicesApi.createPaymentCalendar(props.invoiceId, rows.value.map(r => ({
      due_date: r.due_date, amount: Number(r.amount),
    })))
    toast.success(t('purchase_invoice.payment_calendar.created', { count: res.created_ids.length }))
    emit('close')
    router.push('/purchase-invoices')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal :title="t('purchase_invoice.payment_calendar.title')" width-class="max-w-xl" @close="emit('close')">
    <p class="text-sm text-neutral-600 mb-4">{{ t('purchase_invoice.payment_calendar.help') }}</p>
    <div class="flex flex-wrap items-end gap-2 mb-3">
      <div>
        <label :for="`${pageId}-count`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('purchase_invoice.payment_calendar.count') }}</label>
        <input :id="`${pageId}-count`" v-model.number="count" type="number" min="1" max="60" class="w-24 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
      </div>
      <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="generate">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ t('purchase_invoice.payment_calendar.generate') }}
      </button>
    </div>
    <div class="space-y-2 max-h-80 overflow-y-auto">
      <div v-for="(r, i) in rows" :key="i" class="flex items-center gap-2" data-test="payment-calendar-row">
        <span class="w-6 text-xs text-neutral-400 text-right">{{ i + 2 }}.</span>
        <input v-model="r.due_date" type="date" :aria-label="t('purchase_invoice.payment_calendar.due_date')"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        <input v-model.number="r.amount" type="number" step="0.01" min="0" :aria-label="t('purchase_invoice.payment_calendar.amount')"
          class="w-full min-w-0 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
        <button type="button" :class="btnOutlineSm('danger')" :title="t('common.delete')" @click="rows.splice(i, 1)">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
        </button>
      </div>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-2 mt-3">
      <button type="button" :class="btnOutlineSm('primary')" @click="addRow">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('purchase_invoice.payment_calendar.add') }}
      </button>
      <span class="text-sm text-neutral-600">{{ t('purchase_invoice.payment_calendar.total', { count: rows.length, total: formatMoney(total, currency) }) }}</span>
    </div>
    <div class="flex flex-wrap justify-end gap-2 mt-4">
      <button type="button" :class="btnOutline('neutral')" @click="emit('close')">{{ t('common.cancel') }}</button>
      <button type="button" :class="btnFilled('primary')" :disabled="busy || !valid" data-test="payment-calendar-save" @click="save">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ t('purchase_invoice.payment_calendar.save') }}
      </button>
    </div>
  </Modal>
</template>

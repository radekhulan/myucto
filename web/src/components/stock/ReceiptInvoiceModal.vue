<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { eshopApi, type PriceLevel } from '@/api/eshop'
import { stockApi, type ReceiptInvoiceCreated, type ReceiptSalesInvoice, type StockDocument } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import { formInput } from '@/components/ui/formStyles'
import Modal from '@/components/ui/Modal.vue'

/**
 * Koncept vydané faktury z příjemky: odběratel, cenová hladina dokladu a vybrané řádky.
 * Ceny a daně řeší backend, doklad se otevře v editoru ke kontrole. Nevystavuje se.
 */
const props = defineProps<{ document: StockDocument }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()
const router = useRouter()

const clientId = ref<number | null>(null)
const priceLevels = ref<PriceLevel[]>([])
const priceLevelId = ref<number | null>(null)
const saving = ref(false)
const error = ref('')
/** Faktury ze 409: detail příjemky mohl být starší než stav na serveru. */
const conflicts = ref<ReceiptSalesInvoice[]>([])
/** Koncept vznikl, ale je k němu co říct dřív, než se otevře editor. */
const created = ref<ReceiptInvoiceCreated | null>(null)

const lines = computed(() => props.document.lines ?? [])
/** Faktury, které řádky pořád „drží". Stornovaná zboží uvolnila. */
const documentInvoices = computed(() => (props.document.sales_invoices ?? []).filter(i => i.status !== 'cancelled'))
const liveInvoices = computed(() => {
  const known = new Set(documentInvoices.value.flatMap(i => i.line_nos))
  return [...documentInvoices.value, ...conflicts.value.filter(i => !i.line_nos.every(n => known.has(n)))]
})
const invoicedLineNos = computed(() => new Set(liveInvoices.value.flatMap(i => i.line_nos)))
// Už vyfakturované řádky se nabízejí nezaškrtnuté, přidat je jde vědomě.
const selected = ref(new Set(lines.value.filter(l => !invoicedLineNos.value.has(l.line_no ?? 0)).map(l => l.id!)))
const selectedHasInvoiced = computed(() => lines.value.some(l => selected.value.has(l.id!) && invoicedLineNos.value.has(l.line_no ?? 0)))

const unavailableLines = computed(() => created.value?._warning_meta?.receipt_tracking_unavailable?.lines ?? [])
const foreignVatLines = computed(() => created.value?._warning_meta?.receipt_foreign_client_domestic_vat?.lines ?? [])
/** Ostatní kódy z `_warnings`: editor si je z odpovědi nenačte, jinde by se ztratily. */
const otherWarnings = computed(() => (created.value?._warnings ?? []).flatMap((code): string[] => {
  const meta = created.value?._warning_meta
  if (code === 'receipt_tracking_unavailable' || code === 'receipt_foreign_client_domestic_vat') return []
  if (code === 'oss_derived_notes' && meta?.oss_derived_notes) {
    return meta.oss_derived_notes.items.map(n => t('stock.receipt_invoice.oss_note', { item: n.item, message: n.message }))
  }
  if (code === 'exchange_rate_cnb_deviation' && meta?.exchange_rate_cnb_deviation) {
    const m = meta.exchange_rate_cnb_deviation
    return [t('invoice.warning.exchange_rate_cnb_deviation', { used: m.used_rate.toFixed(3), cnb: m.cnb_rate.toFixed(3), diff: m.diff_percent.toFixed(2) })]
  }
  return [t(`invoice.warning.${code}`)]
}))

function toggle(id: number) {
  const next = new Set(selected.value)
  if (!next.delete(id)) next.add(id)
  selected.value = next
}

function serials(allocations: { serial_number?: string | null; lot_code?: string | null }[] = []): string {
  return allocations.map(a => a.serial_number || a.lot_code).filter(Boolean).join(', ')
}

function alreadyInvoiced(i: ReceiptSalesInvoice): string {
  const lines = i.line_nos.join(', ')
  if (!i.invoice_id) return t('stock.receipt_invoice.already_invoiced_hidden', { lines })
  const number = i.varsymbol || t('stock.receipt_invoice.draft')
  return i.client_name
    ? t('stock.receipt_invoice.already_invoiced', { lines, number, client: i.client_name })
    : t('stock.receipt_invoice.already_invoiced_no_client', { lines, number })
}

function openDraft(id: number) {
  return router.push(`/invoices/${id}/edit`)
}

async function submit() {
  if (clientId.value === null || selected.value.size === 0) return
  saving.value = true
  error.value = ''
  try {
    const invoice = await stockApi.createInvoiceFromReceipt(props.document.id, {
      client_id: clientId.value,
      price_level_id: priceLevelId.value,
      lines: [...selected.value].map(line_id => ({ line_id })),
      allow_duplicate: selectedHasInvoiced.value,
    })
    if (invoice._warnings?.length) created.value = invoice
    else await openDraft(invoice.id)
  } catch (e: any) {
    const failure = e?.response?.data?.error
    if (failure?.code === 'stock.error.receipt_already_invoiced' && Array.isArray(failure.items)) conflicts.value = failure.items
    else error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try { priceLevels.value = (await eshopApi.listPriceLevels()).filter(l => l.is_active) } catch { priceLevels.value = [] }
})
</script>

<template>
  <Modal :title="t('stock.receipt_invoice.title')" @close="emit('close')">
    <div v-if="created" data-test="created-warnings" class="space-y-3 text-sm">
      <p class="text-neutral-700">{{ t('stock.receipt_invoice.created') }}</p>
      <div v-if="unavailableLines.length > 0" data-test="unavailable" class="px-3 py-2.5 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 space-y-1">
        <p class="font-medium">{{ t('stock.receipt_invoice.unavailable') }}</p>
        <p v-for="l in unavailableLines" :key="l.line_id">
          {{ t('stock.receipt_invoice.unavailable_line', { line: l.line_no, sku: l.sku, units: l.units.map(u => u.serial_number || t('stock.receipt_invoice.unavailable_lot', { lot: u.lot_code ?? '', available: u.available, requested: u.requested })).join(', ') }) }}
        </p>
      </div>
      <p v-if="foreignVatLines.length > 0" data-test="foreign-vat" class="px-3 py-2.5 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700">
        {{ t('stock.receipt_invoice.foreign_vat', { lines: foreignVatLines.map(l => l.line_no).join(', ') }) }}
      </p>
      <div v-if="otherWarnings.length > 0" data-test="other-warnings" class="px-3 py-2.5 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 space-y-1">
        <p v-for="(text, index) in otherWarnings" :key="index">{{ text }}</p>
      </div>
    </div>

    <div v-else class="space-y-4">
      <p class="text-sm text-neutral-500">{{ t('stock.receipt_invoice.hint') }}</p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label for="receipt-invoice-client" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('invoice.client') }} *</label>
          <ClientSearchSelect v-model="clientId" teleport input-id="receipt-invoice-client" :placeholder="t('invoice.select_client')" />
        </div>
        <div v-if="priceLevels.length > 0">
          <label for="receipt-invoice-level" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.receipt_invoice.price_level') }}</label>
          <select id="receipt-invoice-level" v-model="priceLevelId" :class="formInput">
            <option :value="null">{{ t('stock.receipt_invoice.price_level_client') }}</option>
            <option v-for="l in priceLevels" :key="l.id" :value="l.id">{{ l.name }}</option>
          </select>
        </div>
      </div>

      <div v-if="liveInvoices.length > 0" data-test="already-invoiced"
        class="px-3 py-2.5 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 text-sm space-y-1">
        <p v-for="(i, index) in liveInvoices" :key="index">{{ alreadyInvoiced(i) }}</p>
      </div>

      <div class="border border-neutral-200 rounded-lg overflow-hidden divide-y divide-neutral-100">
        <label v-for="l in lines" :key="l.id" class="flex items-start gap-3 p-3 text-sm cursor-pointer hover:bg-neutral-50">
          <input type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" :checked="selected.has(l.id!)" @change="toggle(l.id!)" />
          <span class="font-mono text-xs text-neutral-400 w-5 shrink-0 mt-0.5">{{ l.line_no }}</span>
          <span class="min-w-0 flex-1">
            <span class="font-mono text-xs text-neutral-400 mr-2">{{ l.sku }}</span>{{ l.name }}
            <span v-if="serials(l.tracking_allocations)" class="block text-xs text-neutral-500 break-words">{{ serials(l.tracking_allocations) }}</span>
            <span v-if="invoicedLineNos.has(l.line_no ?? 0)" class="block text-xs text-warning-600">{{ t('stock.receipt_invoice.line_invoiced') }}</span>
          </span>
          <span class="font-mono text-xs text-neutral-500 whitespace-nowrap">{{ l.qty }} {{ l.unit }}</span>
        </label>
      </div>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>
    </div>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button v-if="created" type="button" data-test="open-draft" :class="btnFilled('primary')" class="whitespace-nowrap" @click="openDraft(created.id)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          {{ t('stock.receipt_invoice.open_draft') }}
        </button>
        <template v-else>
          <button type="button" data-test="cancel" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('close')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" data-test="create-invoice" :disabled="saving || clientId === null || selected.size === 0"
            :class="btnFilled(selectedHasInvoiced ? 'warning' : 'primary')" class="whitespace-nowrap" @click="submit">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            {{ selectedHasInvoiced ? t('stock.receipt_invoice.submit_anyway') : t('stock.receipt_invoice.submit') }}
          </button>
        </template>
      </div>
    </template>
  </Modal>
</template>

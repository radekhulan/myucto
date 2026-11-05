<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { clientsApi } from '@/api/clients'
import { eshopApi, type PriceLevel } from '@/api/eshop'
import { stockApi, type StockDocument } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

/**
 * Koncept vydané faktury z příjemky: odběratel, cenová hladina dokladu a vybrané řádky.
 * Ceny a daně řeší backend, doklad se otevře v editoru ke kontrole. Nevystavuje se.
 */
const props = defineProps<{ document: StockDocument }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()
const router = useRouter()

const clientId = ref<number | null>(null)
const clientOptions = ref<{ value: number; label: string; secondary?: string }[]>([])
const clientsLoading = ref(false)
let clientSearchVersion = 0
const selectedClientOption = ref<{ value: number; label: string; secondary?: string } | null>(null)
watch(clientId, id => { selectedClientOption.value = clientOptions.value.find(o => o.value === id) ?? null })
const priceLevels = ref<PriceLevel[]>([])
const priceLevelId = ref<number | null>(null)
const saving = ref(false)
const error = ref('')

const lines = computed(() => props.document.lines ?? [])
/** Faktury, které řádky pořád „drží". Stornovaná zboží uvolnila. */
const liveInvoices = computed(() => (props.document.sales_invoices ?? []).filter(i => i.status !== 'cancelled'))
const invoicedLineNos = computed(() => new Set(liveInvoices.value.flatMap(i => i.line_nos)))
// Už vyfakturované řádky se nabízejí nezaškrtnuté, přidat je jde vědomě.
const selected = ref(new Set(lines.value.filter(l => !invoicedLineNos.value.has(l.line_no ?? 0)).map(l => l.id!)))
const selectedHasInvoiced = computed(() => lines.value.some(l => selected.value.has(l.id!) && invoicedLineNos.value.has(l.line_no ?? 0)))

function toggle(id: number) {
  const next = new Set(selected.value)
  if (!next.delete(id)) next.add(id)
  selected.value = next
}

function serials(allocations: { serial_number?: string | null; lot_code?: string | null }[] = []): string {
  return allocations.map(a => a.serial_number || a.lot_code).filter(Boolean).join(', ')
}

async function onClientSearch(q: string) {
  const version = ++clientSearchVersion
  clientsLoading.value = true
  try {
    const query = q.trim()
    const res = await clientsApi.list({ q: query || undefined, role: query ? 'all' : 'customers', archived: false, per_page: 50 })
    if (version !== clientSearchVersion) return
    clientOptions.value = res.data.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))
  } catch { /* našeptávač: chybu uvidí uživatel jako prázdný seznam */ } finally {
    if (version === clientSearchVersion) clientsLoading.value = false
  }
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
    await router.push(`/invoices/${invoice.id}/edit`)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  void onClientSearch('')
  try { priceLevels.value = (await eshopApi.listPriceLevels()).filter(l => l.is_active) } catch { priceLevels.value = [] }
})
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto" @click.self="emit('close')">
    <div class="bg-surface rounded-xl shadow-lg max-w-3xl w-full my-8">
      <header class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="text-lg font-semibold">{{ t('stock.receipt_invoice.title') }}</h3>
        <button type="button" :aria-label="t('common.close')" @click="emit('close')" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
      </header>

      <div class="p-5 space-y-4">
        <p class="text-sm text-neutral-500">{{ t('stock.receipt_invoice.hint') }}</p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('invoice.client') }} *</label>
            <SearchableSelect v-model="clientId" remote :loading="clientsLoading" :options="clientOptions" :selected-option="selectedClientOption"
              :placeholder="t('invoice.select_client')" @search="onClientSearch" />
          </div>
          <div v-if="priceLevels.length > 0">
            <label for="receipt-invoice-level" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.receipt_invoice.price_level') }}</label>
            <select id="receipt-invoice-level" v-model="priceLevelId" class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
              <option :value="null">{{ t('stock.receipt_invoice.price_level_client') }}</option>
              <option v-for="l in priceLevels" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
          </div>
        </div>

        <div v-if="liveInvoices.length > 0" data-test="already-invoiced"
          class="px-3 py-2.5 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 text-sm space-y-1">
          <p v-for="(i, index) in liveInvoices" :key="index">
            <template v-if="i.invoice_id">{{ t('stock.receipt_invoice.already_invoiced', { lines: i.line_nos.join(', '), number: i.varsymbol || t('stock.receipt_invoice.draft'), client: i.client_name ?? '' }) }}</template>
            <template v-else>{{ t('stock.receipt_invoice.already_invoiced_hidden', { lines: i.line_nos.join(', ') }) }}</template>
          </p>
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

        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="emit('close')">{{ t('common.cancel') }}</button>
          <button type="button" data-test="create-invoice" :class="btnFilled(selectedHasInvoiced ? 'warning' : 'primary')"
            :disabled="saving || clientId === null || selected.size === 0" @click="submit">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            {{ saving ? t('common.saving') : selectedHasInvoiced ? t('stock.receipt_invoice.submit_anyway') : t('stock.receipt_invoice.submit') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

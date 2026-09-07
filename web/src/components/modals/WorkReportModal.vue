<script setup lang="ts">
/**
 * Modální editor výkazu pro DRAFT fakturu. Integruje DVĚ sekce:
 *   1. Výkaz práce    — řádky práce (description, work_date, hours, rate) + sazba DPH (12/21, default 21)
 *   2. Výkaz materiálu — řádky materiálu ve stylu editoru faktury (popis, množství, MJ, cena/MJ)
 *                        + sazba DPH (12/21, default 12); cena v cenové konvenci dokladu
 *                        (prices_include_vat → s DPH / bez DPH)
 *
 * Použití:
 *   <WorkReportModal v-model="open" :invoice-id="id" @saved="reload" />
 *
 * Flow:
 *   1. Při open načte invoice + existing work_report (obě části) + číselníky (units, vat-rates)
 *   2. Save:
 *      a. PUT /api/invoices/{id}/work-report           (práce + vat_rate_id)
 *      b. PUT /api/invoices/{id}/work-report/materials (materiál + material_vat_rate_id)
 *      c. PUT /api/invoices/{id} — synchronizace DVOU souhrnných položek faktury:
 *         „Práce" (= title výkazu) a „Materiál" (= material_title). Každá nese svou sazbu DPH;
 *         InvoiceMath dopočítá DPH zdola/shora dle prices_include_vat dokladu.
 *   3. emit('saved') → parent reload
 *
 * Oba výkazy se ukládají (i schvalují) společně — sdílí jednu work_reports řádku.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { invoicesApi, type WorkReportItem, type WorkReportMaterial } from '@/api/invoices'
import { codebooksApi, type Unit, type VatRate } from '@/api/codebooks'
import { useToast } from '@/composables/useToast'
import { focusLastRow } from '@/composables/useRowFocus'
import { apiErrorMessage } from '@/api/errors'
import { useSupplierStore } from '@/stores/supplier'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()

const props = defineProps<{
  modelValue: boolean
  invoiceId: number
}>()
const emit = defineEmits<{
  (e: 'update:modelValue', open: boolean): void
  (e: 'saved'): void
}>()

const loading = ref(false)
const saving = ref(false)
const error = ref('')

// ── Výkaz práce ──
const wrOpen = ref(false)
const wrTitle = ref('')
const wrItems = ref<WorkReportItem[]>([])
const workVatRateId = ref<number | null>(null)
const origWorkTitle = ref('')

// ── Výkaz materiálu ──
const matOpen = ref(false)
const matTitle = ref('')
const matItems = ref<WorkReportMaterial[]>([])
const matVatRateId = ref<number | null>(null)
const origMatTitle = ref('')

// ── Sdílené ──
const wrExistedOnLoad = ref(false)  // existoval výkaz (work_reports řádka) při otevření?
const projectId = ref<number | null>(null)
const defaultRate = ref(1500)
const defaultVatRateId = ref<number | null>(null)
const currency = ref('CZK')
const pricesIncludeVat = ref(false)
const units = ref<Unit[]>([])
const vatRates = ref<VatRate[]>([])

const vatOptions = computed(() => vatRates.value.filter(r => !r.is_reverse_charge))
function vatLabel(r: VatRate): string {
  return locale.value === 'en' ? r.label_en : r.label_cs
}
function rateIdByPercent(p: number): number | null {
  const r = vatRates.value.find(x => Math.round(Number(x.rate_percent)) === p)
  return r ? r.id : null
}
// Neplátce DPH → 0% „Osvobozeno" (rate_percent=0, ne reverse charge). Zrcadlí
// defaultVatRateId() z InvoiceEditor.vue, aby výkaz nezapekl DPH do položek faktury.
function zeroVatRateId(): number | null {
  const r = vatRates.value.find(x => Number(x.rate_percent) === 0 && !x.is_reverse_charge)
  return r ? r.id : null
}
const defaultUnit = computed(() => units.value.find(u => u.code === 'ks')?.code || 'ks')

const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)
// Cena s/bez DPH dle režimu dokladu (jen plátce DPH; jinak nemá smysl rozlišovat).
const pricesInclVat = computed(() => pricesIncludeVat.value && supplierIsVatPayer.value)
const unitPriceHeaderLabel = computed(() => pricesInclVat.value
  ? t('invoice.items_table.unit_price_gross')
  : t('invoice.items_table.unit_price'))

// ── Práce: výpočty ──
const workItemsValid = computed(() =>
  wrItems.value.filter(i => (i.description || '').trim() !== '' && Number(i.hours) > 0)
)
const totalHours = computed(() => workItemsValid.value.reduce((s, i) => s + Number(i.hours || 0), 0))
const totalAmount = computed(() =>
  workItemsValid.value.reduce((s, i) => s + Number(i.hours || 0) * Number(i.rate || 0), 0)
)

// ── Materiál: výpočty ──
const matItemsValid = computed(() =>
  matItems.value.filter(i => (i.description || '').trim() !== '' && Number(i.quantity) > 0)
)
const matTotal = computed(() =>
  matItemsValid.value.reduce((s, i) => s + Math.round(Number(i.quantity || 0) * Number(i.unit_price || 0) * 100) / 100, 0)
)

const canSave = computed(() =>
  // Lze uložit i prázdný stav, pokud výkaz existoval — tím se smaže (vč. položek faktury).
  (workItemsValid.value.length > 0 || matItemsValid.value.length > 0 || wrExistedOnLoad.value) && !saving.value
)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [inv, wr, unitsList, vatList] = await Promise.all([
      invoicesApi.get(props.invoiceId),
      invoicesApi.getWorkReport(props.invoiceId).catch(() => null),
      codebooksApi.units().catch(() => [] as Unit[]),
      codebooksApi.vatRates('CZ').catch(() => [] as VatRate[]),
    ])
    wrExistedOnLoad.value = !!wr
    projectId.value = inv.project_id ?? null
    currency.value = inv.currency || 'CZK'
    pricesIncludeVat.value = !!inv.prices_include_vat
    units.value = unitsList
    vatRates.value = vatList
    // Default VAT pro nový invoice item — vezmi z prvního existujícího řádku
    if (inv.items && inv.items.length > 0) {
      defaultVatRateId.value = inv.items[0].vat_rate_id ?? null
    }

    // ── Práce ── (sekce zabalená, dokud nemá řádky)
    const date = (inv.tax_date || inv.issue_date || '').slice(0, 7) // YYYY-MM
    wrTitle.value = wr?.title || (date ? t('invoice.wr_title_with_date', { date }) : t('invoice.work_report'))
    origWorkTitle.value = wr?.title ?? ''
    wrItems.value = (wr?.items ?? []).map(i => ({ ...i }))
    // Neplátce DPH: vždy 0% (ignoruj případnou uloženou sazbu i default 21 %), ať se
    // do položek faktury nepropíše DPH. Plátce: uložená sazba → 21 % → fallback.
    workVatRateId.value = supplierIsVatPayer.value
      ? (wr?.vat_rate_id ?? rateIdByPercent(21) ?? defaultVatRateId.value)
      : (zeroVatRateId() ?? defaultVatRateId.value)
    wrOpen.value = wrItems.value.length > 0

    // ── Materiál ── (sekce zabalená, dokud nemá řádky)
    matTitle.value = wr?.material_title || t('invoice.wr_material_title')
    origMatTitle.value = wr?.material_title ?? ''
    matItems.value = (wr?.materials ?? []).map(m => ({ ...m }))
    matVatRateId.value = supplierIsVatPayer.value
      ? (wr?.material_vat_rate_id ?? rateIdByPercent(12) ?? defaultVatRateId.value)
      : (zeroVatRateId() ?? defaultVatRateId.value)
    matOpen.value = matItems.value.length > 0
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}

// ── Práce: rozbalení / zabalení sekce ──
function openWork() {
  if (wrItems.value.length === 0) addItem()
  wrOpen.value = true
}
function closeWork() { wrItems.value = []; wrOpen.value = false }

// ── Práce: řádky ──
function addItem() {
  wrItems.value.push({
    description: '',
    work_date: null,
    hours: 1,
    rate: defaultRate.value,
    order_index: wrItems.value.length,
  })
  focusLastRow('[data-row-input="wr-modal"]')
}
function removeItem(idx: number) { wrItems.value.splice(idx, 1) }
function moveItem(idx: number, dir: -1 | 1) {
  const newIdx = idx + dir
  if (newIdx < 0 || newIdx >= wrItems.value.length) return
  const [item] = wrItems.value.splice(idx, 1)
  wrItems.value.splice(newIdx, 0, item)
}

// ── Materiál: rozbalení / zabalení sekce ──
function openMat() {
  if (matItems.value.length === 0) addMaterial()
  matOpen.value = true
}
function closeMat() { matItems.value = []; matOpen.value = false }

// ── Materiál: řádky ──
function addMaterial() {
  matItems.value.push({
    description: '',
    quantity: 1,
    unit: defaultUnit.value,
    unit_price: 0,
    order_index: matItems.value.length,
  })
  focusLastRow('[data-row-input="wrm-modal"]')
}
function removeMaterial(idx: number) { matItems.value.splice(idx, 1) }
function moveMaterial(idx: number, dir: -1 | 1) {
  const newIdx = idx + dir
  if (newIdx < 0 || newIdx >= matItems.value.length) return
  const [item] = matItems.value.splice(idx, 1)
  matItems.value.splice(newIdx, 0, item)
}

function close() {
  emit('update:modelValue', false)
}

/**
 * Upsert/odstranění jedné souhrnné položky faktury (Práce nebo Materiál).
 * Matchuje proti PŮVODNÍMU názvu (drží rename v rámci modalu), pak proti novému.
 */
function syncRow(
  items: any[],
  origTitle: string,
  newTitle: string,
  total: number,
  vatRateId: number | null,
  present: boolean,
  allowEmptyReuse: boolean,
) {
  const unit = defaultUnit.value
  const desc = newTitle.trim()
  const match = (origTitle || '').trim() || desc
  let idx = match !== '' ? items.findIndex(it => (it.description || '').trim() === match) : -1
  if (idx < 0 && desc !== '') idx = items.findIndex(it => (it.description || '').trim() === desc)

  if (present) {
    if (idx < 0 && allowEmptyReuse) idx = items.findIndex(it => (it.description || '').trim() === '')
    if (idx >= 0) {
      items[idx].description = desc
      items[idx].quantity = 1
      items[idx].unit = unit
      items[idx].unit_price_without_vat = total
      items[idx].vat_rate_id = vatRateId ?? items[idx].vat_rate_id ?? defaultVatRateId.value ?? null
    } else {
      items.push({
        description: desc,
        quantity: 1,
        unit,
        unit_price_without_vat: total,
        vat_rate_id: vatRateId ?? defaultVatRateId.value ?? (items[0]?.vat_rate_id ?? null),
        order_index: items.length,
      })
    }
  } else if (idx >= 0) {
    items.splice(idx, 1)
  }
}

async function save() {
  if (!canSave.value) return
  saving.value = true
  error.value = ''
  try {
    const nothingLeft = workItemsValid.value.length === 0 && matItemsValid.value.length === 0
    if (nothingLeft) {
      // Oba výkazy prázdné → smaž celou work_reports řádku (jinak by zůstala prázdná).
      try {
        await invoicesApi.deleteWorkReport(props.invoiceId)
      } catch (e: any) {
        if (e?.response?.status !== 404) throw e
      }
    } else {
      // 1. Uložit výkaz práce (title + sazba + řádky; i prázdné řádky → drží sazbu/title).
      await invoicesApi.saveWorkReport(props.invoiceId, {
        project_id: projectId.value,
        title: wrTitle.value.trim() || t('invoice.work_report'),
        vat_rate_id: workVatRateId.value,
        items: workItemsValid.value.map((it, idx) => ({
          description: it.description,
          work_date: it.work_date || null,
          hours: Number(it.hours),
          rate: Number(it.rate),
          order_index: idx,
        })),
      })

      // 2. Uložit výkaz materiálu.
      await invoicesApi.saveWorkReportMaterials(props.invoiceId, {
        project_id: projectId.value,
        material_title: matTitle.value.trim() || t('invoice.wr_material_title'),
        material_vat_rate_id: matVatRateId.value,
        materials: matItemsValid.value.map((m, idx) => ({
          description: m.description,
          quantity: Number(m.quantity),
          unit: (m.unit || defaultUnit.value).trim(),
          unit_price: Number(m.unit_price),
          order_index: idx,
        })),
      })
    }

    // 3. Sync DVOU souhrnných položek ve faktuře (present=false → řádek se odebere).
    const inv = await invoicesApi.get(props.invoiceId)
    // Slevové položky (item_kind='discount') jsou generované z discount_percent — do payloadu nepatří.
    inv.items = inv.items.filter(it => it.item_kind !== 'discount') as any

    const workDesc = wrTitle.value.trim() || t('invoice.work_report')
    const matDesc = matTitle.value.trim() || t('invoice.wr_material_title')

    syncRow(inv.items as any[], origWorkTitle.value, workDesc, totalAmount.value, workVatRateId.value, workItemsValid.value.length > 0, true)
    syncRow(inv.items as any[], origMatTitle.value, matDesc, matTotal.value, matVatRateId.value, matItemsValid.value.length > 0, false)

    await invoicesApi.update(props.invoiceId, {
      invoice_type: inv.invoice_type,
      client_id: inv.client_id,
      project_id: inv.project_id,
      issue_date: inv.issue_date,
      tax_date: inv.tax_date,
      due_date: inv.due_date,
      currency_id: inv.currency_id,
      reverse_charge: !!inv.reverse_charge,
      language: inv.language,
      varsymbol: inv.varsymbol,
      payment_method: inv.payment_method,
      note_above_items: inv.note_above_items,
      note_below_items: inv.note_below_items,
      discount_percent: inv.discount_percent ?? 0,
      items: (inv.items as any[]).map((it, idx) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: idx,
      })) as any,
    } as any)

    toast.success(t('invoice.wr_saved_and_synced'))
    emit('saved')
    close()
  } catch (e: any) {
    error.value = apiErrorMessage(e, t('invoice.wr_save_failed'))
  } finally {
    saving.value = false
  }
}

// Reload pokaždé, když se modal otevře (různé invoiceId / nový WR po smazání).
watch(() => props.modelValue, (open) => {
  if (open && props.invoiceId > 0) load()
})
onMounted(() => {
  if (props.modelValue && props.invoiceId > 0) load()
})
</script>

<template>
  <Modal v-if="modelValue" :title="t('invoice.wr_btn')" width-class="max-w-4xl" @close="close">
      <div v-if="loading" class="p-8 text-center text-neutral-500">{{ t('common.loading') }}</div>

      <div v-else class="space-y-6">
        <!-- ════════ Sekce: Výkaz práce ════════ -->
        <section class="space-y-4">
          <div class="flex items-center justify-between gap-3 border-b border-neutral-200 pb-1">
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.work_report') }}</h4>
            <button v-if="!wrOpen" type="button" @click="openWork"
                    class="cursor-pointer px-3 h-8 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
              {{ t('invoice.wr_add') }}
            </button>
            <button v-else type="button" @click="closeWork"
                    class="cursor-pointer px-3 h-8 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
              {{ t('invoice.wr_delete') }}
            </button>
          </div>
          <div v-if="wrOpen" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div :class="supplierIsVatPayer ? 'md:col-span-2' : 'md:col-span-3'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.wr_title') }} *</label>
              <input v-model="wrTitle" type="text" maxlength="100" required
                     class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div v-if="supplierIsVatPayer">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.wr_vat_rate') }}</label>
              <select v-model.number="workVatRateId"
                      class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                <option v-for="r in vatOptions" :key="r.id" :value="r.id">{{ vatLabel(r) }}</option>
              </select>
            </div>
          </div>

          <!-- Desktop: tabulka -->
          <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-2 py-2 w-12"></th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('invoice.wr_description') }}</th>
                  <th class="px-3 py-2 text-left font-medium w-28">{{ t('invoice.wr_date') }}</th>
                  <th class="px-3 py-2 text-right font-medium w-24">{{ t('invoice.wr_hours') }}</th>
                  <th class="px-3 py-2 text-right font-medium w-28">{{ t('invoice.wr_rate') }}</th>
                  <th class="px-3 py-2 text-right font-medium w-28">{{ t('invoice.totals.total') }}</th>
                  <th class="px-2 py-2 w-10"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-200">
                <tr v-for="(it, i) in wrItems" :key="i">
                  <td class="px-2 py-2 text-center text-xs text-neutral-400">
                    <button type="button" @click="moveItem(i, -1)" :disabled="i === 0"
                            :title="t('invoice.wr_move_up')"
                            class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                    <button type="button" @click="moveItem(i, 1)" :disabled="i === wrItems.length - 1"
                            :title="t('invoice.wr_move_down')"
                            class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
                  </td>
                  <td class="px-3 py-1.5">
                    <input v-model="it.description" type="text" maxlength="500" data-row-input="wr-modal"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                  </td>
                  <td class="px-3 py-1.5">
                    <DateInput v-model="it.work_date"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                  </td>
                  <td class="px-3 py-1.5">
                    <input v-model.number="it.hours" type="number" step="0.25" min="0"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                  </td>
                  <td class="px-3 py-1.5">
                    <input v-model.number="it.rate" type="number" step="1" min="0"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                  </td>
                  <td class="px-3 py-1.5 text-right font-mono text-neutral-700">
                    {{ ((Number(it.hours)||0) * (Number(it.rate)||0)).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                  </td>
                  <td class="px-2 py-1.5 text-center">
                    <button type="button" @click="removeItem(i)" :title="t('common.delete')"
                            class="cursor-pointer text-danger-500 hover:text-danger-600 text-lg leading-none">&times;</button>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 font-semibold">
                <tr>
                  <td colspan="3" class="p-2">
                    <button type="button" @click="addItem"
                            class="cursor-pointer px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                      {{ t('invoice.wr_add_item') }}
                    </button>
                  </td>
                  <td v-if="wrItems.length > 0" class="px-3 py-2 text-right font-mono">
                    <span class="text-neutral-400 font-normal mr-2">Σ</span>{{ totalHours.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} h
                  </td>
                  <td v-else></td>
                  <td></td>
                  <td v-if="wrItems.length > 0" class="px-3 py-2 text-right font-mono whitespace-nowrap" colspan="2">
                    {{ totalAmount.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} {{ currency }}
                  </td>
                  <td v-else colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- Mobile: stack karet -->
          <div class="md:hidden border border-neutral-200 rounded-lg overflow-hidden">
            <div class="divide-y divide-neutral-100">
              <div v-for="(it, i) in wrItems" :key="`m-${i}`" class="p-3 space-y-2">
                <div class="flex items-start gap-2">
                  <input v-model="it.description" type="text" maxlength="500" data-row-input="wr-modal"
                         :placeholder="t('invoice.wr_description')"
                         class="flex-1 min-w-0 h-9 px-2 border border-neutral-300 rounded text-sm" />
                  <div class="flex items-center gap-1 shrink-0 pt-0.5 text-neutral-400">
                    <button type="button" @click="moveItem(i, -1)" :disabled="i === 0"
                            class="cursor-pointer w-7 h-7 rounded border border-neutral-200 hover:text-neutral-700 disabled:opacity-30">▲</button>
                    <button type="button" @click="moveItem(i, 1)" :disabled="i === wrItems.length - 1"
                            class="cursor-pointer w-7 h-7 rounded border border-neutral-200 hover:text-neutral-700 disabled:opacity-30">▼</button>
                    <button type="button" @click="removeItem(i)"
                            class="cursor-pointer w-7 h-7 rounded border border-danger-500/30 text-danger-500 hover:bg-danger-50 text-base leading-none">&times;</button>
                  </div>
                </div>
                <div class="grid grid-cols-3 gap-2">
                  <div>
                    <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('invoice.wr_date') }}</label>
                    <DateInput v-model="it.work_date"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('invoice.wr_hours') }}</label>
                    <input v-model.number="it.hours" type="number" step="0.25" min="0" inputmode="decimal"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('invoice.wr_rate') }}</label>
                    <input v-model.number="it.rate" type="number" step="1" min="0" inputmode="decimal"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                  </div>
                </div>
                <div class="flex items-baseline justify-between text-sm">
                  <span class="text-xs text-neutral-500">{{ t('invoice.totals.total') }}</span>
                  <span class="font-mono font-medium">
                    {{ ((Number(it.hours)||0) * (Number(it.rate)||0)).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} {{ currency }}
                  </span>
                </div>
              </div>
            </div>
            <div class="bg-neutral-50 border-t border-neutral-200 p-3 space-y-2">
              <button type="button" @click="addItem"
                      class="cursor-pointer w-full h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center justify-center gap-1">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                {{ t('invoice.wr_add_item') }}
              </button>
              <div v-if="wrItems.length > 0" class="flex items-baseline justify-between text-sm font-semibold">
                <span class="font-mono"><span class="text-neutral-400 font-normal mr-1">Σ</span>{{ totalHours.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} h</span>
                <span class="font-mono">{{ totalAmount.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} {{ currency }}</span>
              </div>
            </div>
          </div>
          </div>
        </section>

        <!-- ════════ Sekce: Výkaz materiálu ════════ -->
        <section class="space-y-4">
          <div class="flex items-center justify-between gap-3 border-b border-neutral-200 pb-1">
            <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.work_report_material') }}</h4>
            <button v-if="!matOpen" type="button" @click="openMat"
                    class="cursor-pointer px-3 h-8 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 font-medium rounded-md inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
              {{ t('invoice.wr_material_add') }}
            </button>
            <button v-else type="button" @click="closeMat"
                    class="cursor-pointer px-3 h-8 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
              {{ t('invoice.wr_delete') }}
            </button>
          </div>
          <div v-if="matOpen" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div :class="supplierIsVatPayer ? 'md:col-span-2' : 'md:col-span-3'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.wr_title') }}</label>
              <input v-model="matTitle" type="text" maxlength="100"
                     class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div v-if="supplierIsVatPayer">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.wr_vat_rate') }}</label>
              <select v-model.number="matVatRateId"
                      class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                <option v-for="r in vatOptions" :key="r.id" :value="r.id">{{ vatLabel(r) }}</option>
              </select>
            </div>
          </div>

          <p class="text-xs text-neutral-500">
            {{ pricesInclVat ? t('invoice.wr_material_price_incl') : t('invoice.wr_material_price_excl') }}
          </p>

          <!-- Desktop: tabulka -->
          <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-2 py-2 w-12"></th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('invoice.wr_description') }}</th>
                  <th class="px-3 py-2 text-right font-medium w-24">{{ t('invoice.wr_material_qty') }}</th>
                  <th class="px-3 py-2 text-left font-medium w-24">{{ t('invoice.wr_material_unit') }}</th>
                  <th class="px-3 py-2 text-right font-medium w-32">{{ unitPriceHeaderLabel }}</th>
                  <th class="px-3 py-2 text-right font-medium w-28">{{ t('invoice.totals.total') }}</th>
                  <th class="px-2 py-2 w-10"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-200">
                <tr v-for="(m, i) in matItems" :key="i">
                  <td class="px-2 py-2 text-center text-xs text-neutral-400">
                    <button type="button" @click="moveMaterial(i, -1)" :disabled="i === 0"
                            :title="t('invoice.wr_move_up')"
                            class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▲</button>
                    <button type="button" @click="moveMaterial(i, 1)" :disabled="i === matItems.length - 1"
                            :title="t('invoice.wr_move_down')"
                            class="block w-5 h-4 hover:text-neutral-700 disabled:opacity-30">▼</button>
                  </td>
                  <td class="px-3 py-1.5">
                    <input v-model="m.description" type="text" maxlength="500" data-row-input="wrm-modal"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                  </td>
                  <td class="px-3 py-1.5">
                    <input v-model.number="m.quantity" type="number" step="0.001" min="0"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                  </td>
                  <td class="px-3 py-1.5">
                    <select v-model="m.unit"
                            class="w-full h-9 px-2 border border-neutral-300 rounded text-sm bg-surface">
                      <option v-for="u in units" :key="u.code" :value="u.code">{{ u.code }}</option>
                    </select>
                  </td>
                  <td class="px-3 py-1.5">
                    <input v-model.number="m.unit_price" type="number" step="0.01" min="0"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                  </td>
                  <td class="px-3 py-1.5 text-right font-mono text-neutral-700">
                    {{ ((Number(m.quantity)||0) * (Number(m.unit_price)||0)).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}
                  </td>
                  <td class="px-2 py-1.5 text-center">
                    <button type="button" @click="removeMaterial(i)" :title="t('common.delete')"
                            class="cursor-pointer text-danger-500 hover:text-danger-600 text-lg leading-none">&times;</button>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 font-semibold">
                <tr>
                  <td colspan="4" class="p-2">
                    <button type="button" @click="addMaterial"
                            class="cursor-pointer px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center gap-1">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                      {{ t('invoice.wr_material_add_item') }}
                    </button>
                  </td>
                  <td v-if="matItems.length > 0" class="px-3 py-2 text-right font-mono whitespace-nowrap" colspan="2">
                    {{ matTotal.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} {{ currency }}
                  </td>
                  <td v-else colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- Mobile: stack karet -->
          <div class="md:hidden border border-neutral-200 rounded-lg overflow-hidden">
            <div class="divide-y divide-neutral-100">
              <div v-for="(m, i) in matItems" :key="`mm-${i}`" class="p-3 space-y-2">
                <div class="flex items-start gap-2">
                  <input v-model="m.description" type="text" maxlength="500" data-row-input="wrm-modal"
                         :placeholder="t('invoice.wr_description')"
                         class="flex-1 min-w-0 h-9 px-2 border border-neutral-300 rounded text-sm" />
                  <div class="flex items-center gap-1 shrink-0 pt-0.5 text-neutral-400">
                    <button type="button" @click="moveMaterial(i, -1)" :disabled="i === 0"
                            class="cursor-pointer w-7 h-7 rounded border border-neutral-200 hover:text-neutral-700 disabled:opacity-30">▲</button>
                    <button type="button" @click="moveMaterial(i, 1)" :disabled="i === matItems.length - 1"
                            class="cursor-pointer w-7 h-7 rounded border border-neutral-200 hover:text-neutral-700 disabled:opacity-30">▼</button>
                    <button type="button" @click="removeMaterial(i)"
                            class="cursor-pointer w-7 h-7 rounded border border-danger-500/30 text-danger-500 hover:bg-danger-50 text-base leading-none">&times;</button>
                  </div>
                </div>
                <div class="grid grid-cols-3 gap-2">
                  <div>
                    <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('invoice.wr_material_qty') }}</label>
                    <input v-model.number="m.quantity" type="number" step="0.001" min="0" inputmode="decimal"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-neutral-500 mb-0.5">{{ t('invoice.wr_material_unit') }}</label>
                    <select v-model="m.unit"
                            class="w-full h-9 px-2 border border-neutral-300 rounded text-sm bg-surface">
                      <option v-for="u in units" :key="u.code" :value="u.code">{{ u.code }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[11px] text-neutral-500 mb-0.5">{{ unitPriceHeaderLabel }}</label>
                    <input v-model.number="m.unit_price" type="number" step="0.01" min="0" inputmode="decimal"
                           class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
                  </div>
                </div>
                <div class="flex items-baseline justify-between text-sm">
                  <span class="text-xs text-neutral-500">{{ t('invoice.totals.total') }}</span>
                  <span class="font-mono font-medium">
                    {{ ((Number(m.quantity)||0) * (Number(m.unit_price)||0)).toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} {{ currency }}
                  </span>
                </div>
              </div>
            </div>
            <div class="bg-neutral-50 border-t border-neutral-200 p-3 space-y-2">
              <button type="button" @click="addMaterial"
                      class="cursor-pointer w-full h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md inline-flex items-center justify-center gap-1">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                {{ t('invoice.wr_material_add_item') }}
              </button>
              <div v-if="matItems.length > 0" class="flex items-baseline justify-end text-sm font-semibold">
                <span class="font-mono">{{ matTotal.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }} {{ currency }}</span>
              </div>
            </div>
          </div>
          </div>
        </section>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <p class="text-xs text-neutral-500">
          ℹ {{ t('invoice.wr_sync_note') }}
        </p>
      </div>

      <template #footer>
      <div class="flex items-center justify-between">
        <button @click="close"
                class="cursor-pointer h-10 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button @click="save" :disabled="!canSave"
                class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ saving ? t('common.saving') : t('invoice.wr_save_and_sync') }}
        </button>
      </div>
      </template>
  </Modal>
</template>

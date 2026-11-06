<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  accountingApi,
  type SaldoSettlementAllocation,
  type SaldoSettlementCandidates,
  type SaldoSettlementLine,
} from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, btnIconSm } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { apiErrorMessage, distribute, withPeriodForce, type SaldoSettleTarget } from './saldoSettle'
import { offsetRoute } from './offsetLink'

const PaymentMethodModal = defineAsyncComponent(() => import('@/components/invoices/PaymentMethodModal.vue'))

/**
 * Vyrovnání otevřené položky saldokonta (jedné, nebo označených položek jednoho partnera
 * a účtu). Dvě cesty: rozpad řádku zápisu v deníku na doklady (zápis už v hlavní knize je,
 * saldokonto ho jen přiřadí dokladům), nebo zápočet / odpis proti účtu, který zápis teprve
 * vytvoří (existující zápočet dokladu).
 */
const props = defineProps<{
  items: SaldoSettleTarget[]
  asOf: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done'): void
}>()

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

type Tab = 'journal' | 'offset'
const tab = ref<Tab>('journal')
const loading = ref(true)
const saving = ref(false)
const data = ref<SaldoSettlementCandidates | null>(null)
const offsetOpen = ref(false)

const single = computed(() => props.items.length === 1)
const first = computed(() => props.items[0])
const totalRemaining = computed(() => Math.round(props.items.reduce((s, i) => s + i.remaining_czk, 0) * 100) / 100)
const canOffset = computed(() => single.value && auth.canWrite('accounting.offsets'))
/** Vzájemný zápočet s partnerem (stránka Zápočty předvyplněná partnerem a dokladem). */
const canMutualOffset = computed(() => auth.canWrite('accounting.offsets') && props.items.every(i => i.currency_code === 'CZK'))
const mutualOffsetLink = computed(() => offsetRoute(first.value.partner_id,
  props.items.map(i => ({ doc_type: i.doc_type, doc_id: i.doc_id, amount: i.remaining_czk }))))

/** Jeden doklad: víc řádků s částkou. Víc dokladů: jeden řádek rozdělený mezi doklady. */
const picked = ref<Record<number, number>>({})
const selectedLine = ref<number | null>(null)
const split = ref<number[]>([])

function lineLabel(l: SaldoSettlementLine): string {
  return [l.document_no, l.description].filter(Boolean).join(' - ')
}

function toggleLine(l: SaldoSettlementLine) {
  if (picked.value[l.line_id] !== undefined) {
    const { [l.line_id]: _, ...rest } = picked.value
    picked.value = rest
    return
  }
  const used = Object.values(picked.value).reduce((s, a) => s + a, 0)
  const left = Math.max(0, Math.round((first.value.remaining_czk - used) * 100) / 100)
  picked.value = { ...picked.value, [l.line_id]: Math.min(l.free_amount, left || l.free_amount) }
}

function selectSplitLine(l: SaldoSettlementLine) {
  selectedLine.value = l.line_id
  split.value = distribute(l.free_amount, props.items.map(i => i.remaining_czk))
}

const allocationsTotal = computed(() => single.value
  ? Object.values(picked.value).reduce((s, a) => s + Number(a || 0), 0)
  : split.value.reduce((s, a) => s + Number(a || 0), 0))

const validation = computed<string | null>(() => {
  const lines = data.value?.items ?? []
  if (single.value) {
    const entries = Object.entries(picked.value)
    if (entries.length === 0) return t('accounting.saldo_settle.pick_line')
    for (const [id, amount] of entries) {
      const line = lines.find(l => l.line_id === Number(id))
      if (!line || !(Number(amount) > 0)) return t('accounting.saldo_settle.amount_positive')
      if (Math.round(Number(amount) * 100) > Math.round(line.free_amount * 100)) return t('accounting.saldo_settle.amount_over_line')
    }
    if (Math.round(allocationsTotal.value * 100) > Math.round(first.value.remaining_czk * 100)) return t('accounting.saldo_settle.amount_over_document')
    return null
  }
  const line = lines.find(l => l.line_id === selectedLine.value)
  if (!line) return t('accounting.saldo_settle.pick_line')
  if (!split.value.some(a => Number(a) > 0)) return t('accounting.saldo_settle.amount_positive')
  if (split.value.some((a, i) => Number(a) < 0 || Math.round(Number(a) * 100) > Math.round(props.items[i].remaining_czk * 100))) {
    return t('accounting.saldo_settle.amount_over_document')
  }
  if (Math.round(allocationsTotal.value * 100) > Math.round(line.free_amount * 100)) return t('accounting.saldo_settle.amount_over_line')
  return null
})

async function load() {
  loading.value = true
  try {
    data.value = await accountingApi.getSaldoSettlementCandidates({
      doc_type: first.value.doc_type,
      doc_id: first.value.doc_id,
      account: first.value.account_code,
      as_of: props.asOf,
    })
    picked.value = {}
    selectedLine.value = null
    split.value = []
    // Shoda částky i čísla dokladu je jistá: předvyplní se, potvrzení zůstává na uživateli.
    const top = data.value.items[0]
    if (top && top.amount_match && top.doc_match) {
      if (single.value) toggleLine(top)
      else selectSplitLine(top)
    }
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}

function confirmForce(): boolean {
  return window.confirm(t('accounting.saldo_settle.period_closed_confirm'))
}

async function submit() {
  if (validation.value || saving.value) return
  const allocations = single.value
    ? Object.entries(picked.value).map(([lineId, amount]) => ({
        line_id: Number(lineId), doc_type: first.value.doc_type, doc_id: first.value.doc_id, amount: Number(amount),
      }))
    : props.items.map((it, i) => ({
        line_id: Number(selectedLine.value), doc_type: it.doc_type, doc_id: it.doc_id, amount: Number(split.value[i] || 0),
      })).filter(a => a.amount > 0)
  saving.value = true
  try {
    const result = await withPeriodForce(
      force => accountingApi.createSaldoAllocations({ allocations }, force),
      auth.isCompanyAdminRole,
      confirmForce,
    )
    if (result === null) return
    toast.success(t('accounting.saldo_settle.saved', { count: result.created.length }))
    emit('done')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

async function removeAllocation(a: SaldoSettlementAllocation) {
  if (!window.confirm(t('accounting.saldo_settle.delete_confirm'))) return
  try {
    const result = await withPeriodForce(force => accountingApi.deleteSaldoAllocation(a.id, force), auth.isCompanyAdminRole, confirmForce)
    if (result === null) return
    toast.success(t('accounting.saldo_settle.deleted'))
    emit('done')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

function docLink(it: SaldoSettleTarget) {
  return it.doc_type === 'purchase_invoice'
    ? { name: 'purchase-invoice-detail', params: { id: it.doc_id } }
    : { name: 'invoice-detail', params: { id: it.doc_id } }
}

function onOffsetDone() {
  offsetOpen.value = false
  emit('done')
}

onMounted(load)
</script>

<template>
  <!-- Modal se teleportuje na konec stránky a překryl by okno zápočtu, proto se
       po dobu zápočtu schová; po zavření zápočtu se vrátí se stejným stavem. -->
  <Modal v-if="!offsetOpen" :title="single ? t('accounting.saldo_settle.title_single', { doc: first.doc_no }) : t('accounting.saldo_settle.title_bulk', { count: items.length })"
    width-class="max-w-4xl" @close="emit('close')">
    <div class="space-y-4" data-testid="saldo-settle-dialog">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
        <div>
          <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.col_partner') }}</div>
          <div class="font-medium">{{ first.partner_name }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.col_account') }}</div>
          <div class="font-mono">{{ first.account_code }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo_settle.remaining_total') }}</div>
          <div class="font-mono font-semibold">{{ formatMoney(totalRemaining) }}</div>
        </div>
      </div>
      <ul v-if="!single" class="text-xs text-neutral-600 flex flex-wrap gap-x-3 gap-y-1">
        <li v-for="it in items" :key="`${it.doc_type}-${it.doc_id}`" class="font-mono">{{ it.doc_no }} ({{ formatMoney(it.remaining_czk) }})</li>
      </ul>

      <div class="flex flex-wrap gap-1.5" role="tablist" :aria-label="t('accounting.saldo_settle.ways')">
        <button type="button" role="tab" :aria-selected="tab === 'journal'" @click="tab = 'journal'"
          :class="[tab === 'journal' ? btnFilled('neutral') : btnOutline('neutral'), 'whitespace-nowrap']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t('accounting.saldo_settle.tab_journal') }}
        </button>
        <button type="button" role="tab" :aria-selected="tab === 'offset'" @click="tab = 'offset'"
          :class="[tab === 'offset' ? btnFilled('neutral') : btnOutline('neutral'), 'whitespace-nowrap']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
          {{ t('accounting.saldo_settle.tab_offset') }}
        </button>
      </div>

      <!-- (a) Spárovat se zápisem v deníku -->
      <section v-if="tab === 'journal'" role="tabpanel" class="space-y-3">
        <p class="text-sm text-neutral-600">{{ single ? t('accounting.saldo_settle.journal_intro_single') : t('accounting.saldo_settle.journal_intro_bulk') }}</p>

        <div v-if="data && data.allocations.length > 0" class="border border-neutral-200 rounded-md">
          <div class="px-3 py-2 text-xs font-medium text-neutral-500 uppercase tracking-wide border-b border-neutral-100">{{ t('accounting.saldo_settle.existing') }}</div>
          <ul class="divide-y divide-neutral-100 text-sm">
            <li v-for="a in data.allocations" :key="a.id" class="px-3 py-2 flex flex-wrap items-center gap-2">
              <span class="whitespace-nowrap">{{ formatDate(a.entry_date) }}</span>
              <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(a.entry_id) } }" class="font-mono text-primary-600 hover:underline">{{ a.document_no || `#${a.entry_id}` }}</RouterLink>
              <span class="text-neutral-600 truncate min-w-0 flex-1">{{ a.description }}</span>
              <span class="font-mono">{{ formatMoney(a.amount) }}</span>
              <button v-if="a.kind === 'settlement'" type="button" :class="btnIconSm('danger')" :title="t('accounting.saldo_settle.delete')" :aria-label="t('accounting.saldo_settle.delete')" @click="removeAllocation(a)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </li>
          </ul>
        </div>

        <div v-if="loading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
        <div v-else-if="data && !data.document.supported" class="text-sm px-3 py-2 rounded-md bg-warning-50 text-warning-700 border border-warning-500/30">
          {{ t('accounting.saldo_settle.foreign_currency') }}
        </div>
        <EmptyState v-else-if="!data || data.items.length === 0" dense accent="neutral" icon="search" :title="t('accounting.saldo_settle.no_candidates')" />
        <div v-else class="overflow-x-auto border border-neutral-200 rounded-md">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-8"><span class="sr-only">{{ t('accounting.saldo_settle.col_pick') }}</span></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.col_date') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.col_entry') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_account') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo_settle.col_free') }}</th>
                <th v-if="single" class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo_settle.col_amount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="l in data.items" :key="l.line_id" class="hover:bg-neutral-50">
                <td class="px-3 py-2">
                  <input v-if="single" type="checkbox" class="rounded border-neutral-300" :checked="picked[l.line_id] !== undefined"
                    :aria-label="t('accounting.saldo_settle.pick_aria', { entry: l.document_no || l.entry_id })" @change="toggleLine(l)" />
                  <input v-else type="radio" name="saldo-settle-line" class="border-neutral-300" :checked="selectedLine === l.line_id"
                    :aria-label="t('accounting.saldo_settle.pick_aria', { entry: l.document_no || l.entry_id })" @change="selectSplitLine(l)" />
                </td>
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(l.entry_date) }}</td>
                <td class="px-3 py-2 min-w-[14rem]">
                  <span class="block">{{ lineLabel(l) }}</span>
                  <span class="flex flex-wrap gap-1 mt-0.5">
                    <span v-if="l.amount_match" class="text-[11px] px-1.5 rounded bg-success-50 text-success-700">{{ t('accounting.saldo_settle.match_amount') }}</span>
                    <span v-if="l.doc_match" class="text-[11px] px-1.5 rounded bg-primary-50 text-primary-700">{{ t('accounting.saldo_settle.match_doc') }}</span>
                    <span v-if="l.partner_match" class="text-[11px] px-1.5 rounded bg-neutral-100 text-neutral-700">{{ t('accounting.saldo_settle.match_partner') }}</span>
                  </span>
                </td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ l.account_code }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(l.free_amount) }}</td>
                <td v-if="single" class="px-3 py-2 text-right">
                  <input v-if="picked[l.line_id] !== undefined" v-model.number="picked[l.line_id]" type="number" step="0.01" min="0.01" :max="l.free_amount"
                    class="w-32 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right"
                    :aria-label="t('accounting.saldo_settle.col_amount')" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="!single && selectedLine !== null" class="border border-neutral-200 rounded-md p-3 space-y-2">
          <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo_settle.split_title') }}</div>
          <div v-for="(it, i) in items" :key="`${it.doc_type}-${it.doc_id}`" class="flex flex-wrap items-center gap-2 text-sm">
            <span class="font-mono min-w-[8rem]">{{ it.doc_no }}</span>
            <span class="text-neutral-500 text-xs">{{ t('accounting.saldo_settle.remaining_short', { amount: formatMoney(it.remaining_czk) }) }}</span>
            <input v-model.number="split[i]" type="number" step="0.01" min="0" :max="it.remaining_czk"
              class="ml-auto w-32 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right"
              :aria-label="t('accounting.saldo_settle.amount_for', { doc: it.doc_no })" />
          </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
          <span>{{ t('accounting.saldo_settle.total') }} <strong class="font-mono">{{ formatMoney(allocationsTotal) }}</strong></span>
          <span v-if="validation && !loading && data?.document.supported" class="text-xs text-neutral-500">{{ validation }}</span>
        </div>
      </section>

      <!-- (b) Zápočet / odpis proti účtu -->
      <section v-else role="tabpanel" class="space-y-3 text-sm">
        <p class="text-neutral-600">{{ t('accounting.saldo_settle.offset_intro') }}</p>
        <div class="flex flex-wrap gap-2">
          <button v-if="canOffset && first.currency_code === 'CZK'" type="button" :class="[btnFilled('primary'), 'whitespace-nowrap']" @click="offsetOpen = true">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ t('accounting.saldo_settle.offset_open') }}
          </button>
          <RouterLink v-if="canMutualOffset" :to="mutualOffsetLink" :class="[btnOutline('primary'), 'whitespace-nowrap']" data-testid="saldo-settle-mutual-offset">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ t('accounting.saldo_settle.mutual_offset_partner') }}
          </RouterLink>
          <RouterLink v-for="it in items" :key="`${it.doc_type}-${it.doc_id}`" :to="docLink(it)" :class="[btnOutline('neutral'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            {{ t('accounting.saldo_settle.open_document', { doc: it.doc_no }) }}
          </RouterLink>
        </div>
        <p v-if="!single" class="text-xs text-neutral-500">{{ t('accounting.saldo_settle.offset_bulk_hint') }}</p>
      </section>
    </div>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button v-if="tab === 'journal'" type="button" :class="btnFilled('success')" :disabled="!!validation || saving" data-testid="saldo-settle-submit" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('accounting.saldo_settle.submit') }}
        </button>
      </div>
    </template>
  </Modal>

  <PaymentMethodModal v-if="offsetOpen" :doc-type="first.doc_type" settlement-only
    :doc-id="first.doc_id" :doc-number="first.doc_no" :amount="first.remaining_czk" currency="CZK"
    :partner-name="first.partner_name"
    :title="t('accounting.saldo_settle.offset_title', { doc: first.doc_no })"
    :intro="t('accounting.saldo_settle.offset_modal_intro', { amount: formatMoney(first.remaining_czk) })"
    @close="offsetOpen = false" @done="onOffsetDone" />
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  accountingApi,
  type SaldoAdvanceCandidates,
  type SaldoAdvanceDeduction,
  type SaldoAdvanceFinal,
} from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, btnIconSm } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { apiErrorMessage, distribute, withPeriodForce, type SaldoSettleTarget } from './saldoSettle'

/**
 * Vyrovnání zálohy na 314/324 čerpáním konečnou fakturou téhož partnera. Zálohu
 * nevyrovnává zápis v deníku, ale faktura, která ji odečítá na zálohovém účtu; dialog
 * zapíše, kolik z odpočtu faktury patří které záloze. Jedna záloha: víc faktur s částkou.
 * Víc záloh: jedna faktura rozdělená mezi zálohy.
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

const loading = ref(true)
const saving = ref(false)
const data = ref<SaldoAdvanceCandidates | null>(null)

const single = computed(() => props.items.length === 1)
const first = computed(() => props.items[0])
const totalRemaining = computed(() => Math.round(props.items.reduce((s, i) => s + i.remaining_czk, 0) * 100) / 100)

const picked = ref<Record<number, number>>({})
const selectedFinal = ref<number | null>(null)
const split = ref<number[]>([])

function toggleFinal(f: SaldoAdvanceFinal) {
  if (picked.value[f.doc_id] !== undefined) {
    const { [f.doc_id]: _, ...rest } = picked.value
    picked.value = rest
    return
  }
  const used = Object.values(picked.value).reduce((s, a) => s + a, 0)
  const left = Math.max(0, Math.round((first.value.remaining_czk - used) * 100) / 100)
  picked.value = { ...picked.value, [f.doc_id]: Math.min(f.unassigned, left || f.unassigned) }
}

function selectSplitFinal(f: SaldoAdvanceFinal) {
  selectedFinal.value = f.doc_id
  split.value = distribute(f.unassigned, props.items.map(i => i.remaining_czk))
}

const total = computed(() => single.value
  ? Object.values(picked.value).reduce((s, a) => s + Number(a || 0), 0)
  : split.value.reduce((s, a) => s + Number(a || 0), 0))

const validation = computed<string | null>(() => {
  const finals = data.value?.items ?? []
  if (single.value) {
    const entries = Object.entries(picked.value)
    if (entries.length === 0) return t('accounting.saldo_settle.advance_pick_final')
    for (const [id, amount] of entries) {
      const f = finals.find(x => x.doc_id === Number(id))
      if (!f || !(Number(amount) > 0)) return t('accounting.saldo_settle.amount_positive')
      if (Math.round(Number(amount) * 100) > Math.round(f.unassigned * 100)) return t('accounting.saldo_settle.advance_amount_over_final')
    }
    if (Math.round(total.value * 100) > Math.round(first.value.remaining_czk * 100)) return t('accounting.saldo_settle.advance_amount_over_advance')
    return null
  }
  const f = finals.find(x => x.doc_id === selectedFinal.value)
  if (!f) return t('accounting.saldo_settle.advance_pick_final')
  if (!split.value.some(a => Number(a) > 0)) return t('accounting.saldo_settle.amount_positive')
  if (split.value.some((a, i) => Number(a) < 0 || Math.round(Number(a) * 100) > Math.round(props.items[i].remaining_czk * 100))) {
    return t('accounting.saldo_settle.advance_amount_over_advance')
  }
  if (Math.round(total.value * 100) > Math.round(f.unassigned * 100)) return t('accounting.saldo_settle.advance_amount_over_final')
  return null
})

async function load() {
  loading.value = true
  try {
    data.value = await accountingApi.getSaldoAdvanceCandidates({
      doc_type: first.value.doc_type,
      doc_id: first.value.doc_id,
      account: first.value.account_code,
      as_of: props.asOf,
    })
    picked.value = {}
    selectedFinal.value = null
    split.value = []
    // Jediná faktura se shodnou částkou (nebo s číslem zálohy a shodnou částkou) je jistá:
    // předvyplní se, potvrzení zůstává na uživateli.
    const exact = data.value.items.filter(f => f.amount_match)
    const top = exact.length === 1 ? exact[0] : data.value.items.find(f => f.amount_match && f.doc_match)
    if (top) {
      if (single.value) toggleFinal(top)
      else selectSplitFinal(top)
    }
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    loading.value = false
  }
}

function confirmForce(): boolean {
  return window.confirm(t('accounting.saldo_settle.advance_period_closed_confirm'))
}

async function submit() {
  if (validation.value || saving.value) return
  const docType = first.value.doc_type
  const deductions = single.value
    ? Object.entries(picked.value).map(([finalId, amount]) => ({
        doc_type: docType, advance_id: first.value.doc_id, final_id: Number(finalId), amount: Number(amount),
      }))
    : props.items.map((it, i) => ({
        doc_type: docType, advance_id: it.doc_id, final_id: Number(selectedFinal.value), amount: Number(split.value[i] || 0),
      })).filter(d => d.amount > 0)
  saving.value = true
  try {
    const result = await withPeriodForce(
      force => accountingApi.createSaldoAdvanceDeductions({ deductions }, force),
      auth.isCompanyAdminRole,
      confirmForce,
    )
    if (result === null) return
    toast.success(t('accounting.saldo_settle.advance_saved', { count: result.created.length }))
    emit('done')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

async function removeDeduction(d: SaldoAdvanceDeduction) {
  if (!window.confirm(t('accounting.saldo_settle.advance_delete_confirm'))) return
  try {
    const result = await withPeriodForce(force => accountingApi.deleteSaldoAdvanceDeduction(d.doc_type, d.id, force), auth.isCompanyAdminRole, confirmForce)
    if (result === null) return
    toast.success(t('accounting.saldo_settle.advance_deleted'))
    emit('done')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

function docLink(docType: string, id: number) {
  return docType === 'purchase_invoice'
    ? { name: 'purchase-invoice-detail', params: { id } }
    : { name: 'invoice-detail', params: { id } }
}

onMounted(load)
</script>

<template>
  <Modal :title="single ? t('accounting.saldo_settle.advance_title_single', { doc: first.doc_no }) : t('accounting.saldo_settle.advance_title_bulk', { count: items.length })"
    width-class="max-w-4xl" @close="emit('close')">
    <div class="space-y-4" data-testid="saldo-advance-settle-dialog">
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
          <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo_settle.advance_remaining_total') }}</div>
          <div class="font-mono font-semibold">{{ formatMoney(totalRemaining) }}</div>
        </div>
      </div>
      <ul v-if="!single" class="text-xs text-neutral-600 flex flex-wrap gap-x-3 gap-y-1">
        <li v-for="it in items" :key="`${it.doc_type}-${it.doc_id}`" class="font-mono">{{ it.doc_no }} ({{ formatMoney(it.remaining_czk) }})</li>
      </ul>

      <p class="text-sm text-neutral-600">{{ single ? t('accounting.saldo_settle.advance_intro_single') : t('accounting.saldo_settle.advance_intro_bulk') }}</p>

      <div v-if="data && data.deductions.length > 0" class="border border-neutral-200 rounded-md">
        <div class="px-3 py-2 text-xs font-medium text-neutral-500 uppercase tracking-wide border-b border-neutral-100">{{ t('accounting.saldo_settle.advance_existing') }}</div>
        <ul class="divide-y divide-neutral-100 text-sm">
          <li v-for="d in data.deductions" :key="d.id" class="px-3 py-2 flex flex-wrap items-center gap-2">
            <span v-if="d.posted_on" class="whitespace-nowrap">{{ formatDate(d.posted_on) }}</span>
            <RouterLink :to="docLink(d.doc_type, d.final_id)" class="font-mono text-primary-600 hover:underline">{{ d.final_no }}</RouterLink>
            <span class="text-[11px] px-1.5 rounded" :class="d.source === 'saldo' ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-700'">
              {{ d.source === 'saldo' ? t('accounting.saldo_settle.advance_source_saldo') : t('accounting.saldo_settle.advance_source_import') }}
            </span>
            <span class="flex-1" />
            <span class="font-mono">{{ formatMoney(d.amount) }}</span>
            <button v-if="d.deletable" type="button" :class="btnIconSm('danger')" :title="t('accounting.saldo_settle.advance_delete')" :aria-label="t('accounting.saldo_settle.advance_delete')" @click="removeDeduction(d)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            </button>
          </li>
        </ul>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="data && !data.document.supported" class="text-sm px-3 py-2 rounded-md bg-warning-50 text-warning-700 border border-warning-500/30">
        {{ t('accounting.saldo_settle.advance_unsupported') }}
      </div>
      <EmptyState v-else-if="!data || data.items.length === 0" dense accent="neutral" icon="search" :title="t('accounting.saldo_settle.advance_no_candidates')" />
      <div v-else class="overflow-x-auto border border-neutral-200 rounded-md">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 w-8"><span class="sr-only">{{ t('accounting.saldo_settle.col_pick') }}</span></th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.advance_col_final') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.advance_col_posted') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo_settle.advance_col_deductible') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo_settle.advance_col_unassigned') }}</th>
              <th v-if="single" class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo_settle.col_amount') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="f in data.items" :key="f.doc_id" class="hover:bg-neutral-50">
              <td class="px-3 py-2">
                <input v-if="single" type="checkbox" class="rounded border-neutral-300" :checked="picked[f.doc_id] !== undefined"
                  :aria-label="t('accounting.saldo_settle.advance_pick_aria', { doc: f.doc_no })" @change="toggleFinal(f)" />
                <input v-else type="radio" name="saldo-advance-final" class="border-neutral-300" :checked="selectedFinal === f.doc_id"
                  :aria-label="t('accounting.saldo_settle.advance_pick_aria', { doc: f.doc_no })" @change="selectSplitFinal(f)" />
              </td>
              <td class="px-3 py-2 min-w-[12rem]">
                <RouterLink :to="docLink(f.doc_type, f.doc_id)" class="font-mono text-primary-600 hover:underline">{{ f.doc_no }}</RouterLink>
                <span class="flex flex-wrap gap-1 mt-0.5">
                  <span v-if="f.amount_match" class="text-[11px] px-1.5 rounded bg-success-50 text-success-700">{{ t('accounting.saldo_settle.match_amount') }}</span>
                  <span v-if="f.doc_match" class="text-[11px] px-1.5 rounded bg-primary-50 text-primary-700">{{ t('accounting.saldo_settle.advance_match_doc') }}</span>
                  <span v-if="!f.after_advance" class="text-[11px] px-1.5 rounded bg-warning-50 text-warning-700">{{ t('accounting.saldo_settle.advance_before') }}</span>
                </span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(f.posted_on) }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(f.deductible) }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(f.unassigned) }}</td>
              <td v-if="single" class="px-3 py-2 text-right">
                <input v-if="picked[f.doc_id] !== undefined" v-model.number="picked[f.doc_id]" type="number" step="0.01" min="0.01" :max="f.unassigned"
                  class="w-32 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right"
                  :aria-label="t('accounting.saldo_settle.col_amount')" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="!single && selectedFinal !== null" class="border border-neutral-200 rounded-md p-3 space-y-2">
        <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo_settle.advance_split_title') }}</div>
        <div v-for="(it, i) in items" :key="`${it.doc_type}-${it.doc_id}`" class="flex flex-wrap items-center gap-2 text-sm">
          <span class="font-mono min-w-[8rem]">{{ it.doc_no }}</span>
          <span class="text-neutral-500 text-xs">{{ t('accounting.saldo_settle.remaining_short', { amount: formatMoney(it.remaining_czk) }) }}</span>
          <input v-model.number="split[i]" type="number" step="0.01" min="0" :max="it.remaining_czk"
            class="ml-auto w-32 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right"
            :aria-label="t('accounting.saldo_settle.amount_for', { doc: it.doc_no })" />
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <span>{{ t('accounting.saldo_settle.total') }} <strong class="font-mono">{{ formatMoney(total) }}</strong></span>
        <span v-if="validation && !loading && data?.document.supported" class="text-xs text-neutral-500">{{ validation }}</span>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="button" :class="[btnFilled('success'), 'whitespace-nowrap']" :disabled="!!validation || saving" data-testid="saldo-advance-settle-submit" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('accounting.saldo_settle.advance_submit') }}
        </button>
      </div>
    </template>
  </Modal>
</template>

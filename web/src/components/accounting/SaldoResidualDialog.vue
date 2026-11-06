<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type SaldoSettlementLine } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import { apiErrorMessage, withPeriodForce } from './saldoSettle'

/**
 * Zůstatek účtu bez dokladu (záloha, přeplatek zaúčtovaný interním dokladem): z vybraného
 * řádku zápisu vznikne ostatní pohledávka nebo závazek, navázaný na ten zápis a BEZ nového
 * zaúčtování. Saldokonto pak položku vidí a zůstatek hlavní knihy nezdvojí.
 */
const props = defineProps<{
  accountCode: string
  accountName: string
  asOf: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done'): void
}>()

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const lines = ref<SaldoSettlementLine[]>([])
const loading = ref(true)
const saving = ref(false)
const search = ref('')
const selected = ref<SaldoSettlementLine | null>(null)
const form = ref({ amount: 0, title: '', partner_id: null as number | null, due_on: '' })

async function load() {
  loading.value = true
  try {
    lines.value = await accountingApi.getSaldoResidualLines({ account: props.accountCode, as_of: props.asOf, q: search.value.trim() || undefined })
    if (selected.value && !lines.value.some(l => l.line_id === selected.value?.line_id)) selected.value = null
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
    lines.value = []
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
})
onBeforeUnmount(() => clearTimeout(timer))

function pick(l: SaldoSettlementLine) {
  selected.value = l
  form.value = {
    amount: l.free_amount,
    title: [l.document_no, l.description].filter(Boolean).join(' - ').slice(0, 255),
    partner_id: null,
    due_on: l.entry_date,
  }
}

/** Strana položky se řídí stranou řádku: má dáti = pohledávka, dal = závazek. */
const sideLabel = computed(() => selected.value?.side === 'debit'
  ? t('accounting.saldo_settle.residual_receivable')
  : t('accounting.saldo_settle.residual_payable'))

const validation = computed<string | null>(() => {
  if (!selected.value) return t('accounting.saldo_settle.pick_line')
  const amount = Number(form.value.amount)
  if (!(amount > 0)) return t('accounting.saldo_settle.amount_positive')
  if (Math.round(amount * 100) > Math.round(selected.value.free_amount * 100)) return t('accounting.saldo_settle.amount_over_line')
  if (!form.value.title.trim()) return t('accounting.saldo_settle.residual_title_required')
  return null
})

async function submit() {
  const line = selected.value
  if (!line || validation.value || saving.value) return
  saving.value = true
  try {
    const result = await withPeriodForce(
      force => accountingApi.createSaldoOtherItem({
        line_id: line.line_id,
        amount: Number(form.value.amount),
        title: form.value.title.trim(),
        partner_id: form.value.partner_id,
        due_on: form.value.due_on || undefined,
      }, force),
      auth.isCompanyAdminRole,
      () => window.confirm(t('accounting.saldo_settle.period_closed_confirm')),
    )
    if (result === null) return
    toast.success(t('accounting.saldo_settle.residual_saved', { number: result.item.document_no ?? `#${result.item.id}` }))
    emit('done')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <Modal :title="t('accounting.saldo_settle.residual_title', { account: `${accountCode} - ${accountName}` })" width-class="max-w-4xl" @close="emit('close')">
    <div class="space-y-4" data-testid="saldo-residual-dialog">
      <p class="text-sm text-neutral-600">{{ t('accounting.saldo_settle.residual_intro') }}</p>
      <label class="block">
        <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo_settle.residual_search') }}</span>
        <input v-model="search" type="search" maxlength="100" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </label>

      <div v-if="loading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="lines.length === 0" dense accent="neutral" icon="search" :title="t('accounting.saldo_settle.residual_empty')" />
      <div v-else class="overflow-x-auto border border-neutral-200 rounded-md max-h-80">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide sticky top-0">
            <tr>
              <th class="px-3 py-2 w-8"><span class="sr-only">{{ t('accounting.saldo_settle.col_pick') }}</span></th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.col_date') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.col_entry') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo_settle.col_side') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo_settle.col_free') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="l in lines" :key="l.line_id" class="hover:bg-neutral-50 cursor-pointer" @click="pick(l)">
              <td class="px-3 py-2">
                <input type="radio" name="saldo-residual-line" class="border-neutral-300" :checked="selected?.line_id === l.line_id"
                  :aria-label="t('accounting.saldo_settle.pick_aria', { entry: l.document_no || l.entry_id })" @change="pick(l)" />
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(l.entry_date) }}</td>
              <td class="px-3 py-2 min-w-[14rem]">{{ [l.document_no, l.description].filter(Boolean).join(' - ') }}</td>
              <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ l.account_code }}</td>
              <td class="px-3 py-2 whitespace-nowrap">{{ l.side === 'debit' ? t('accounting.saldo_settle.side_debit') : t('accounting.saldo_settle.side_credit') }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(l.free_amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="selected" class="border border-neutral-200 rounded-md p-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        <div class="sm:col-span-2 text-xs px-2 py-1.5 rounded bg-neutral-50 text-neutral-700">{{ sideLabel }}</div>
        <label class="block sm:col-span-2">
          <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo_settle.residual_item_title') }}</span>
          <input v-model="form.title" type="text" maxlength="255" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="block">
          <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo_settle.col_amount') }}</span>
          <input v-model.number="form.amount" type="number" step="0.01" min="0.01" :max="selected.free_amount"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
        </label>
        <label class="block">
          <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo_settle.residual_due') }}</span>
          <DateInput v-model="form.due_on" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </label>
        <div class="sm:col-span-2">
          <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.col_partner') }}</span>
          <ClientSearchSelect v-model="form.partner_id" :role="selected.side === 'debit' ? 'customers' : 'vendors'" class="block" />
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <span v-if="validation && selected" class="text-xs text-neutral-500 mr-auto">{{ validation }}</span>
        <button type="button" :class="btnOutline('neutral')" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button type="button" :class="btnFilled('primary')" :disabled="!!validation || saving" data-testid="saldo-residual-submit" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.saldo_settle.residual_submit') }}
        </button>
      </div>
    </template>
  </Modal>
</template>

<script setup lang="ts">
/**
 * „Označit jako uhrazené" — jedno tlačítko, tři způsoby úhrady:
 *
 *   plain      → jen evidenční označení (beze změny v deníku). Vlastní logiku si
 *                drží rodič (děkovný e-mail u FV, transition u PF), proto se jen
 *                emituje `mark-paid` a modal se zavře.
 *   cash       → pokladní doklad (PPD u vydané, VPD u přijaté) přes CashDocumentService,
 *                ten sám zaúčtuje 211/311 resp. 321/211 a fakturu vyrovná.
 *   settlement → zápočet proti zvolenému účtu (předvolba 355 / 365).
 *
 * Přijatá faktura nemá paid_total → cash i settlement vyžadují PLNOU výši (backend
 * to vynucuje, tady jen zamkneme pole a řekneme to uživateli).
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type ChartAccount, type SettlementDocType } from '@/api/accounting'
import { cashApi, type CashRegister } from '@/api/cash'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

type Method = 'plain' | 'cash' | 'settlement'

const props = defineProps<{
  docType: SettlementDocType
  docId: number
  docNumber: string
  /** Zbytek k úhradě (u přijaté faktury celková částka). */
  amount: number
  partnerName?: string | null
  /** Jen u vydané faktury — děkovný e-mail za úhradu. */
  thanks?: { enabled: boolean; hasRecipient: boolean; defaultChecked: boolean }
  /** Rodič právě běží (mark-paid/transition) → zamknout tlačítko. */
  busy?: boolean
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'done'): void
  (e: 'mark-paid', payload: { date: string; sendThanks: boolean }): void
}>()

const { t } = useI18n()
const toast = useToast()

const method = ref<Method>('plain')
const settledOn = ref(appIsoDate())
const amount = ref(props.amount)
const note = ref('')
const sendThanks = ref(!!props.thanks?.enabled && !!props.thanks?.hasRecipient && !!props.thanks?.defaultChecked)
const registerId = ref<number | null>(null)
const accountId = ref<number | null>(null)
const registers = ref<CashRegister[]>([])
const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')

// Částečnou výši umí jen ZÁPOČET: eviduje se v invoice_settlements a zbytek přijaté
// faktury se z nich dopočítává. Hotovostní úhrada přijaté faktury a prosté označení
// „zaplaceno" částečnou částku neevidují, takže tam zůstává plná výše.
const fixedAmount = computed(() => props.docType === 'purchase_invoice' && method.value !== 'settlement')
const activeRegisters = computed(() => registers.value.filter(r => r.is_active))

const methods = computed<{ key: Method; label: string; hint: string }[]>(() => [
  { key: 'plain', label: t('invoice.pay_method.plain'), hint: t('invoice.pay_method.plain_hint') },
  { key: 'cash', label: t('invoice.pay_method.cash'), hint: t('invoice.pay_method.cash_hint') },
  { key: 'settlement', label: t('invoice.pay_method.settlement'), hint: t('invoice.pay_method.settlement_hint') },
])

const postingHint = computed(() => {
  if (method.value === 'cash') {
    const reg = activeRegisters.value.find(r => r.id === registerId.value)
    if (!reg) return ''
    return props.docType === 'invoice' ? `${reg.account_code} MD / 311 D` : `321 MD / ${reg.account_code} D`
  }
  if (method.value === 'settlement') {
    const acc = accounts.value.find(a => a.id === accountId.value)
    if (!acc) return ''
    return props.docType === 'invoice' ? `${acc.account_code} MD / 311 D` : `321 MD / ${acc.account_code} D`
  }
  return ''
})

async function ensureLoaded(m: Method) {
  error.value = ''
  if (m === 'cash' && registers.value.length === 0) {
    loading.value = true
    try {
      registers.value = await cashApi.listRegisters()
      registerId.value = (activeRegisters.value.find(r => r.is_default) ?? activeRegisters.value[0])?.id ?? null
      if (activeRegisters.value.length === 0) error.value = t('invoice.pay_cash.no_register')
    } catch (e: any) {
      error.value = e?.response?.data?.error?.message || t('common.error')
    } finally {
      loading.value = false
    }
  }
  if (m === 'settlement' && accounts.value.length === 0) {
    loading.value = true
    try {
      const [all, current] = await Promise.all([
        accountingApi.listAccounts(),
        accountingApi.listSettlements(props.docType, props.docId),
      ])
      // Účtovat nelze na syntetiku, která UŽ MÁ analytiky (na ty se účtuje místo ní).
      // Syntetika bez potomků je běžný účtovatelný účet — filtrovat podle is_synthetic
      // by v osnově bez analytik select vyprázdnilo.
      const parentIds = new Set(all.map(a => a.parent_id).filter((v): v is number => v !== null))
      accounts.value = all.filter(a => a.is_active && !parentIds.has(a.id))
      accountId.value = current.default_account.account_id
        ?? accounts.value.find(a => a.account_code === current.default_account.account_code)?.id
        ?? null
    } catch (e: any) {
      error.value = e?.response?.data?.error?.message || t('common.error')
    } finally {
      loading.value = false
    }
  }
}

watch(method, m => { ensureLoaded(m) })
watch(() => props.amount, v => { amount.value = v })
// Přepnutí metody vrátí částku na plnou výši: ručně zkrácená částka dává smysl jen
// u zápočtu a u ostatních metod by se tiše propsala do úhrady, která částečná být nemůže.
watch(method, () => { amount.value = props.amount })

async function submit() {
  error.value = ''

  if (method.value === 'plain') {
    emit('mark-paid', { date: settledOn.value, sendThanks: !!props.thanks?.enabled && sendThanks.value })
    return
  }

  if (amount.value <= 0) { error.value = t('invoice.pay_common.amount_required'); return }
  saving.value = true
  try {
    if (method.value === 'cash') {
      if (!registerId.value) { error.value = t('invoice.pay_cash.register_required'); return }
      await cashApi.createDocument({
        register_id: registerId.value,
        doc_type: props.docType === 'invoice' ? 'in' : 'out',
        purpose: props.docType === 'invoice' ? 'invoice_payment' : 'purchase_payment',
        issue_date: settledOn.value,
        description: t('invoice.pay_cash.description', { number: props.docNumber }),
        vat_mode: 'none',
        total_amount: amount.value,
        partner_name: props.partnerName || null,
        ...(props.docType === 'invoice'
          ? { invoice_id: props.docId }
          : { purchase_invoice_id: props.docId }),
      } as any)
    } else {
      if (!accountId.value) { error.value = t('invoice.pay_settlement.account_required'); return }
      await accountingApi.createSettlement({
        doc_type: props.docType,
        doc_id: props.docId,
        settled_on: settledOn.value,
        amount: amount.value,
        account_id: accountId.value,
        note: note.value.trim() || null,
      })
    }
    toast.success(t('invoice.pay_common.done'))
    emit('done')
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    const key = code ? `invoice.pay_common.error.${code}` : ''
    const localized = key ? t(key) : ''
    error.value = localized && localized !== key
      ? localized
      : (e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="emit('close')">
    <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
      <h3 class="text-lg font-semibold mb-1">{{ t('invoice.modals.mark_paid_title') }}</h3>
      <p class="text-sm text-neutral-500 mb-3">{{ docNumber }}</p>

      <div class="space-y-3">
        <!-- Volba způsobu úhrady -->
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.pay_method.label') }}</label>
          <div class="grid grid-cols-3 gap-1 p-1 bg-neutral-100 rounded-lg">
            <button v-for="m in methods" :key="m.key" type="button" @click="method = m.key"
              class="cursor-pointer h-8 px-2 text-sm rounded-md transition-colors"
              :class="method === m.key
                ? 'bg-surface text-neutral-900 font-medium shadow-sm'
                : 'text-neutral-600 hover:text-neutral-900'">
              {{ m.label }}
            </button>
          </div>
          <p class="text-xs text-neutral-500 mt-1">{{ methods.find(m => m.key === method)?.hint }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.pay_common.date') }}</label>
          <DateInput v-model="settledOn" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>

        <div v-if="loading" class="py-3 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>

        <template v-else>
          <div v-if="method === 'cash'">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.register') }}</label>
            <select v-model.number="registerId" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm">
              <option v-for="r in activeRegisters" :key="r.id" :value="r.id">
                {{ r.name }} ({{ r.account_code }})
              </option>
            </select>
          </div>

          <div v-if="method === 'settlement'">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.pay_settlement.account') }}</label>
            <select v-model.number="accountId" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm">
              <option v-for="a in accounts" :key="a.id" :value="a.id">
                {{ a.account_code }} — {{ a.name }}
              </option>
            </select>
          </div>

          <div v-if="method !== 'plain'">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.pay_common.amount') }}</label>
            <input v-model.number="amount" type="number" step="0.01" min="0" :disabled="fixedAmount"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm disabled:bg-neutral-50 disabled:text-neutral-500" />
            <p v-if="fixedAmount" class="text-xs text-neutral-500 mt-1">{{ t('invoice.pay_common.full_amount_only') }}</p>
            <p v-else-if="docType === 'purchase_invoice'" class="text-xs text-neutral-500 mt-1">
              {{ t('invoice.pay_settlement.partial_hint') }}
            </p>
          </div>

          <div v-if="method === 'settlement'">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.pay_settlement.note') }}</label>
            <input v-model="note" type="text" maxlength="500" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>

          <label v-if="method === 'plain' && thanks?.enabled" class="flex items-start gap-2 text-sm text-neutral-700 cursor-pointer">
            <input v-model="sendThanks" type="checkbox" :disabled="!thanks.hasRecipient"
              class="mt-0.5 rounded border-neutral-300 text-primary-600 disabled:opacity-50" />
            <span>
              {{ t('invoice.send_payment_thanks') }}
              <span v-if="!thanks.hasRecipient" class="block text-xs text-warning-600">{{ t('invoice.send_payment_thanks_no_recipient') }}</span>
            </span>
          </label>

          <p v-if="postingHint" class="text-xs text-neutral-500">
            {{ t('invoice.pay_common.will_post') }}: <span class="font-mono">{{ postingHint }}</span>
            — {{ formatMoney(amount) }}
          </p>
        </template>

        <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>

        <div class="flex justify-end gap-2 pt-1">
          <button type="button" @click="emit('close')" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button type="button" @click="submit" :disabled="saving || busy || loading" :class="btnFilled('success')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
            {{ saving || busy ? t('common.saving') : t('invoice.pay_common.confirm') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

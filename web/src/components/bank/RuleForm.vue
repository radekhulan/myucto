<script setup lang="ts">
import { reactive, watch, computed, ref, onMounted, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChartAccount } from '@/api/accounting'
import { bankPostingApi, type BankPostingRulePayload, type RuleDryRunResult, type SupplierBankAccount } from '@/api/bankPosting'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { settingsApi } from '@/api/settings'
import { useDimensions } from '@/composables/useDimensions'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import { usableDimensions } from './ruleDimensions'
import { otherItemsApi, type OtherItem } from '@/api/otherItems'

// Saldokontní účty nepatří do pravidla (H2) — datalist je na ne-bankovní straně filtruje.
const SALDO_PREFIXES = ['311', '321', '314', '324', '325']

const props = defineProps<{
  modelValue: BankPostingRulePayload
  accounts: ChartAccount[]
  mode: 'create' | 'edit'
  /** Základ částky (z transakce) pro helper „± %"; když chybí, helper se skryje. */
  baseAmount?: number
  showDryRun?: boolean
  /** Pravidlo se zakládá z konkrétního pohybu: hned se použije a je rovnou automatické. */
  fromTransaction?: boolean
  /** Edit: uložený režim pravidla (pro upozornění na vynucené povýšení). */
  initialMode?: 'suggest' | 'auto'
  /** Edit: pravidlo má 5 potvrzení beze změny, povýšení tedy není vynucené. */
  promotionCandidate?: boolean
  /** Vlastní bankovní účet a dimenze pravidla (editor pravidla, ne rychlé pravidlo z ručního zaúčtování). */
  showScope?: boolean
  /** Nabídnout cílovou ostatní položku pravidla (editor pravidla); bez práva číst položky se skryje. */
  otherItemTarget?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [BankPostingRulePayload] }>()

const { t } = useI18n()
const idPrefix = `bank-rule-form-${useId()}`
const debitListId = `${idPrefix}-debit`
const creditListId = `${idPrefix}-credit`

const form = reactive<BankPostingRulePayload>({ ...props.modelValue })
let syncing = false

watch(() => props.modelValue, (v) => {
  syncing = true
  Object.assign(form, v)
  syncing = false
})
watch(form, () => {
  if (!syncing) emit('update:modelValue', { ...form })
}, { deep: true })

const forcedPromotion = computed(() =>
  props.mode === 'edit' && props.initialMode === 'suggest' && form.mode === 'auto' && !props.promotionCandidate,
)

const activeAccounts = computed(() =>
  props.accounts.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)
function isSaldo(code: string): boolean {
  return SALDO_PREFIXES.some(p => code.startsWith(p))
}
// Ne-bankovní strana dle směru: incoming → protiúčet je credit; outgoing → debit.
const nonBankSide = computed<'debit' | 'credit'>(() => form.direction === 'incoming' ? 'credit' : 'debit')
const bankSideOptions = computed(() => activeAccounts.value.filter(a => a.account_code.startsWith('221')))
const nonBankOptions = computed(() => activeAccounts.value.filter(a => !isSaldo(a.account_code)))

// Helper „± %": z baseAmount dopočítá min/max lokálně (na BE jdou jen min/max, H4g).
const bandPercent = ref(10)
const currencies = ref<string[]>(['CZK'])

// Vlastní účet pravidla: aktivní účty a účet, na který je pravidlo už omezené (i archivovaný).
// Rychlé pravidlo z ručního zaúčtování tyhle volby nemá, dimenze se proto načítají jen v editoru pravidla.
const dims = props.showScope ? useDimensions() : null
const dimensionsVisible = computed(() => !!dims && dims.enabled.value && dims.documentTypes.value.length > 0)
const ownAccounts = ref<SupplierBankAccount[]>([])
const ownAccountOptions = computed(() => ownAccounts.value.filter(a => !!a.is_active || a.id === form.bank_account_id))
function ownAccountLabel(a: SupplierBankAccount): string {
  const number = [a.account_number, a.bank_code].filter(Boolean).join('/')
  return a.label ? `${a.label} (${number})` : number
}

onMounted(async () => {
  try {
    const available = await settingsApi.listCurrencies()
    currencies.value = [...new Set(['CZK', ...available.filter(c => c.is_active).map(c => c.code)])]
  } catch {
    currencies.value = ['CZK']
  }
  void loadOtherItems()
  if (!props.showScope) return
  try {
    ownAccounts.value = await bankPostingApi.listAccounts()
  } catch {
    ownAccounts.value = []
  }
  try {
    await dims?.load()
  } catch {
    return
  }
  // Nové pravidlo (z pohybu) převezme jen hodnoty, které formulář ukáže a server přijme.
  if (dims && props.mode === 'create' && Object.keys(form.dimensions ?? {}).length > 0) {
    form.dimensions = usableDimensions(form.dimensions ?? {}, dims.documentTypes.value, dims.valueById.value)
  }
})
// Cílová ostatní položka: potvrzené či zaúčtované otevřené položky v CZK podle směru pravidla.
// Uživatel bez práva číst ostatní položky dostane 403 a volba se neukáže.
const otherItemsLoaded = ref(false)
const otherItemsVisible = computed(() => !!props.otherItemTarget && (otherItemsLoaded.value || !!form.other_item_id))
const otherItemOptions = ref<OtherItem[]>([])
async function loadOtherItems() {
  if (!props.otherItemTarget) return
  try {
    const result = await otherItemsApi.list({
      side: form.direction === 'incoming' ? 'receivable' : 'payable', status: 'open', from: '1000-01-01', to: '9999-12-31',
    })
    otherItemOptions.value = result.items
      .map(item => ({ ...item, id: Number((item as { source_id?: number }).source_id ?? item.id) }))
      .filter(item => ['confirmed', 'posted'].includes(String(item.status)) && item.currency === 'CZK')
    otherItemsLoaded.value = true
  } catch {
    otherItemOptions.value = []
  }
}
watch(() => form.direction, () => { void loadOtherItems() })
function otherItemLabel(item: OtherItem): string {
  return [item.document_no, item.partner_name || item.title, formatMoney(item.remaining_amount, item.currency)].filter(Boolean).join(' · ')
}
// Pravidlo s položkou účtuje na její účet; výběr položky ho předvyplní na ne-bankovní straně.
watch(() => form.other_item_id, (id) => {
  const item = otherItemOptions.value.find(option => option.id === id)
  if (!item?.account_code) return
  if (nonBankSide.value === 'debit') form.debit_account_code = item.account_code
  else form.credit_account_code = item.account_code
})

function applyBand() {
  if (props.baseAmount == null) return
  const base = Math.abs(props.baseAmount)
  const f = bandPercent.value / 100
  form.amount_min = Math.floor(base * (1 - f))
  form.amount_max = Math.ceil(base * (1 + f))
}

// Dry-run panel
const dryRun = ref<RuleDryRunResult | null>(null)
const dryRunLoading = ref(false)
const dryRunError = ref('')
async function runDryRun() {
  dryRunError.value = ''
  dryRunLoading.value = true
  dryRun.value = null
  try {
    dryRun.value = await bankPostingApi.dryRunRule({ ...form })
  } catch {
    dryRunError.value = t('bank.posting.err_generic')
  } finally {
    dryRunLoading.value = false
  }
}

defineExpose({ runDryRun, dryRun })
</script>

<template>
  <div class="space-y-3">
    <div>
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_name') }}</label>
      <input v-model="form.name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
    </div>

    <div>
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_direction') }}</label>
      <select v-model="form.direction" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option value="incoming">{{ t('bank.posting.dir_incoming') }}</option>
        <option value="outgoing">{{ t('bank.posting.dir_outgoing') }}</option>
      </select>
    </div>

    <div v-if="showScope">
      <label :for="`${idPrefix}-own-account`" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_own_account') }}</label>
      <select :id="`${idPrefix}-own-account`" v-model="form.bank_account_id" data-test="rule-own-account"
        class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option :value="null">{{ t('bank.posting.rule_own_account_any') }}</option>
        <option v-for="a in ownAccountOptions" :key="a.id" :value="a.id">{{ ownAccountLabel(a) }}</option>
      </select>
      <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank.posting.rule_own_account_hint') }}</p>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_counterparty') }}</label>
        <input v-model="form.counterparty_account" type="text"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_counterparty_bank') }}</label>
        <input v-model="form.counterparty_bank" type="text"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_vs') }}</label>
        <input v-model="form.variable_symbol" type="text" inputmode="numeric"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_fragment') }}</label>
        <input v-model="form.message_contains" type="text"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank.posting.rule_message_hint') }}</p>
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_amount_range') }}</label>
      <div class="flex items-center gap-2">
        <input v-model.number="form.amount_min" type="number" step="0.01" :placeholder="t('bank.posting.amount_min')"
          class="w-full min-w-0 h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
        <span class="text-neutral-400">–</span>
        <input v-model.number="form.amount_max" type="number" step="0.01" :placeholder="t('bank.posting.amount_max')"
          class="w-full min-w-0 h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
      </div>
      <div v-if="baseAmount != null" class="flex items-center gap-2 mt-1.5">
        <span class="text-xs text-neutral-500">± %</span>
        <input v-model.number="bandPercent" type="number" min="0" max="100" step="1"
          class="w-16 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right" />
        <button type="button" @click="applyBand"
          class="cursor-pointer text-xs text-primary-600 hover:underline">{{ t('bank.posting.apply_band') }}</button>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="sm:col-span-2">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_auto_cap') }}</label>
        <input v-model.number="form.auto_amount_cap" type="number" min="0" step="0.01"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_priority') }}</label>
        <input v-model.number="form.priority" type="number" min="0" max="999" step="1"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
      </div>
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_currency') }}</label>
        <select v-model="form.applies_currency" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option>
        </select>
      </div>
    </div>
    <p class="text-xs text-neutral-500 -mt-1">
      {{ t('bank.posting.rule_priority_hint') }} · {{ t('bank.posting.rule_currency_hint') }}
    </p>

    <div class="grid grid-cols-2 gap-2">
      <div>
        <label :for="debitListId" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.debit') }}</label>
        <ChartAccountSelect v-model="form.debit_account_code" :input-id="debitListId"
          :accounts="nonBankSide === 'debit' ? nonBankOptions : bankSideOptions" />
      </div>
      <div>
        <label :for="creditListId" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.credit') }}</label>
        <ChartAccountSelect v-model="form.credit_account_code" :input-id="creditListId"
          :accounts="nonBankSide === 'credit' ? nonBankOptions : bankSideOptions" />
      </div>
    </div>
    <p class="text-xs text-neutral-500">{{ t('bank.posting.saldo_hint') }}</p>

    <div v-if="otherItemsVisible">
      <label :for="`${idPrefix}-other-item`" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_other_item') }}</label>
      <select :id="`${idPrefix}-other-item`" v-model="form.other_item_id" data-test="rule-other-item"
        class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
        <option :value="null">{{ t('bank.posting.rule_other_item_none') }}</option>
        <option v-if="form.other_item_id && !otherItemOptions.some(item => item.id === form.other_item_id)" :value="form.other_item_id">#{{ form.other_item_id }}</option>
        <option v-for="item in otherItemOptions" :key="item.id" :value="item.id">{{ otherItemLabel(item) }}</option>
      </select>
      <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank.posting.rule_other_item_hint') }}</p>
    </div>

    <div v-if="dimensionsVisible" data-test="rule-dimensions">
      <p class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_dimensions') }}</p>
      <DimensionFields v-model="form.dimensions" />
      <p class="text-xs text-neutral-500 mt-0.5">{{ t('bank.posting.rule_dimensions_hint') }}</p>
    </div>

    <div v-if="mode === 'edit'">
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.rule_mode') }}</label>
      <select v-model="form.mode" data-test="rule-mode-select"
        :disabled="!form.is_active && initialMode !== 'auto'"
        class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50">
        <option value="suggest">{{ t('bank.posting.mode_suggest') }}</option>
        <option value="auto" :disabled="!form.is_active">{{ t('bank.posting.mode_auto') }}</option>
      </select>
      <p v-if="forcedPromotion" class="text-xs text-warning-600 mt-0.5" data-test="rule-mode-forced">{{ t('automation.rules.mode_forced_hint') }}</p>
      <p v-if="!form.is_active && form.mode === 'suggest'" class="text-xs text-neutral-500 mt-0.5">{{ t('automation.rules.mode_inactive_hint') }}</p>
      <p class="text-xs text-neutral-500 mt-0.5">{{ t('automation.rules.mode_change_hint') }}</p>
    </div>
    <p v-else-if="fromTransaction" class="text-xs text-neutral-500" data-test="rule-mode-hint">{{ t('bank.posting.mode_from_transaction_hint') }}</p>
    <p v-else class="text-xs text-neutral-500" data-test="rule-mode-hint">{{ t('bank.posting.mode_suggest_only_hint') }}</p>

    <div v-if="showDryRun" class="border-t border-neutral-200 pt-3">
      <button type="button" @click="runDryRun" :disabled="dryRunLoading"
        class="cursor-pointer h-9 px-3 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 disabled:opacity-50 rounded-md font-medium">
        {{ dryRunLoading ? '…' : t('bank.posting.dry_run') }}
      </button>
      <div v-if="dryRunError" class="text-sm text-danger-500 mt-2">{{ dryRunError }}</div>
      <div v-else-if="dryRun" class="mt-2">
        <p v-if="dryRun.shadowed_by_own_transfer" class="mb-2 rounded-md bg-warning-50 p-3 text-sm text-warning-700">
          {{ t('bank.posting.dry_run_own_transfer_shadowed') }}
        </p>
        <p v-if="dryRun.matched_count === 0" class="text-sm text-warning-600">{{ t('bank.posting.dry_run_zero') }}</p>
        <template v-else>
          <p class="text-sm text-neutral-700">
            {{ t('bank.posting.dry_run_result', { count: dryRun.matched_count }) }}
            <span v-if="dryRun.already_posted_count > 0" class="text-warning-600">
              · {{ t('bank.posting.dry_run_already', { count: dryRun.already_posted_count }) }}
            </span>
          </p>
          <ul class="mt-1.5 border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-48 overflow-auto text-xs">
            <li v-for="s in dryRun.sample" :key="s.id" class="px-3 py-1.5 flex items-center justify-between gap-2">
              <span class="text-neutral-500">{{ formatDate(s.posted_at) }}</span>
              <span class="truncate flex-1 text-neutral-600">{{ s.description }}</span>
              <span class="font-mono whitespace-nowrap" :class="s.amount > 0 ? 'text-success-700 dark:text-success-600' : 'text-danger-500'">
                {{ formatMoney(s.amount, 'CZK') }}
              </span>
              <span v-if="s.already_posted" class="text-[10px] uppercase px-1.5 py-0.5 rounded bg-neutral-200 text-neutral-600">
                {{ t('bank.posting.dry_run_posted_tag') }}
              </span>
            </li>
          </ul>
        </template>
      </div>
    </div>
  </div>
</template>

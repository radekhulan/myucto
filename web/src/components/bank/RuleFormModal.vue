<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import {
  bankPostingApi, bankPostingErrorMessage,
  type BankPostingRule, type BankPostingRulePayload, type RuleCreateResult,
} from '@/api/bankPosting'
import RuleForm from './RuleForm.vue'
import { promoteConfirmMessage } from './rulePromotion'

const props = defineProps<{
  /** Editovaný záznam; null/undefined = zakládání nového. */
  rule?: BankPostingRule | null
  /** Prefill payload pro create (learned hint / z transakce). */
  prefill?: Partial<BankPostingRulePayload>
  /** Základ částky pro helper „± %" (z transakce). */
  baseAmount?: number
  /**
   * Pohyb, ze kterého se pravidlo zakládá. Pravidlo se po uložení hned použije na
   * tenhle pohyb, je rovnou automatické a volitelně zaúčtuje i další shodné pohyby.
   */
  sourceTransactionId?: number | null
}>()
const emit = defineEmits<{ saved: [BankPostingRule, RuleCreateResult?]; close: [] }>()

const { t } = useI18n()
const toast = useToast()

const isEdit = computed(() => !!props.rule)
const accounts = ref<ChartAccount[]>([])
const saving = ref(false)
const backfill = ref(false)
const fromTransaction = computed(() => !isEdit.value && !!props.sourceTransactionId)
// Z pohybu: „použít i na další odpovídající pohyby" je výchozí volba (uživatel pravidlo
// zakládá právě proto, aby se opakované platby přestaly účtovat ručně).
const applyMatching = ref(true)
const applicableCount = ref(0)
const formRef = ref<InstanceType<typeof RuleForm> | null>(null)

function emptyPayload(): BankPostingRulePayload {
  return {
    name: '', is_active: true, direction: 'outgoing',
    counterparty_account: null, counterparty_bank: null,
    variable_symbol: null, message_contains: null,
    amount_min: null, amount_max: null,
    priority: 100, operation_type: null, auto_amount_cap: null,
    applies_currency: 'CZK', counterparty_prefix: null,
    debit_account_code: '', credit_account_code: '',
    description: null, mode: 'suggest',
    bank_account_id: null, dimensions: {}, other_item_id: null,
    skip_document_matching: false, card_payment: null,
  }
}

const payload = reactive<BankPostingRulePayload>(
  props.rule
    ? { ...toPayload(props.rule) }
    : { ...emptyPayload(), ...(props.prefill ?? {}) },
)

function toPayload(r: BankPostingRule): BankPostingRulePayload {
  return {
    name: r.name, is_active: r.is_active, direction: r.direction,
    counterparty_account: r.counterparty_account, counterparty_bank: r.counterparty_bank,
    variable_symbol: r.variable_symbol, message_contains: r.message_contains,
    amount_min: r.amount_min, amount_max: r.amount_max,
    priority: r.priority, operation_type: r.operation_type, auto_amount_cap: r.auto_amount_cap,
    applies_currency: r.applies_currency, counterparty_prefix: r.counterparty_prefix,
    debit_account_code: r.debit_account_code, credit_account_code: r.credit_account_code,
    description: r.description, mode: r.mode,
    bank_account_id: r.bank_account_id ?? null, dimensions: { ...(r.dimensions ?? {}) },
    other_item_id: r.other_item_id ?? null,
    skip_document_matching: !!r.skip_document_matching, card_payment: r.card_payment ?? null,
  }
}

onMounted(async () => {
  accounts.value = await accountingApi.listAccounts()
})

useHotkey('escape', () => { if (!saving.value) emit('close') })

// Počet shod z posledního dry-runu — feeduje backfill checkbox label.
const dryRunCount = computed(() => formRef.value?.dryRun?.matched_count ?? 0)
watch(dryRunCount, (n) => { if (n === 0) backfill.value = false })

// Z pohybu se počet dalších použitelných shod dopočítává sám (tiše, bez panelu testu),
// aby checkbox nabídl konkrétní číslo i bez kliknutí na „Otestovat na historii".
let countTimer: ReturnType<typeof setTimeout> | null = null
let countSeq = 0
async function refreshApplicableCount() {
  if (!payload.debit_account_code || !payload.credit_account_code) {
    applicableCount.value = 0
    return
  }
  const seq = ++countSeq
  try {
    const res = await bankPostingApi.dryRunRule({ ...payload, source_transaction_id: props.sourceTransactionId ?? undefined })
    if (seq === countSeq) applicableCount.value = res.applicable_count ?? 0
  } catch {
    if (seq === countSeq) applicableCount.value = 0
  }
}
if (fromTransaction.value) {
  watch(payload, () => {
    if (countTimer) clearTimeout(countTimer)
    countTimer = setTimeout(refreshApplicableCount, 500)
  }, { deep: true, immediate: true })
}
onBeforeUnmount(() => { if (countTimer) clearTimeout(countTimer) })

function sourceMessage(res: RuleCreateResult): { kind: 'success' | 'info' | 'warning'; text: string } {
  const applied = res.applied ?? 0
  const src = res.source_result
  const appliedText = applied > 0 ? ' ' + t('bank.posting.rule_applied_others', { count: applied }) : ''
  if (src?.status === 'posted') {
    return { kind: 'success', text: t('bank.posting.rule_created_posted') + appliedText }
  }
  if (src?.status === 'already_posted') {
    return {
      kind: src.same_accounts ? 'success' : 'info',
      text: (src.same_accounts ? t('bank.posting.rule_created_same_posting') : t('bank.posting.rule_created_repost_offer')) + appliedText,
    }
  }
  const reasonKey = `bank.posting.rule_source_reason.${src?.reason ?? 'other'}`
  const reason = t(reasonKey) === reasonKey ? t('bank.posting.rule_source_reason.other') : t(reasonKey)
  return {
    kind: src?.status === 'blocked' ? 'warning' : 'info',
    text: t('bank.posting.rule_created_not_posted', { reason }) + appliedText,
  }
}

async function save() {
  if (saving.value) return
  saving.value = true
  try {
    if (isEdit.value && props.rule) {
      const rule = props.rule
      // Režim se mění auditovatelnými endpointy promote/demote až po uložení pravidla;
      // vynucené povýšení (bez 5 potvrzení) musí uživatel výslovně potvrdit.
      const { mode, ...fields } = payload
      const promote = mode === 'auto' && rule.mode === 'suggest'
      const demote = mode === 'suggest' && rule.mode === 'auto'
      if (promote && !confirm(promoteConfirmMessage({
        ...rule, amount_min: payload.amount_min, amount_max: payload.amount_max,
      }, t))) return
      const updated = await bankPostingApi.updateRule(rule.id, { ...fields, backfill_suggestions: backfill.value })
      let saved: BankPostingRule = updated
      if (promote) saved = await bankPostingApi.promoteRule(rule.id)
      else if (demote) saved = await bankPostingApi.demoteRule(rule.id)
      const msg = updated.backfilled && updated.backfilled > 0
        ? t('bank.posting.rule_created_backfilled', { count: updated.backfilled })
        : promote ? t('automation.rules.promoted')
          : demote ? t('automation.rules.demoted')
            : t('common.saved')
      toast.success(msg)
      emit('saved', saved)
    } else {
      if (fromTransaction.value) {
        const res = await bankPostingApi.createRule({
          ...payload,
          source_transaction_id: props.sourceTransactionId ?? undefined,
          apply_matching: applyMatching.value && applicableCount.value > 0,
        })
        const msg = sourceMessage(res)
        toast[msg.kind](msg.text)
        emit('saved', res.rule, res)
        return
      }
      const res = await bankPostingApi.createRule({ ...payload, backfill_suggestions: backfill.value })
      const msg = res.backfilled && res.backfilled > 0
        ? t('bank.posting.rule_created_backfilled', { count: res.backfilled })
        : t('common.saved')
      toast.success(msg)
      emit('saved', res.rule, res)
    }
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-surface rounded-xl shadow-lg max-w-md w-full max-h-[85vh] overflow-y-auto p-5">
      <h3 class="text-lg font-semibold mb-3">
        {{ isEdit ? t('bank.posting.rule_edit_title') : t('bank.posting.rule_create_title') }}
      </h3>

      <RuleForm ref="formRef" :model-value="payload" @update:model-value="Object.assign(payload, $event)" :accounts="accounts"
        :mode="isEdit ? 'edit' : 'create'" :base-amount="baseAmount" :show-dry-run="true"
        :from-transaction="fromTransaction" :initial-mode="rule?.mode"
        :promotion-candidate="rule?.promotion_candidate ?? false" :show-scope="true" :other-item-target="true" />

      <label v-if="fromTransaction && applicableCount > 0" data-test="apply-matching"
        class="flex items-center gap-2 mt-3 text-sm text-neutral-700 cursor-pointer">
        <input v-model="applyMatching" type="checkbox" class="rounded border-neutral-300" />
        {{ t('bank.posting.apply_matching_checkbox', { count: applicableCount }) }}
      </label>
      <label v-else-if="!fromTransaction && dryRunCount > 0" class="flex items-center gap-2 mt-3 text-sm text-neutral-700 cursor-pointer">
        <input v-model="backfill" type="checkbox" class="rounded border-neutral-300" />
        {{ t('bank.posting.backfill_checkbox', { count: dryRunCount }) }}
      </label>

      <div class="flex justify-end gap-2 pt-4">
        <button @click="emit('close')" :disabled="saving"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
          {{ t('common.cancel') }}
        </button>
        <button @click="save" :disabled="saving"
          class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ saving ? '…' : t('common.save') }}
        </button>
      </div>
    </div>
  </div>
</template>

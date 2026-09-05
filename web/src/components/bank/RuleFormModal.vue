<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import {
  bankPostingApi, bankPostingErrorMessage,
  type BankPostingRule, type BankPostingRulePayload,
} from '@/api/bankPosting'
import RuleForm from './RuleForm.vue'

const props = defineProps<{
  /** Editovaný záznam; null/undefined = zakládání nového. */
  rule?: BankPostingRule | null
  /** Prefill payload pro create (learned hint / z transakce). */
  prefill?: Partial<BankPostingRulePayload>
  /** Základ částky pro helper „± %" (z transakce). */
  baseAmount?: number
}>()
const emit = defineEmits<{ saved: [BankPostingRule]; close: [] }>()

const { t } = useI18n()
const toast = useToast()

const isEdit = computed(() => !!props.rule)
const accounts = ref<ChartAccount[]>([])
const saving = ref(false)
const backfill = ref(false)
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
  }
}

onMounted(async () => {
  accounts.value = await accountingApi.listAccounts()
})

useHotkey('escape', () => { if (!saving.value) emit('close') })

// Počet shod z posledního dry-runu — feeduje backfill checkbox label.
const dryRunCount = computed(() => formRef.value?.dryRun?.matched_count ?? 0)
watch(dryRunCount, (n) => { if (n === 0) backfill.value = false })

async function save() {
  if (saving.value) return
  saving.value = true
  try {
    if (isEdit.value && props.rule) {
      const { mode: _mode, ...fields } = payload
      const updated = await bankPostingApi.updateRule(props.rule.id, { ...fields, backfill_suggestions: backfill.value })
      const msg = updated.backfilled && updated.backfilled > 0
        ? t('bank.posting.rule_created_backfilled', { count: updated.backfilled })
        : t('common.saved')
      toast.success(msg)
      emit('saved', updated)
    } else {
      const res = await bankPostingApi.createRule({ ...payload, backfill_suggestions: backfill.value })
      const msg = res.backfilled && res.backfilled > 0
        ? t('bank.posting.rule_created_backfilled', { count: res.backfilled })
        : t('common.saved')
      toast.success(msg)
      emit('saved', res.rule)
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
        :mode="isEdit ? 'edit' : 'create'" :base-amount="baseAmount" :show-dry-run="true" />

      <label v-if="dryRunCount > 0" class="flex items-center gap-2 mt-3 text-sm text-neutral-700 cursor-pointer">
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

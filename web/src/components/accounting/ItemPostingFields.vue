<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChartAccount, PostingRule } from '@/api/accounting'
import ChartAccountSelect from './ChartAccountSelect.vue'

/**
 * Předkontace a protiúčet řádku faktury (zaúčtování už při zadání dokladu).
 *
 * Řádek tím nese úplnou dvojici účtů: u přijaté faktury účet nákladu (MD, pole
 * v editoru vedle) a protiúčet = závazek (Dal), u vydané protiúčet = pohledávka (MD)
 * a výnosový účet (Dal). Výběr předkontace doplní prázdné účty řádku; server je
 * při zaúčtování doplní stejně, takže pole jsou jen předvyplnění, ne druhý výpočet.
 */
const props = withDefaults(defineProps<{
  direction: 'purchase' | 'sale'
  ruleKey: string | null | undefined
  counterAccount: string | null | undefined
  rules: PostingRule[]
  accounts: ChartAccount[]
  /** Protiúčet, který platí bez volby na řádku (z předkontace dokladu). */
  defaultCounter?: string | null
  /** Pole pod sebou (mobilní karta). */
  stacked?: boolean
  disabled?: boolean
}>(), { defaultCounter: null, stacked: false, disabled: false })

const emit = defineEmits<{
  'update:ruleKey': [string | null]
  'update:counterAccount': [string | null]
  /** Vybraná předkontace: rodič doplní účet řádku, pokud ho řádek nemá. */
  ruleApplied: [rule: PostingRule]
}>()

const { t } = useI18n()

// Protiúčet je saldokonto třídy 3: závazek u přijaté, pohledávka u vydané faktury.
const counterAccounts = computed(() => props.accounts.filter(a => a.is_active
  && a.account_code.startsWith('3')
  && !['343', '314', '324'].includes(a.account_code.slice(0, 3))
  && a.account_type === (props.direction === 'purchase' ? 'liability' : 'asset')))

/** Server u předkontace nese, na které řádky faktur se hodí (`line_purchase`, `line_sale`). */
function matchesDirection(r: PostingRule): boolean {
  return props.direction === 'purchase' ? r.line_purchase === true : r.line_sale === true
}

const ruleOptions = computed(() => props.rules
  .filter(r => r.is_active && (r.debit_account_code || r.credit_account_code)
    && (matchesDirection(r) || r.rule_key === props.ruleKey))
  .slice()
  .sort((a, b) => a.rule_key.localeCompare(b.rule_key)))

function onRule(value: string) {
  const key = value === '' ? null : value
  emit('update:ruleKey', key)
  const rule = key ? props.rules.find(r => r.rule_key === key) : undefined
  if (!rule) return
  const counter = props.direction === 'purchase' ? rule.credit_account_code : rule.debit_account_code
  if (!props.counterAccount && counter) emit('update:counterAccount', counter)
  emit('ruleApplied', rule)
}

function onCounter(value: string) {
  emit('update:counterAccount', value.trim() === '' ? null : value.trim())
}

const counterLabel = computed(() => t(props.direction === 'purchase'
  ? 'accounting.editor_posting.counter_purchase'
  : 'accounting.editor_posting.counter_sale'))
</script>

<template>
  <div :class="stacked ? 'space-y-2' : 'contents'">
    <label :class="stacked ? 'block text-xs text-neutral-600' : 'flex items-center gap-2 text-xs text-neutral-500'"
      data-test="item-posting-rule">
      <span class="shrink-0" :class="stacked ? 'block mb-1' : ''">{{ t('accounting.editor_posting.rule') }}</span>
      <select :value="ruleKey ?? ''" :disabled="disabled" :aria-label="t('accounting.editor_posting.rule')"
        class="h-8 px-1 border border-neutral-300 rounded bg-surface text-sm text-neutral-900"
        :class="stacked ? 'w-full h-10! px-2' : 'w-64 max-w-full'"
        @change="onRule(($event.target as HTMLSelectElement).value)">
        <option value="">{{ t('accounting.editor_posting.rule_none') }}</option>
        <option v-if="ruleKey && !ruleOptions.some(r => r.rule_key === ruleKey)" :value="ruleKey">{{ ruleKey }}</option>
        <option v-for="r in ruleOptions" :key="r.rule_key" :value="r.rule_key">
          {{ r.description || r.rule_key }} ({{ r.debit_account_code || '…' }} / {{ r.credit_account_code || '…' }})
        </option>
      </select>
    </label>
    <label :class="stacked ? 'block text-xs text-neutral-600' : 'flex items-center gap-2 text-xs text-neutral-500'"
      :title="t('accounting.editor_posting.counter_hint')" data-test="item-counter-account">
      <span class="shrink-0" :class="stacked ? 'block mb-1' : ''">{{ counterLabel }}</span>
      <ChartAccountSelect :model-value="counterAccount ?? ''" :accounts="counterAccounts" :disabled="disabled"
        :compact="!stacked" :aria-label="counterLabel" :class="stacked ? 'w-full' : 'w-44 max-w-full'"
        :placeholder="defaultCounter ? t('accounting.editor_posting.counter_default', { code: defaultCounter }) : undefined"
        @update:model-value="onCounter" />
    </label>
  </div>
</template>

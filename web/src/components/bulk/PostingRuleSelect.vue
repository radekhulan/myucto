<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type PostingRule } from '@/api/accounting'

/**
 * Výběr předkontace (stejný číselník a popisky jako pole Předkontace na řádku faktury).
 * `direction` zúží nabídku na předkontace pro řádky vydaných, resp. přijatých faktur.
 */
const props = withDefaults(defineProps<{
  modelValue: string | null | undefined
  direction?: 'sale' | 'purchase' | null
  inputId?: string
  disabled?: boolean
  emptyLabel?: string
}>(), { direction: null, inputId: undefined, disabled: false, emptyLabel: undefined })

const emit = defineEmits<{ 'update:modelValue': [string | null] }>()
const { t } = useI18n()
const rules = ref<PostingRule[]>([])

onMounted(() => {
  accountingApi.listPostingRules()
    .then(map => { rules.value = Object.values(map ?? {}) })
    .catch(() => { rules.value = [] })
})

const options = computed(() => rules.value
  .filter(r => r.is_active && (r.debit_account_code || r.credit_account_code)
    && (props.direction === null
      || (props.direction === 'purchase' ? r.line_purchase === true : r.line_sale === true)
      || r.rule_key === props.modelValue))
  .slice()
  .sort((a, b) => a.rule_key.localeCompare(b.rule_key)))
</script>

<template>
  <select :id="inputId" :value="modelValue ?? ''" :disabled="disabled"
    class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="posting-rule-select"
    @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value || null)">
    <option value="">{{ emptyLabel ?? t('accounting.editor_posting.rule_none') }}</option>
    <option v-if="modelValue && !options.some(r => r.rule_key === modelValue)" :value="modelValue">{{ modelValue }}</option>
    <option v-for="r in options" :key="r.rule_key" :value="r.rule_key">
      {{ r.description || r.rule_key }} ({{ r.debit_account_code || '…' }} / {{ r.credit_account_code || '…' }})
    </option>
  </select>
</template>

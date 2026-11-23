<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChartAccount } from '@/api/accounting'
import { accountPickerOptions } from '@/utils/chartAccountOptions'

const props = defineProps<{
  modelValue: string | null | undefined
  accounts: ChartAccount[]
  inputId?: string
  ariaLabel?: string
  placeholder?: string
  disabled?: boolean
  /** Nižší pole (řádek položky dokladu). */
  compact?: boolean
  /**
   * Pole je mez rozsahu účtů: přijme i začátek kódu (5, 501) a pod polem ukáže, kolik účtů
   * na něj začíná. Neexistující kód (500 jako dolní mez) není chyba a počítají se i neaktivní
   * účty, protože rozsah se týká i starých pohybů.
   */
  allowPrefix?: boolean
  /** Osnova se teprve načítá: u meze rozsahu ještě nic nehlásit (prázdný seznam neznamená „žádný účet“). */
  loading?: boolean
  /** Vlastní třídy pole místo výšky podle `compact` (např. výška lišty filtrů). */
  inputClass?: string
}>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()
const { t } = useI18n()
const listId = `chart-account-options-${useId()}`
const options = computed(() => accountPickerOptions(props.accounts))
// Mez rozsahu server porovnává bez ohledu na velikost písmen (221.ka = 221.KA), pole také.
const selected = computed(() => props.allowPrefix
  ? props.accounts.find(account => account.account_code.toUpperCase() === (props.modelValue || '').toUpperCase())
  : options.value.find(account => account.account_code === props.modelValue))
const prefixCount = computed(() => {
  const prefix = (props.modelValue || '').toUpperCase()
  return props.allowPrefix && prefix !== '' && !selected.value
    ? props.accounts.filter(account => account.account_code.toUpperCase().startsWith(prefix)).length
    : 0
})
</script>

<template>
  <div>
    <input :id="inputId" :value="modelValue || ''" :list="listId" type="text" :disabled="disabled"
      :aria-label="ariaLabel" :placeholder="placeholder || t('accounting.template.account_search')" autocomplete="off"
      class="w-full px-3 border border-neutral-300 rounded-md font-mono bg-surface" :class="inputClass ?? (compact ? 'h-8 text-xs' : 'h-10 text-sm')"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)" />
    <datalist :id="listId">
      <option v-for="account in options" :key="account.account_code" :value="account.account_code" :label="account.name">{{ account.account_code }} / {{ account.name }}</option>
    </datalist>
    <template v-if="!(allowPrefix && loading)">
      <p v-if="selected" class="text-xs text-neutral-500 mt-0.5 truncate">{{ selected.name }}</p>
      <p v-else-if="prefixCount > 0" class="text-xs text-neutral-500 mt-0.5 truncate">{{ t('accounting.template.account_prefix', { prefix: modelValue, n: prefixCount }) }}</p>
      <p v-else-if="modelValue && allowPrefix" class="text-xs text-neutral-500 mt-0.5 truncate">{{ t('accounting.template.account_prefix_none', { prefix: modelValue }) }}</p>
      <p v-else-if="modelValue && !disabled" class="text-xs text-danger-500 mt-0.5">{{ t('accounting.manual.unknown_code') }}</p>
    </template>
  </div>
</template>

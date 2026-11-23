<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'

/**
 * Hodnota kontroly `deferrals_agenda_difference`: rozdíl agendy Časové rozlišení proti hlavní
 * knize po účtech a proklik do agendy k datu kontroly (tam jsou řádky 38x bez položky).
 */
const props = defineProps<{ value: unknown }>()
const { t } = useI18n()

interface AgendaAccount { account_code: string; difference: number }
const parsed = computed(() => {
  const v = (props.value && typeof props.value === 'object' ? props.value : {}) as { as_of?: unknown; difference?: unknown; accounts?: unknown }
  const accounts = Array.isArray(v.accounts)
    ? (v.accounts as Record<string, unknown>[]).map(row => ({ account_code: String(row.account_code ?? ''), difference: Number(row.difference ?? 0) }))
    : [] as AgendaAccount[]
  return { asOf: typeof v.as_of === 'string' ? v.as_of : '', difference: Number(v.difference ?? 0), accounts }
})
const link = computed(() => ({ name: 'accounting-accruals', query: parsed.value.asOf ? { as_of: parsed.value.asOf } : {} }))
</script>

<template>
  <span class="inline-flex flex-wrap items-center justify-end gap-x-3 gap-y-0.5" data-test="accrual-agenda-check">
    <span v-for="row in parsed.accounts" :key="row.account_code" class="font-mono">{{ row.account_code }}: {{ formatMoney(row.difference) }}</span>
    <span v-if="!parsed.accounts.length" class="font-mono">{{ formatMoney(parsed.difference) }}</span>
    <RouterLink :to="link" class="text-primary-600 hover:underline">{{ t('accruals.check.open_agenda') }}</RouterLink>
  </span>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { bankApi, type AccountBalancesResponse } from '@/api/bank'
import { cashApi, type CashRegister } from '@/api/cash'
import { formatDate, formatMoney } from '@/composables/useFormat'

/**
 * Stav peněz v nabídce Peníze na telefonu: bankovní účty podle posledního výpisu
 * nebo avíza a zůstatky pokladen. Načítá se až při otevření nabídky, ne s layoutem.
 */
const props = defineProps<{ showBank: boolean; showCash: boolean }>()

const { t } = useI18n()
const bank = ref<AccountBalancesResponse | null>(null)
const registers = ref<CashRegister[]>([])
const loading = ref(true)

const ACCOUNTS_VISIBLE = 3
const showAll = ref(false)
// Výchozí účty první, pak podle stavu v CZK: na telefon se vejdou jen ty podstatné.
const sortedAccounts = computed(() => [...(bank.value?.accounts ?? [])].sort((a, b) =>
  Number(b.is_default) - Number(a.is_default)
  || Math.abs(b.current_balance_czk ?? 0) - Math.abs(a.current_balance_czk ?? 0)))
const accounts = computed(() => showAll.value ? sortedAccounts.value : sortedAccounts.value.slice(0, ACCOUNTS_VISIBLE))
const hiddenCount = computed(() => sortedAccounts.value.length - accounts.value.length)

onMounted(async () => {
  const [bankResult, cashResult] = await Promise.allSettled([
    props.showBank ? bankApi.accountBalances() : Promise.resolve(null),
    props.showCash ? cashApi.listRegisters() : Promise.resolve([] as CashRegister[]),
  ])
  if (bankResult.status === 'fulfilled') bank.value = bankResult.value
  if (cashResult.status === 'fulfilled') registers.value = cashResult.value ?? []
  loading.value = false
})
</script>

<template>
  <div v-if="loading" class="mb-3 h-24 animate-pulse rounded-2xl bg-neutral-100" aria-hidden="true"></div>
  <div
    v-else-if="sortedAccounts.length || registers.length"
    class="mb-3 overflow-hidden rounded-2xl border border-neutral-200 bg-surface shadow-sm"
    data-testid="mobile-money-summary"
  >
    <div v-if="accounts.length" class="px-4 pb-2 pt-3">
      <div class="text-xs font-medium text-neutral-500">{{ t('nav.mobile.money_bank_total') }}</div>
      <div class="text-2xl font-semibold tabular-nums text-neutral-900">
        {{ formatMoney(bank?.total_czk.current ?? 0, bank?.base_currency ?? 'CZK', 0) }}
      </div>
    </div>
    <ul class="divide-y divide-neutral-100 border-t border-neutral-100 text-sm">
      <li v-for="account in accounts" :key="`b${account.id}`" class="flex items-center gap-3 px-4 py-2">
        <span class="min-w-0 flex-1">
          <span class="block truncate text-neutral-800">{{ account.label || account.account_number }}</span>
          <span class="block text-xs text-neutral-500">{{ formatDate(account.statement_date) }}</span>
        </span>
        <span class="shrink-0 font-medium tabular-nums text-neutral-900">{{ formatMoney(account.current_balance, account.code, 0) }}</span>
      </li>
      <li v-if="hiddenCount > 0">
        <button type="button" class="w-full cursor-pointer px-4 py-2 text-left text-sm font-medium text-primary-700 active:bg-neutral-100" @click="showAll = true">
          {{ t('nav.mobile.money_more', { n: hiddenCount }) }}
        </button>
      </li>
      <li v-for="register in registers" :key="`c${register.id}`" class="flex items-center gap-3 px-4 py-2">
        <span class="min-w-0 flex-1">
          <span class="block truncate text-neutral-800">{{ register.name }}</span>
          <span class="block text-xs text-neutral-500">{{ t('nav.accounting_cash') }}</span>
        </span>
        <span class="shrink-0 font-medium tabular-nums text-neutral-900">{{ formatMoney(register.balance, register.currency_code, 0) }}</span>
      </li>
    </ul>
  </div>
</template>

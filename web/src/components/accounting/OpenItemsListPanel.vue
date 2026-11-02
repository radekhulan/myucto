<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { accountingApi, type OpenItemsListReport } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ asOf: string }>()

const { t } = useI18n()
const toast = useToast()

const codes = ref('')
const report = ref<OpenItemsListReport | null>(null)
const loading = ref(false)
const exporting = ref(false)
const expanded = ref<number | null>(null)

function normalizedCodes(): string {
  return codes.value.split(/[\s,;]+/).filter(Boolean).join(',')
}

async function load() {
  if (!props.asOf) return
  loading.value = true
  expanded.value = null
  try {
    report.value = await accountingApi.getOpenItemsList({ as_of: props.asOf, codes: normalizedCodes() || undefined })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

async function exportFile(format: 'pdf' | 'xlsx') {
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/open-items-list/export', {
      as_of: props.asOf, codes: normalizedCodes() || undefined, format,
    })
    const url = URL.createObjectURL(r.data as unknown as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `otevrene-polozky-${props.asOf}.${format}`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

function openLink(accountId: number) {
  return { name: 'accounting-account-statement', params: { accountId }, query: { to: props.asOf, mode: 'open' } }
}

watch(() => props.asOf, load, { immediate: true })
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mt-4 space-y-3" data-test="open-items-list">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div>
        <h2 class="text-sm font-semibold text-neutral-700">{{ t('accounting.balance_inventory.open_items.title') }}</h2>
        <p class="text-xs text-neutral-500 mt-0.5">{{ t('accounting.balance_inventory.open_items.hint', { date: formatDate(asOf) }) }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <input v-model="codes" type="text" :placeholder="t('accounting.balance_inventory.open_items.codes_ph')"
          class="h-9 w-56 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="open-items-codes" @keyup.enter="load" />
        <button type="button" :disabled="loading" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="load">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
          {{ t('accounting.balance_inventory.open_items.show') }}
        </button>
        <button type="button" :disabled="!report || exporting" :class="[btnOutline('primary'), 'whitespace-nowrap']" @click="exportFile('pdf')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.balance_inventory.export_pdf') }}
        </button>
        <button type="button" :disabled="!report || exporting" :class="[btnOutline('primary'), 'whitespace-nowrap']" @click="exportFile('xlsx')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.balance_inventory.export_xlsx') }}
        </button>
      </div>
    </div>

    <div v-if="report && report.accounts.length === 0" class="text-sm text-neutral-500" data-test="open-items-empty">
      {{ t('accounting.balance_inventory.open_items.empty') }}
    </div>

    <div v-else-if="report" class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.balance_inventory.col_account') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.balance_inventory.col_name') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('accounting.balance_inventory.open_items.col_count') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.open.col_open_md') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.open.col_open_d') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.col_balance') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.balance_inventory.open_items.col_check') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <template v-for="acc in report.accounts" :key="acc.account.id">
            <tr class="hover:bg-neutral-50 cursor-pointer" @click="expanded = expanded === acc.account.id ? null : acc.account.id">
              <td class="px-3 py-2 whitespace-nowrap">
                <RouterLink :to="openLink(acc.account.id)" class="font-mono text-primary-600 hover:text-primary-700 hover:underline" @click.stop>
                  {{ acc.account.code }}
                </RouterLink>
              </td>
              <td class="px-3 py-2">{{ acc.account.name }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ acc.open_count }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(acc.open_md) }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(acc.open_d) }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap" :class="acc.balance_unusual ? 'text-danger-600 font-semibold' : ''">{{ formatMoney(acc.balance_signed) }}</td>
              <td class="px-3 py-2 text-xs whitespace-nowrap" :class="acc.matches ? 'text-success-700' : 'text-danger-700'">
                {{ acc.matches ? t('accounting.account_statement.open.check_ok') : t('accounting.account_statement.open.check_diff', { amount: formatMoney(acc.difference) }) }}
              </td>
            </tr>
            <tr v-if="expanded === acc.account.id">
              <td colspan="7" class="px-3 py-2 bg-neutral-50">
                <div v-if="acc.items.length === 0" class="text-xs text-neutral-500">{{ t('accounting.account_statement.open.empty') }}</div>
                <div v-for="it in acc.items" :key="it.line_id" class="flex flex-wrap gap-x-3 py-0.5 text-xs">
                  <span class="whitespace-nowrap">{{ formatDate(it.entry_date) }}</span>
                  <span class="font-mono">{{ it.document_no || `#${it.entry_id}` }}</span>
                  <span class="font-mono text-neutral-500">{{ it.account_code }}</span>
                  <span class="flex-1 min-w-40">{{ it.description || '' }}</span>
                  <span class="text-neutral-600">{{ it.partner || '' }}</span>
                  <span class="font-mono whitespace-nowrap">
                    {{ formatMoney(it.open_amount) }}
                    {{ it.side === 'debit' ? t('accounting.balance_inventory.side_debit') : t('accounting.balance_inventory.side_credit') }}
                  </span>
                </div>
                <div v-if="acc.truncated" class="pt-1 text-xs text-warning-700">{{ t('accounting.balance_inventory.open_items.truncated', { total: acc.total }) }}</div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>

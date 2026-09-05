<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useSupplierStore } from '@/stores/supplier'
import JournalTemplates from '@/pages/accounting/JournalTemplates.vue'
import ExpenseRules from '@/pages/accounting/ExpenseRules.vue'
import BankPostingRules from '@/pages/bank/BankPostingRules.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const supplierStore = useSupplierStore()
const accountingMode = computed(() => supplierStore.currentSupplier?.accounting_mode)
const journalEnabled = computed(() => accountingMode.value === 'double_entry')
type Tab = 'journal' | 'expense' | 'posting'

// Dostupné záložky podle režimu účtování. Všechny jsou per-firma a dávají smysl jen
// v podvojném účetnictví; katalog šablon bankovních pravidel pro právě zvolenou
// firmu má samostatnou stránku /admin/bank-rule-templates v Nástrojích.
const availableTabs = computed<Tab[]>(() => (journalEnabled.value ? ['journal', 'expense', 'posting'] : []))

function tabFromQuery(value: unknown): Tab {
  const v = String(value ?? '')
  if (v === 'expense' && journalEnabled.value) return 'expense'
  if (v === 'posting' && journalEnabled.value) return 'posting'
  return 'journal'
}

const tab = ref<Tab>(tabFromQuery(route.query.section))
const effectiveTab = computed<Tab>(() =>
  availableTabs.value.includes(tab.value) ? tab.value : (availableTabs.value[0] ?? 'journal'),
)

watch(() => route.query.section, value => { tab.value = tabFromQuery(value) })
watch(accountingMode, () => { tab.value = tabFromQuery(route.query.section) })

function switchTab(value: Tab) {
  if (effectiveTab.value === value) return
  router.replace({ query: { ...route.query, section: value === 'journal' ? undefined : value } })
}

const tabClass = (value: Tab) =>
  effectiveTab.value === value
    ? 'border-primary-600 text-primary-700 font-medium'
    : 'border-transparent text-neutral-600 hover:text-neutral-900'
</script>

<template>
  <div>
    <div v-if="availableTabs.length > 1" class="border-b border-neutral-200 mb-4 flex flex-wrap gap-1">
      <button v-if="journalEnabled" type="button"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 -mb-px transition whitespace-nowrap"
        :class="tabClass('journal')" @click="switchTab('journal')">
        {{ t('template_tools.journal') }}
      </button>
      <button v-if="journalEnabled" type="button"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 -mb-px transition whitespace-nowrap"
        :class="tabClass('expense')" @click="switchTab('expense')">
        {{ t('template_tools.expense') }}
      </button>
      <button v-if="journalEnabled" type="button"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 -mb-px transition whitespace-nowrap"
        :class="tabClass('posting')" @click="switchTab('posting')">
        {{ t('template_tools.posting') }}
      </button>
    </div>

    <ExpenseRules v-if="journalEnabled && effectiveTab === 'expense'" embedded />
    <BankPostingRules v-else-if="journalEnabled && effectiveTab === 'posting'" />
    <JournalTemplates v-else-if="journalEnabled" embedded />

    <!-- Bez podvojného účetnictví není co zobrazit — všechny tři záložky jsou
         šablony k účtování do deníku. Prázdná stránka by ale vypadala jako
         rozbitá aplikace, tak je tu aspoň důvod a cesta dál. -->
    <div v-else class="rounded-lg border border-neutral-200 bg-surface p-5 text-sm">
      <p class="font-medium text-neutral-900">{{ t('template_tools.requires_double_entry') }}</p>
      <p class="mt-1 text-neutral-600">{{ t('template_tools.requires_double_entry_hint') }}</p>
      <RouterLink to="/admin/settings?tab=accounting" class="mt-3 inline-block text-primary-700 underline hover:text-primary-800">
        {{ t('template_tools.open_accounting_settings') }}
      </RouterLink>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { computed, ref, watch, onMounted } from 'vue'
import { useIsPhone } from '@/composables/useIsPhone'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import StatementList from './StatementList.vue'
import BankAccounts from '@/pages/admin/BankAccounts.vue'
import BankAccountAnalytics from './BankAccountAnalytics.vue'
import PostingSuggestions from './PostingSuggestions.vue'
import UnpostedTransactions from './UnpostedTransactions.vue'
import { bankPostingApi } from '@/api/bankPosting'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const isAdmin = computed(() => auth.isCompanyAdminRole)
const canReadBankSettings = computed(() => auth.isDemo || auth.canRead('settings.bank_accounts'))
const canWriteBankSettings = computed(() => auth.isDemo || auth.canWrite('settings.bank_accounts'))
const isDoubleEntry = computed(() => !auth.isClientRole && auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_mode === 'double_entry')

// Sjednocená stránka „Bankovní účty" (Finance): výpisy + účetní automatika + admin záložky.
// Pravidla účtování (dřívější tab „rules") žijí pod Šablony (/templates?section=posting) —
// z tabu K zaúčtování je na ně jen odkaz, ať se neztratí flow „založ pravidlo za chodu".
type Tab = 'statements' | 'all_movements' | 'posting' | 'analytics' | 'accounts' | 'balances' | 'email'
// „Všechny pohyby" = samostatný top-level tab hned za Výpisy (ne pod K zaúčtování). Je to
// přehled výpisů, ne účetnictví, proto ho má každá firma; účetní sloupce si řeší sám (#136).
// „Kontace účtů" = metadata vlastních účtů pro analytiku 221.xxx (audit UI mezer 2026-07).
const ACCOUNTING_TABS: Tab[] = ['posting', 'analytics']
const ADMIN_TABS: Tab[] = ['email']
const visibleTabs = computed<Tab[]>(() => [
  'statements',
  'all_movements',
  ...(isDoubleEntry.value ? ACCOUNTING_TABS : []),
  ...(canReadBankSettings.value ? ['accounts'] as Tab[] : []),
  'balances',
  ...(isAdmin.value ? ADMIN_TABS : []),
])

function tabFromQuery(q: unknown): Tab {
  const v = String(q ?? '')
  return (visibleTabs.value as string[]).includes(v) && v !== 'statements' ? (v as Tab) : 'statements'
}
const tab = ref<Tab>(tabFromQuery(route.query.tab))
const postingView = ref<'unposted' | 'history'>('unposted')
watch(() => route.query.tab, (q) => { tab.value = tabFromQuery(q) })
// Role/režim se může doresolvit až po mountu (session check) — přehodnoť deep-link ?tab=.
watch([isAdmin, isDoubleEntry, canReadBankSettings], () => { tab.value = tabFromQuery(route.query.tab) })

function switchTab(v: Tab) {
  if (tab.value === v) return
  // Výpisy = default bez ?tab (jejich filtry si query řídí samy a tab by přepsaly).
  router.replace({ query: v === 'statements' ? {} : { tab: v } })
}

// Počet návrhů čekajících na zaúčtování — badge na tabu „K zaúčtování".
const pendingCount = ref(0)
async function loadPendingCount() {
  if (!isDoubleEntry.value) return
  try { pendingCount.value = await bankPostingApi.unpostedCount() } catch { /* badge best-effort */ }
}
onMounted(loadPendingCount)
watch(isDoubleEntry, (v) => { if (v) loadPendingCount() })

function tabLabel(v: Tab): string {
  return v === 'statements' ? t('bank.title')
    : v === 'all_movements' ? t('bank.posting.tab_all')
    : v === 'posting' ? t('bank.posting.tab_suggestions')
    : v === 'analytics' ? t('bank.analytics.tab')
    : v === 'accounts' ? t('bank_accounts.tab_accounts')
    : v === 'balances' ? t('bank_accounts.tab_balances')
    : t('bank_accounts.tab_email_notices')
}
useWindowRefresh(loadPendingCount, undefined, { partial: true })

// Na telefonu se sedm záložek nevejde: tři hlavní jdou do segmentového přepínače,
// ostatní do výběru „Více". Desktop (i úzký panel) drží původní pruh záložek.
const isPhone = useIsPhone()
const PHONE_PRIMARY_TABS: Tab[] = ['statements', 'all_movements', 'balances']
const phonePrimaryTabs = computed(() => visibleTabs.value.filter(v => PHONE_PRIMARY_TABS.includes(v)))
const phoneMoreTabs = computed(() => visibleTabs.value.filter(v => !PHONE_PRIMARY_TABS.includes(v)))
const phoneMoreValue = computed(() => phoneMoreTabs.value.includes(tab.value) ? tab.value : '')
function onPhoneMore(e: Event) {
  const v = (e.target as HTMLSelectElement).value as Tab
  if (v) switchTab(v)
}
function phoneMoreLabel(v: Tab): string {
  return v === 'posting' && pendingCount.value > 0 ? `${tabLabel(v)} (${pendingCount.value})` : tabLabel(v)
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('bank_accounts.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('bank_accounts.subtitle') }}</p>
    </div>

    <div v-if="visibleTabs.length > 1 && isPhone" class="mb-4 space-y-2" data-test="bank-phone-tabs">
      <div role="tablist" class="grid gap-1 rounded-lg bg-neutral-100 p-1"
        :style="{ gridTemplateColumns: `repeat(${phonePrimaryTabs.length}, minmax(0, 1fr))` }">
        <button v-for="tt in phonePrimaryTabs" :key="tt" type="button" role="tab"
          :aria-selected="tab === tt"
          :data-test="`bank-phone-tab-${tt}`"
          @click="switchTab(tt)"
          class="cursor-pointer min-h-10 rounded-md px-1.5 py-1 text-[13px] leading-tight text-center transition"
          :class="tab === tt ? 'bg-surface text-primary-700 font-medium shadow-sm' : 'text-neutral-600'">
          {{ tabLabel(tt) }}
        </button>
      </div>
      <label v-if="phoneMoreTabs.length" class="flex items-center gap-2 text-sm">
        <span class="text-neutral-500 whitespace-nowrap">{{ t('bank.phone_tabs_more') }}</span>
        <select :value="phoneMoreValue" data-test="bank-phone-more" @change="onPhoneMore"
          class="flex-1 min-w-0 h-10 rounded-md border bg-surface px-2 text-sm"
          :class="phoneMoreValue ? 'border-primary-500/60 text-primary-700 font-medium' : 'border-neutral-300 text-neutral-700'">
          <option value="" disabled>{{ t('bank.phone_tabs_more_placeholder') }}</option>
          <option v-for="tt in phoneMoreTabs" :key="tt" :value="tt">{{ phoneMoreLabel(tt) }}</option>
        </select>
      </label>
    </div>
    <div v-else-if="visibleTabs.length > 1" class="relative mb-4">
      <div class="relative border-b border-neutral-200 flex gap-1 overflow-x-auto">
        <button v-for="tt in visibleTabs" :key="tt"
          @click="switchTab(tt)"
          :data-active="tab === tt || undefined"
          class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap inline-flex items-center gap-1.5"
          :class="tab === tt
            ? 'border-primary-600 text-primary-700 font-medium'
            : 'border-transparent text-neutral-600 hover:text-neutral-900'">
          {{ tabLabel(tt) }}
          <span v-if="tt === 'posting' && pendingCount > 0"
            class="inline-flex items-center bg-warning-50 text-warning-600 rounded-full px-1.5 text-xs">{{ pendingCount }}</span>
        </button>
      </div>
    </div>

    <StatementList v-if="tab === 'statements'" embedded />
    <UnpostedTransactions v-else-if="tab === 'all_movements'" key="all-movements" scope="all"
      @counts-changed="loadPendingCount" />
    <div v-else-if="tab === 'posting'">
      <div class="flex items-center justify-between gap-2 mb-4 flex-wrap">
        <div class="flex gap-1 border-b border-neutral-200 overflow-x-auto flex-1">
        <button type="button" @click="postingView = 'unposted'"
          class="cursor-pointer px-3 py-2 text-sm border-b-2 transition whitespace-nowrap max-md:px-2 max-md:text-[13px]"
          :class="postingView === 'unposted' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'">
          {{ isPhone ? t('bank.posting.tab_unposted_short') : t('bank.posting.tab_unposted') }}
          <span v-if="pendingCount > 0" class="ml-1 text-xs text-neutral-500">({{ pendingCount }})</span>
        </button>
        <button type="button" @click="postingView = 'history'"
          class="cursor-pointer px-3 py-2 text-sm border-b-2 transition whitespace-nowrap max-md:px-2 max-md:text-[13px]"
          :class="postingView === 'history' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'">
          {{ isPhone ? t('bank.posting.tab_suggestions_history_short') : t('bank.posting.tab_suggestions_history') }}
        </button>
        </div>
        <RouterLink to="/templates?section=posting" class="text-sm text-primary-600 hover:underline whitespace-nowrap max-md:text-xs">
          {{ t('bank.posting.manage_rules_link') }}
        </RouterLink>
      </div>
      <UnpostedTransactions v-if="postingView === 'unposted'" key="unposted" scope="unposted"
        @counts-changed="loadPendingCount" />
      <PostingSuggestions v-else @counts-changed="loadPendingCount" />
    </div>
    <BankAccountAnalytics v-else-if="tab === 'analytics'" />
    <BankAccounts v-else embedded :can-manage-accounts="canWriteBankSettings" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useIsPhone } from '@/composables/useIsPhone'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import CreditCardSettings from './CreditCardSettings.vue'
import { importCreditCardStatement } from './creditCardImport'
import { creditCardsApi, type CreditCardAccount } from '@/api/creditCards'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
// Telefon: dlouhé úvodní vysvětlení importu je sbalené za tlačítkem.
const isPhone = useIsPhone()
const introOpen = ref(false)

type Tab = 'accounts' | 'settings'
const tabs: Tab[] = ['accounts', 'settings']
const tab = ref<Tab>(route.query.tab === 'settings' ? 'settings' : 'accounts')
watch(tab, v => {
  if (route.query.tab !== v) void router.replace({ query: { ...route.query, tab: v } })
})

const accounts = ref<CreditCardAccount[]>([])
const loading = ref(false)
const showArchived = ref(false)
const importing = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

async function load() {
  loading.value = true
  try {
    accounts.value = await creditCardsApi.list(showArchived.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.load_failed')))
  } finally {
    loading.value = false
  }
}
watch(showArchived, load)
onMounted(load)

async function onFile(ev: Event) {
  const input = ev.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  importing.value = true
  try {
    const { result, reposted } = await importCreditCardStatement(file, null, msg => window.confirm(msg))
    if (reposted !== null) toast.success(t('credit_cards.converted', { n: reposted }))
    if (result.target_supplier_id) {
      toast.info(t('credit_cards.imported_other_company', { company: result.target_supplier_name ?? '' }))
      return
    }
    toast.success(result.duplicate ? t('credit_cards.imported_duplicate') : t('credit_cards.imported', { n: result.transactions }))
    if (result.credit_card_account_id) {
      void router.push({ name: 'credit-card-detail', params: { id: result.credit_card_account_id } })
    } else {
      await load()
    }
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_cards.import_failed')))
  } finally {
    importing.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'import', label: importing.value ? t('credit_cards.importing') : t('credit_cards.import'), icon: 'upload',
    tier: 'primary', variant: 'primary', title: t('credit_cards.import_hint'),
    run: () => fileInput.value?.click(), loading: importing.value, disabled: importing.value,
    show: auth.canWrite('bank.import'),
  },
])

function detail(a: CreditCardAccount) {
  void router.push({ name: 'credit-card-detail', params: { id: a.id } })
}
const unverifiedCount = computed(() => accounts.value.filter(a => !a.is_verified && !a.archived).length)

function differenceClass(a: CreditCardAccount): string {
  if (a.balance_difference === null) return 'text-neutral-500'
  return Math.abs(a.balance_difference) < 0.005 ? 'text-success-700' : 'text-warning-700'
}
function differenceLabel(a: CreditCardAccount): string {
  if (a.balance_difference === null) return '-'
  return Math.abs(a.balance_difference) < 0.005
    ? t('credit_cards.difference_ok')
    : t('credit_cards.difference', { amount: formatMoney(a.balance_difference) })
}
</script>

<template>
  <div>
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('credit_cards.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('credit_cards.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
      <input ref="fileInput" type="file" accept="application/pdf,.pdf" class="hidden" data-testid="credit-card-file" @change="onFile" />
    </div>

    <div class="mb-4 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-neutral-700 max-md:px-3 max-md:py-1">
      <button v-if="isPhone" type="button" data-testid="credit-card-intro-toggle" :aria-expanded="introOpen"
        class="cursor-pointer w-full min-h-10 flex items-center justify-between gap-2 text-left font-medium text-primary-700"
        @click="introOpen = !introOpen">
        {{ t('credit_cards.intro_toggle') }}
        <svg class="w-4 h-4 shrink-0 transition-transform" :class="{ 'rotate-180': introOpen }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
      </button>
      <template v-if="!isPhone || introOpen">
        <p :class="{ 'pt-1': isPhone }">{{ t('credit_cards.supported_banks') }}</p>
        <p class="mt-1" :class="{ 'pb-2': isPhone }">{{ t('credit_cards.import_destination') }}</p>
      </template>
    </div>

    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in tabs" :key="tt" type="button" @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t(`credit_cards.tab_${tt}`) }}
      </button>
    </div>

    <CreditCardSettings v-if="tab === 'settings'" />

    <template v-else>
      <div v-if="unverifiedCount > 0" class="mb-3 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
        {{ t('credit_cards.unverified_banner', { n: unverifiedCount }) }}
      </div>
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <label class="inline-flex items-center gap-2 text-sm text-neutral-600 cursor-pointer">
          <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300" />
          {{ t('credit_cards.show_archived') }}
        </label>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="accounts.length === 0" boxed icon="coin"
        :title="t('credit_cards.empty')" :message="t('credit_cards.empty_hint')" />
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_label') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_analytic') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('credit_cards.col_limit') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('credit_cards.col_ledger') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('credit_cards.col_last_statement') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('credit_cards.col_status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in accounts" :key="a.id" class="hover:bg-neutral-50 cursor-pointer" @click="detail(a)">
                <td class="px-3 py-2">
                  <RouterLink :to="{ name: 'credit-card-detail', params: { id: a.id } }" class="font-medium text-primary-700 hover:underline" @click.stop>
                    {{ a.label }}
                  </RouterLink>
                  <div class="text-xs text-neutral-500">{{ t(`credit_cards.issuer.${a.issuer}`) }}</div>
                </td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ a.account_number }}{{ a.bank_code ? '/' + a.bank_code : '' }}</td>
                <td class="px-3 py-2 font-mono text-xs">{{ a.account_code ?? '-' }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">{{ a.credit_limit !== null ? formatMoney(a.credit_limit) : '-' }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <div>{{ a.ledger_balance !== null ? formatMoney(a.ledger_balance) : '-' }}</div>
                  <div class="text-xs" :class="differenceClass(a)">{{ differenceLabel(a) }}</div>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">
                  <template v-if="a.last_statement">
                    {{ formatDate(a.last_statement.statement_date) }} · {{ formatMoney(a.last_statement.curr_balance) }}
                  </template>
                  <template v-else>-</template>
                  <div v-if="a.unposted_count > 0" class="text-warning-700">{{ t('credit_cards.unposted', { n: a.unposted_count }) }}</div>
                </td>
                <td class="px-3 py-2 text-center">
                  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap"
                    :class="a.archived ? 'bg-warning-50 text-warning-700 ring-warning-600/20' : 'bg-success-50 text-success-700 ring-success-600/20'">
                    {{ a.archived ? t('credit_cards.status_archived') : t('credit_cards.status_active') }}
                  </span>
                  <span v-if="!a.is_verified" class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap bg-warning-50 text-warning-700 ring-warning-600/20">
                    {{ t('credit_cards.status_unverified') }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="md:hidden divide-y divide-neutral-100">
          <RouterLink v-for="a in accounts" :key="`m-${a.id}`" :to="{ name: 'credit-card-detail', params: { id: a.id } }"
            class="block px-4 py-3 hover:bg-neutral-50">
            <div class="flex items-center justify-between gap-2">
              <span class="font-medium text-neutral-800 truncate">{{ a.label }}</span>
              <span class="flex flex-wrap gap-1 justify-end">
                <span v-if="!a.is_verified" class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap bg-warning-50 text-warning-700 ring-warning-600/20">
                  {{ t('credit_cards.status_unverified') }}
                </span>
                <span class="text-sm font-medium whitespace-nowrap">{{ a.ledger_balance !== null ? formatMoney(a.ledger_balance) : '-' }}</span>
              </span>
            </div>
            <div class="mt-1 text-xs text-neutral-600 flex flex-wrap gap-x-3">
              <span>{{ t(`credit_cards.issuer.${a.issuer}`) }}</span>
              <span class="font-mono">{{ a.account_code ?? '-' }}</span>
              <span :class="differenceClass(a)">{{ differenceLabel(a) }}</span>
              <span v-if="a.unposted_count > 0" class="text-warning-700">{{ t('credit_cards.unposted', { n: a.unposted_count }) }}</span>
            </div>
          </RouterLink>
        </div>
      </div>
    </template>
  </div>
</template>

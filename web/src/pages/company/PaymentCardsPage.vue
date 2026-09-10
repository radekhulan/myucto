<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import UnmatchedCardPayments from './UnmatchedCardPayments.vue'
import { paymentCardsApi, type PaymentCard } from '@/api/paymentCards'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

type Tab = 'cards' | 'unmatched'
const tabs = computed<Tab[]>(() => (auth.canRead('bank') ? ['cards', 'unmatched'] : ['cards']))
const tab = ref<Tab>(route.query.tab === 'unmatched' ? 'unmatched' : 'cards')
watch(tab, v => {
  if (route.query.tab !== v) void router.replace({ query: { ...route.query, tab: v } })
})

const cards = ref<PaymentCard[]>([])
const loading = ref(false)
const showArchived = ref(false)

async function load() {
  loading.value = true
  try {
    cards.value = await paymentCardsApi.list(showArchived.value)
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_cards.load_failed')))
  } finally {
    loading.value = false
  }
}
watch(showArchived, load)
onMounted(load)

const actions = computed<ActionItem[]>(() => [
  {
    key: 'new', label: t('payment_cards.new'), icon: 'plus', tier: 'primary', variant: 'primary',
    to: { name: 'payment-card-new' }, show: auth.canWrite('settings.bank_accounts'),
  },
])

type Status = 'active' | 'inactive' | 'archived'
function statusOf(c: PaymentCard): Status {
  if (c.archived) return 'archived'
  return c.is_active ? 'active' : 'inactive'
}
const STATUS_CLASS: Record<Status, string> = {
  active: 'bg-success-50 text-success-700 ring-success-600/20',
  inactive: 'bg-neutral-100 text-neutral-600 ring-neutral-500/20',
  archived: 'bg-warning-50 text-warning-700 ring-warning-600/20',
}
function validity(c: PaymentCard): string {
  if (!c.valid_from && !c.valid_to) return t('payment_cards.validity_open')
  return [
    c.valid_from ? t('payment_cards.validity_from', { date: formatDate(c.valid_from) }) : '',
    c.valid_to ? t('payment_cards.validity_to', { date: formatDate(c.valid_to) }) : '',
  ].filter(Boolean).join(' ')
}
function detail(c: PaymentCard) {
  void router.push({ name: 'payment-card-detail', params: { id: c.id } })
}
</script>

<template>
  <div>
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('payment_cards.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('payment_cards.subtitle') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <div v-if="tabs.length > 1" class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in tabs" :key="tt" type="button" @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt === 'cards' ? t('payment_cards.tab_cards') : t('payment_cards.tab_unmatched') }}
      </button>
    </div>

    <UnmatchedCardPayments v-if="tab === 'unmatched'" />

    <template v-else>
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <label class="inline-flex items-center gap-2 text-sm text-neutral-600 cursor-pointer">
          <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300" />
          {{ t('payment_cards.show_archived') }}
        </label>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="cards.length === 0" boxed icon="coin"
        :title="t('payment_cards.empty')" :message="t('payment_cards.empty_hint')"
        :cta="auth.canWrite('settings.bank_accounts') ? t('payment_cards.new') : undefined" cta-icon="plus"
        to="/payment-cards/new" />
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.col_label') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.col_last4') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.col_holder') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.col_type') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.col_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.col_validity') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('payment_cards.col_status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in cards" :key="c.id" class="hover:bg-neutral-50 cursor-pointer" @click="detail(c)">
                <td class="px-3 py-2">
                  <RouterLink :to="{ name: 'payment-card-detail', params: { id: c.id } }" class="font-medium text-primary-700 hover:underline" @click.stop>
                    {{ c.label }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ t('payment_cards.masked', { last4: c.last4 }) }}</td>
                <td class="px-3 py-2">{{ c.holder || '—' }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ t(`payment_cards.type.${c.card_type}`) }}</td>
                <td class="px-3 py-2 text-xs text-neutral-600">{{ c.account_label || c.account_number || '—' }}</td>
                <td class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">{{ validity(c) }}</td>
                <td class="px-3 py-2 text-center">
                  <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap"
                    :class="STATUS_CLASS[statusOf(c)]">{{ t(`payment_cards.status_${statusOf(c)}`) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="md:hidden divide-y divide-neutral-100">
          <RouterLink v-for="c in cards" :key="`m-${c.id}`" :to="{ name: 'payment-card-detail', params: { id: c.id } }"
            class="block px-4 py-3 hover:bg-neutral-50">
            <div class="flex items-center justify-between gap-2">
              <span class="font-medium text-neutral-800 truncate">{{ c.label }}</span>
              <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap"
                :class="STATUS_CLASS[statusOf(c)]">{{ t(`payment_cards.status_${statusOf(c)}`) }}</span>
            </div>
            <div class="mt-1 text-xs text-neutral-600 flex flex-wrap gap-x-3">
              <span class="font-mono">{{ t('payment_cards.masked', { last4: c.last4 }) }}</span>
              <span v-if="c.holder">{{ c.holder }}</span>
              <span>{{ validity(c) }}</span>
            </div>
          </RouterLink>
        </div>
      </div>
    </template>
  </div>
</template>

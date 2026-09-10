<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import {
  paymentCardsApi, PAYMENT_CARD_NETWORKS, PAYMENT_CARD_TYPES,
  type PaymentCard, type PaymentCardHolders, type PaymentCardPayload,
} from '@/api/paymentCards'
import { settingsApi, type CurrencyAccount } from '@/api/settings'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { formatAccountNumber } from '@/utils/bankAccount'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const cardId = computed<number | null>(() => (route.params.id ? Number(route.params.id) : null))
const isNew = computed(() => cardId.value === null)
const canWrite = computed(() => auth.canWrite('settings.bank_accounts'))

const card = ref<PaymentCard | null>(null)
const holders = ref<PaymentCardHolders>({ employees: [], users: [] })
const accounts = ref<CurrencyAccount[]>([])
const loading = ref(true)
const saving = ref(false)
const busy = ref(false)
const errors = ref<Record<string, string>>({})

const form = reactive<PaymentCardPayload>({
  label: '', last4: '', card_type: 'debit', card_network: null, currency_id: null,
  holder_name: null, employee_id: null, user_id: null, valid_from: null, valid_to: null,
  is_active: true, note: null,
})
const readOnly = computed(() => !canWrite.value || !!card.value?.archived)

function fill(c: PaymentCard) {
  Object.assign(form, {
    label: c.label, last4: c.last4, card_type: c.card_type, card_network: c.card_network,
    currency_id: c.currency_id, holder_name: c.holder_name, employee_id: c.employee_id,
    user_id: c.user_id, valid_from: c.valid_from, valid_to: c.valid_to, is_active: c.is_active, note: c.note,
  })
  analyticChoice.value = c.clearing?.account_code ?? null
}

// ── mezičlen karty (účtování plateb kartou přes 378.x) ──────────────────────
const canPost = computed(() => auth.canWrite('bank.post'))
const analyticChoice = ref<string | null>(null)
const analyticBusy = ref(false)
const clearing = computed(() => card.value?.clearing ?? null)
const showClearing = computed(() => !!clearing.value && (clearing.value.enabled || !!clearing.value.account_code))

/** Odpověď uložení karty nese kartu bez mezičlenu — ten zůstává z detailu. */
function keepClearing(c: PaymentCard): PaymentCard {
  return { ...c, clearing: c.clearing ?? card.value?.clearing }
}

async function changeAnalytic(confirm = false) {
  if (!card.value) return
  analyticBusy.value = true
  try {
    const r = await paymentCardsApi.setAnalytic(card.value.id, analyticChoice.value, confirm)
    card.value = { ...r.card, clearing: r.clearing }
    analyticChoice.value = r.clearing.account_code
    toast.success(t('payment_cards.clearing_changed_toast'))
  } catch (e) {
    if (apiErrorCode(e) === 'confirm_required' && window.confirm(apiErrorMessage(e))) {
      analyticBusy.value = false
      await changeAnalytic(true)
      return
    }
    toast.error(apiErrorMessage(e, t('payment_cards.clearing_change_failed')))
  } finally {
    analyticBusy.value = false
  }
}

async function verify() {
  if (!card.value) return
  busy.value = true
  try {
    card.value = keepClearing(await paymentCardsApi.verify(card.value.id))
    toast.success(t('payment_cards.verified_toast'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_cards.save_failed')))
  } finally {
    busy.value = false
  }
}

function accountLabel(a: CurrencyAccount): string {
  const num = a.account_number ? formatAccountNumber(a.account_number, a.bank_code) : (a.iban ?? '')
  return `${num} — ${a.label || a.code}`
}

async function load() {
  loading.value = true
  try {
    const [h, currencies] = await Promise.all([
      paymentCardsApi.holders(),
      settingsApi.listCurrencies().catch(() => [] as CurrencyAccount[]),
    ])
    holders.value = h
    accounts.value = currencies.filter(a => a.account_number || a.iban)
    if (cardId.value !== null) {
      card.value = await paymentCardsApi.get(cardId.value)
      fill(card.value)
    } else if (typeof route.query.last4 === 'string' && /^\d{4}$/.test(route.query.last4)) {
      form.last4 = route.query.last4
    }
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_cards.load_failed')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function payload(): PaymentCardPayload {
  return {
    ...form,
    holder_name: form.holder_name?.trim() || null,
    note: form.note?.trim() || null,
    valid_from: form.valid_from || null,
    valid_to: form.valid_to || null,
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  const wasNew = isNew.value
  try {
    const r = wasNew
      ? await paymentCardsApi.create(payload())
      : await paymentCardsApi.update(cardId.value as number, payload())
    card.value = keepClearing(r.card)
    fill(card.value)
    toast.success(t('payment_cards.saved'))
    if (r.last4_truncated) toast.warning(t('payment_cards.last4_truncated'))
    if (wasNew) void router.replace({ name: 'payment-card-detail', params: { id: r.card.id } })
  } catch (e: any) {
    errors.value = e?.response?.data?.error?.errors ?? {}
    toast.error(apiErrorMessage(e, t('payment_cards.save_failed')))
  } finally {
    saving.value = false
  }
}

async function archive() {
  if (!card.value || !window.confirm(t('payment_cards.archive_confirm'))) return
  busy.value = true
  try {
    card.value = keepClearing(await paymentCardsApi.archive(card.value.id))
    fill(card.value)
    toast.success(t('payment_cards.archived_toast'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_cards.save_failed')))
  } finally {
    busy.value = false
  }
}

async function restore() {
  if (!card.value) return
  busy.value = true
  try {
    card.value = keepClearing(await paymentCardsApi.restore(card.value.id))
    fill(card.value)
    toast.success(t('payment_cards.restored_toast'))
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_cards.save_failed')))
  } finally {
    busy.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'save', label: t('payment_cards.save'), icon: 'check', tier: 'primary', variant: 'primary',
    run: save, loading: saving.value, disabled: saving.value || loading.value,
    show: canWrite.value && !card.value?.archived,
  },
  {
    key: 'restore', label: t('payment_cards.restore'), icon: 'uturn', tier: 'primary', variant: 'success',
    run: restore, loading: busy.value, show: canWrite.value && !!card.value?.archived,
  },
  {
    key: 'verify', label: t('payment_cards.verify'), icon: 'check', tier: 'secondary', variant: 'success',
    run: verify, disabled: busy.value,
    show: canWrite.value && !!card.value && !card.value.is_verified && !card.value.archived,
  },
  { key: 'back', label: t('payment_cards.back'), icon: 'table', tier: 'secondary', variant: 'neutral', to: { name: 'payment-cards' } },
  {
    key: 'archive', label: t('payment_cards.archive'), icon: 'archive', tier: 'overflow', variant: 'warning',
    run: archive, disabled: busy.value, show: canWrite.value && !!card.value && !card.value.archived,
  },
])

const INPUT = 'h-9 w-full px-3 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50'
</script>

<template>
  <div>
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold truncate">
          {{ isNew ? t('payment_cards.new_title') : (card?.label || t('payment_cards.edit_title')) }}
        </h1>
        <p v-if="card" class="text-sm text-neutral-500 mt-0.5 font-mono">{{ t('payment_cards.masked', { last4: card.last4 }) }}</p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <div v-if="card?.archived" class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('payment_cards.archived_notice') }}
    </div>
    <div v-else-if="card && !card.is_verified" class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('payment_cards.unverified_notice') }}
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <form v-else class="grid gap-4 lg:grid-cols-2" @submit.prevent="save">
      <fieldset :disabled="readOnly" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5 space-y-3">
        <legend class="sr-only">{{ t('payment_cards.section_card') }}</legend>
        <h2 class="text-sm font-semibold text-neutral-700">{{ t('payment_cards.section_card') }}</h2>
        <label class="block">
          <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_label') }}</span>
          <input v-model="form.label" type="text" maxlength="120" :class="INPUT" required />
          <span v-if="errors.label" class="block text-xs text-danger-600 mt-1">{{ errors.label }}</span>
        </label>
        <label class="block">
          <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_last4') }}</span>
          <input v-model="form.last4" type="text" inputmode="numeric" autocomplete="off" maxlength="23"
            :class="[INPUT, 'font-mono']" placeholder="1234" required />
          <span class="block text-xs text-neutral-500 mt-1">{{ t('payment_cards.field_last4_hint') }}</span>
          <span v-if="errors.last4" class="block text-xs text-danger-600 mt-1">{{ errors.last4 }}</span>
        </label>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_type') }}</span>
            <select v-model="form.card_type" :class="INPUT">
              <option v-for="ct in PAYMENT_CARD_TYPES" :key="ct" :value="ct">{{ t(`payment_cards.type.${ct}`) }}</option>
            </select>
          </label>
          <label class="block">
            <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_network') }}</span>
            <select v-model="form.card_network" :class="INPUT">
              <option :value="null">{{ t('payment_cards.field_none') }}</option>
              <option v-for="n in PAYMENT_CARD_NETWORKS" :key="n" :value="n">{{ t(`payment_cards.network.${n}`) }}</option>
            </select>
          </label>
        </div>
        <label class="block">
          <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_note') }}</span>
          <textarea v-model="form.note" rows="2" maxlength="500"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50"></textarea>
          <span v-if="errors.note" class="block text-xs text-danger-600 mt-1">{{ errors.note }}</span>
        </label>
      </fieldset>

      <div class="space-y-4">
        <fieldset :disabled="readOnly" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5 space-y-3">
          <legend class="sr-only">{{ t('payment_cards.section_holder') }}</legend>
          <h2 class="text-sm font-semibold text-neutral-700">{{ t('payment_cards.section_holder') }}</h2>
          <p class="text-xs text-neutral-500">{{ t('payment_cards.holder_hint') }}</p>
          <label class="block">
            <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_holder_name') }}</span>
            <input v-model="form.holder_name" type="text" maxlength="191" :class="INPUT" />
            <span v-if="errors.holder_name" class="block text-xs text-danger-600 mt-1">{{ errors.holder_name }}</span>
          </label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_employee') }}</span>
              <select v-model="form.employee_id" :class="INPUT">
                <option :value="null">{{ t('payment_cards.field_none') }}</option>
                <option v-for="e in holders.employees" :key="e.id" :value="e.id">{{ e.name }}</option>
              </select>
              <span v-if="errors.employee_id" class="block text-xs text-danger-600 mt-1">{{ errors.employee_id }}</span>
            </label>
            <label class="block">
              <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_user') }}</span>
              <select v-model="form.user_id" :class="INPUT">
                <option :value="null">{{ t('payment_cards.field_none') }}</option>
                <option v-for="u in holders.users" :key="u.id" :value="u.id">{{ u.name }}</option>
              </select>
              <span v-if="errors.user_id" class="block text-xs text-danger-600 mt-1">{{ errors.user_id }}</span>
            </label>
          </div>
        </fieldset>

        <fieldset :disabled="readOnly" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5 space-y-3">
          <legend class="sr-only">{{ t('payment_cards.section_account') }}</legend>
          <h2 class="text-sm font-semibold text-neutral-700">{{ t('payment_cards.section_account') }}</h2>
          <label class="block">
            <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_account') }}</span>
            <select v-model="form.currency_id" :class="INPUT">
              <option :value="null">{{ t('payment_cards.field_none') }}</option>
              <option v-for="a in accounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
            </select>
            <span v-if="errors.currency_id" class="block text-xs text-danger-600 mt-1">{{ errors.currency_id }}</span>
          </label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_valid_from') }}</span>
              <input v-model="form.valid_from" type="date" :class="INPUT" />
              <span v-if="errors.valid_from" class="block text-xs text-danger-600 mt-1">{{ errors.valid_from }}</span>
            </label>
            <label class="block">
              <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.field_valid_to') }}</span>
              <input v-model="form.valid_to" type="date" :class="INPUT" />
              <span v-if="errors.valid_to" class="block text-xs text-danger-600 mt-1">{{ errors.valid_to }}</span>
            </label>
          </div>
          <label class="inline-flex items-center gap-2 text-sm text-neutral-700 cursor-pointer">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
            {{ t('payment_cards.field_active') }}
          </label>
        </fieldset>
      </div>
    </form>

    <section v-if="!loading && showClearing && clearing" class="mt-4 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5 space-y-3"
      data-testid="card-clearing">
      <h2 class="text-sm font-semibold text-neutral-700">{{ t('payment_cards.section_clearing') }}</h2>
      <div class="grid gap-3 sm:grid-cols-3 text-sm">
        <div>
          <div class="text-xs text-neutral-500">{{ t('payment_cards.clearing_account') }}</div>
          <div class="font-mono">{{ clearing.account_code ?? t('payment_cards.clearing_none') }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('payment_cards.clearing_balance') }}</div>
          <div class="font-medium" :class="Math.abs(clearing.balance) >= 0.005 ? 'text-warning-700' : 'text-neutral-800'">
            {{ formatMoney(clearing.balance) }}
          </div>
          <div class="text-xs text-neutral-500">{{ t('payment_cards.clearing_balance_help') }}</div>
        </div>
        <div class="flex items-end">
          <RouterLink v-if="clearing.account_id" :to="{ name: 'accounting-account-statement', params: { accountId: clearing.account_id } }"
            :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" />
            </svg>
            {{ t('payment_cards.clearing_open_statement') }}
          </RouterLink>
        </div>
      </div>
      <div v-if="canPost && !card?.archived" class="flex flex-wrap items-end gap-2">
        <label class="block text-sm">
          <span class="text-xs font-medium text-neutral-600">{{ t('payment_cards.clearing_change_label') }}</span>
          <select v-model="analyticChoice" :class="INPUT" class="min-w-[16rem]">
            <option :value="null">{{ t('payment_cards.clearing_auto') }}</option>
            <option v-for="o in clearing.options" :key="o.id" :value="o.account_code">{{ o.account_code }} — {{ o.name }}</option>
          </select>
        </label>
        <button type="button" :class="btnOutline('warning')" class="whitespace-nowrap"
          :disabled="analyticBusy || analyticChoice === clearing.account_code" @click="changeAnalytic()">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
          </svg>
          {{ t('payment_cards.clearing_change') }}
        </button>
      </div>
      <p class="text-xs text-neutral-500">{{ t('payment_cards.clearing_change_help') }}</p>
    </section>
  </div>
</template>

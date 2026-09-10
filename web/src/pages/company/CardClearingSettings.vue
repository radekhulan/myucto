<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import {
  paymentCardsApi,
  type CardClearingAccountOption,
  type CardClearingSettings,
  type CardClearingSettingsResponse,
} from '@/api/paymentCards'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const data = ref<CardClearingSettingsResponse | null>(null)
const form = reactive<CardClearingSettings>({
  configured: false,
  enabled: false,
  effective_from: null,
  clearing_synthetic: '378',
  writeoff_account_id: null,
  holder_account_id: null,
  fx_loss_account_id: null,
  fx_gain_account_id: null,
  rounding_loss_account_id: null,
  rounding_gain_account_id: null,
  auto_create_cards: true,
  unmatched_alert_days: 30,
})

const canConfigure = computed(() => auth.canWrite('bank.post') && !!data.value?.double_entry)
const options = computed(() => data.value?.account_options ?? [])
const byPrefix = (prefix: string) => computed(() =>
  options.value.filter(a => a.account_code.startsWith(prefix) && !['378', '261', '395'].includes(a.account_code)))
const expenseAccounts = byPrefix('5')
const revenueAccounts = byPrefix('6')
const holderAccounts = byPrefix('335')
const syntheticBalance = computed(() =>
  data.value?.synthetic_options.find(o => o.account_code === data.value?.settings.clearing_synthetic)?.balance ?? 0)

function apply(r: CardClearingSettingsResponse) {
  data.value = r
  Object.assign(form, r.settings)
  if (!form.effective_from && r.defaults.effective_from) form.effective_from = r.defaults.effective_from
}

async function load() {
  loading.value = true
  try {
    apply(await paymentCardsApi.clearingSettings())
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_cards.clearing.load_failed')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function save(confirm = false) {
  saving.value = true
  try {
    apply(await paymentCardsApi.saveClearingSettings({ ...form, confirm }))
    toast.success(t('payment_cards.clearing.saved'))
  } catch (e) {
    if (apiErrorCode(e) === 'confirm_required' && window.confirm(apiErrorMessage(e))) {
      saving.value = false
      await save(true)
      return
    }
    toast.error(apiErrorMessage(e, t('payment_cards.clearing.save_failed')))
  } finally {
    saving.value = false
  }
}

function accountLabel(a: CardClearingAccountOption): string {
  return `${a.account_code} — ${a.name}`
}
function defaultLabel(code: string | undefined): string {
  return t('payment_cards.clearing.default_account', { code: code ?? '' })
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'save', label: t('payment_cards.clearing.save'), icon: 'check', tier: 'primary', variant: 'success',
    run: () => { void save() }, loading: saving.value, disabled: saving.value || loading.value,
    show: canConfigure.value,
  },
])

const SELECT = 'h-9 w-full px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50'
</script>

<template>
  <div>
    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="data">
      <div v-if="!data.double_entry" class="mb-4 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-700">
        {{ t('payment_cards.clearing.tax_evidence_notice') }}
      </div>
      <div v-if="data.unverified_cards > 0" class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
        {{ t('payment_cards.clearing.unverified_notice', { n: data.unverified_cards }) }}
      </div>

      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5">
        <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('payment_cards.clearing.title') }}</h2>
            <p class="mt-1 text-sm text-neutral-600 max-w-3xl">{{ t('payment_cards.clearing.description') }}</p>
          </div>
          <ActionBar :actions="actions" />
        </div>

        <form class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="save()">
          <div class="md:col-span-2 xl:col-span-3 flex flex-wrap items-end gap-4">
            <label class="inline-flex items-center gap-2 text-sm font-medium text-neutral-700 cursor-pointer">
              <input v-model="form.enabled" type="checkbox" class="rounded border-neutral-300" :disabled="!canConfigure" data-testid="clearing-enabled" />
              {{ t('payment_cards.clearing.enabled') }}
            </label>
            <label class="block text-sm font-medium text-neutral-700">
              {{ t('payment_cards.clearing.effective_from') }}
              <input v-model="form.effective_from" type="date" :class="SELECT" class="mt-1" :disabled="!canConfigure" />
            </label>
          </div>
          <p class="md:col-span-2 xl:col-span-3 -mt-2 text-xs text-neutral-500">
            {{ t('payment_cards.clearing.effective_from_help', { date: data.defaults.effective_from ? formatDate(data.defaults.effective_from) : '—' }) }}
          </p>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payment_cards.clearing.synthetic') }}
            <select v-model="form.clearing_synthetic" :class="SELECT" class="mt-1" :disabled="!canConfigure">
              <option v-for="o in data.synthetic_options" :key="o.account_code" :value="o.account_code" :disabled="!o.available">
                {{ t(`payment_cards.clearing.synthetic_${o.account_code}`) }}
              </option>
            </select>
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payment_cards.clearing.synthetic_help') }}</span>
            <span v-if="Math.abs(syntheticBalance) >= 0.005" class="mt-1 block text-xs text-warning-700">
              {{ t('payment_cards.clearing.synthetic_balance', { amount: formatMoney(syntheticBalance) }) }}
            </span>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payment_cards.clearing.writeoff_account') }}
            <select v-model="form.writeoff_account_id" :class="SELECT" class="mt-1" :disabled="!canConfigure">
              <option :value="null">{{ defaultLabel(data.defaults.writeoff_account_code) }}</option>
              <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
            </select>
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payment_cards.clearing.writeoff_help') }}</span>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payment_cards.clearing.holder_account') }}
            <select v-model="form.holder_account_id" :class="SELECT" class="mt-1" :disabled="!canConfigure">
              <option :value="null">{{ defaultLabel(data.defaults.holder_account_code) }}</option>
              <option v-for="a in holderAccounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
            </select>
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payment_cards.clearing.holder_help') }}</span>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payment_cards.clearing.fx_loss_account') }}
            <select v-model="form.fx_loss_account_id" :class="SELECT" class="mt-1" :disabled="!canConfigure">
              <option :value="null">{{ defaultLabel(data.defaults.fx_loss_account_code) }}</option>
              <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
            </select>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payment_cards.clearing.fx_gain_account') }}
            <select v-model="form.fx_gain_account_id" :class="SELECT" class="mt-1" :disabled="!canConfigure">
              <option :value="null">{{ defaultLabel(data.defaults.fx_gain_account_code) }}</option>
              <option v-for="a in revenueAccounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
            </select>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payment_cards.clearing.rounding_loss_account') }}
            <select v-model="form.rounding_loss_account_id" :class="SELECT" class="mt-1" :disabled="!canConfigure">
              <option :value="null">{{ defaultLabel(data.defaults.rounding_loss_account_code) }}</option>
              <option v-for="a in expenseAccounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
            </select>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payment_cards.clearing.rounding_gain_account') }}
            <select v-model="form.rounding_gain_account_id" :class="SELECT" class="mt-1" :disabled="!canConfigure">
              <option :value="null">{{ defaultLabel(data.defaults.rounding_gain_account_code) }}</option>
              <option v-for="a in revenueAccounts" :key="a.id" :value="a.id">{{ accountLabel(a) }}</option>
            </select>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payment_cards.clearing.alert_days') }}
            <input v-model.number="form.unmatched_alert_days" type="number" min="1" max="365" :class="SELECT" class="mt-1" :disabled="!canConfigure" />
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payment_cards.clearing.alert_days_help') }}</span>
          </label>

          <div class="md:col-span-2 xl:col-span-3">
            <label class="inline-flex items-center gap-2 text-sm text-neutral-700 cursor-pointer">
              <input v-model="form.auto_create_cards" type="checkbox" class="rounded border-neutral-300" :disabled="!canConfigure" />
              {{ t('payment_cards.clearing.auto_create_cards') }}
            </label>
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payment_cards.clearing.auto_create_cards_help') }}</span>
          </div>
        </form>
      </section>

      <section class="mt-4 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 md:p-5">
        <h3 class="text-sm font-semibold text-neutral-700 mb-2">{{ t('payment_cards.clearing.scheme_title') }}</h3>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.clearing.scheme_case') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('payment_cards.clearing.scheme_entry') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in ['payment', 'settlement', 'refund', 'writeoff', 'holder']" :key="row">
                <td class="px-3 py-2">{{ t(`payment_cards.clearing.scheme_${row}`) }}</td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ t(`payment_cards.clearing.scheme_${row}_entry`, { syn: form.clearing_synthetic }) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

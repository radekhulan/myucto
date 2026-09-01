<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  gopayApi,
  type GoPayAccountOption,
  type GoPayClearing,
  type GoPayClearingDetail,
  type GoPaySettings,
} from '@/api/gopay'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatDateTime, formatMoney } from '@/composables/useFormat'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()

const loading = ref(true)
const saving = ref(false)
const importing = ref(false)
const processingId = ref<number | null>(null)
const configured = ref(false)
const accountOptions = ref<GoPayAccountOption[]>([])
const clearings = ref<GoPayClearing[]>([])
const selected = ref<GoPayClearingDetail | null>(null)
const detailLoading = ref(false)
const file = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const form = reactive<GoPaySettings>({
  currency: 'CZK',
  gopay_account_id: null,
  receivable_account_id: null,
  fee_account_id: null,
  clearing_account_id: null,
  destination_bank_account_id: null,
  payout_account_number: '115-1391640287',
  payout_bank_code: '0100',
  payout_date_tolerance_days: 3,
})

const canConfigure = computed(() => auth.canWrite('bank.post'))
const canImport = computed(() => auth.canWrite('bank.import') && auth.canWrite('bank.post'))
const account221 = computed(() => accountOptions.value.filter(a => a.account_code.startsWith('221')))
const account311 = computed(() => accountOptions.value.filter(a => a.account_code.startsWith('311')))
const account261 = computed(() => accountOptions.value.filter(a => a.account_code.startsWith('261')))
const expenseAccounts = computed(() => accountOptions.value.filter(a => a.account_type === 'expense'))
const importDisabledReason = computed(() => {
  if (!configured.value) return t('gopay.import.configure_first')
  if (!canImport.value) return t('gopay.import.permission_missing')
  if (!file.value) return t('gopay.import.choose_file')
  return ''
})

function applySettings(settings: GoPaySettings) {
  Object.assign(form, settings)
}

function errorMessage(error: any): string {
  const code = String(error?.response?.data?.error?.code ?? '').replace(/^gopay\./, '')
  const key = `gopay.errors.${code}`
  return code && t(key) !== key
    ? t(key)
    : error?.response?.data?.error?.message || t('common.error')
}

async function load() {
  loading.value = true
  try {
    const [settings, items] = await Promise.all([gopayApi.settings(), gopayApi.list()])
    configured.value = settings.configured
    accountOptions.value = settings.account_options
    applySettings(settings.settings)
    clearings.value = items
    const requestedClearing = Number(route.query.clearing ?? 0)
    if (requestedClearing > 0 && items.some(item => item.id === requestedClearing)) {
      selected.value = await gopayApi.detail(requestedClearing)
    }
  } catch (error) {
    toast.error(errorMessage(error))
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  saving.value = true
  try {
    const result = await gopayApi.saveSettings({ ...form })
    configured.value = result.configured
    accountOptions.value = result.account_options
    applySettings(result.settings)
    toast.success(t('gopay.settings.saved'))
  } catch (error) {
    toast.error(errorMessage(error))
  } finally {
    saving.value = false
  }
}

function chooseFile(event: Event) {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function importXml() {
  if (!file.value || importDisabledReason.value) return
  importing.value = true
  try {
    const result = await gopayApi.importXml(file.value)
    selected.value = result.clearing
    file.value = null
    if (fileInput.value) fileInput.value.value = ''
    clearings.value = await gopayApi.list()
    toast.success(result.duplicate ? t('gopay.import.duplicate') : t('gopay.import.success'))
  } catch (error) {
    toast.error(errorMessage(error))
  } finally {
    importing.value = false
  }
}

async function showDetail(clearing: GoPayClearing) {
  if (selected.value?.id === clearing.id) {
    selected.value = null
    return
  }
  detailLoading.value = true
  try {
    selected.value = await gopayApi.detail(clearing.id)
  } catch (error) {
    toast.error(errorMessage(error))
  } finally {
    detailLoading.value = false
  }
}

async function process(clearing: GoPayClearing | GoPayClearingDetail) {
  processingId.value = clearing.id
  try {
    const detail = await gopayApi.process(clearing.id)
    selected.value = detail
    clearings.value = await gopayApi.list()
    toast.success(detail.issue_count === 0 ? t('gopay.process.success') : t('gopay.process.review'))
  } catch (error) {
    toast.error(errorMessage(error))
  } finally {
    processingId.value = null
  }
}

function statusClass(status: string): string {
  if (status === 'processed' || status === 'posted') return 'bg-success-50 text-success-700'
  if (status === 'needs_review') return 'bg-warning-50 text-warning-700'
  return 'bg-neutral-100 text-neutral-700'
}

function accountLabel(account: GoPayAccountOption): string {
  return `${account.account_code} - ${account.name}`
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-bold text-neutral-900">{{ t('gopay.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-600">{{ t('gopay.subtitle') }}</p>
    </header>

    <div v-if="loading" class="rounded-xl border border-neutral-200 bg-surface p-8 text-center text-neutral-500">
      {{ t('common.loading') }}
    </div>

    <template v-else>
      <section class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
        <div class="mb-4">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('gopay.settings.title') }}</h2>
          <p class="mt-1 text-sm text-neutral-600">{{ t('gopay.settings.description') }}</p>
        </div>

        <form class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" @submit.prevent="saveSettings">
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('gopay.settings.gopay_account') }}
            <select v-model="form.gopay_account_id" class="form-select mt-1 w-full" :disabled="!canConfigure" required>
              <option :value="null">{{ t('gopay.settings.select') }}</option>
              <option v-for="account in account221" :key="account.id" :value="account.id">{{ accountLabel(account) }}</option>
            </select>
            <span class="mt-1 block text-xs text-neutral-500">{{ t('gopay.settings.gopay_account_help') }}</span>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('gopay.settings.destination_account') }}
            <select v-model="form.destination_bank_account_id" class="form-select mt-1 w-full" :disabled="!canConfigure" required>
              <option :value="null">{{ t('gopay.settings.select') }}</option>
              <option v-for="account in account221" :key="account.id" :value="account.id">{{ accountLabel(account) }}</option>
            </select>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('gopay.settings.receivable_account') }}
            <select v-model="form.receivable_account_id" class="form-select mt-1 w-full" :disabled="!canConfigure" required>
              <option :value="null">{{ t('gopay.settings.select') }}</option>
              <option v-for="account in account311" :key="account.id" :value="account.id">{{ accountLabel(account) }}</option>
            </select>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('gopay.settings.fee_account') }}
            <select v-model="form.fee_account_id" class="form-select mt-1 w-full" :disabled="!canConfigure" required>
              <option :value="null">{{ t('gopay.settings.select') }}</option>
              <option v-for="account in expenseAccounts" :key="account.id" :value="account.id">{{ accountLabel(account) }}</option>
            </select>
          </label>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('gopay.settings.clearing_account') }}
            <select v-model="form.clearing_account_id" class="form-select mt-1 w-full" :disabled="!canConfigure" required>
              <option :value="null">{{ t('gopay.settings.select') }}</option>
              <option v-for="account in account261" :key="account.id" :value="account.id">{{ accountLabel(account) }}</option>
            </select>
          </label>

          <div class="grid grid-cols-[minmax(0,1fr)_5rem] gap-3">
            <label class="block text-sm font-medium text-neutral-700">
              {{ t('gopay.settings.payout_account') }}
              <input v-model.trim="form.payout_account_number" class="form-input mt-1 w-full" :disabled="!canConfigure" required>
            </label>
            <label class="block text-sm font-medium text-neutral-700">
              {{ t('gopay.settings.bank_code') }}
              <input v-model.trim="form.payout_bank_code" class="form-input mt-1 w-full" inputmode="numeric" maxlength="4" :disabled="!canConfigure" required>
            </label>
          </div>

          <label class="block text-sm font-medium text-neutral-700">
            {{ t('gopay.settings.date_tolerance') }}
            <input v-model.number="form.payout_date_tolerance_days" class="form-input mt-1 w-full" type="number" min="0" max="14" :disabled="!canConfigure" required>
          </label>

          <div v-if="canConfigure" class="flex items-end md:col-span-2 xl:col-span-3">
            <button type="submit" :class="btnFilled('success')" :disabled="saving">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check" /></svg>
              {{ saving ? t('gopay.settings.saving') : t('gopay.settings.save') }}
            </button>
          </div>
        </form>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('gopay.import.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-600">{{ t('gopay.import.description') }}</p>
        <div class="mt-4 flex flex-wrap items-end gap-3">
          <label class="min-w-[16rem] flex-1 text-sm font-medium text-neutral-700">
            {{ t('gopay.import.file') }}
            <input ref="fileInput" class="form-input mt-1 block w-full" type="file" accept=".xml,application/xml,text/xml" :disabled="importing || !canImport" @change="chooseFile">
          </label>
          <button type="button" :class="btnFilled('primary')" :disabled="importing || !!importDisabledReason" :title="importDisabledReason || undefined" @click="importXml">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.upload" /></svg>
            {{ importing ? t('gopay.import.importing') : t('gopay.import.button') }}
          </button>
        </div>
        <p v-if="importDisabledReason" class="mt-2 text-xs text-warning-700">{{ importDisabledReason }}</p>
      </section>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div class="border-b border-neutral-200 px-5 py-4">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('gopay.clearings.title') }}</h2>
        </div>

        <EmptyState v-if="clearings.length === 0" boxed accent="neutral" icon="inbox" :title="t('gopay.clearings.empty')" :description="t('gopay.clearings.empty_description')" />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs font-semibold uppercase tracking-wide text-neutral-600">
              <tr>
                <th class="px-4 py-3">{{ t('gopay.col.date') }}</th>
                <th class="px-4 py-3">{{ t('gopay.col.clearing_id') }}</th>
                <th class="px-4 py-3">{{ t('gopay.col.period') }}</th>
                <th class="px-4 py-3 text-right">{{ t('gopay.col.gross') }}</th>
                <th class="px-4 py-3 text-right">{{ t('gopay.col.fees') }}</th>
                <th class="px-4 py-3 text-right">{{ t('gopay.col.sent') }}</th>
                <th class="px-4 py-3">{{ t('gopay.col.posted') }}</th>
                <th class="px-4 py-3">{{ t('gopay.col.status') }}</th>
                <th class="px-4 py-3 text-right">{{ t('common.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="clearing in clearings" :key="clearing.id" class="hover:bg-neutral-50/70">
                <td class="whitespace-nowrap px-4 py-3">{{ formatDate(clearing.performed_on) }}</td>
                <td class="px-4 py-3 font-medium text-neutral-900">{{ clearing.clearing_id }}</td>
                <td class="whitespace-nowrap px-4 py-3">{{ formatDate(clearing.cleared_from) }} - {{ formatDate(clearing.cleared_to) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(clearing.amount_gross, clearing.currency) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(clearing.amount_fee + clearing.amount_storno_fee, clearing.currency) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums">{{ formatMoney(clearing.amount_sent, clearing.currency) }}</td>
                <td class="whitespace-nowrap px-4 py-3">{{ clearing.posted_count }} / {{ clearing.movement_count }}</td>
                <td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(clearing.status)">{{ t(`gopay.status.${clearing.status}`) }}</span></td>
                <td class="px-4 py-3">
                  <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" :class="btnOutlineSm('primary')" @click="showDetail(clearing)">
                      <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.eye" /></svg>
                      {{ selected?.id === clearing.id ? t('gopay.close') : t('common.detail') }}
                    </button>
                    <a :href="gopayApi.downloadUrl(clearing.id)" :class="btnOutlineSm('neutral')">
                      <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.download" /></svg>
                      XML
                    </a>
                    <button v-if="canConfigure" type="button" :class="btnOutlineSm('warning')" :disabled="processingId === clearing.id" @click="process(clearing)">
                      <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.cycle" /></svg>
                      {{ t('gopay.process.button') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="detailLoading" class="rounded-xl border border-neutral-200 bg-surface p-6 text-center text-neutral-500">
        {{ t('common.loading') }}
      </section>

      <section v-else-if="selected" class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 px-5 py-4">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('gopay.detail.title', { id: selected.clearing_id }) }}</h2>
            <p class="mt-1 text-sm text-neutral-600">{{ t('gopay.detail.imported', { date: formatDateTime(selected.imported_at), file: selected.file_name }) }}</p>
          </div>
          <button type="button" :class="btnOutline('neutral')" @click="selected = null">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.x" /></svg>
            {{ t('gopay.close') }}
          </button>
        </div>

        <div v-if="selected.payout_issue_message" class="m-5 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
          {{ selected.payout_issue_message }}
        </div>
        <div v-else-if="selected.bank_transaction_id" class="m-5 rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-800">
          {{ t('gopay.detail.bank_matched', { date: formatDate(selected.bank_posted_on), document: selected.bank_journal_document_no || '' }) }}
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs font-semibold uppercase tracking-wide text-neutral-600">
              <tr>
                <th class="px-4 py-3">{{ t('gopay.movement.date') }}</th>
                <th class="px-4 py-3">{{ t('gopay.movement.type') }}</th>
                <th class="px-4 py-3">{{ t('gopay.movement.order') }}</th>
                <th class="px-4 py-3">{{ t('gopay.movement.payment_id') }}</th>
                <th class="px-4 py-3 text-right">{{ t('gopay.movement.amount') }}</th>
                <th class="px-4 py-3">{{ t('gopay.movement.document') }}</th>
                <th class="px-4 py-3">{{ t('gopay.movement.posting') }}</th>
                <th class="px-4 py-3">{{ t('gopay.movement.status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="movement in selected.movements" :key="movement.id">
                <td class="whitespace-nowrap px-4 py-3">{{ formatDate(movement.performed_on) }}</td>
                <td class="px-4 py-3">{{ t(`gopay.movement_type.${movement.movement_type}`) }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ movement.order_id || '-' }}</td>
                <td class="px-4 py-3 font-mono text-xs">{{ movement.payment_session_id || '-' }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ formatMoney(movement.amount, selected.currency) }}</td>
                <td class="px-4 py-3">
                  <RouterLink v-if="movement.invoice_id" class="text-primary-700 hover:underline" :to="`/invoices/${movement.invoice_id}`">{{ movement.invoice_number }}</RouterLink>
                  <RouterLink v-else-if="movement.credit_note_id" class="text-primary-700 hover:underline" :to="`/invoices/${movement.credit_note_id}`">{{ movement.credit_note_number }}</RouterLink>
                  <span v-else>-</span>
                </td>
                <td class="px-4 py-3">{{ movement.journal_document_no || '-' }}</td>
                <td class="px-4 py-3">
                  <span class="rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(movement.status)">{{ t(`gopay.movement_status.${movement.status}`) }}</span>
                  <p v-if="movement.issue_message" class="mt-1 max-w-sm text-xs text-warning-700">{{ movement.issue_message }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
  </div>
</template>

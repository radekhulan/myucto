<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { purchaseInvoiceSubmissionsApi, type EmailIntakeAccount, type EmailIntakeStatus } from '@/api/purchaseInvoiceSubmissions'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { formatDateTime } from '@/composables/useFormat'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const SETUP_PATH = '/bank?tab=email'
const INBOX_PATH = '/purchase-invoices/incoming?source=email'

const { t, tm, rt } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

const status = ref<EmailIntakeStatus | null>(null)
const loading = ref(true)
const error = ref('')
let request = 0

const canConfigure = computed(() => auth.isCompanyAdminRole && auth.canWrite('bank.import'))
const configured = computed(() => (status.value?.accounts.length ?? 0) > 0)
const lastScanAt = computed(() => status.value?.accounts
  .map(a => a.last_scan_at)
  .filter((at): at is string => !!at)
  .sort()
  .at(-1) ?? null)
const lastError = computed(() => status.value?.accounts
  .filter(a => a.last_scan_status === 'error' && a.last_scan_message)
  .sort((a, b) => (b.last_scan_at ?? '').localeCompare(a.last_scan_at ?? ''))[0] ?? null)
const anyEnabled = computed(() => status.value?.accounts.some(a => a.enabled) ?? false)
const stateLabel = computed(() => status.value?.active
  ? t('purchase_email.state_active')
  : anyEnabled.value ? t('purchase_email.state_invoices_off') : t('purchase_email.state_disabled'))

function list(key: string): string[] {
  return (tm(key) as unknown[]).map(item => rt(item as Parameters<typeof rt>[0]))
}

function accountBadge(account: EmailIntakeAccount): { label: string; cls: string } {
  if (!account.enabled) return { label: t('purchase_email.account_disabled'), cls: 'bg-neutral-100 text-neutral-600' }
  if (account.last_scan_status === 'error') return { label: t('purchase_email.account_error'), cls: 'bg-danger-50 text-danger-600' }
  if (!account.ingest_pdf_invoices) return { label: t('purchase_email.account_invoices_off'), cls: 'bg-warning-50 text-warning-600' }
  return { label: t('purchase_email.account_ok'), cls: 'bg-success-50 text-success-600' }
}

async function load() {
  const current = ++request
  loading.value = true
  error.value = ''
  try {
    const data = await purchaseInvoiceSubmissionsApi.emailStatus()
    if (current === request) status.value = data
  } catch (e) {
    if (current === request) error.value = apiErrorMessage(e)
  } finally {
    if (current === request) loading.value = false
  }
}

onMounted(load)
watch(() => supplierStore.currentSupplierId, () => { status.value = null; void load() })
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold">{{ t('purchase_email.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1">{{ t('purchase_email.subtitle') }}</p>
      </div>
      <RouterLink v-if="canConfigure" :to="SETUP_PATH" :class="btnFilled('primary')" class="whitespace-nowrap" data-testid="email-setup">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" /></svg>
        {{ t('purchase_email.setup_action') }}
      </RouterLink>
    </header>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4" data-testid="email-status">
      <h2 class="font-semibold text-neutral-800">{{ t('purchase_email.status_title') }}</h2>

      <div v-if="loading && !status" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="error && !status" class="flex flex-wrap items-center gap-3 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-600">
        <span>{{ t('purchase_email.status_failed') }} {{ error }}</span>
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="load">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('purchase_email.retry') }}
        </button>
      </div>

      <template v-else-if="status">
        <div v-if="!configured" class="rounded-md bg-neutral-50 border border-neutral-200 p-4 space-y-2" data-testid="email-empty">
          <p class="font-medium text-neutral-800">{{ t('purchase_email.empty_title') }}</p>
          <p class="text-sm text-neutral-600">{{ canConfigure ? t('purchase_email.empty_admin') : t('purchase_email.empty_other') }}</p>
        </div>

        <template v-else>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 min-w-0">
              <p class="text-xs text-neutral-500">{{ t('purchase_email.state_label') }}</p>
              <p class="mt-1 font-semibold" :class="status.active ? 'text-success-600' : 'text-warning-600'" data-testid="email-state">{{ stateLabel }}</p>
            </div>
            <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 min-w-0">
              <p class="text-xs text-neutral-500">{{ t('purchase_email.last_scan_label') }}</p>
              <p class="mt-1 font-semibold text-neutral-800">{{ lastScanAt ? formatDateTime(lastScanAt) : t('purchase_email.last_scan_never') }}</p>
            </div>
            <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 min-w-0">
              <p class="text-xs text-neutral-500">{{ t('purchase_email.received_label', { days: status.days }) }}</p>
              <p class="mt-1 text-xl font-semibold text-neutral-800" data-testid="email-received">{{ status.received }}</p>
            </div>
            <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 min-w-0">
              <p class="text-xs text-neutral-500">{{ t('purchase_email.waiting_label') }}</p>
              <p class="mt-1 text-xl font-semibold" :class="status.waiting > 0 ? 'text-warning-600' : 'text-neutral-800'" data-testid="email-waiting">{{ status.waiting }}</p>
            </div>
          </div>

          <p v-if="lastError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-600 break-words" data-testid="email-last-error">
            {{ t('purchase_email.last_error', { message: lastError.last_scan_message }) }}
          </p>
          <p v-if="anyEnabled && !status.active" class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-700">
            {{ t('purchase_email.invoices_off_hint') }}
          </p>

          <div v-if="status.accounts.length > 1" class="space-y-2">
            <p class="text-sm font-medium text-neutral-700">{{ t('purchase_email.accounts_title') }}</p>
            <ul class="divide-y divide-neutral-100 border border-neutral-200 rounded-md">
              <li v-for="account in status.accounts" :key="account.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                <span class="font-medium text-neutral-800 truncate min-w-0">{{ account.name }}</span>
                <span class="flex flex-wrap items-center gap-2 text-xs text-neutral-500">
                  <span>{{ account.last_scan_at ? formatDateTime(account.last_scan_at) : t('purchase_email.last_scan_never') }}</span>
                  <span class="px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="accountBadge(account).cls">{{ accountBadge(account).label }}</span>
                </span>
              </li>
            </ul>
          </div>
        </template>

        <div class="flex flex-wrap items-center gap-2">
          <RouterLink v-if="configured || status.received > 0 || status.waiting > 0" :to="INBOX_PATH" :class="btnOutline('primary')" class="whitespace-nowrap" data-testid="email-open-inbox">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.inbox" /></svg>
            {{ t('purchase_email.open_inbox') }}
          </RouterLink>
          <RouterLink v-if="canConfigure" :to="SETUP_PATH" :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" /></svg>
            {{ t('purchase_email.setup_action') }}
          </RouterLink>
          <span v-else class="text-xs text-neutral-500" data-testid="email-no-setup">{{ t('purchase_email.no_setup_permission') }}</span>
        </div>
      </template>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="font-semibold text-neutral-800">{{ t('purchase_email.about_title') }}</h2>
      <p v-for="(line, i) in list('purchase_email.about')" :key="i" class="text-sm text-neutral-700">{{ line }}</p>
    </section>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="font-semibold text-neutral-800">{{ t('purchase_email.steps_title') }}</h2>
      <ol class="list-decimal pl-5 space-y-2 text-sm text-neutral-700">
        <li v-for="(line, i) in list('purchase_email.steps')" :key="i">{{ line }}</li>
      </ol>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
        <h2 class="font-semibold text-neutral-800">{{ t('purchase_email.formats_title') }}</h2>
        <ul class="list-disc pl-5 space-y-1.5 text-sm text-neutral-700">
          <li v-for="(line, i) in list('purchase_email.formats')" :key="i">{{ line }}</li>
        </ul>
        <h3 class="font-medium text-neutral-800 pt-1">{{ t('purchase_email.formats_not_title') }}</h3>
        <ul class="list-disc pl-5 space-y-1.5 text-sm text-neutral-700">
          <li v-for="(line, i) in list('purchase_email.formats_not')" :key="i">{{ line }}</li>
        </ul>
      </section>
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
        <h2 class="font-semibold text-neutral-800">{{ t('purchase_email.when_title') }}</h2>
        <ul class="list-disc pl-5 space-y-1.5 text-sm text-neutral-700">
          <li v-for="(line, i) in list('purchase_email.when')" :key="i">{{ line }}</li>
        </ul>
        <h3 class="font-medium text-neutral-800 pt-1">{{ t('purchase_email.result_title') }}</h3>
        <p class="text-sm text-neutral-700">{{ t('purchase_email.result') }}</p>
      </section>
    </div>

    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-3">
      <h2 class="font-semibold text-neutral-800">{{ t('purchase_email.troubles_title') }}</h2>
      <ul class="list-disc pl-5 space-y-1.5 text-sm text-neutral-700">
        <li v-for="(line, i) in list('purchase_email.troubles')" :key="i">{{ line }}</li>
      </ul>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, onActivated, onMounted, ref, useId, watch } from 'vue'
import { RouterLink, onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { isAxiosError } from 'axios'
import { salesQuotesApi, type QuotePayload, type QuoteStatus, type SalesQuote } from '@/api/salesQuotes'
import { clientsApi, type Client } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import { codebooksApi, type Currency, type VatRate } from '@/api/codebooks'
import { stockApi, type Warehouse } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { addDaysIso, appIsoDate } from '@/utils/date'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useWindowTitle } from '@/composables/useWindowTitle'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import DateInput from '@/components/ui/DateInput.vue'
import Modal from '@/components/ui/Modal.vue'
import PdfFrame from '@/components/documents/PdfFrame.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import QuoteSections from './QuoteSections.vue'
import QuoteBilling from './QuoteBilling.vue'
import QuoteFiles from './QuoteFiles.vue'
import QuoteHistory from './QuoteHistory.vue'
import { quoteStatusBadge, sectionTotals } from './quotePresentation'

type StateAction = Extract<QuoteStatus, 'accepted' | 'declined' | 'withdrawn'>
type Dialog = 'send' | 'pdf' | StateAction | null

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const suppliers = useSupplierStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const formId = useId()
const editor = ref<HTMLFormElement | null>(null)
const quote = ref<SalesQuote | null>(null)
const form = ref<QuotePayload>(initialForm())
const signature = ref('')
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const modalError = ref('')
const dialog = ref<Dialog>(null)
const contacts = ref<Client | null>(null)
const clients = ref<Client[]>([])
const clientLoading = ref(false)
const projects = ref<Project[]>([])
const currencies = ref<Currency[]>([])
const vatRates = ref<VatRate[]>([])
const warehouses = ref<Warehouse[]>([])
const chosenSections = ref<string[]>([])
const stateNote = ref('')
const email = ref({ to: '', subject: '', message: '' })
let emailRequest: { signature: string; key: string } | null = null
let loadRequest = 0
let searchRequest = 0
let projectRequest = 0
let referencesReady = false

const id = computed(() => Number(route.params.id) || 0)
const versionId = computed(() => Number(route.query.version_id) || undefined)
const historical = computed(() => versionId.value !== undefined)
const canWrite = computed(() => auth.canWrite('sales_quotes.write'))
const canSend = computed(() => auth.canWrite('sales_quotes.send'))
const editable = computed(() => canWrite.value && !historical.value && (!quote.value || quote.value.status === 'draft') && !quote.value?.send_pending)
const dirty = computed(() => editable.value && signature.value !== '' && signature.value !== JSON.stringify(payload()))
const stockEnabled = computed(() => auth.hasCommercialFeatures && suppliers.currentSupplier?.stock_enabled === true)
const stockReadable = computed(() => stockEnabled.value && auth.canRead('stock'))
const isVatPayer = computed(() => suppliers.currentSupplier?.is_vat_payer === true)
const currency = computed(() => currencies.value.find(entry => entry.id === form.value.currency_id)?.code || quote.value?.currency || 'CZK')
const selectedClient = computed(() => form.value.client_id === null ? null : {
  value: form.value.client_id,
  label: contacts.value?.id === form.value.client_id
    ? clientLabel(contacts.value)
    : clients.value.find(client => client.id === form.value.client_id)?.company_name
      || (quote.value?.client_id === form.value.client_id ? quote.value.client_name : '') || `#${form.value.client_id}`,
})
const projectOptions = computed(() => projects.value.filter(project => !project.archived_at || project.id === form.value.project_id).map(project => ({
  value: project.id,
  label: project.name,
  secondary: project.project_number ?? undefined,
})))
const fileImages = computed(() => {
  const current = quote.value
  if (!current) return []
  return current.files.filter(file => file.mode === 'inline').map(file => ({
    id: file.id, name: file.name, url: salesQuotesApi.fileUrl(current.id, file.id, current.version_id),
  }))
})
const pdfUrl = computed(() => quote.value ? salesQuotesApi.pdfUrl(quote.value.id, quote.value.version_id) : '')
const pdfDownload = computed(() => quote.value ? salesQuotesApi.pdfUrl(quote.value.id, quote.value.version_id, true) : '')
const recipients = computed(() => {
  const options = (contacts.value?.email_contacts ?? []).filter(contact => contact.is_active && contact.email)
  const addresses = new Map(options.map(contact => [contact.email, contact.contact_name || contact.label || contact.email]))
  if (contacts.value?.main_email) addresses.set(contacts.value.main_email, addresses.get(contacts.value.main_email) || contacts.value.main_email)
  return [...addresses].map(([address, name]) => ({ address, name }))
})

useWindowTitle(() => quote.value?.number || t('sales_quotes.new'), () => quote.value?.title ?? null)

const actions = computed<ActionItem[]>(() => {
  const current = quote.value
  const state = current?.status
  const disabled = busy.value || loading.value || current?.send_pending === true
  const sendable = !!current && (state === 'draft' || state === 'sent' || state === 'accepted')
  const currentVersion = !!current && !historical.value
  return [
    { key: 'save', label: t('sales_quotes.save'), icon: 'check', tier: 'primary', variant: 'primary',
      show: editable.value, disabled: disabled || (current !== null && !dirty.value), run: () => editor.value?.requestSubmit() },
    { key: 'send', label: t(state === 'sent' || state === 'accepted' ? 'sales_quotes.send_again' : 'sales_quotes.send'), icon: 'send',
      tier: state === 'draft' ? 'secondary' : 'overflow', variant: 'primary', show: canSend.value && sendable && currentVersion,
      disabled: disabled || dirty.value, run: openSend },
    { key: 'accept', label: t('sales_quotes.accept'), icon: 'checkCircle', tier: 'primary', variant: 'success',
      show: canWrite.value && state === 'sent' && currentVersion, disabled, run: () => openState('accepted') },
    { key: 'pdf', label: t('sales_quotes.pdf_preview'), icon: 'eye', tier: 'secondary', variant: 'neutral',
      show: !!current, disabled: busy.value || dirty.value, run: () => { dialog.value = 'pdf' } },
    { key: 'revise', label: t('sales_quotes.revise'), icon: 'copy', tier: 'secondary', variant: 'primary',
      show: canWrite.value && !!state && ['sent', 'declined', 'expired', 'withdrawn'].includes(state) && currentVersion, disabled, run: revise },
    { key: 'decline', label: t('sales_quotes.decline'), icon: 'x', tier: 'overflow', variant: 'danger',
      show: canWrite.value && (state === 'sent' || state === 'expired') && currentVersion, disabled, run: () => openState('declined') },
    { key: 'withdraw', label: t('sales_quotes.withdraw'), icon: 'archive', tier: 'overflow', variant: 'warning',
      show: canWrite.value && (state === 'draft' || state === 'sent' || state === 'expired') && currentVersion,
      disabled: disabled || dirty.value, run: () => openState('withdrawn') },
  ]
})

function clientLabel(client: Client): string {
  return client.company_name || [client.first_name, client.last_name].filter(Boolean).join(' ') || `#${client.id}`
}

function initialForm(): QuotePayload {
  return {
    title: '', client_id: null, project_id: null, currency_id: null, language: 'cs', prices_include_vat: false,
    valid_until: addDaysIso(appIsoDate(), 30), sections: [],
  }
}

function payload(): QuotePayload {
  const source = form.value
  return {
    title: source.title.trim(), client_id: source.client_id, project_id: source.project_id, currency_id: source.currency_id,
    language: source.language, prices_include_vat: source.prices_include_vat, valid_until: source.valid_until, row_version: quote.value?.row_version,
    sections: source.sections.map(section => ({
      id: section.id, title: section.title.trim(), kind: section.kind, markdown: section.markdown,
      items: section.items.map(item => ({
        id: item.id, kind: item.kind, description: item.description.trim(), unit: item.unit.trim(),
        quantity: item.duration_minutes == null ? String(item.quantity) : (item.duration_minutes / 60).toFixed(3),
        unit_price: String(item.unit_price), discount_percent: String(item.discount_percent),
        vat_rate: String(item.vat_rate), vat_rate_id: item.vat_rate_id ?? null, stock_item_id: item.stock_item_id ?? null,
        warehouse_id: item.warehouse_id ?? null, duration_minutes: item.duration_minutes ?? null,
      })),
    })),
  }
}

function apply(value: SalesQuote) {
  quote.value = value
  form.value = {
    title: value.title, client_id: value.client_id, project_id: value.project_id, currency_id: value.currency_id,
    language: value.language, prices_include_vat: value.prices_include_vat, valid_until: value.valid_until,
    sections: structuredClone(value.sections),
  }
  signature.value = JSON.stringify(payload())
}

async function loadReferences() {
  if (referencesReady) return
  await Promise.all([
    codebooksApi.currencies(true).then(list => { currencies.value = list }),
    codebooksApi.vatRates('CZ', appIsoDate()).then(list => { vatRates.value = list }),
    stockReadable.value ? stockApi.listWarehouses(true).then(list => { warehouses.value = list }).catch(() => {}) : null,
  ])
  referencesReady = true
}

async function loadContext(clientId: number | null, allowProjectPrefill = false) {
  const current = ++projectRequest
  contacts.value = null
  projects.value = []
  if (!clientId) return
  const [client, entries] = await Promise.all([
    auth.canRead('clients') ? clientsApi.get(clientId).catch(() => null) : Promise.resolve(null),
    auth.canRead('projects') ? projectsApi.listForClient(clientId).catch(() => []) : Promise.resolve([]),
  ])
  if (current !== projectRequest) return
  contacts.value = client
  projects.value = entries
  if (allowProjectPrefill && client) {
    form.value.language = client.language
    if (client.currency_default_id && !form.value.sections.some(section => section.items.length)) form.value.currency_id = client.currency_default_id
  }
}

async function load(silent = false) {
  const current = ++loadRequest
  if (!silent) loading.value = true
  error.value = ''
  try {
    if (id.value) {
      const [value] = await Promise.all([
        salesQuotesApi.get(id.value, versionId.value), editable.value ? loadReferences() : Promise.resolve(),
      ])
      if (current !== loadRequest) return
      apply(value)
      if (editable.value && !referencesReady) await loadReferences()
      if (current !== loadRequest) return
      await loadContext(value.client_id)
    } else {
      quote.value = null
      form.value = initialForm()
      form.value.sections = [{ id: crypto.randomUUID(), title: t('sales_quotes.default_section'), kind: 'required', markdown: '', items: [] }]
      await loadReferences()
      if (current !== loadRequest) return
      const fromClient = Number(route.query.client_id) || null
      const fromProject = Number(route.query.project_id) || null
      const project = fromProject && auth.canRead('projects') ? await projectsApi.get(fromProject).catch(() => null) : null
      if (current !== loadRequest) return
      form.value.currency_id = currencies.value.find(entry => entry.is_default)?.id ?? currencies.value[0]?.id ?? null
      form.value.client_id = project?.client_id ?? fromClient
      form.value.project_id = project?.id ?? null
      await loadContext(form.value.client_id, true)
      if (current !== loadRequest) return
      if (project?.currency_id) form.value.currency_id = project.currency_id
      signature.value = JSON.stringify(payload())
    }
  } catch (e) {
    if (current === loadRequest) error.value = apiErrorMessage(e)
  } finally {
    if (current === loadRequest) loading.value = false
  }
}

async function searchClients(query: string) {
  const current = ++searchRequest
  clientLoading.value = true
  try {
    const list = await clientsApi.list({ q: query, role: 'customers', per_page: 30 })
    if (current === searchRequest) clients.value = list.data
  } catch (e) {
    if (current === searchRequest) toast.error(apiErrorMessage(e))
  } finally {
    if (current === searchRequest) clientLoading.value = false
  }
}

function changeClient(clientId: number | null) {
  form.value.client_id = clientId
  form.value.project_id = null
  void loadContext(clientId, true)
}

function validate(source: QuotePayload): string | undefined {
  if (!source.title || !source.client_id || !source.currency_id || !source.valid_until) return t('sales_quotes.incomplete')
  if (!source.sections.length || source.sections.some(section => !section.title)) return t('sales_quotes.sections_required')
  if (source.sections.some(section => section.items.some(item => !item.description || Number(item.quantity) <= 0))) return t('sales_quotes.items_invalid')
  return undefined
}

async function save() {
  if (!editable.value || busy.value || blockDemoMutation()) return
  const source = payload()
  const invalid = validate(source)
  if (invalid) {
    error.value = invalid
    return
  }
  busy.value = true
  error.value = ''
  try {
    const current = id.value
    const result = current ? await salesQuotesApi.update(current, source) : await salesQuotesApi.create(source)
    apply(result)
    toast.success(t('sales_quotes.saved'))
    if (!current) await router.replace({ name: 'sales-quote-detail', params: { id: result.id } })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function revise() {
  if (!quote.value || busy.value || blockDemoMutation()) return
  busy.value = true
  error.value = ''
  try {
    apply(await salesQuotesApi.revise(quote.value.id))
    await loadReferences()
    toast.success(t('sales_quotes.revision_created'))
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

function openSend() {
  if (!quote.value || dirty.value) return
  const defaults = quote.value.mail_defaults
  email.value = {
    to: defaults?.to || quote.value.client_snapshot?.main_email || contacts.value?.main_email || '',
    subject: defaults?.subject ?? `${quote.value.number ? quote.value.number + ' · ' : ''}${quote.value.title}`,
    message: defaults?.message ?? '',
  }
  modalError.value = ''
  dialog.value = 'send'
}

function openState(status: StateAction) {
  modalError.value = ''
  stateNote.value = ''
  chosenSections.value = []
  dialog.value = status
}

async function submitDialog() {
  const current = quote.value
  const action = dialog.value
  if (!current || !action || action === 'pdf' || busy.value || blockDemoMutation()) return
  busy.value = true
  modalError.value = ''
  try {
    if (action === 'send') {
      const input = { ...email.value, to: email.value.to.trim(), subject: email.value.subject.trim() }
      const signature = JSON.stringify([current.id, current.version_id, input])
      if (emailRequest?.signature !== signature) emailRequest = { signature, key: crypto.randomUUID() }
      const updated = await salesQuotesApi.send(current.id, { ...input, row_version: current.row_version, idempotency_key: emailRequest.key })
      apply(updated)
      emailRequest = null
      toast.success(t(updated.send_pending ? 'sales_quotes.send_pending' : 'sales_quotes.email_sent'))
    } else {
      apply(await salesQuotesApi.setState(current.id, action, chosenSections.value, stateNote.value.trim(), current.row_version))
    }
    dialog.value = null
  } catch (e) {
    modalError.value = apiErrorMessage(e)
    if (action === 'send') {
      if (isAxiosError<{ error?: { code?: string } }>(e) && e.response?.data?.error?.code === 'quote_send_failed') emailRequest = null
      try {
        apply(await salesQuotesApi.get(current.id))
      } catch {
        // Původní chyba odeslání zůstává viditelná.
      }
    }
  } finally {
    busy.value = false
  }
}

const dialogTitle = computed(() => {
  if (dialog.value === 'send') return t('sales_quotes.send')
  if (dialog.value === 'accepted') return t('sales_quotes.accept')
  if (dialog.value === 'declined') return t('sales_quotes.decline')
  return t('sales_quotes.withdraw')
})

function canLeave() {
  return !dirty.value || window.confirm(t('sales_quotes.leave_unsaved'))
}

onBeforeRouteLeave(canLeave)
onBeforeRouteUpdate(canLeave)
watch([id, versionId], () => { dialog.value = null; void load() })
onMounted(() => { void load() })
useWindowRefresh(() => load(true), () => !!id.value && !dirty.value && !busy.value && !loading.value)
onActivated(() => { if (id.value && !loading.value && !busy.value && !dirty.value) void load(true) })
</script>

<template>
  <div class="mx-auto max-w-screen-2xl space-y-4 p-3 sm:p-5">
    <RouterLink to="/sales-quotes" class="inline-flex items-center gap-1 text-sm font-medium text-primary-700 hover:underline">
      <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevronLeft" /></svg>
      {{ t('nav.sales_quotes') }}
    </RouterLink>

    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-2xl font-semibold text-neutral-900">{{ quote?.title || t('sales_quotes.new') }}</h1>
          <span v-if="quote" :class="['whitespace-nowrap rounded px-2 py-0.5 text-xs font-medium', quoteStatusBadge[quote.status]]">{{ t(`sales_quotes.status.${quote.status}`) }}</span>
        </div>
        <p v-if="quote" class="mt-1 text-sm text-neutral-600">{{ quote.number }} · {{ t('sales_quotes.revision_number', { number: quote.revision }) }}</p>
      </div>
      <ActionBar :actions="actions" />
    </header>

    <p v-if="error" role="alert" class="rounded-md border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700">{{ error }}</p>
    <p v-if="loading" class="p-8 text-center text-sm text-neutral-500" aria-live="polite">{{ t('common.loading') }}</p>
    <template v-else-if="quote || !id">
      <p v-if="quote?.send_pending" role="status" class="rounded-md border border-warning-300 bg-warning-50 p-3 text-sm text-warning-700">{{ t('sales_quotes.send_pending') }}</p>
      <p v-if="historical" class="rounded-md bg-primary-50 p-3 text-sm text-primary-800">
        {{ t('sales_quotes.history_revision') }}
        <RouterLink :to="{ name: 'sales-quote-detail', params: { id } }" class="font-semibold underline">{{ t('sales_quotes.current_revision') }}</RouterLink>
      </p>
      <form :id="formId" ref="editor" class="space-y-4" @submit.prevent="save">
        <fieldset :disabled="busy" class="space-y-4">
          <div v-if="editable" class="grid grid-cols-1 gap-3 rounded-lg border border-neutral-200 bg-surface p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-4">
            <label class="text-sm sm:col-span-2">
              <span class="mb-1 block font-medium">{{ t('sales_quotes.title') }}</span>
              <input v-model="form.title" required maxlength="190" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
            </label>
            <div class="text-sm">
              <label class="mb-1 block font-medium" :for="`${formId}-client`">{{ t('sales_quotes.client') }}</label>
              <SearchableSelect :model-value="form.client_id" :input-id="`${formId}-client`" remote required :loading="clientLoading"
                :options="clients.map(client => ({ value: client.id, label: clientLabel(client), secondary: client.ic ?? undefined }))"
                :selected-option="selectedClient" :disabled="!auth.canRead('clients')" :placeholder="t('sales_quotes.select_client')"
                @search="searchClients" @update:model-value="changeClient" />
            </div>
            <div class="text-sm">
              <label class="mb-1 block font-medium" :for="`${formId}-project`">{{ t('sales_quotes.project') }}</label>
              <SearchableSelect v-model="form.project_id" :input-id="`${formId}-project`" :options="projectOptions"
                :selected-option="projectOptions.find(project => project.value === form.project_id) ?? (form.project_id ? { value: form.project_id, label: quote?.project_name || `#${form.project_id}` } : null)"
                :disabled="!form.client_id || !auth.canRead('projects')" :placeholder="t('sales_quotes.no_project')" />
            </div>
            <label class="text-sm">
              <span class="mb-1 block font-medium">{{ t('sales_quotes.valid_until') }}</span>
              <DateInput v-model="form.valid_until" required />
            </label>
            <label class="text-sm">
              <span class="mb-1 block font-medium">{{ t('sales_quotes.currency') }}</span>
              <select v-model="form.currency_id" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
                <option :value="null">{{ t('sales_quotes.select_currency') }}</option>
                <option v-for="entry in currencies.filter(item => item.is_active || item.id === form.currency_id)" :key="entry.id" :value="entry.id">{{ entry.label || entry.code }}</option>
              </select>
            </label>
            <label class="text-sm">
              <span class="mb-1 block font-medium">{{ t('sales_quotes.language') }}</span>
              <select v-model="form.language" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
                <option value="cs">{{ t('sales_quotes.language_cs') }}</option>
                <option value="en">{{ t('sales_quotes.language_en') }}</option>
              </select>
            </label>
            <label class="flex items-center gap-2 self-end pb-2 text-sm">
              <input v-model="form.prices_include_vat" type="checkbox" :disabled="!isVatPayer" />
              {{ t('sales_quotes.prices_include_vat') }}
            </label>
          </div>
          <dl v-else-if="quote" class="grid grid-cols-2 gap-3 rounded-lg border border-neutral-200 bg-surface p-3 text-sm sm:grid-cols-4 sm:p-4">
            <div>
              <dt class="text-neutral-600">{{ t('sales_quotes.client') }}</dt>
              <dd><RouterLink v-if="auth.canRead('clients')" :to="{ name: 'client-detail', params: { id: quote.client_id } }" class="font-medium text-primary-700 hover:underline">{{ quote.client_name }}</RouterLink>
                <span v-else class="font-medium">{{ quote.client_name }}</span></dd>
            </div>
            <div v-if="quote.project_id">
              <dt class="text-neutral-600">{{ t('sales_quotes.project') }}</dt>
              <dd><RouterLink v-if="auth.canRead('projects')" :to="{ name: 'project-detail', params: { id: quote.project_id } }" class="font-medium text-primary-700 hover:underline">{{ quote.project_name || projects.find(project => project.id === quote?.project_id)?.name || `#${quote.project_id}` }}</RouterLink>
                <span v-else class="font-medium">{{ quote.project_name || `#${quote.project_id}` }}</span></dd>
            </div>
            <div><dt class="text-neutral-600">{{ t('sales_quotes.valid_until') }}</dt><dd class="font-medium">{{ formatDate(quote.valid_until) }}</dd></div>
            <div><dt class="text-neutral-600">{{ t('sales_quotes.currency') }}</dt><dd class="font-medium">{{ quote.currency }}</dd></div>
          </dl>
          <QuoteSections v-model="form.sections" :editable="editable" :images="fileImages" :stock-enabled="stockReadable" :stock-warehouses="warehouses"
            :can-use-price-list="auth.canRead('price_list')" :vat-rates="vatRates" :is-vat-payer="isVatPayer" :client-id="form.client_id" :currency-id="form.currency_id"
            :currency="currency" :prices-include-vat="form.prices_include_vat" :reverse-charge="contacts?.reverse_charge ?? quote?.reverse_charge ?? false" :accepted="quote?.status === 'accepted'"
            :accepted-sections="quote?.accepted_section_ids ?? []" />
        </fieldset>
      </form>

      <template v-if="quote">
        <QuoteBilling v-if="quote.status === 'accepted'" :key="quote.version_id" :quote="quote" :historical="historical"
          :can-invoice="canWrite && auth.canWrite('invoices.create')" :can-read-invoices="auth.canRead('invoices')"
          :can-edit-invoices="auth.canWrite('invoices')"
          :can-order="canWrite && stockReadable && auth.canWrite('stock.orders.write')" :can-read-orders="stockReadable" @refresh="load(true)" />
        <QuoteFiles :key="quote.version_id" :quote="quote" :editable="editable" :dirty="dirty" :disabled="busy" @updated="apply" @busy="busy = $event" />
        <section v-if="quote.revisions.length > 1" class="space-y-2 rounded-lg border border-neutral-200 bg-surface p-3 sm:p-4">
          <h2 class="text-lg font-semibold">{{ t('sales_quotes.revisions') }}</h2>
          <ul class="flex flex-wrap gap-2">
            <li v-for="revision in quote.revisions" :key="revision.id">
              <RouterLink :to="{ name: 'sales-quote-detail', params: { id }, query: { version_id: revision.id } }"
                class="inline-flex gap-2 rounded-md border border-neutral-300 px-2 py-1 text-sm text-primary-700 hover:underline">
                {{ t('sales_quotes.revision_number', { number: revision.revision }) }}
                <span class="text-neutral-600">{{ formatDate(revision.created_at) }}</span>
              </RouterLink>
            </li>
          </ul>
        </section>
        <QuoteHistory :key="quote.id" :quote="quote" :can-write="canWrite && !historical" :disabled="busy || dirty" @updated="apply" @busy="busy = $event" />
      </template>
      <p v-else class="rounded-lg border border-neutral-200 bg-surface p-3 text-sm text-neutral-600">{{ t('sales_quotes.save_before_files') }}</p>
    </template>
  </div>

  <Modal v-if="dialog === 'pdf'" :title="t('sales_quotes.pdf_preview')" width-class="max-w-6xl" @close="dialog = null">
    <div class="h-[70dvh] [&>*]:h-full [&>*]:w-full"><PdfFrame :src="pdfUrl" :title="t('sales_quotes.pdf_preview')" /></div>
    <template #footer>
      <a :href="pdfDownload" target="_blank" rel="noopener" :class="btnOutline('primary')">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
        {{ t('sales_quotes.download_pdf') }}
      </a>
    </template>
  </Modal>
  <Modal v-else-if="dialog && quote" :title="dialogTitle" @close="!busy && (dialog = null)">
    <form :id="`${formId}-action`" class="space-y-3" :aria-busy="busy" @submit.prevent="submitDialog">
      <p v-if="modalError" role="alert" class="rounded-md bg-danger-50 p-2 text-sm text-danger-700">{{ modalError }}</p>
      <fieldset :disabled="busy" class="space-y-3">
        <template v-if="dialog === 'send'">
          <label class="block text-sm">
            <span class="mb-1 block font-medium">{{ t('sales_quotes.email_to') }}</span>
            <input v-model="email.to" required type="email" autocomplete="email" :list="`${formId}-contacts`"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3" />
          </label>
          <datalist :id="`${formId}-contacts`">
            <option v-for="contact in recipients" :key="contact.address" :value="contact.address">{{ contact.name }}</option>
          </datalist>
          <label class="block text-sm">
            <span class="mb-1 block font-medium">{{ t('sales_quotes.email_subject') }}</span>
            <input v-model="email.subject" required maxlength="255" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3" />
          </label>
          <label class="block text-sm">
            <span class="mb-1 block font-medium">{{ t('sales_quotes.email_message') }}</span>
            <textarea v-model="email.message" rows="5" maxlength="10000" class="w-full resize-y rounded-md border border-neutral-300 bg-surface px-3 py-2" />
          </label>
          <div class="rounded-md bg-neutral-50 p-2 text-sm">
            <h3 class="font-medium">{{ t('sales_quotes.email_attachments') }}</h3>
            <ul class="mt-1 space-y-1 text-neutral-700">
              <li><a :href="pdfUrl" target="_blank" rel="noopener" class="text-primary-700 hover:underline">{{ t('sales_quotes.pdf_preview') }}</a></li>
              <li v-for="file in quote.files.filter(file => file.mode === 'email')" :key="file.id">{{ file.name }}</li>
            </ul>
          </div>
        </template>
        <template v-if="dialog === 'accepted' && quote.sections.some(section => section.kind === 'optional')">
          <p class="text-sm text-neutral-600">{{ t('sales_quotes.accept_options') }}</p>
          <label v-for="section in quote.sections.filter(section => section.kind === 'optional')" :key="section.id" class="flex flex-wrap items-center gap-2 text-sm">
            <input v-model="chosenSections" type="checkbox" :value="section.id" />
            <span class="flex-1">{{ section.title }}</span>
            <span class="font-medium tabular-nums">{{ formatMoney(sectionTotals(section, quote.prices_include_vat, true).with_vat, quote.currency) }}</span>
          </label>
        </template>
        <label v-if="dialog !== 'send'" class="block text-sm">
          <span class="mb-1 block font-medium">{{ t('sales_quotes.internal_note') }}</span>
          <textarea v-model="stateNote" maxlength="6000" rows="3" class="w-full resize-y rounded-md border border-neutral-300 bg-surface px-3 py-2" />
        </label>
      </fieldset>
    </form>
    <template #footer>
      <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="dialog = null">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
        {{ t('common.cancel') }}
      </button>
      <button type="submit" :form="`${formId}-action`" :class="btnFilled(dialog === 'accepted' ? 'success' : dialog === 'send' ? 'primary' : 'warning')" :disabled="busy">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="dialog === 'send' ? ICONS.send : ICONS.check" /></svg>
        {{ dialogTitle }}
      </button>
    </template>
  </Modal>
</template>

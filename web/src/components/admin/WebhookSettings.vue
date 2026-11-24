<script setup lang="ts">
import { computed, nextTick, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { recurringApi } from '@/api/recurring'
import {
  webhooksApi, webhookEventTypes, type WebhookConfiguration, type WebhookDelivery,
  type WebhookEndpoint, type WebhookEndpointInput,
} from '@/api/webhooks'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatUtcDateTime } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ templateId: number | null; helpHref: string }>()
const payloadExample = `{
  "type": "invoice.paid",
  "version": 1,
  "event_id": "evt_0123456789abcdef0123456789abcdef",
  "occurred_at": "2026-11-24T14:32:05.123456Z",
  "supplier_id": 7,
  "invoice_id": 1234,
  "invoice_type": "invoice",
  "recurring_template_id": null,
  "reason": "payment"
}`
const { t, te } = useI18n()
const toast = useToast()
const templateFieldId = useId()
const formId = useId()
const formElement = ref<HTMLFormElement | null>(null)
const attemptedSave = ref(false)
const configuration = ref<WebhookConfiguration | null>(null)
const form = ref<WebhookEndpointInput | null>(null)
const editingId = ref<number | null>(null)
const error = ref('')
const busy = ref(false)
const secret = ref('')
const historyId = ref<number | null>(null)
const deliveries = ref<WebhookDelivery[]>([])
const hasMore = ref(false)
const templateOptions = ref<{ value: number; label: string }[]>([])
const selectedTemplate = ref<{ value: number; label: string } | null>(null)
const templatesLoading = ref(false)
const templatesTruncated = ref(false)
let revision = 0
let templateSearchRevision = 0

const allowed = computed(() => configuration.value?.available && configuration.value.policy !== 'disabled')
const endpoints = computed(() => configuration.value?.available
  ? configuration.value.endpoints.filter(endpoint => props.templateId === null || endpoint.template_id === props.templateId)
  : [])
const editorEventTypes = computed(() => props.templateId === null
  ? webhookEventTypes
  : webhookEventTypes.filter(event => isInvoiceEvent(event) || form.value?.event_types.includes(event)))
const invalidEventScope = computed(() => form.value !== null && form.value.template_id !== null
  && form.value.event_types.some(event => !isInvoiceEvent(event)))
const inputClass = 'w-full min-w-0 h-9 px-3 border border-neutral-300 rounded-md text-sm aria-invalid:border-danger-600 read-only:bg-neutral-50'
const formErrors = computed(() => {
  const endpoint = form.value
  if (!attemptedSave.value || !endpoint) return {}

  const url = endpoint.url.trim()
  let urlError = ''
  if (editingId.value === null) {
    if (!url) {
      urlError = 'integrations.webhooks.url_required'
    } else if (!isHttpsAddress(url)) {
      urlError = 'integrations.webhooks.url_invalid'
    }
  }

  return {
    name: endpoint.name.trim() ? '' : 'integrations.webhooks.name_required',
    url: urlError,
    events: endpoint.event_types.length ? '' : 'integrations.webhooks.event_required',
  }
})

function isHttpsAddress(url: string): boolean {
  const authority = url.match(/^https:\/\/([^/?#]+)/i)?.[1]
  if (!authority || authority.includes('@') || /[\s#\\]/.test(url)) return false
  try {
    return Boolean(new URL(url).hostname)
  } catch {
    return false
  }
}

function isInvoiceEvent(event: string): boolean {
  return event.startsWith('invoice.')
}

function eventLabel(event: string): string {
  const key = 'integrations.webhooks.events.' + event.replaceAll('.', '_')
  return te(key) ? t(key) : event
}

function templateLabel(templateId: number): string {
  return t('integrations.webhooks.template_number', { id: templateId })
}

async function run(action: (isCurrent: () => boolean) => Promise<void>) {
  if (busy.value) return
  const version = revision
  const isCurrent = () => version === revision
  busy.value = true
  error.value = ''
  try {
    await action(isCurrent)
  } catch (e) {
    if (isCurrent()) error.value = apiErrorMessage(e)
  } finally {
    if (isCurrent()) busy.value = false
  }
}

async function loadEndpoints() {
  const version = revision
  const result = await webhooksApi.list()
  if (version !== revision) return
  configuration.value = result
  if (historyId.value !== null && !endpoints.value.some(endpoint => endpoint.id === historyId.value)) {
    historyId.value = null
    deliveries.value = []
  }
}

async function refresh() {
  const version = revision
  await loadEndpoints()
  if (version === revision && historyId.value !== null) await loadHistory(historyId.value)
}

async function searchTemplates(q = '') {
  const version = ++templateSearchRevision
  templatesLoading.value = true
  try {
    const result = await recurringApi.list({ q, per_page: 30 })
    if (version !== templateSearchRevision) return
    templateOptions.value = result.data.map(template => ({ value: template.id, label: template.name }))
    templatesTruncated.value = result.meta.total > result.data.length
  } catch (e) {
    if (version === templateSearchRevision) error.value = apiErrorMessage(e)
  } finally {
    if (version === templateSearchRevision) templatesLoading.value = false
  }
}

function chooseTemplate(id: number | null) {
  if (!form.value) return
  form.value.template_id = id
  selectedTemplate.value = templateOptions.value.find(option => option.value === id) ?? null
}

function closeEditor() {
  form.value = null
  templateSearchRevision++
  templateOptions.value = []
  templatesLoading.value = false
  templatesTruncated.value = false
}

function edit(endpoint?: WebhookEndpoint) {
  attemptedSave.value = false
  editingId.value = endpoint?.id ?? null
  form.value = endpoint ? { ...endpoint, event_types: [...endpoint.event_types] } : {
    name: '', url: '', enabled: true, event_types: ['invoice.paid'],
    template_id: props.templateId, include_advance: false,
  }
  const id = form.value.template_id
  selectedTemplate.value = id === null ? null : { value: id, label: templateLabel(id) }
  if (props.templateId !== null) return
  void searchTemplates()
  if (id !== null) {
    const editor = form.value
    void recurringApi.get(id).then(template => {
      if (form.value === editor && form.value.template_id === id) {
        selectedTemplate.value = { value: id, label: template.name }
      }
    }).catch(() => {})
  }
}

async function save() {
  if (!form.value || invalidEventScope.value || busy.value) return
  attemptedSave.value = true
  error.value = ''
  if (Object.values(formErrors.value).some(Boolean)) {
    await nextTick()
    formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
    return
  }

  const body = { ...form.value, name: form.value.name.trim(), url: form.value.url.trim() }
  body.include_advance = body.event_types.includes('invoice.paid') && body.include_advance
  const id = editingId.value
  void run(async isCurrent => {
    if (id === null) {
      const created = await webhooksApi.create(body)
      if (!isCurrent()) return
      secret.value = created.secret
    } else {
      await webhooksApi.update(id, {
        name: body.name, event_types: body.event_types, template_id: body.template_id,
        include_advance: body.include_advance,
      })
      if (!isCurrent()) return
    }
    closeEditor()
    await loadEndpoints()
  })
}

function setEnabled(endpoint: WebhookEndpoint) {
  void run(async isCurrent => {
    await webhooksApi.update(endpoint.id, { enabled: !endpoint.enabled })
    if (isCurrent()) await loadEndpoints()
  })
}

function remove(endpoint: WebhookEndpoint) {
  if (!window.confirm(t('integrations.webhooks.delete_confirm', { name: endpoint.name }))) return
  void run(async isCurrent => {
    await webhooksApi.remove(endpoint.id)
    if (!isCurrent()) return
    if (editingId.value === endpoint.id) closeEditor()
    secret.value = ''
    await loadEndpoints()
  })
}

function rotate(endpoint: WebhookEndpoint) {
  if (!window.confirm(t('integrations.webhooks.rotate_confirm', { name: endpoint.name }))) return
  void run(async isCurrent => {
    const result = await webhooksApi.rotate(endpoint.id)
    if (isCurrent()) secret.value = result.secret
  })
}

async function loadHistory(id: number, more = false) {
  const version = revision
  const before = more ? deliveries.value.at(-1)?.id : undefined
  const result = await webhooksApi.deliveries(id, before)
  if (version !== revision) return
  historyId.value = id
  deliveries.value = more ? [...deliveries.value, ...result] : result
  hasMore.value = result.length === 25
}

function sendTest(id: number) {
  void run(async isCurrent => {
    await webhooksApi.test(id)
    if (!isCurrent()) return
    toast.success(t('integrations.webhooks.test_queued'))
    await loadHistory(id)
  })
}

function retry(id: number, deliveryId: number) {
  void run(async isCurrent => {
    await webhooksApi.retry(id, deliveryId)
    if (isCurrent()) await loadHistory(id)
  })
}

async function copySecret() {
  try {
    await navigator.clipboard.writeText(secret.value)
    toast.success(t('integrations.webhooks.secret_copied'))
  } catch {
    toast.error(t('integrations.webhooks.secret_copy_failed'))
  }
}

function deactivate() {
  revision++
  busy.value = false
  configuration.value = null
  secret.value = ''
  historyId.value = null
  deliveries.value = []
  closeEditor()
}

onMounted(() => void run(loadEndpoints))
onActivated(() => void run(loadEndpoints))
onDeactivated(deactivate)
onBeforeUnmount(deactivate)
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold">{{ t('integrations.webhooks.tab') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('integrations.webhooks.description') }}</p>
        <div v-if="templateId !== null" class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
          <span>{{ templateLabel(templateId) }}</span>
          <RouterLink :to="{ name: 'admin-integrations', query: { tab: 'webhooks' } }" class="text-primary-700 hover:underline">
            {{ t('integrations.webhooks.all_endpoints') }}
          </RouterLink>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline()" :disabled="busy" @click="run(refresh)">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('common.refresh') }}
        </button>
        <button v-if="allowed && !form" type="button" :class="btnFilled()" :disabled="busy" @click="edit()">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.plus" /></svg>
          {{ t('integrations.webhooks.add') }}
        </button>
      </div>
    </div>

    <div class="space-y-2 rounded-lg border border-neutral-200 bg-surface p-4 text-sm">
      <p class="text-neutral-600">{{ t('integrations.webhooks.payload_summary') }}</p>
      <a :href="helpHref" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-medium text-primary-700 hover:underline">
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.help" /></svg>
        {{ t('integrations.webhooks.documentation') }}
      </a>
      <details>
        <summary class="cursor-pointer font-medium text-neutral-700">{{ t('integrations.webhooks.payload_example') }}</summary>
        <pre class="mt-2 overflow-x-auto rounded-md bg-neutral-50 p-3 text-xs text-neutral-800"><code>{{ payloadExample }}</code></pre>
      </details>
    </div>

    <p v-if="error" role="alert" class="rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ error }}</p>
    <p v-if="!configuration && busy" role="status" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="configuration && !configuration.available" class="rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-700">
      {{ t('integrations.webhooks.license_required') }}
    </p>
    <p v-else-if="configuration?.policy === 'disabled'" class="rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-700">
      {{ t('integrations.webhooks.policy_disabled') }}
    </p>
    <p v-else-if="configuration?.policy === 'allowlist'" class="text-sm text-neutral-500">
      {{ t('integrations.webhooks.policy_allowlist') }}
    </p>

    <div v-if="secret" role="status" class="space-y-2 rounded-lg border border-primary-200 bg-primary-50 p-4">
      <p class="text-sm font-medium">{{ t('integrations.webhooks.secret_hint') }}</p>
      <code class="block select-all break-all text-sm">{{ secret }}</code>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('primary')" @click="copySecret">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.copy" /></svg>
          {{ t('integrations.webhooks.copy_secret') }}
        </button>
        <button type="button" :class="btnOutline()" @click="secret = ''">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.close') }}
        </button>
      </div>
    </div>

    <form v-if="form && allowed" ref="formElement" class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm"
      novalidate @submit.prevent="save">
      <div class="space-y-1">
        <h3 class="font-medium">{{ t(editingId === null ? 'integrations.webhooks.add' : 'integrations.webhooks.edit') }}</h3>
        <p class="text-sm text-neutral-600">{{ t('integrations.webhooks.required_fields') }}</p>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <div class="min-w-0 space-y-1">
          <label :for="`${formId}-name`" class="block text-sm font-medium">
            {{ t('integrations.webhooks.name') }} <span aria-hidden="true" class="text-danger-700">*</span>
          </label>
          <input :id="`${formId}-name`" v-model="form.name" type="text" required maxlength="120" :class="inputClass"
            :aria-invalid="formErrors.name ? true : undefined" :aria-describedby="formErrors.name ? `${formId}-name-error` : undefined" />
          <p v-if="formErrors.name" :id="`${formId}-name-error`" class="text-sm text-danger-700">{{ t(formErrors.name) }}</p>
        </div>
        <div class="min-w-0 space-y-1">
          <label :for="`${formId}-url`" class="block text-sm font-medium">
            {{ t('integrations.webhooks.url') }} <span v-if="editingId === null" aria-hidden="true" class="text-danger-700">*</span>
          </label>
          <input :id="`${formId}-url`" v-model="form.url" type="url" :required="editingId === null" maxlength="2048"
            :class="inputClass" :readonly="editingId !== null" :placeholder="editingId === null ? 'https://example.com/webhooks' : undefined"
            :aria-invalid="formErrors.url ? true : undefined"
            :aria-describedby="formErrors.url ? `${formId}-url-error` : editingId !== null ? `${formId}-url-hint` : undefined" />
          <p v-if="formErrors.url" :id="`${formId}-url-error`" class="text-sm text-danger-700">{{ t(formErrors.url) }}</p>
          <p v-else-if="editingId !== null" :id="`${formId}-url-hint`" class="text-sm text-neutral-600">
            {{ t('integrations.webhooks.url_readonly_hint') }}
          </p>
        </div>
      </div>
      <fieldset class="space-y-2" tabindex="-1" :aria-invalid="formErrors.events ? true : undefined"
        :aria-describedby="formErrors.events ? `${formId}-events-error` : undefined">
        <legend class="text-sm font-medium">
          {{ t('integrations.webhooks.event_types') }} <span class="text-danger-700">*</span>
        </legend>
        <div class="flex flex-wrap gap-x-5 gap-y-2">
          <label v-for="event in editorEventTypes" :key="event" class="inline-flex items-center gap-2 text-sm">
            <input v-model="form.event_types" type="checkbox" :value="event"
              :disabled="form.template_id !== null && !isInvoiceEvent(event) && !form.event_types.includes(event)"
              class="rounded border-neutral-300 text-primary-600 disabled:opacity-50" />
            <span>{{ eventLabel(event) }}</span>
          </label>
        </div>
        <p v-if="formErrors.events" :id="`${formId}-events-error`" class="text-sm text-danger-700">{{ t(formErrors.events) }}</p>
        <p v-if="form.template_id !== null && !invalidEventScope" class="text-xs text-neutral-500">
          {{ t('integrations.webhooks.template_scope_hint') }}
        </p>
        <p v-if="invalidEventScope" role="alert" class="text-sm text-danger-700">
          {{ t('integrations.webhooks.template_scope_error') }}
        </p>
      </fieldset>
      <label v-if="form.event_types.includes('invoice.paid')" class="flex items-start gap-2 text-sm">
        <input v-model="form.include_advance" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
        <span>{{ t('integrations.webhooks.include_advance') }}</span>
      </label>
      <div v-if="templateId === null" class="max-w-sm space-y-1">
        <label :for="templateFieldId" class="text-sm">{{ t('integrations.webhooks.template_filter') }}</label>
        <SearchableSelect :model-value="form.template_id" :options="templateOptions" :selected-option="selectedTemplate"
          :input-id="templateFieldId" :placeholder="t('integrations.webhooks.all_invoices')" remote
          :loading="templatesLoading" :loading-label="t('common.loading')" :truncated="templatesTruncated"
          :truncated-label="t('integrations.webhooks.refine_templates')" :no-results-label="t('integrations.webhooks.no_templates')"
          :clear-label="t('integrations.webhooks.all_invoices')" @search="searchTemplates" @update:model-value="chooseTemplate" />
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="submit" :class="btnFilled()" :disabled="busy || invalidEventScope">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
        <button type="button" :class="btnOutline()" :disabled="busy" @click="closeEditor">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
    </form>

    <p v-if="configuration?.available && !endpoints.length" class="text-sm text-neutral-500">{{ t('integrations.webhooks.empty') }}</p>
    <article v-for="endpoint in endpoints" :key="endpoint.id" class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
      <div class="flex flex-wrap items-center gap-2">
        <h3 class="font-medium">{{ endpoint.name }}</h3>
        <span class="rounded-full px-2 py-0.5 text-xs font-medium"
          :class="endpoint.enabled ? 'bg-success-100 text-success-800' : 'bg-neutral-100 text-neutral-700'">
          {{ t(endpoint.enabled ? 'integrations.webhooks.active' : 'integrations.webhooks.paused') }}
        </span>
      </div>
      <p class="break-all font-mono text-xs text-neutral-600">{{ endpoint.url }}</p>
      <ul class="flex flex-wrap gap-1.5">
        <li v-for="event in endpoint.event_types" :key="event" class="rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700">{{ eventLabel(event) }}</li>
        <li v-if="endpoint.template_id !== null" class="rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700">{{ templateLabel(endpoint.template_id) }}</li>
      </ul>
      <div class="flex flex-wrap gap-2">
        <button v-if="allowed" type="button" :class="btnOutline()" :disabled="busy" @click="edit(endpoint)">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.edit" /></svg>
          {{ t('common.edit') }}
        </button>
        <button type="button" :class="btnOutline()" :disabled="busy" @click="run(() => loadHistory(endpoint.id))">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.eye" /></svg>
          {{ t('integrations.webhooks.history') }}
        </button>
        <button v-if="allowed && endpoint.enabled" type="button" :class="btnOutline('primary')" :disabled="busy" @click="sendTest(endpoint.id)">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.send" /></svg>
          {{ t('integrations.webhooks.test') }}
        </button>
        <button v-if="allowed || endpoint.enabled" type="button" :class="btnOutline(endpoint.enabled ? 'warning' : 'success')" :disabled="busy" @click="setEnabled(endpoint)">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="endpoint.enabled ? ICONS.pause : ICONS.play" /></svg>
          {{ t(endpoint.enabled ? 'integrations.webhooks.pause' : 'integrations.webhooks.resume') }}
        </button>
        <button v-if="allowed" type="button" :class="btnOutline('warning')" :disabled="busy" @click="rotate(endpoint)">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('integrations.webhooks.rotate') }}
        </button>
        <button type="button" :class="btnOutline('danger')" :disabled="busy" @click="remove(endpoint)">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.trash" /></svg>
          {{ t('common.delete') }}
        </button>
      </div>
      <div v-if="historyId === endpoint.id" class="space-y-2 border-t border-neutral-200 pt-3">
        <p v-if="!deliveries.length" class="text-sm text-neutral-500">{{ t('integrations.webhooks.no_deliveries') }}</p>
        <div v-for="delivery in deliveries" :key="delivery.id" class="flex flex-wrap items-center justify-between gap-2 rounded bg-neutral-50 p-2 text-sm">
          <div class="min-w-0 space-y-0.5">
            <p class="font-medium">{{ eventLabel(delivery.event_type) }}</p>
            <p class="break-all font-mono text-xs text-neutral-500">{{ delivery.event_id }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>{{ formatUtcDateTime(delivery.created_at) }}</span>
            <span>{{ t('integrations.webhooks.status.' + delivery.status) }}</span>
            <span>{{ t('integrations.webhooks.attempts', { count: delivery.attempts }) }}</span>
            <span v-if="delivery.http_status">HTTP {{ delivery.http_status }}</span>
            <code v-if="delivery.last_error_code" class="text-xs text-danger-700">{{ delivery.last_error_code }}</code>
            <span v-if="delivery.next_attempt_at && delivery.status === 'pending'" class="text-xs text-neutral-500">
              {{ t('integrations.webhooks.next_attempt', { date: formatUtcDateTime(delivery.next_attempt_at) }) }}
            </span>
          </div>
          <button v-if="allowed && endpoint.enabled && delivery.status === 'failed'" type="button" :class="btnOutline('warning')" :disabled="busy"
            @click="retry(endpoint.id, delivery.id)">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
            {{ t('integrations.webhooks.retry') }}
          </button>
        </div>
        <button v-if="hasMore" type="button" :class="btnOutline()" :disabled="busy" @click="run(() => loadHistory(endpoint.id, true))">
          <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
          {{ t('integrations.webhooks.older') }}
        </button>
      </div>
    </article>
  </section>
</template>

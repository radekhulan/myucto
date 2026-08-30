<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { documentsApi, type DocItem } from '@/api/documents'
import {
  payrollApi,
  type PayrollForeignPermitKind,
  type PayrollForeignPermitPayload,
  type PayrollForeignPermitView,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { addDaysIso } from '@/utils/date'
import { todayIso } from './employmentLifecycleUi'

const props = defineProps<{
  personId: number
  canWrite: boolean
  canReadDocuments: boolean
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')
const view = ref<PayrollForeignPermitView | null>(null)
const form = ref<PayrollForeignPermitPayload>(emptyForm())
const documentQuery = ref('')
const documentCandidates = ref<DocItem[]>([])
const selectedDocument = ref<DocItem | null>(null)

const alerts = computed(() => view.value?.alerts ?? [])
const history = computed(() => view.value?.history ?? [])

function emptyForm(): PayrollForeignPermitPayload {
  return {
    permit_kind: 'residence',
    permit_label: '',
    issuing_country_code: 'CZ',
    effective_from: todayIso(),
    valid_until: '',
    document_id: 0,
    supersedes_permit_id: null,
  }
}

function statusClass(status: string): string {
  return {
    valid: 'bg-success-50 text-success-700',
    expiring: 'bg-warning-50 text-warning-800',
    expired: 'bg-danger-50 text-danger-700',
    future: 'bg-neutral-100 text-neutral-700',
    superseded: 'bg-neutral-100 text-neutral-600',
  }[status] ?? 'bg-neutral-100 text-neutral-700'
}

function validDocumentId(): number | null {
  return selectedDocument.value?.id ?? null
}

async function searchDocuments(): Promise<void> {
  const query = documentQuery.value.trim()
  if (query.length < 2 || selectedDocument.value !== null) {
    documentCandidates.value = []
    return
  }
  try {
    documentCandidates.value = (await documentsApi.search(query))
      .filter(document => document.scope === 'company')
  } catch {
    documentCandidates.value = []
    toast.error(t('payroll.people.foreign_permits.document_search_failed'))
  }
}

function chooseDocument(document: DocItem): void {
  selectedDocument.value = document
  form.value.document_id = document.id
  documentQuery.value = document.title
  documentCandidates.value = []
}

function clearDocument(): void {
  selectedDocument.value = null
  form.value.document_id = 0
  documentQuery.value = ''
  documentCandidates.value = []
}

function changeDocumentQuery(): void {
  selectedDocument.value = null
  form.value.document_id = 0
  void searchDocuments()
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    view.value = await payrollApi.foreignPermits(props.personId)
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('payroll.people.foreign_permits.load_failed'))
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (saving.value) return
  saveError.value = ''
  const documentId = validDocumentId()
  if (documentId === null) {
    saveError.value = t('payroll.people.foreign_permits.document_required')
    return
  }
  saving.value = true
  try {
    view.value = await payrollApi.createForeignPermit(props.personId, {
      ...form.value,
      permit_label: form.value.permit_label.trim(),
      issuing_country_code: form.value.issuing_country_code.trim().toUpperCase(),
      document_id: documentId,
      supersedes_permit_id: form.value.supersedes_permit_id || null,
    })
    form.value = emptyForm()
    clearDocument()
    toast.success(t('payroll.people.foreign_permits.saved'))
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.people.foreign_permits.save_failed'))
  } finally {
    saving.value = false
  }
}

function selectPredecessor(id: number): void {
  form.value.supersedes_permit_id = id
  const permit = history.value.find(item => item.id === id)
  if (permit) {
    form.value.permit_kind = permit.permit_kind as PayrollForeignPermitKind
    form.value.effective_from = addDaysIso(permit.valid_until, 1)
  }
}

watch(() => props.personId, () => { form.value = emptyForm(); clearDocument(); void load() })
onMounted(() => { void load() })
</script>

<template>
  <details class="group rounded-lg border border-payroll-500/30 bg-surface" data-test="foreign-permits">
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
      <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-neutral-900">{{ t('payroll.people.foreign_permits.title') }}</span>
        <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.people.foreign_permits.subtitle') }}</span>
      </span>
      <span v-if="alerts.length" class="shrink-0 rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800" data-test="foreign-permits-alert-count">
        {{ t('payroll.people.foreign_permits.alert_count', { count: alerts.length }, alerts.length) }}
      </span>
    </summary>

    <div class="space-y-3 border-t border-neutral-200 p-3">
      <div v-if="loading" class="h-20 animate-pulse rounded-md bg-neutral-100" />
      <p v-else-if="loadError" class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700" role="alert">{{ loadError }}</p>
      <template v-else>
        <div v-if="alerts.length" class="rounded-md border border-warning-500/30 bg-warning-50 p-2 text-xs text-warning-800" data-test="foreign-permits-alerts">
          <p class="font-medium">{{ t('payroll.people.foreign_permits.alerts_title') }}</p>
          <ul class="mt-1 space-y-1">
            <li v-for="alert in alerts" :key="alert.permit_id">
              {{ t(`payroll.people.foreign_permits.status.${alert.status}`) }}: {{ alert.permit_label }} — {{ alert.valid_until }}
            </li>
          </ul>
        </div>

        <p v-if="history.length === 0" class="rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600">{{ t('payroll.people.foreign_permits.empty') }}</p>
        <div v-else class="space-y-2" data-test="foreign-permits-history">
          <article v-for="permit in history" :key="permit.id" class="rounded-md border border-neutral-200 p-2 text-xs">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="font-medium text-neutral-900">{{ permit.permit_label }}</span>
              <span :class="`rounded-full px-2 py-0.5 font-medium ${statusClass(permit.status)}`">{{ t(`payroll.people.foreign_permits.status.${permit.status}`) }}</span>
            </div>
            <p class="mt-1 text-neutral-600">{{ t(`payroll.people.foreign_permits.kind.${permit.permit_kind}`) }} · {{ permit.issuing_country_code }} · {{ permit.effective_from }} — {{ permit.valid_until }}</p>
            <div class="mt-1 flex flex-wrap items-center gap-3">
              <RouterLink v-if="canReadDocuments && permit.document_id !== null" :to="{ name: 'document-detail', params: { id: permit.document_id } }" class="text-primary-600 hover:text-primary-700">
                {{ t('payroll.people.foreign_permits.open_document') }}
              </RouterLink>
              <button v-if="canWrite && permit.status !== 'superseded'" type="button" :class="[btnOutline('neutral'), '!px-2 !py-1 !text-xs']" @click="selectPredecessor(permit.id)">
                {{ t('payroll.people.foreign_permits.renew') }}
              </button>
            </div>
          </article>
        </div>

        <form v-if="canWrite" class="grid grid-cols-1 gap-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3 sm:grid-cols-2 lg:grid-cols-3" data-test="foreign-permit-form" @submit.prevent="save">
          <p class="text-sm font-medium text-neutral-900 sm:col-span-2 lg:col-span-3">{{ t('payroll.people.foreign_permits.add_title') }}</p>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.foreign_permits.kind_label') }}
            <select v-model="form.permit_kind" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="foreign-permit-kind">
              <option value="residence">{{ t('payroll.people.foreign_permits.kind.residence') }}</option>
              <option value="work">{{ t('payroll.people.foreign_permits.kind.work') }}</option>
            </select>
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.foreign_permits.label') }}
            <input v-model="form.permit_label" required maxlength="128" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="foreign-permit-label">
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.foreign_permits.country') }}
            <input v-model="form.issuing_country_code" required maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm uppercase" data-test="foreign-permit-country">
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.foreign_permits.effective_from') }}
            <input v-model="form.effective_from" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" data-test="foreign-permit-effective-from">
          </label>
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.foreign_permits.valid_until') }}
            <input v-model="form.valid_until" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm">
          </label>
          <label class="relative text-xs text-neutral-600">
            {{ t('payroll.people.foreign_permits.document_label') }}
            <input v-model="documentQuery" required autocomplete="off" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm" :placeholder="t('payroll.people.foreign_permits.document_search_placeholder')" data-test="foreign-permit-document-search" @input="changeDocumentQuery">
            <div v-if="documentCandidates.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-md border border-neutral-200 bg-surface shadow-lg" data-test="foreign-permit-document-candidates">
              <button v-for="document in documentCandidates" :key="document.id" type="button" class="cursor-pointer block w-full px-3 py-2 text-left text-xs text-neutral-700 hover:bg-neutral-50" @click="chooseDocument(document)">
                {{ document.title }} · #{{ document.id }}
              </button>
            </div>
            <span v-if="selectedDocument" class="mt-1 flex items-center justify-between gap-2 text-xs text-success-700" data-test="foreign-permit-selected-document">
              {{ selectedDocument.title }} · #{{ selectedDocument.id }}
              <button type="button" class="cursor-pointer text-neutral-600 underline hover:text-neutral-900" @click="clearDocument">{{ t('payroll.people.foreign_permits.document_clear') }}</button>
            </span>
          </label>
          <p class="text-xs text-neutral-600 sm:col-span-2 lg:col-span-3">{{ t('payroll.people.foreign_permits.document_hint') }}</p>
          <p v-if="saveError" class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700 sm:col-span-2 lg:col-span-3" role="alert" data-test="foreign-permit-error">{{ saveError }}</p>
          <div class="flex justify-end sm:col-span-2 lg:col-span-3">
            <button type="submit" :class="btnFilled('primary')" :disabled="saving" data-test="foreign-permit-save">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ saving ? t('common.saving') : t('payroll.people.foreign_permits.save') }}
            </button>
          </div>
        </form>
      </template>
    </div>
  </details>
</template>

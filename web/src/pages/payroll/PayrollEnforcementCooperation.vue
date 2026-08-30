<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollEnforcementApi,
  type EnforcementCaseSummary,
  type XmlzamCandidate,
  type XmlzamRequestDetail,
  type XmlzamResponsePreview,
} from '@/api/payrollEnforcement'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const environment = ref('production')
const candidates = ref<XmlzamCandidate[]>([])
const requestDetail = ref<XmlzamRequestDetail | null>(null)
const cases = ref<EnforcementCaseSummary[]>([])
const selectedCaseId = ref<number | null>(null)
const periodDraft = ref('')
const periods = ref<string[]>([])
const preview = ref<XmlzamResponsePreview | null>(null)
const frozenResponseId = ref<number | null>(null)
const queuedOutboxId = ref<number | null>(null)
const loading = ref(false)
const busy = ref(false)
const canWrite = computed(() => auth.canWrite('payroll.enforcement.cooperation'))
const requestedScopeSet = computed(() => new Set(requestDetail.value?.requested_scopes ?? []))
const requestsWages = computed(() => requestedScopeSet.value.has('vyse_srazek'))
const xsdComplete = computed(() => [
  'vyse_srazek',
  'trvani_praconiho_pomeru',
  'poradi_exekuce',
].every(scope => requestedScopeSet.value.has(scope)))
const canPreview = computed(() => canWrite.value
  && requestDetail.value !== null
  && selectedCaseId.value !== null
  && xsdComplete.value
  && (!requestsWages.value || periods.value.length > 0)
  && !busy.value)

function errorMessage(error: any, fallback: string): string {
  return error?.response?.data?.error?.message || fallback
}

function resetResponse() {
  preview.value = null
  frozenResponseId.value = null
  queuedOutboxId.value = null
}

async function loadCandidates() {
  loading.value = true
  requestDetail.value = null
  cases.value = []
  selectedCaseId.value = null
  periods.value = []
  resetResponse()
  try {
    candidates.value = await payrollEnforcementApi.cooperationCandidates(environment.value)
  } catch (error) {
    candidates.value = []
    toast.error(errorMessage(error, t('payroll.enforcement_cooperation.load_failed')))
  } finally {
    loading.value = false
  }
}

async function importCandidate(candidate: XmlzamCandidate) {
  if (!canWrite.value || busy.value) return
  busy.value = true
  try {
    const imported = await payrollEnforcementApi.importCooperationRequest(
      environment.value,
      candidate.inbox_message_id,
      candidate.document_file_id,
    )
    const [detail, casePage] = await Promise.all([
      payrollEnforcementApi.cooperationRequestDetail(imported.id, environment.value),
      payrollEnforcementApi.casesPage({ employee_id: imported.employee_id, limit: 100, offset: 0 }),
    ])
    requestDetail.value = detail
    cases.value = casePage.cases.filter(item => item.case_kind === 'enforcement')
    selectedCaseId.value = cases.value.length === 1 ? cases.value[0]!.id : null
    periods.value = []
    resetResponse()
    candidates.value = candidates.value.filter(item => item !== candidate)
    toast.success(t('payroll.enforcement_cooperation.imported'))
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.enforcement_cooperation.import_failed')))
  } finally {
    busy.value = false
  }
}

function addPeriod() {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(periodDraft.value)) return
  if (!periods.value.includes(periodDraft.value)) {
    periods.value = [...periods.value, periodDraft.value].sort()
  }
  periodDraft.value = ''
  resetResponse()
}

function removePeriod(period: string) {
  periods.value = periods.value.filter(item => item !== period)
  resetResponse()
}

async function createPreview() {
  if (!requestDetail.value || selectedCaseId.value === null || !canPreview.value) return
  busy.value = true
  try {
    preview.value = await payrollEnforcementApi.previewCooperationResponse(
      requestDetail.value.id,
      environment.value,
      selectedCaseId.value,
      periods.value,
    )
    frozenResponseId.value = null
    queuedOutboxId.value = null
  } catch (error) {
    resetResponse()
    toast.error(errorMessage(error, t('payroll.enforcement_cooperation.preview_failed')))
  } finally {
    busy.value = false
  }
}

async function freezeResponse() {
  if (!requestDetail.value || selectedCaseId.value === null || !preview.value || busy.value) return
  busy.value = true
  try {
    const response = await payrollEnforcementApi.freezeCooperationResponse(
      requestDetail.value.id,
      environment.value,
      selectedCaseId.value,
      periods.value,
      crypto.randomUUID(),
    )
    frozenResponseId.value = response.id
    toast.success(t('payroll.enforcement_cooperation.frozen'))
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.enforcement_cooperation.freeze_failed')))
  } finally {
    busy.value = false
  }
}

async function enqueueResponse() {
  const detail = requestDetail.value
  if (frozenResponseId.value === null || !detail?.recipient || busy.value) return
  busy.value = true
  try {
    const dispatch = await payrollEnforcementApi.enqueueCooperationResponse(
      frozenResponseId.value,
      environment.value,
      detail.recipient.id,
    )
    queuedOutboxId.value = dispatch.outbox_id
    toast.success(t('payroll.enforcement_cooperation.queued'))
  } catch (error) {
    toast.error(errorMessage(error, t('payroll.enforcement_cooperation.enqueue_failed')))
  } finally {
    busy.value = false
  }
}

function formatMinor(value: number): string {
  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(value / 100)
}

watch(environment, loadCandidates, { immediate: true })
watch(selectedCaseId, resetResponse)
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.enforcement_cooperation.title') }}</h1>
        <p class="mt-1 max-w-4xl text-sm text-neutral-500">{{ t('payroll.enforcement_cooperation.subtitle') }}</p>
      </div>
      <RouterLink to="/payroll/enforcement" :class="btnOutline('neutral')">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.coin" /></svg>
        {{ t('payroll.enforcement_cooperation.open_cases') }}
      </RouterLink>
    </header>

    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="font-semibold text-neutral-900">1. {{ t('payroll.enforcement_cooperation.source_title') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.enforcement_cooperation.source_hint') }}</p>
        </div>
        <label class="text-xs font-medium text-neutral-600">
          {{ t('payroll.enforcement_cooperation.environment') }}
          <select v-model="environment" :disabled="busy" class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
            <option value="production">{{ t('payroll.enforcement_cooperation.production') }}</option>
            <option value="test">{{ t('payroll.enforcement_cooperation.test') }}</option>
          </select>
        </label>
      </div>
      <p v-if="!canWrite" class="mt-3 rounded-md bg-warning-50 p-3 text-sm text-warning-700">{{ t('payroll.enforcement_cooperation.read_only') }}</p>
      <div v-if="candidates.length" class="mt-4 grid gap-3 lg:grid-cols-2">
        <article v-for="candidate in candidates" :key="candidate.document_file_id" class="rounded-md border border-neutral-200 p-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <h3 class="truncate font-medium text-neutral-900">{{ candidate.subject || candidate.original_name }}</h3>
              <p class="mt-1 text-xs text-neutral-500">{{ candidate.sender_name || candidate.sender_box_id }} · {{ candidate.delivered_at || candidate.fetched_at }}</p>
              <p class="mt-1 text-xs text-neutral-500">{{ candidate.original_name }} · {{ Math.ceil(candidate.size_bytes / 1024) }} kB</p>
            </div>
            <button data-test="xmlzam-import" :class="btnFilled('primary')" :disabled="!canWrite || busy" @click="importCandidate(candidate)">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.inbox" /></svg>
              {{ t('payroll.enforcement_cooperation.import') }}
            </button>
          </div>
        </article>
      </div>
      <EmptyState v-else-if="!loading && requestDetail === null" class="mt-4" :title="t('payroll.enforcement_cooperation.empty_title')" :description="t('payroll.enforcement_cooperation.empty_hint')" icon="inbox" />
    </section>

    <template v-if="requestDetail">
      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="font-semibold text-neutral-900">2. {{ t('payroll.enforcement_cooperation.request_title') }}</h2>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement_cooperation.employee') }}</dt><dd class="font-medium text-neutral-900">{{ requestDetail.employee.full_name }}</dd></div>
          <div><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement_cooperation.case_reference') }}</dt><dd class="font-medium text-neutral-900">{{ requestDetail.case_reference }}</dd></div>
          <div><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement_cooperation.issued_on') }}</dt><dd class="font-medium text-neutral-900">{{ requestDetail.issued_on }}</dd></div>
          <div><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement_cooperation.source_document') }}</dt><dd><RouterLink class="text-primary-700 hover:underline" :to="`/documents/${requestDetail.source.document_id}`">#{{ requestDetail.source.document_id }}</RouterLink></dd></div>
        </dl>
        <div class="mt-3 flex flex-wrap gap-2">
          <span v-for="scope in requestDetail.requested_scopes" :key="scope" class="rounded-full bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700">{{ t(`payroll.enforcement_cooperation.scopes.${scope}`) }}</span>
        </div>
        <p v-if="!xsdComplete" class="mt-3 rounded-md border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700">{{ t('payroll.enforcement_cooperation.partial_scope_blocked') }}</p>
        <p v-else-if="requestDetail.recipient_match_status === 'missing'" class="mt-3 rounded-md border border-warning-300 bg-warning-50 p-3 text-sm text-warning-700">{{ t('payroll.enforcement_cooperation.recipient_missing') }}</p>
        <p v-else-if="requestDetail.recipient_match_status === 'ambiguous'" class="mt-3 rounded-md border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700">{{ t('payroll.enforcement_cooperation.recipient_ambiguous') }}</p>
        <p v-else class="mt-3 text-sm text-success-700">{{ t('payroll.enforcement_cooperation.recipient_matched', { name: requestDetail.recipient?.name }) }}</p>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="font-semibold text-neutral-900">3. {{ t('payroll.enforcement_cooperation.evidence_title') }}</h2>
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
          <label class="text-xs font-medium text-neutral-600">
            {{ t('payroll.enforcement_cooperation.case') }}
            <select v-model="selectedCaseId" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
              <option :value="null">{{ t('payroll.enforcement_cooperation.select_case') }}</option>
              <option v-for="item in cases" :key="item.id" :value="item.id">#{{ item.id }} · {{ t(`payroll.enforcement.status.${item.status}`) }}</option>
            </select>
          </label>
          <div v-if="requestsWages">
            <label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement_cooperation.periods') }}</label>
            <div class="mt-1 flex flex-wrap gap-2">
              <input v-model="periodDraft" data-test="xmlzam-period" type="month" class="rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
              <button data-test="xmlzam-add-period" :class="btnOutline('neutral')" :disabled="!periodDraft" @click="addPeriod">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.plus" /></svg>
                {{ t('payroll.enforcement_cooperation.add_period') }}
              </button>
            </div>
            <div class="mt-2 flex flex-wrap gap-2">
              <button v-for="period in periods" :key="period" type="button" class="cursor-pointer rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-700 hover:bg-danger-50 hover:text-danger-700" @click="removePeriod(period)">{{ period }} ×</button>
            </div>
          </div>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <button data-test="xmlzam-preview" :class="btnFilled('primary')" :disabled="!canPreview" @click="createPreview">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.eye" /></svg>
            {{ t('payroll.enforcement_cooperation.preview') }}
          </button>
          <p v-if="cases.length === 0" class="text-sm text-warning-700">{{ t('payroll.enforcement_cooperation.no_case') }}</p>
          <p v-else-if="requestsWages && periods.length === 0" class="text-sm text-warning-700">{{ t('payroll.enforcement_cooperation.period_required') }}</p>
        </div>
      </section>

      <section v-if="preview" class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="font-semibold text-neutral-900">4. {{ t('payroll.enforcement_cooperation.review_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.enforcement_cooperation.review_hint') }}</p>
        <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div v-if="preview.priority !== undefined"><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement_cooperation.priority') }}</dt><dd class="font-medium">{{ preview.priority }}</dd></div>
          <div v-if="preview.employment"><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement_cooperation.employment') }}</dt><dd class="font-medium">{{ preview.employment.active ? t('common.yes') : t('common.no') }}</dd></div>
          <div><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement_cooperation.response_id') }}</dt><dd class="font-mono text-xs">{{ preview.response_identifier }}</dd></div>
          <div><dt class="text-xs text-neutral-500">SHA-256</dt><dd class="truncate font-mono text-xs" :title="preview.xml_sha256">{{ preview.xml_sha256 }}</dd></div>
        </dl>
        <div v-if="preview.wages?.length" class="mt-4 overflow-x-auto">
          <table class="w-full min-w-[36rem] text-left text-sm">
            <thead class="text-xs uppercase text-neutral-500"><tr><th class="py-2">{{ t('payroll.enforcement_cooperation.period') }}</th><th>{{ t('payroll.enforcement_cooperation.gross') }}</th><th>{{ t('payroll.enforcement_cooperation.withheld') }}</th></tr></thead>
            <tbody><tr v-for="wage in preview.wages" :key="wage.period" class="border-t border-neutral-200"><td class="py-2">{{ wage.period }}</td><td>{{ formatMinor(wage.gross_minor) }}</td><td>{{ formatMinor(wage.withheld_minor) }}</td></tr></tbody>
          </table>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
          <button v-if="frozenResponseId === null" data-test="xmlzam-freeze" :class="btnFilled('success')" :disabled="busy" @click="freezeResponse">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.check" /></svg>
            {{ t('payroll.enforcement_cooperation.freeze') }}
          </button>
          <button v-if="frozenResponseId !== null && requestDetail.recipient" data-test="xmlzam-enqueue" :class="btnFilled('primary')" :disabled="busy || queuedOutboxId !== null" @click="enqueueResponse">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="ICONS.send" /></svg>
            {{ t('payroll.enforcement_cooperation.enqueue') }}
          </button>
        </div>
        <div v-if="queuedOutboxId !== null" class="mt-4 rounded-md border border-success-300 bg-success-50 p-3 text-sm text-success-700">
          <p>{{ t('payroll.enforcement_cooperation.queued_hint') }}</p>
          <RouterLink class="mt-2 inline-flex font-medium text-primary-700 hover:underline" to="/admin/databox">{{ t('payroll.enforcement_cooperation.open_databox') }}</RouterLink>
        </div>
      </section>
    </template>
  </div>
</template>

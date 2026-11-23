<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzExternalRoot,
  type PayrollJmhzExternalRootCandidates,
  type PayrollJmhzExternalRootMode,
  type PayrollJmhzTransportEnvironment,
  type PayrollRun,
} from '@/api/payroll'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import { formatPeriod } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{
  environment: PayrollJmhzTransportEnvironment
}>()

const emit = defineEmits<{
  'open-submission': [payload: { submissionId: number; period: string }]
}>()

const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))

const roots = ref<PayrollJmhzExternalRoot[]>([])
const loadError = ref('')

const open = ref(false)
const runs = ref<PayrollRun[]>([])
const runId = ref<number | null>(null)
const mode = ref<PayrollJmhzExternalRootMode>('original_xml')
const xmlBase64 = ref('')
const xmlName = ref('')
const submissionGuid = ref('')
const candidates = ref<PayrollJmhzExternalRootCandidates | null>(null)
const candidatesLoading = ref(false)
const formGuids = ref<Record<number, string>>({})
const formsConfirmed = ref(false)
const note = ref('')
const saving = ref(false)
const error = ref('')

const selectedRun = computed(() => runs.value.find(run => run.id === runId.value) ?? null)
const filledForms = computed(() => Object.entries(formGuids.value)
  .filter(([, guid]) => guid.trim() !== '')
  .map(([employmentId, guid]) => ({ employment_id: Number(employmentId), form_guid: guid.trim() })))
const canSubmit = computed(() => {
  if (selectedRun.value === null || note.value.trim().length < 10 || saving.value) return false
  if (mode.value === 'original_xml') return xmlBase64.value !== ''
  return submissionGuid.value.trim() !== '' && filledForms.value.length > 0 && formsConfirmed.value
})

async function loadRoots() {
  loadError.value = ''
  try {
    roots.value = (await payrollApi.jmhzExternalRoots(props.environment)).items
  } catch (e) {
    loadError.value = apiErrorMessage(e, t('payroll.jmhz_external_root.load_failed'))
  }
}

async function openDialog() {
  open.value = true
  error.value = ''
  runId.value = null
  mode.value = 'original_xml'
  xmlBase64.value = ''
  xmlName.value = ''
  submissionGuid.value = ''
  candidates.value = null
  formGuids.value = {}
  formsConfirmed.value = false
  note.value = ''
  try {
    runs.value = (await payrollApi.runs())
      .filter(run => run.status === 'approved' && run.revision_id !== null)
  } catch (e) {
    error.value = apiErrorMessage(e, t('payroll.jmhz_external_root.load_failed'))
  }
}

async function loadCandidates() {
  candidates.value = null
  formGuids.value = {}
  const run = selectedRun.value
  if (run === null || run.revision_id === null || mode.value !== 'guid_reference') return
  candidatesLoading.value = true
  error.value = ''
  try {
    candidates.value = await payrollApi.jmhzExternalRootCandidates(run.revision_id, run.office_id, props.environment)
  } catch (e) {
    error.value = apiErrorMessage(e, t('payroll.jmhz_external_root.load_failed'))
  } finally {
    candidatesLoading.value = false
  }
}

watch([runId, mode], () => { void loadCandidates() })

async function pickFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const buffer = new Uint8Array(await file.arrayBuffer())
  let binary = ''
  for (const byte of buffer) binary += String.fromCharCode(byte)
  xmlBase64.value = btoa(binary)
  xmlName.value = file.name
}

async function submit() {
  const run = selectedRun.value
  if (run === null || run.revision_id === null || !canSubmit.value) return
  saving.value = true
  error.value = ''
  try {
    const result = await payrollApi.attachJmhzExternalRoot({
      revision_id: run.revision_id,
      office: run.office_id,
      mode: mode.value,
      note: note.value.trim(),
      ...(mode.value === 'original_xml'
        ? { xml_base64: xmlBase64.value }
        : { submission_guid: submissionGuid.value.trim(), forms: filledForms.value, forms_confirmed: formsConfirmed.value }),
    }, props.environment)
    open.value = false
    await loadRoots()
    emit('open-submission', { submissionId: result.submission_id, period: result.period })
  } catch (e) {
    error.value = apiErrorMessage(e, t('payroll.jmhz_external_root.save_failed'))
  } finally {
    saving.value = false
  }
}

watch(() => props.environment, () => { void loadRoots() })
onMounted(() => { void loadRoots() })
</script>

<template>
  <section class="space-y-3 rounded-lg border border-neutral-200 p-4" data-test="jmhz-external-root">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h3 class="text-sm font-semibold text-neutral-900">{{ t('payroll.jmhz_external_root.title') }}</h3>
        <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.jmhz_external_root.intro') }}</p>
      </div>
      <button
        v-if="canWrite"
        type="button"
        :class="btnOutline()"
        data-test="jmhz-external-root-open"
        @click="openDialog"
      >
        {{ t('payroll.jmhz_external_root.open') }}
      </button>
    </div>
    <p v-if="loadError" class="text-sm text-red-700">{{ loadError }}</p>
    <ul v-if="roots.length" class="divide-y divide-neutral-100 text-sm" data-test="jmhz-external-root-list">
      <li v-for="root in roots" :key="root.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
        <div class="min-w-0">
          <span class="font-medium text-neutral-900">{{ formatPeriod(root.period) }}</span>
          <span class="ml-2 text-xs text-neutral-600">{{ t(`payroll.jmhz_external_root.mode.${root.mode}`) }}</span>
          <span class="ml-2 font-mono text-xs text-neutral-500">{{ root.submission_guid }}</span>
          <span class="ml-2 text-xs text-neutral-600">{{ t('payroll.jmhz_external_root.forms', { count: root.form_count }) }}</span>
        </div>
        <button
          type="button"
          class="text-xs font-medium text-primary-700 hover:underline"
          :data-test="`jmhz-external-root-show-${root.id}`"
          @click="emit('open-submission', { submissionId: root.submission_id, period: root.period })"
        >
          {{ t('payroll.jmhz_external_root.show') }}
        </button>
      </li>
    </ul>

    <Modal
      v-if="open"
      :title="t('payroll.jmhz_external_root.dialog_title')"
      width-class="max-w-2xl"
      @close="open = false"
    >
      <div class="space-y-4 text-sm text-neutral-700" data-test="jmhz-external-root-dialog">
        <p>{{ t('payroll.jmhz_external_root.dialog_intro') }}</p>
        <label class="block">
          <span class="font-medium text-neutral-900">{{ t('payroll.jmhz_external_root.run') }}</span>
          <select v-model="runId" class="mt-1 w-full rounded-md border border-neutral-300 px-2 py-1.5" data-test="jmhz-external-root-run">
            <option :value="null">{{ t('payroll.jmhz_external_root.run_placeholder') }}</option>
            <option v-for="run in runs" :key="run.id" :value="run.id">
              {{ formatPeriod(run.period_start.slice(0, 7)) }} · {{ t('payroll.jmhz_external_root.revision', { no: run.revision_no }) }}
            </option>
          </select>
        </label>
        <fieldset class="space-y-1">
          <legend class="font-medium text-neutral-900">{{ t('payroll.jmhz_external_root.mode_label') }}</legend>
          <label class="flex items-start gap-2">
            <input v-model="mode" type="radio" value="original_xml" data-test="jmhz-external-root-mode-xml">
            <span>{{ t('payroll.jmhz_external_root.mode_xml_hint') }}</span>
          </label>
          <label class="flex items-start gap-2">
            <input v-model="mode" type="radio" value="guid_reference" data-test="jmhz-external-root-mode-guids">
            <span>{{ t('payroll.jmhz_external_root.mode_guids_hint') }}</span>
          </label>
        </fieldset>
        <label v-if="mode === 'original_xml'" class="block">
          <span class="font-medium text-neutral-900">{{ t('payroll.jmhz_external_root.file') }}</span>
          <input type="file" accept=".xml,application/xml,text/xml" class="mt-1 block w-full text-sm" data-test="jmhz-external-root-file" @change="pickFile">
          <span v-if="xmlName" class="text-xs text-neutral-600">{{ xmlName }}</span>
        </label>
        <template v-else>
          <label class="block">
            <span class="font-medium text-neutral-900">{{ t('payroll.jmhz_external_root.submission_guid') }}</span>
            <input v-model="submissionGuid" type="text" class="mt-1 w-full rounded-md border border-neutral-300 px-2 py-1.5 font-mono" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" data-test="jmhz-external-root-guid">
          </label>
          <p v-if="candidatesLoading" class="text-xs text-neutral-500">{{ t('payroll.jmhz_external_root.loading') }}</p>
          <table v-else-if="candidates" class="w-full text-xs" data-test="jmhz-external-root-forms">
            <thead>
              <tr class="text-left text-neutral-500">
                <th class="py-1 pr-2">{{ t('payroll.jmhz_external_root.employee') }}</th>
                <th class="py-1 pr-2">{{ t('payroll.jmhz_external_root.id_ppv') }}</th>
                <th class="py-1">{{ t('payroll.jmhz_external_root.form_guid') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in candidates.employments" :key="row.employment_id">
                <td class="py-1 pr-2">{{ row.employee_name ?? `#${row.employee_id}` }}</td>
                <td class="py-1 pr-2 font-mono">{{ row.employment_external_identifier }}</td>
                <td class="py-1">
                  <input
                    v-model="formGuids[row.employment_id]"
                    type="text"
                    class="w-full rounded border border-neutral-300 px-1.5 py-1 font-mono"
                    :data-test="`jmhz-external-root-form-${row.employment_id}`"
                  >
                </td>
              </tr>
            </tbody>
          </table>
          <label class="flex items-start gap-2">
            <input v-model="formsConfirmed" type="checkbox" data-test="jmhz-external-root-confirm">
            <span>{{ t('payroll.jmhz_external_root.confirm') }}</span>
          </label>
        </template>
        <label class="block">
          <span class="font-medium text-neutral-900">{{ t('payroll.jmhz_external_root.note') }}</span>
          <textarea v-model="note" rows="2" class="mt-1 w-full rounded-md border border-neutral-300 px-2 py-1.5" :placeholder="t('payroll.jmhz_external_root.note_placeholder')" data-test="jmhz-external-root-note" />
        </label>
        <p v-if="error" class="text-sm text-red-700" data-test="jmhz-external-root-error">{{ error }}</p>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2">
          <button type="button" :class="btnOutline()" @click="open = false">{{ t('common.cancel') }}</button>
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="!canSubmit"
            data-test="jmhz-external-root-submit"
            @click="submit"
          >
            {{ t('payroll.jmhz_external_root.submit') }}
          </button>
        </div>
      </template>
    </Modal>
  </section>
</template>

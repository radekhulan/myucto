<script setup lang="ts">
import { computed, onMounted, reactive, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  accountingApi,
  type ChartAccount,
  type CostCenter,
  type JournalSide,
  type JournalTemplateDetail,
  type JournalTemplateLinePayload,
  type JournalTemplateSummary,
} from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'

const props = defineProps<{
  embedded?: boolean
  initialDraft?: {
    description?: string
    source?: 'invoices' | 'purchase-invoices'
    docId?: number
    lines?: Array<{ account_code: string; side: JournalSide; amount: number | null; cost_center?: string | null }>
  }
}>()
const emit = defineEmits<{ close: []; saved: [] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const templates = ref<JournalTemplateSummary[]>([])
const accounts = ref<ChartAccount[]>([])
const costCenters = ref<CostCenter[]>([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const editingId = ref<number | null>(null)
const pageId = useId()

interface EditableLine {
  account_code: string
  side: JournalSide
  default_amount: number | null
  label: string
  cost_center: string
}

const form = reactive({
  name: '',
  description: '',
  lines: [] as EditableLine[],
})

const pickable = computed(() =>
  accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

function emptyLine(): EditableLine {
  return { account_code: '', side: 'debit', default_amount: null, label: '', cost_center: '' }
}

async function loadTemplates() {
  loading.value = true
  error.value = ''
  try {
    templates.value = await accountingApi.listJournalTemplates()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  if (props.initialDraft) {
    startNew()
    form.name = props.initialDraft.description || ''
    loading.value = true
    try {
      const [loadedAccounts, loadedCenters] = await Promise.all([accountingApi.listAccounts(), accountingApi.listCostCenters()])
      accounts.value = loadedAccounts
      costCenters.value = loadedCenters
      let sourceLines = props.initialDraft.lines || []
      if (props.initialDraft.source && props.initialDraft.docId) {
        const preview = await accountingApi.postingPreview(props.initialDraft.source, props.initialDraft.docId)
        const actual = preview.already_posted ? (await accountingApi.getEntry(preview.already_posted)).lines : preview.lines
        if (actual.some(line => !line.account_code)) throw new Error(t('accounting.templates.account_required'))
        sourceLines = actual.map(line => ({ ...line, account_code: line.account_code! }))
      }
      if (sourceLines.length) form.lines = sourceLines.map(line => ({ ...emptyLine(), account_code: line.account_code, side: line.side, cost_center: line.cost_center || '' }))
    } catch (e: any) {
      error.value = e?.response?.data?.error?.message || t('common.error')
    } finally { loading.value = false }
    return
  }
  await Promise.all([
    loadTemplates(),
    accountingApi.listAccounts().then(v => { accounts.value = v }).catch(() => { accounts.value = [] }),
    accountingApi.listCostCenters().then(v => { costCenters.value = v }).catch(() => { costCenters.value = [] }),
  ])
})

function startNew() {
  editingId.value = null
  form.name = ''
  form.description = ''
  form.lines = [emptyLine(), { ...emptyLine(), side: 'credit' }]
  error.value = ''
}

async function editTemplate(tpl: JournalTemplateSummary) {
  error.value = ''
  try {
    const detail = await accountingApi.getJournalTemplate(tpl.id)
    fillForm(detail)
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}

function fillForm(detail: JournalTemplateDetail) {
  editingId.value = detail.id
  form.name = detail.name
  form.description = detail.description ?? ''
  form.lines = detail.lines.map(line => ({
    account_code: line.account_code,
    side: line.side,
    default_amount: line.default_amount,
    label: line.label ?? '',
    cost_center: line.cost_center ?? '',
  }))
}

function addLine() { form.lines.push(emptyLine()) }
function removeLine(index: number) { if (form.lines.length > 1) form.lines.splice(index, 1) }
function cancelEdit() {
  editingId.value = null; form.name = ''; form.description = ''; form.lines = []
  if (props.initialDraft) emit('close')
}

function nullableAmount(value: unknown): number | null {
  return value === '' || value === null || value === undefined ? null : Number(value)
}

async function saveTemplate() {
  if (loading.value || saving.value) return
  error.value = ''
  if (!form.name.trim()) { error.value = t('accounting.manual.template.name_required'); return }
  if (form.lines.some(line => !line.account_code.trim())) {
    error.value = t('accounting.templates.account_required')
    return
  }

  const lines: JournalTemplateLinePayload[] = form.lines.map(line => ({
    account_code: line.account_code.trim(),
    side: line.side,
    amount: nullableAmount(line.default_amount),
    label: line.label.trim() || null,
    cost_center: line.cost_center.trim() || null,
  }))
  const payload = {
    name: form.name.trim(),
    description: form.description.trim() || null,
    lines,
  }

  saving.value = true
  try {
    if (editingId.value === null) {
      await accountingApi.createJournalTemplate(payload)
    } else {
      await accountingApi.updateJournalTemplate(editingId.value, payload)
    }
    toast.success(t('accounting.templates.saved'))
    if (props.initialDraft) emit('saved')
    else { cancelEdit(); await loadTemplates() }
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    saving.value = false
  }
}

async function deleteTemplate(tpl: JournalTemplateSummary) {
  if (!confirm(t('accounting.manual.template.delete_confirm', { name: tpl.name }))) return
  error.value = ''
  try {
    await accountingApi.deleteJournalTemplate(tpl.id)
    if (editingId.value === tpl.id) cancelEdit()
    toast.success(t('common.deleted'))
    await loadTemplates()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  }
}
</script>

<template>
  <div>
    <template v-if="!initialDraft">
    <div v-if="!embedded" class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('accounting.templates.title') }}</h1>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h2 v-if="embedded" class="text-xl font-semibold">{{ t('accounting.templates.title') }}</h2>
        <p class="text-sm text-neutral-500 mt-1">{{ t('accounting.templates.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('accounting.templates')" type="button" @click="startNew" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('accounting.templates.new') }}
      </button>
    </div>

    <div v-if="error" class="mb-4 rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-sm text-danger-600">{{ error }}</div>
    <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="templates.length === 0" boxed icon="doc"
      :title="t('accounting.manual.template.empty')"
      :cta="auth.canWrite('accounting.templates') ? t('accounting.templates.new') : undefined"
      @action="startNew" />
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-600">
          <tr>
            <th class="px-4 py-3 text-left font-medium">{{ t('accounting.manual.template.name') }}</th>
            <th class="px-4 py-3 text-left font-medium">{{ t('accounting.manual.description') }}</th>
            <th class="px-4 py-3 text-right font-medium">{{ t('accounting.templates.lines_count') }}</th>
            <!-- Sloupec akcí si musí říct o šířku, jinak ji celou spolyká Popis
                 a tři tlačítka se zalomí na dva řádky i na širokém monitoru. -->
            <th class="px-4 py-3 text-right font-medium w-80">{{ t('common.actions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="tpl in templates" :key="tpl.id">
            <td class="px-4 py-3 font-medium text-neutral-800">
              {{ tpl.name }}
              <span v-if="tpl.is_seeded" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-primary-100 text-primary-700">{{ t('accounting.manual.template.recommended') }}</span>
            </td>
            <td class="px-4 py-3 text-neutral-500">{{ tpl.description || '—' }}</td>
            <td class="px-4 py-3 text-right text-neutral-500">{{ tpl.line_count }}</td>
            <td class="px-4 py-3">
              <div class="flex flex-wrap justify-end gap-2">
                <RouterLink :to="{ path: '/accounting/journal/new', query: { template_id: String(tpl.id) } }" :class="btnOutlineSm('primary')">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  {{ t('accounting.templates.use') }}
                </RouterLink>
                <button v-if="auth.canWrite('accounting.templates')" type="button" @click="editTemplate(tpl)" :class="btnOutlineSm('neutral')">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  {{ t('common.edit') }}
                </button>
                <button v-if="auth.canWrite('accounting.templates')" type="button" @click="deleteTemplate(tpl)" :class="btnOutlineSm('danger')">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  {{ t('common.delete') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    </template>
    <datalist :id="`${pageId}-journal-template-cost-centers`">
      <option v-for="center in costCenters" :key="center.id" :value="center.code">{{ center.code }} / {{ center.name }}</option>
    </datalist>
    <Modal v-if="auth.canWrite('accounting.templates') && form.lines.length"
      :title="editingId === null ? t('accounting.templates.new') : t('accounting.templates.edit')"
      widthClass="max-w-4xl" @close="cancelEdit">
      <div class="space-y-4">
      <p v-if="initialDraft" class="text-sm text-neutral-600">{{ t('accounting.template.journal_hint') }}</p>
      <p v-if="error" role="alert" class="rounded-md bg-danger-50 p-3 text-sm text-danger-600">{{ error }}</p>
      <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.template.name') }}</label>
          <input v-model="form.name" type="text" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.description') }}</label>
          <input v-model="form.description" type="text" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>

      <div>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <label class="text-sm font-medium text-neutral-700">{{ t('accounting.manual.lines') }}</label>
          <button type="button" @click="addLine" :class="btnOutlineSm('neutral')">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('accounting.manual.add_line') }}
          </button>
        </div>
        <div class="space-y-2">
          <div v-for="(line, index) in form.lines" :key="index" class="grid grid-cols-12 gap-2 items-start">
            <div class="col-span-12 sm:col-span-6 min-w-0">
              <ChartAccountSelect v-model="line.account_code" :accounts="pickable" :disabled="loading" :aria-label="t('accounting.manual.account_placeholder')" />
            </div>
            <select v-model="line.side" :aria-label="t('accounting.template.side')" class="col-span-4 sm:col-span-2 h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="debit">{{ t('accounting.journal.side.debit') }}</option>
              <option value="credit">{{ t('accounting.journal.side.credit') }}</option>
            </select>
            <input v-model.number="line.default_amount" type="number" min="0" step="0.01" :placeholder="t('accounting.templates.default_amount')" class="col-span-6 sm:col-span-3 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
            <button type="button" @click="removeLine(index)" :disabled="form.lines.length <= 1" class="col-span-2 sm:col-span-1 h-9 inline-flex items-center justify-center text-danger-500 hover:text-danger-600 disabled:opacity-30" :title="t('accounting.manual.remove_line')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            </button>
            <input v-model="line.label" type="text" maxlength="255" :placeholder="t('accounting.templates.line_label')" class="col-span-12 sm:col-span-6 h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            <input v-model="line.cost_center" :list="`${pageId}-journal-template-cost-centers`" type="text" maxlength="50" :placeholder="t('accounting.manual.cost_center')" class="col-span-12 sm:col-span-6 h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
      </div>

      <div class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-3">
        <button type="button" @click="cancelEdit" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" @click="saveTemplate" :disabled="saving || loading" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
      </div>
    </Modal>
  </div>
</template>

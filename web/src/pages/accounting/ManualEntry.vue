<script setup lang="ts">
import { ref, onMounted, reactive, computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import {
  accountingApi,
  type ChartAccount, type CostCenter, type JournalSide, type ManualLinePayload,
  type JournalTemplateSummary, type JournalTemplateLinePayload,
  type LinkCandidate, type JournalLinkPayload,
} from '@/api/accounting'
import { transferApi } from '@/api/closing'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import DocumentLinkPicker from '@/components/accounting/DocumentLinkPicker.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const pageId = useId()

const accounts = ref<ChartAccount[]>([])
const costCenters = ref<CostCenter[]>([])
const saving = ref(false)
const error = ref('')

interface LineRow {
  account_code: string
  side: JournalSide
  amount: number | null
  cost_center: string
}

function emptyLine(side: JournalSide = 'debit'): LineRow {
  return { account_code: '', side, amount: null, cost_center: '' }
}

const form = reactive({
  entry_date: appIsoDate(),
  description: '',
  document_no: '',
})
const lines = ref<LineRow[]>([emptyLine('debit'), emptyLine('credit')])

/**
 * Doklady, se kterými zápis souvisí (migrace 1514).
 *
 * Ukládají se AŽ SE ZÁPISEM, jedním requestem: neplatná vazba pak nenechá
 * v deníku zápis, o kterém si uživatel myslí, že doklad nese. Se `source_type`
 * zápisu to nemá nic společného — ten zůstává 'manual' se source_id NULL,
 * jinak by zápis kolidoval se skutečným zaúčtováním dokladu.
 */
interface PickedLink extends LinkCandidate { note: string }
const pickedLinks = ref<PickedLink[]>([])
const pickedKeys = computed(() => pickedLinks.value.map(l => `${l.doc_type}:${l.doc_id}`))

function addPickedLink(c: LinkCandidate) {
  if (pickedKeys.value.includes(`${c.doc_type}:${c.doc_id}`)) return
  pickedLinks.value.push({ ...c, note: '' })
}
function removePickedLink(i: number) { pickedLinks.value.splice(i, 1) }
function docTypeLabel(type: string): string {
  const key = `accounting.journal.source.${type}`
  const v = t(key)
  return v === key ? type : v
}

// Jen aktivní, neaktivní se v novém zápisu nenabízí; syntetika i analytika lze účtovat.
const pickable = computed(() =>
  accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)
const accountByCode = computed<Record<string, ChartAccount>>(() => {
  const m: Record<string, ChartAccount> = {}
  for (const a of accounts.value) m[a.account_code] = a
  return m
})

onMounted(async () => {
  await Promise.all([
    accountingApi.listAccounts().then(v => { accounts.value = v }).catch(() => { accounts.value = [] }),
    accountingApi.listCostCenters().then(v => { costCenters.value = v }).catch(() => { costCenters.value = [] }),
  ])

  // Kopie existujícího zápisu (Journal.vue akce „Kopírovat jako nový") — stejné
  // řádky/částky, dnešní datum (form.entry_date už je defaultně dnešek).
  const copyFrom = Number(route.query.copy_from || 0)
  if (copyFrom > 0) {
    try {
      const src = await accountingApi.getEntry(copyFrom)
      form.description = src.description ?? ''
      lines.value = src.lines.map(l => ({
        account_code: l.account_code ?? '',
        side: l.side,
        amount: l.amount,
        cost_center: l.cost_center ?? '',
      }))
      // Vazby na doklady jedou s kopií: opravný zápis se skoro vždy váže na tentýž
      // doklad jako ten původní. Doklad mezitím smazaný (bez popisu) se vynechá.
      pickedLinks.value = (src.links ?? [])
        .filter(l => l.document)
        .map(l => ({
          doc_type: l.doc_type,
          doc_id: l.doc_id,
          label: l.document!.title || `#${l.doc_id}`,
          sublabel: l.document!.subtitle,
          date: l.document!.date,
          amount: l.document!.amount,
          currency: l.document!.currency || 'CZK',
          note: l.note ?? '',
        }))
    } catch { /* neexistující/cizí zápis — pokračuje prázdný formulář */ }
    return
  }

  // Deep-link „Nový zápis z této šablony" (?template_id=)
  const templateId = Number(route.query.template_id || 0)
  if (templateId > 0) {
    await applyTemplate(templateId)
  }
})

function addLine() { lines.value.push(emptyLine()) }
function removeLine(i: number) { if (lines.value.length > 1) lines.value.splice(i, 1) }
const linesHaveAccounts = computed(() =>
  lines.value.length > 0 && lines.value.every(l => l.account_code.trim() !== ''))

const totalDebit = computed(() =>
  lines.value.filter(l => l.side === 'debit').reduce((s, l) => s + (Number(l.amount) || 0), 0),
)
const totalCredit = computed(() =>
  lines.value.filter(l => l.side === 'credit').reduce((s, l) => s + (Number(l.amount) || 0), 0),
)
// Zaokrouhlení na haléře kvůli plovoucí čárce.
const diff = computed(() => Math.round((totalDebit.value - totalCredit.value) * 100) / 100)
const balanced = computed(() => diff.value === 0 && totalDebit.value > 0)

const hasEmptyLine = computed(() =>
  lines.value.some(l => !l.account_code.trim() || !(Number(l.amount) > 0)),
)
const canSubmit = computed(() => balanced.value && !hasEmptyLine.value && !saving.value)

/**
 * @param andNew Po úspěchu nenaviguje do deníku, ale vyčistí řádky pro další zápis
 *   (datum a text zůstávají — typický případ: víc podobných interních dokladů za sebou).
 */
async function save(andNew = false) {
  if (blockDemoMutation()) return
  error.value = ''
  if (!form.entry_date) { error.value = t('accounting.manual.date_required'); return }
  if (hasEmptyLine.value) { error.value = t('accounting.manual.lines_incomplete'); return }
  if (!balanced.value) { error.value = t('accounting.manual.not_balanced'); return }

  const payloadLines: ManualLinePayload[] = lines.value.map(l => {
    const line: ManualLinePayload = {
      account_code: l.account_code.trim(),
      side: l.side,
      amount: Number(l.amount),
    }
    if (l.cost_center.trim()) line.cost_center = l.cost_center.trim()
    return line
  })

  const payloadLinks: JournalLinkPayload[] = pickedLinks.value.map(l => ({
    doc_type: l.doc_type,
    doc_id: l.doc_id,
    ...(l.note.trim() ? { note: l.note.trim() } : {}),
  }))

  saving.value = true
  try {
    const entry = await accountingApi.createEntry({
      entry_date: form.entry_date,
      description: form.description.trim() || undefined,
      document_no: form.document_no.trim() || undefined,
      lines: payloadLines,
      ...(payloadLinks.length ? { links: payloadLinks } : {}),
    })
    toast.success(t('accounting.manual.saved', { id: entry.id }))
    if (andNew) {
      form.document_no = ''
      lines.value = [emptyLine('debit'), emptyLine('credit')]
      pickedLinks.value = []
    } else {
      router.push('/accounting/journal')
    }
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    const localized = code ? t(`accounting.error.${code}`) : ''
    error.value = (localized && localized !== `accounting.error.${code}`)
      ? localized
      : (e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

// ── Uložit jako šablonu ─────────────────────────────────────────────────────
const showSaveTemplate = ref(false)
const templateSaving = ref(false)
const templateError = ref('')
const templateForm = reactive({ name: '', description: '', keepAmounts: false })
const templateLineLabels = ref<string[]>([])

function openSaveTemplate() {
  templateError.value = ''
  templateForm.name = ''
  templateForm.description = ''
  templateForm.keepAmounts = false
  templateLineLabels.value = lines.value.map(l => accountByCode.value[l.account_code]?.name ?? '')
  showSaveTemplate.value = true
}

async function submitSaveTemplate() {
  templateError.value = ''
  const name = templateForm.name.trim()
  if (!name) { templateError.value = t('accounting.manual.template.name_required'); return }

  const payloadLines: JournalTemplateLinePayload[] = lines.value.map((l, i) => ({
    account_code: l.account_code.trim(),
    side: l.side,
    amount: templateForm.keepAmounts && Number(l.amount) > 0 ? Number(l.amount) : null,
    label: templateLineLabels.value[i]?.trim() || undefined,
    cost_center: l.cost_center.trim() || undefined,
  }))

  templateSaving.value = true
  try {
    await accountingApi.createJournalTemplate({
      name,
      description: templateForm.description.trim() || undefined,
      lines: payloadLines,
    })
    toast.success(t('accounting.manual.template.saved'))
    showSaveTemplate.value = false
  } catch (e: any) {
    templateError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    templateSaving.value = false
  }
}

// ── Nový ze šablony ──────────────────────────────────────────────────────────
const showLoadTemplate = ref(false)
const templates = ref<JournalTemplateSummary[]>([])
const loadingTemplates = ref(false)
const selectedTemplateId = ref<number | ''>('')
const loadingTemplateDetail = ref(false)
const loadTemplateError = ref('')
const csvFile = ref<File | null>(null)
const csvFileInput = ref<HTMLInputElement | null>(null)
const csvImporting = ref(false)

async function openLoadTemplate() {
  loadTemplateError.value = ''
  selectedTemplateId.value = ''
  csvFile.value = null
  showLoadTemplate.value = true
  loadingTemplates.value = true
  try {
    templates.value = await accountingApi.listJournalTemplates()
  } catch {
    templates.value = []
  } finally {
    loadingTemplates.value = false
  }
}

/** Předvyplní řádky ManualEntry ze šablony; needitovatelné nic není — účet/strana/částka lze v gridu přepsat. */
async function applyTemplate(id: number): Promise<void> {
  loadingTemplateDetail.value = true
  loadTemplateError.value = ''
  try {
    const tpl = await accountingApi.getJournalTemplate(id)
    lines.value = tpl.lines.map(l => ({
      account_code: l.account_code,
      side: l.side,
      amount: l.default_amount,
      cost_center: l.cost_center ?? '',
    }))
    if (!form.description.trim()) form.description = tpl.name
    showLoadTemplate.value = false
  } catch (e: any) {
    loadTemplateError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loadingTemplateDetail.value = false
  }
}

function onCsvPick(e: Event) {
  const input = e.target as HTMLInputElement
  csvFile.value = input.files?.[0] ?? null
}

/** Napáruje CSV mzdovou rekapitulaci na řádky vybrané šablony a rovnou předvyplní grid. */
async function importTemplateCsv() {
  if (!selectedTemplateId.value || !csvFile.value) return
  loadTemplateError.value = ''
  csvImporting.value = true
  try {
    const result = await accountingApi.importJournalTemplateCsv(Number(selectedTemplateId.value), csvFile.value)
    lines.value = result.lines.map(l => ({
      account_code: l.account_code,
      side: l.side,
      amount: l.amount,
      cost_center: l.cost_center ?? '',
    }))
    const tpl = templates.value.find(t2 => t2.id === selectedTemplateId.value)
    if (tpl && !form.description.trim()) form.description = tpl.name
    showLoadTemplate.value = false
    if (result.unmatched.length > 0) {
      toast.warning(t('accounting.manual.template.csv_unmatched', { n: result.unmatched.length }))
    } else {
      toast.success(t('accounting.manual.template.csv_matched', { n: result.matched_count }))
    }
  } catch (e: any) {
    loadTemplateError.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    csvImporting.value = false
  }
}

async function deleteTemplate(tpl: JournalTemplateSummary) {
  if (!confirm(t('accounting.manual.template.delete_confirm', { name: tpl.name }))) return
  try {
    await accountingApi.deleteJournalTemplate(tpl.id)
    templates.value = templates.value.filter(x => x.id !== tpl.id)
    if (selectedTemplateId.value === tpl.id) selectedTemplateId.value = ''
  } catch (e: any) {
    loadTemplateError.value = e?.response?.data?.error?.message || t('common.error')
  }
}

// ── Převod mezi účty přes 261 — Peníze na cestě (F4 R14) ───────────────────
const showTransfer = ref(false)
const transferSaving = ref(false)
const transferError = ref('')
interface TransferCandidate {
  tx_id: number
  statement_id: number
  posted_at: string
  amount: number
  direction: 'out' | 'in'
}
const transferCandidates = ref<TransferCandidate[]>([])
const transfer = reactive({
  account_from: '',
  account_to: '',
  amount: null as number | null,
  date_out: appIsoDate(),
  date_in: appIsoDate(),
  description: '',
})

function openTransfer() {
  transferError.value = ''
  transferCandidates.value = []
  Object.assign(transfer, {
    account_from: '',
    account_to: '',
    amount: null,
    date_out: appIsoDate(),
    date_in: appIsoDate(),
    description: '',
  })
  showTransfer.value = true
}

async function submitTransfer(force = false) {
  transferError.value = ''
  const from = transfer.account_from.trim()
  const to = transfer.account_to.trim()
  if (!accountByCode.value[from] || !accountByCode.value[to]) {
    transferError.value = t('accounting.manual.unknown_code')
    return
  }
  if (from === to) {
    transferError.value = t('accounting.closing.transfer.same_accounts')
    return
  }
  if (!(Number(transfer.amount) > 0) || !transfer.date_out || !transfer.date_in) {
    transferError.value = t('accounting.closing.transfer.fields_required')
    return
  }
  transferSaving.value = true
  try {
    const result = await transferApi.create({
      date_out: transfer.date_out,
      date_in: transfer.date_in,
      amount: Number(transfer.amount),
      account_from: from,
      account_to: to,
      description: transfer.description.trim() || undefined,
      force: force || undefined,
    })
    const docs = (result.entries ?? []).map(e => e.document_no).filter(Boolean).join(', ')
    toast.success(docs
      ? t('accounting.closing.transfer.saved', { docs })
      : t('common.saved'))
    showTransfer.value = false
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'bank_transfer_candidates') {
      transferCandidates.value = e?.response?.data?.error?.data?.candidates ?? []
    }
    const key = `accounting.closing.errors.${code}`
    const localized = code ? t(key) : ''
    transferError.value = (localized && localized !== key)
      ? localized
      : (e?.response?.data?.error?.message || t('common.error'))
  } finally {
    transferSaving.value = false
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.manual.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.manual.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <button type="button" @click="openLoadTemplate" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          <span class="whitespace-nowrap">{{ t('accounting.manual.template.new_from') }}</span>
        </button>
        <button type="button" @click="openTransfer" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          <span class="whitespace-nowrap">{{ t('accounting.closing.transfer.title') }}</span>
        </button>
        <RouterLink to="/accounting/journal" class="text-sm text-neutral-500 hover:text-neutral-700 whitespace-nowrap">{{ t('common.back') }}</RouterLink>
      </div>
    </div>

    <datalist :id="`${pageId}-coa-options`">
      <option v-for="a in pickable" :key="a.id" :value="a.account_code">{{ a.account_code }} — {{ a.name }}</option>
    </datalist>
    <datalist :id="`${pageId}-cost-center-options`">
      <option v-for="center in costCenters" :key="center.id" :value="center.code">{{ center.code }} — {{ center.name }}</option>
    </datalist>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <!-- Hlavička -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.entry_date') }}</label>
          <DateInput v-model="form.entry_date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.document_no') }}</label>
          <input v-model="form.document_no" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.description') }}</label>
          <input v-model="form.description" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>

      <!-- Vazba na doklad (migrace 1514) — informativní, zápis zůstává ručním
           zápisem (source_type 'manual'), takže nekoliduje se zaúčtováním dokladu. -->
      <div class="border-t border-neutral-200 pt-4">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.journal.links.title') }}</label>
        <p class="text-xs text-neutral-500 mb-2">{{ t('accounting.journal.links.hint') }}</p>

        <ul v-if="pickedLinks.length" class="mb-2 divide-y divide-neutral-200 rounded-lg border border-neutral-200">
          <li v-for="(l, i) in pickedLinks" :key="`${l.doc_type}:${l.doc_id}`"
            class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 px-3 py-2 text-sm">
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="text-xs text-neutral-500">{{ docTypeLabel(l.doc_type) }}</span>
                <span class="font-medium">{{ l.label }}</span>
                <span v-if="l.date" class="text-xs text-neutral-500">{{ formatDate(l.date) }}</span>
                <span class="font-mono text-xs text-neutral-600">{{ formatMoney(l.amount ?? 0, l.currency || 'CZK') }}</span>
              </div>
              <p v-if="l.sublabel" class="mt-0.5 truncate text-xs text-neutral-500">{{ l.sublabel }}</p>
              <input v-model="l.note" type="text" maxlength="255"
                :placeholder="t('accounting.journal.links.note_placeholder')"
                class="mt-1.5 h-8 w-full rounded-md border border-neutral-300 px-2 text-xs" />
            </div>
            <button type="button" :class="btnOutlineSm('danger')" @click="removePickedLink(i)">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
              </svg>
              {{ t('accounting.journal.links.remove') }}
            </button>
          </li>
        </ul>

        <DocumentLinkPicker :excluded="pickedKeys" @select="addPickedLink" />
      </div>

      <!-- Řádky -->
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="text-sm font-medium text-neutral-700">{{ t('accounting.manual.lines') }}</label>
          <button @click="addLine" class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 font-medium">+ {{ t('accounting.manual.add_line') }}</button>
        </div>
        <div class="space-y-2">
          <div v-for="(l, i) in lines" :key="i" class="grid grid-cols-12 gap-2 items-start">
            <div class="col-span-12 sm:col-span-5">
              <input v-model="l.account_code" :list="`${pageId}-coa-options`" type="text"
                :placeholder="t('accounting.manual.account_placeholder')"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
              <div v-if="l.account_code && accountByCode[l.account_code]" class="text-xs text-neutral-500 mt-0.5 pl-1 truncate">
                {{ accountByCode[l.account_code].name }}
              </div>
              <div v-else-if="l.account_code" class="text-xs text-danger-500 mt-0.5 pl-1">{{ t('accounting.manual.unknown_code') }}</div>
            </div>
            <div class="col-span-4 sm:col-span-2">
              <select v-model="l.side" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="debit">{{ t('accounting.journal.side.debit') }}</option>
                <option value="credit">{{ t('accounting.journal.side.credit') }}</option>
              </select>
            </div>
            <div class="col-span-5 sm:col-span-2">
              <input v-model.number="l.amount" type="number" step="0.01" min="0" :placeholder="t('accounting.manual.amount')"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
            </div>
            <div class="col-span-2 sm:col-span-2">
              <input v-model="l.cost_center" :list="`${pageId}-cost-center-options`" type="text" maxlength="50" :placeholder="t('accounting.manual.cost_center')"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div class="col-span-1 flex items-center justify-center h-9">
              <button @click="removeLine(i)" :disabled="lines.length <= 1"
                class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed"
                :title="t('accounting.manual.remove_line')">✕</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Balance indikátor -->
      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-3">
        <div class="flex items-center gap-4 text-sm">
          <span class="text-neutral-500">{{ t('accounting.journal.side.debit') }}: <strong class="font-mono text-neutral-800">{{ formatMoney(totalDebit) }}</strong></span>
          <span class="text-neutral-500">{{ t('accounting.journal.side.credit') }}: <strong class="font-mono text-neutral-800">{{ formatMoney(totalCredit) }}</strong></span>
          <span class="px-2 py-0.5 rounded text-xs font-medium"
            :class="balanced ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
            {{ balanced ? t('accounting.manual.balanced') : t('accounting.manual.unbalanced', { diff: formatMoney(diff) }) }}
          </span>
        </div>
      </div>

      <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>

      <div class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-3">
        <button type="button" @click="openSaveTemplate" :disabled="!linesHaveAccounts" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          <span class="whitespace-nowrap">{{ t('accounting.manual.template.save_as') }}</span>
        </button>
        <RouterLink to="/accounting/journal" :class="btnOutline('neutral')">{{ t('common.cancel') }}</RouterLink>
        <button type="button" @click="save(true)" :disabled="!canSubmit" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          <span class="whitespace-nowrap">{{ t('accounting.manual.save_and_new') }}</span>
        </button>
        <button type="button" @click="save()" :disabled="!canSubmit" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('accounting.manual.post') }}
        </button>
      </div>
    </div>

    <!-- Modal: převod mezi účty (261) -->
    <div v-if="showTransfer" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('accounting.closing.transfer.title') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.closing.transfer.hint') }}</p>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.transfer.from') }}</label>
              <input v-model="transfer.account_from" :list="`${pageId}-coa-options`" type="text"
                :placeholder="t('accounting.manual.account_placeholder')"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <div v-if="transfer.account_from && accountByCode[transfer.account_from.trim()]"
                class="text-xs text-neutral-500 mt-0.5 truncate">{{ accountByCode[transfer.account_from.trim()].name }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.transfer.to') }}</label>
              <input v-model="transfer.account_to" :list="`${pageId}-coa-options`" type="text"
                :placeholder="t('accounting.manual.account_placeholder')"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <div v-if="transfer.account_to && accountByCode[transfer.account_to.trim()]"
                class="text-xs text-neutral-500 mt-0.5 truncate">{{ accountByCode[transfer.account_to.trim()].name }}</div>
            </div>
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.transfer.amount') }}</label>
              <input v-model.number="transfer.amount" type="number" step="0.01" min="0"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.transfer.date_out') }}</label>
              <DateInput v-model="transfer.date_out" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.transfer.date_in') }}</label>
              <DateInput v-model="transfer.date_in" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.description') }}</label>
            <input v-model="transfer.description" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div v-if="transferCandidates.length" class="rounded-md border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-700">
            <div class="flex items-start gap-2">
              <svg class="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
              <div class="min-w-0 flex-1">
                <p>{{ t('accounting.closing.transfer.bank_candidates_warning') }}</p>
                <ul class="mt-2 space-y-1">
                  <li v-for="candidate in transferCandidates" :key="candidate.tx_id" class="flex flex-wrap items-center gap-x-2">
                    <RouterLink :to="`/bank/${candidate.statement_id}`" class="font-medium underline hover:no-underline">
                      {{ formatDate(candidate.posted_at) }}
                    </RouterLink>
                    <span>{{ formatMoney(Math.abs(candidate.amount)) }}</span>
                  </li>
                </ul>
                <button type="button" @click="submitTransfer(true)" :disabled="transferSaving" :class="btnOutline('warning')" class="mt-3">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  {{ t('accounting.closing.transfer.force_submit') }}
                </button>
              </div>
            </div>
          </div>
          <div v-if="transferError" class="text-sm text-danger-500">{{ transferError }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="showTransfer = false" :class="btnOutline('neutral')">
              {{ t('common.cancel') }}
            </button>
            <button @click="submitTransfer()" :disabled="transferSaving" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ transferSaving ? t('common.saving') : t('accounting.closing.transfer.submit') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: uložit jako šablonu -->
    <div v-if="showSaveTemplate" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('accounting.manual.template.save_as') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.manual.template.save_hint') }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.template.name') }}</label>
            <input v-model="templateForm.name" type="text" maxlength="255" autofocus
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.description') }}</label>
            <input v-model="templateForm.description" type="text" maxlength="255"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="space-y-1.5">
            <label class="block text-sm font-medium text-neutral-700">{{ t('accounting.manual.template.line_labels') }}</label>
            <div v-for="(l, i) in lines" :key="i" class="flex items-center gap-2 text-sm">
              <span class="font-mono text-xs text-neutral-500 w-24 shrink-0 truncate">{{ l.account_code || '—' }} {{ t(`accounting.journal.side.${l.side}`) }}</span>
              <input v-model="templateLineLabels[i]" type="text" maxlength="255"
                :placeholder="t('accounting.manual.template.line_label_placeholder')"
                class="flex-1 h-8 px-2 border border-neutral-300 rounded-md text-xs" />
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="templateForm.keepAmounts" type="checkbox" class="rounded border-neutral-300" />
            {{ t('accounting.manual.template.keep_amounts') }}
          </label>
          <p class="text-xs text-neutral-400">{{ t('accounting.manual.template.keep_amounts_hint') }}</p>
          <div v-if="templateError" class="text-sm text-danger-500">{{ templateError }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="showSaveTemplate = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button type="button" @click="submitSaveTemplate" :disabled="templateSaving" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ templateSaving ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: nový ze šablony -->
    <div v-if="showLoadTemplate" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('accounting.manual.template.new_from') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.manual.template.load_hint') }}</p>

        <div v-if="loadingTemplates" class="text-center text-neutral-500 py-6 text-sm">{{ t('common.loading') }}</div>
        <EmptyState v-else-if="templates.length === 0" dense accent="neutral" icon="doc" :title="t('accounting.manual.template.empty')" />
        <div v-else class="space-y-3">
          <div class="border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-64 overflow-y-auto">
            <label v-for="tpl in templates" :key="tpl.id"
              class="flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-neutral-50"
              :class="{ 'bg-primary-50': selectedTemplateId === tpl.id }">
              <input v-model="selectedTemplateId" type="radio" :value="tpl.id" class="shrink-0" />
              <div class="min-w-0 flex-1">
                <div class="text-sm text-neutral-800 truncate">
                  {{ tpl.name }}
                  <span v-if="tpl.is_seeded" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-primary-100 text-primary-700">{{ t('accounting.manual.template.recommended') }}</span>
                </div>
                <div class="text-xs text-neutral-400 truncate">{{ tpl.description || '—' }} · {{ t('accounting.manual.template.line_count', { n: tpl.line_count }) }}</div>
              </div>
              <button type="button" @click.prevent.stop="deleteTemplate(tpl)"
                class="cursor-pointer text-neutral-300 hover:text-danger-500 shrink-0" :title="t('common.delete')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </label>
          </div>

          <div v-if="selectedTemplateId" class="border-t border-neutral-200 pt-3 space-y-2">
            <label class="block text-xs font-medium text-neutral-500">{{ t('accounting.manual.template.csv_import') }}</label>
            <p class="text-xs text-neutral-400">{{ t('accounting.manual.template.csv_hint') }}</p>
            <div class="flex flex-wrap items-center gap-2">
              <input ref="csvFileInput" type="file" accept=".csv,text/csv" class="hidden" @change="onCsvPick" />
              <button type="button" :class="btnOutline('neutral')" @click="csvFileInput?.click()">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 4v12m0-12-4 4m4-4 4 4" /></svg>
                {{ t('accounting.manual.template.csv_choose') }}
              </button>
              <span class="min-w-0 flex-1 truncate text-xs text-neutral-500">{{ csvFile?.name || t('accounting.manual.template.csv_none') }}</span>
              <button type="button" @click="importTemplateCsv" :disabled="!csvFile || csvImporting" :class="btnOutline('primary')">
                <span class="whitespace-nowrap">{{ csvImporting ? t('common.loading') : t('accounting.manual.template.csv_apply') }}</span>
              </button>
            </div>
          </div>

          <div v-if="loadTemplateError" class="text-sm text-danger-500">{{ loadTemplateError }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="showLoadTemplate = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button type="button" @click="selectedTemplateId && applyTemplate(Number(selectedTemplateId))"
              :disabled="!selectedTemplateId || loadingTemplateDetail" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ loadingTemplateDetail ? t('common.loading') : t('accounting.manual.template.load') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { journalAmount } from '@/utils/journalAmount'
import { ref, onMounted, reactive, computed, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import {
  accountingApi,
  type ChartAccount, type CostCenter, type JournalSide, type ManualLinePayload,
  type JournalTemplateSummary, type JournalTemplateLinePayload,
  type LinkCandidate, type JournalLinkPayload, type EntryFormHistoryRow, type ManualPairPayload,
} from '@/api/accounting'
import { transferApi } from '@/api/closing'
import { documentsApi } from '@/api/documents'
import type { AiManualDraft } from '@/api/aiAssistant'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import DocumentLinkPicker from '@/components/accounting/DocumentLinkPicker.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import JournalPairsEditor, { type PairEditorRow } from '@/components/accounting/JournalPairsEditor.vue'
import { apiErrorInfo, apiErrorText, entryFormAt, linesToPairs, pairsToLines } from '@/utils/entryForm'
import { compactDimensions, type DimensionMap } from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'

const { t } = useI18n()
const dims = useDimensions()
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
  is_red_storno?: boolean
  account_code: string
  side: JournalSide
  amount: number | null
  cost_center: string
  /** Dimenze řádku (Firma → Dimenze) — typ → hodnota. */
  dimensions: DimensionMap
}

function emptyLine(side: JournalSide = 'debit'): LineRow {
  return { account_code: '', side, amount: null, cost_center: '', dimensions: {} }
}

const form = reactive({
  entry_date: appIsoDate(),
  description: '',
  document_no: '',
})
const lines = ref<LineRow[]>([emptyLine('debit'), emptyLine('credit')])

// ── Forma zápisu (bod 6): jednoduchý zápis po dvojicích, složený po řádcích ──
// Forma se čte z historie firmy k datu zápisu; firma bez volby (složená) nevidí nic nového.
interface PairRow extends PairEditorRow { dimensions: DimensionMap }
function emptyPair(): PairRow {
  return { debit_account_code: '', credit_account_code: '', amount: null, line_text: '', cost_center: '', dimensions: {} }
}
const entryFormHistory = ref<EntryFormHistoryRow[]>([])
const entryForm = computed(() => entryFormAt(entryFormHistory.value, form.entry_date))
const isSingle = computed(() => entryForm.value === 'single')
const pairs = ref<PairRow[]>([emptyPair()])
const pairErrors = ref<Record<number, string>>({})
const pairsEditor = ref<InstanceType<typeof JournalPairsEditor> | null>(null)

function pairsFromLines(src: LineRow[]): PairRow[] | null {
  const drafts = linesToPairs(src.map(l => ({ account_code: l.account_code, side: l.side, amount: l.amount, is_red_storno: l.is_red_storno })))
  if (!drafts) return null
  return drafts.map(d => ({ ...emptyPair(), ...d }))
}

// Změna data přes hranici formy (např. před datum přepnutí) převede rozepsané řádky
// na dvojice a zpět, kde je převod jednoznačný; jinak se začne prázdnou verzí.
watch(isSingle, (single) => {
  pairErrors.value = {}
  if (single) {
    if (lines.value.every(l => !l.account_code.trim() && l.amount == null)) { pairs.value = [emptyPair()]; return }
    const converted = pairsFromLines(lines.value)
    if (converted) pairs.value = converted
    else { pairs.value = [emptyPair()]; toast.warning(t('accounting.pairs.manual.lines_not_pairable')) }
  } else {
    const filled = pairs.value.filter(p => p.debit_account_code.trim() || p.credit_account_code.trim() || p.amount != null)
    lines.value = filled.length
      ? pairsToLines(filled.map(p => ({ debit_account_code: p.debit_account_code, credit_account_code: p.credit_account_code, amount: Number(p.amount) || 0, is_red_storno: p.is_red_storno })))
        .map(l => ({ ...emptyLine(l.side), account_code: l.account_code, amount: l.amount, is_red_storno: l.is_red_storno }))
      : [emptyLine('debit'), emptyLine('credit')]
  }
})
/** Dokumenty (Dokumenty → soubory), které se na zápis z AI návrhu navážou po uložení. */
const aiDraftDocumentIds = ref<number[]>([])

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
    accountingApi.getEntryForm().then(v => { entryFormHistory.value = v.history }).catch(() => { entryFormHistory.value = [] }),
  ])

  // Návrh z AI asistenta („Upravit v ručním zápisu") jede v history.state,
  // ne v URL — částky a popis nemají co dělat v adrese ani v historii prohlížeče.
  const aiDraft = (window.history.state as { aiDraft?: AiManualDraft } | null)?.aiDraft
  if (aiDraft && Array.isArray(aiDraft.lines) && aiDraft.lines.length) {
    if (aiDraft.entry_date) form.entry_date = aiDraft.entry_date
    form.description = aiDraft.description ?? ''
    form.document_no = aiDraft.document_no ?? ''
    lines.value = aiDraft.lines.map(l => ({
      ...emptyLine(l.side === 'credit' ? 'credit' : 'debit'),
      account_code: String(l.account_code ?? ''),
      amount: Number(l.amount) || null,
    }))
    aiDraftDocumentIds.value = (aiDraft.document_ids ?? []).filter(id => Number.isInteger(id) && id > 0)
    if (isSingle.value) adoptLinesAsPairs()
    return
  }

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
        is_red_storno: 'is_red_storno' in l ? !!l.is_red_storno : false,
        cost_center: l.cost_center ?? '',
        dimensions: { ...(l.dimensions ?? {}) },
      }))
      if (isSingle.value) {
        if (src.pairs?.length) {
          pairs.value = src.pairs.map(p => ({
            ...emptyPair(),
            debit_account_code: p.debit_account_code,
            credit_account_code: p.credit_account_code,
            amount: p.amount,
            line_text: p.line_text ?? '',
            is_red_storno: !!p.is_red_storno,
          }))
        } else {
          adoptLinesAsPairs()
        }
      }
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

/**
 * Přílohy a poznámka se nahrají až po zaúčtování, stejnou cestou jako v detailu
 * zápisu (přílohy §33a, poznámky). Selhání zápis nevrací — ten už v deníku je.
 */
const NOTE_MAX_LENGTH = 5000
const pendingFiles = ref<File[]>([])
const note = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)

function addFiles(files: File[]) {
  for (const f of files) {
    if (!pendingFiles.value.some(p => p.name === f.name && p.size === f.size)) pendingFiles.value.push(f)
  }
}
function onPickFiles(e: Event) {
  const input = e.target as HTMLInputElement
  addFiles(input.files ? Array.from(input.files) : [])
  input.value = ''
}
function onDropFiles(e: DragEvent) {
  e.preventDefault()
  dragOver.value = false
  addFiles(Array.from(e.dataTransfer?.files ?? []))
}
function removeFile(i: number) { pendingFiles.value.splice(i, 1) }
function fmtBytes(n: number): string {
  return n < 1024 * 1024 ? `${Math.max(1, Math.round(n / 1024))} kB` : `${(n / 1024 / 1024).toFixed(1)} MB`
}

async function saveExtras(entryId: number) {
  const files = pendingFiles.value
  const body = note.value.trim()
  if (files.length) {
    try {
      await accountingApi.uploadJournalAttachments(entryId, files)
    } catch {
      toast.warning(t('accounting.manual.attachments_failed'))
    }
  }
  if (body) {
    try {
      await accountingApi.createJournalNote(entryId, body)
    } catch {
      toast.warning(t('accounting.manual.note_failed'))
    }
  }
  pendingFiles.value = []
  note.value = ''
}

/** Řádky načtené ze zdroje (AI návrh, kopie, šablona) převede na dvojice, kde je to jednoznačné. */
function adoptLinesAsPairs() {
  const converted = pairsFromLines(lines.value)
  if (converted) {
    pairs.value = converted
  } else {
    pairs.value = [emptyPair()]
    toast.warning(t('accounting.pairs.manual.lines_not_pairable'))
  }
}

function addLine() { lines.value.push(emptyLine()) }
function removeLine(i: number) { if (lines.value.length > 1) lines.value.splice(i, 1) }

const totalDebit = computed(() =>
  lines.value.filter(l => l.side === 'debit').reduce((s, l) => s + journalAmount(l), 0),
)
const totalCredit = computed(() =>
  lines.value.filter(l => l.side === 'credit').reduce((s, l) => s + journalAmount(l), 0),
)
// Zaokrouhlení na haléře kvůli plovoucí čárce.
const diff = computed(() => Math.round((totalDebit.value - totalCredit.value) * 100) / 100)
const balanced = computed(() => diff.value === 0 && lines.value.some(l => Number(l.amount) > 0))

const hasEmptyLine = computed(() =>
  lines.value.some(l => !l.account_code.trim() || !(Number(l.amount) > 0)),
)
const canSubmit = computed(() => isSingle.value
  ? !!pairsEditor.value?.complete && !saving.value
  : balanced.value && !hasEmptyLine.value && !saving.value)

/**
 * @param andNew Po úspěchu nenaviguje do deníku, ale vyčistí řádky pro další zápis
 *   (datum a text zůstávají — typický případ: víc podobných interních dokladů za sebou).
 */
async function save(andNew = false) {
  if (blockDemoMutation()) return
  error.value = ''
  pairErrors.value = {}
  if (!form.entry_date) { error.value = t('accounting.manual.date_required'); return }
  if (isSingle.value) {
    if (!pairsEditor.value?.complete) { error.value = t('accounting.pairs.manual.pairs_incomplete'); return }
  } else {
    if (hasEmptyLine.value) { error.value = t('accounting.manual.lines_incomplete'); return }
    if (!balanced.value) { error.value = t('accounting.manual.not_balanced'); return }
  }

  const payloadPairs: ManualPairPayload[] = isSingle.value ? pairs.value.map(p => {
    const pair: ManualPairPayload = {
      debit_account_code: p.debit_account_code.trim(),
      credit_account_code: p.credit_account_code.trim(),
      amount: Number(p.amount),
      is_red_storno: !!p.is_red_storno,
    }
    if (p.line_text.trim()) pair.line_text = p.line_text.trim()
    if (p.cost_center?.trim()) pair.cost_center = p.cost_center.trim()
    const pairDims = compactDimensions(p.dimensions)
    if (dims.enabled.value && Object.keys(pairDims).length > 0) pair.dimensions = pairDims
    return pair
  }) : []

  const payloadLines: ManualLinePayload[] = isSingle.value ? [] : lines.value.map(l => {
    const line: ManualLinePayload = {
      account_code: l.account_code.trim(),
      side: l.side,
      amount: Number(l.amount),
      is_red_storno: !!l.is_red_storno,
    }
    if (l.cost_center.trim()) line.cost_center = l.cost_center.trim()
    const lineDims = compactDimensions(l.dimensions)
    if (dims.enabled.value && Object.keys(lineDims).length > 0) line.dimensions = lineDims
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
      ...(isSingle.value ? { pairs: payloadPairs } : { lines: payloadLines }),
      ...(payloadLinks.length ? { links: payloadLinks } : {}),
    })
    toast.success(t('accounting.manual.saved', { id: entry.id }))
    await saveExtras(entry.id)
    if (aiDraftDocumentIds.value.length) {
      const docIds = aiDraftDocumentIds.value
      aiDraftDocumentIds.value = []
      const results = await Promise.allSettled(docIds.map(id => documentsApi.addLink(id, 'journal_entry', entry.id)))
      if (results.some(r => r.status === 'rejected')) toast.warning(t('accounting.ai_assistant.proposal.link_failed'))
    }
    for (const w of entry.dimension_warnings ?? []) {
      toast.warning(t('dimensions.rules.posting_warning', { message: w.message }))
    }
    if (andNew) {
      form.document_no = ''
      lines.value = [emptyLine('debit'), emptyLine('credit')]
      pairs.value = [emptyPair()]
      pickedLinks.value = []
    } else {
      router.push('/accounting/journal')
    }
  } catch (e: any) {
    const info = apiErrorInfo(e)
    // Chyba dvojice míří na řádek editoru (index = číslo dvojice - 1), ostatní do hlášky formuláře.
    if (isSingle.value && info.pairNo !== null && info.code.startsWith('pair_')) {
      pairErrors.value = { [info.pairNo - 1]: apiErrorText({ response: { data: { error: { code: info.code, message: info.message } } } }, t) }
    } else {
      error.value = apiErrorText(e, t, true)
    }
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

/** Řádky pro šablonu: ve formě jednoduchý zápis dvojice rozepsané na MD a Dal s číslem dvojice. */
interface TemplateSourceLine extends LineRow { pair_no?: number; line_text?: string }
const templateSource = computed<TemplateSourceLine[]>(() => isSingle.value
  ? pairs.value.flatMap((p, i) => (['debit', 'credit'] as const).map(side => ({
      ...emptyLine(side),
      account_code: side === 'debit' ? p.debit_account_code : p.credit_account_code,
      amount: p.amount,
      is_red_storno: p.is_red_storno,
      cost_center: p.cost_center ?? '',
      dimensions: p.dimensions,
      pair_no: i + 1,
      line_text: p.line_text,
    })))
  : lines.value)
const templateHasAccounts = computed(() =>
  templateSource.value.length > 0 && templateSource.value.every(l => l.account_code.trim() !== ''))

function openSaveTemplate() {
  templateError.value = ''
  templateForm.name = ''
  templateForm.description = ''
  templateForm.keepAmounts = false
  templateLineLabels.value = templateSource.value.map(l => accountByCode.value[l.account_code]?.name ?? '')
  showSaveTemplate.value = true
}

async function submitSaveTemplate() {
  templateError.value = ''
  if (templateSource.value.some(l => l.is_red_storno)) { templateError.value = t('accounting.journal.red_storno_template_unsupported'); return }
  const name = templateForm.name.trim()
  if (!name) { templateError.value = t('accounting.manual.template.name_required'); return }

  const payloadLines: JournalTemplateLinePayload[] = templateSource.value.map((l, i) => ({
    ...(l.pair_no ? { pair_no: l.pair_no, line_text: l.line_text?.trim() || null } : {}),
    account_code: l.account_code.trim(),
    side: l.side,
    amount: templateForm.keepAmounts && Number(l.amount) > 0 ? Number(l.amount) : null,
    label: templateLineLabels.value[i]?.trim() || undefined,
    cost_center: l.cost_center.trim() || undefined,
    ...(dims.enabled.value ? { dimensions: compactDimensions(l.dimensions) } : {}),
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

/**
 * Šablona po dvojicích (řádky s `pair_no`) naplní editor dvojic přímo; řádková šablona
 * se převede, kde je párování jednoznačné, jinak uživatel dvojice zadá sám.
 */
function applyTemplatePairs(tplLines: { pair_no?: number | null; line_text?: string | null; account_code: string; side: JournalSide; default_amount: number | null; cost_center: string | null; dimensions?: Record<number, number> }[]) {
  if (tplLines.length > 0 && tplLines.every(l => l.pair_no)) {
    const byNo = new Map<number, typeof tplLines>()
    for (const l of tplLines) byNo.set(l.pair_no!, [...(byNo.get(l.pair_no!) ?? []), l])
    const built: PairRow[] = []
    for (const [, group] of [...byNo.entries()].sort((a, b) => a[0] - b[0])) {
      const debit = group.find(l => l.side === 'debit')
      const credit = group.find(l => l.side === 'credit')
      if (!debit || !credit) { adoptLinesAsPairs(); return }
      built.push({
        ...emptyPair(),
        debit_account_code: debit.account_code,
        credit_account_code: credit.account_code,
        amount: debit.default_amount,
        line_text: debit.line_text ?? credit.line_text ?? '',
        cost_center: debit.cost_center ?? credit.cost_center ?? '',
        dimensions: { ...(debit.dimensions ?? credit.dimensions ?? {}) },
      })
    }
    pairs.value = built
    return
  }
  adoptLinesAsPairs()
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
      dimensions: { ...(l.dimensions ?? {}) },
    }))
    if (isSingle.value) applyTemplatePairs(tpl.lines)
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
        is_red_storno: 'is_red_storno' in l ? !!l.is_red_storno : false,
      cost_center: l.cost_center ?? '',
      dimensions: {},
    }))
    if (isSingle.value) adoptLinesAsPairs()
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
      <option v-for="a in pickable" :key="a.id" :value="a.account_code">{{ a.account_code }} - {{ a.name }}</option>
    </datalist>
    <datalist :id="`${pageId}-cost-center-options`">
      <option v-for="center in costCenters" :key="center.id" :value="center.code">{{ center.code }} - {{ center.name }}</option>
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

      <!-- Dvojice (jednoduchý zápis): Účet MD, Účet Dal, Částka, text. Zápis je vyrovnaný z definice. -->
      <div v-if="isSingle" data-test="manual-pairs">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
          <label class="text-sm font-medium text-neutral-700">{{ t('accounting.pairs.manual.pairs') }}</label>
          <span class="text-xs px-2 py-0.5 rounded bg-primary-50 text-primary-700">{{ t('accounting.pairs.settings.form_single') }}</span>
        </div>
        <p class="text-xs text-neutral-500 mb-2">{{ t('accounting.pairs.manual.hint') }}</p>
        <JournalPairsEditor ref="pairsEditor" v-model="pairs" :accounts="accounts" :errors="pairErrors"
          with-cost-center :cost-center-list-id="`${pageId}-cost-center-options`" list-id="manual-pairs-coa">
          <template #extra="{ pair }">
            <DimensionFields v-if="dims.enabled.value" v-model="(pair as PairRow).dimensions" compact class="col-span-12" />
          </template>
        </JournalPairsEditor>
      </div>

      <!-- Řádky -->
      <div v-else>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <label class="text-sm font-medium text-neutral-700">{{ t('accounting.manual.lines') }}</label>
          <button type="button" @click="addLine" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('accounting.manual.add_line') }}
          </button>
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
              <label class="mt-1 flex items-center gap-1 text-xs text-neutral-600">
                <input v-model="l.is_red_storno" type="checkbox" />
                {{ t('accounting.journal.red_storno') }}
              </label>
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
            <!-- Dimenze řádku (Firma → Dimenze) — jen id hodnot, nikdy jména osob. -->
            <DimensionFields v-if="dims.enabled.value" v-model="l.dimensions" compact class="col-span-12 sm:col-span-11" />
          </div>
        </div>
      </div>

      <!-- Balance indikátor -->
      <div v-if="!isSingle" class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-3">
        <div class="flex items-center gap-4 text-sm">
          <span class="text-neutral-500">{{ t('accounting.journal.side.debit') }}: <strong class="font-mono text-neutral-800">{{ formatMoney(totalDebit) }}</strong></span>
          <span class="text-neutral-500">{{ t('accounting.journal.side.credit') }}: <strong class="font-mono text-neutral-800">{{ formatMoney(totalCredit) }}</strong></span>
          <span class="px-2 py-0.5 rounded text-xs font-medium"
            :class="balanced ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
            {{ balanced ? t('accounting.manual.balanced') : t('accounting.manual.unbalanced', { diff: formatMoney(diff) }) }}
          </span>
        </div>
      </div>

      <!-- Přílohy a poznámka — nahrají se k zápisu hned po zaúčtování. -->
      <div class="border-t border-neutral-200 pt-4">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.journal.extras_title') }}</label>
        <p class="text-xs text-neutral-500 mb-2">{{ t('accounting.manual.extras_hint') }}</p>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
          <div>
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
              <span class="text-xs font-medium text-neutral-600">{{ t('accounting.journal.attachments') }}</span>
              <button type="button" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" @click="fileInput?.click()">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
                {{ t('accounting.journal.att_add') }}
              </button>
              <input ref="fileInput" type="file" multiple class="hidden" @change="onPickFiles" />
            </div>
            <div class="border-2 border-dashed rounded-lg px-3 py-3 text-center text-xs transition cursor-pointer"
              :class="dragOver ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-200 text-neutral-400'"
              @click="fileInput?.click()"
              @dragenter.prevent="dragOver = true" @dragover.prevent="dragOver = true"
              @dragleave.prevent="dragOver = false" @drop="onDropFiles">
              {{ t('accounting.journal.att_drop') }}
            </div>
            <ul v-if="pendingFiles.length" class="mt-2 space-y-1">
              <li v-for="(f, i) in pendingFiles" :key="`${f.name}:${f.size}`"
                class="flex items-center justify-between gap-3 px-2 py-1 rounded bg-neutral-50 text-sm">
                <span class="min-w-0 truncate">{{ f.name }}</span>
                <span class="flex shrink-0 items-center gap-2">
                  <span class="text-xs text-neutral-500 font-mono">{{ fmtBytes(f.size) }}</span>
                  <button type="button" class="cursor-pointer text-danger-500 hover:text-danger-600"
                    :title="t('accounting.manual.file_remove')" @click="removeFile(i)">✕</button>
                </span>
              </li>
            </ul>
          </div>
          <div>
            <span class="block text-xs font-medium text-neutral-600 mb-1">{{ t('accounting.journal.notes.title') }}</span>
            <textarea v-model="note" rows="3" :maxlength="NOTE_MAX_LENGTH"
              :placeholder="t('accounting.journal.notes.placeholder')"
              class="w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"></textarea>
          </div>
        </div>
      </div>

      <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>

      <div class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-3">
        <button type="button" @click="openSaveTemplate" :disabled="!templateHasAccounts" :class="btnOutline('neutral')">
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
            <div v-for="(l, i) in templateSource" :key="i" class="flex items-center gap-2 text-sm">
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

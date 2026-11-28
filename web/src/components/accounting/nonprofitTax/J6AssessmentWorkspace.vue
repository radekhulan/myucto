<script setup lang="ts">
import { computed, onMounted, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { nonprofitTaxJ6Api } from '@/api/nonprofitTaxJ6'
import type {
  J6ActivityRow, J6AssessmentHistoryPage, J6AssessmentSummary, J6CurrentSourceHeader, J6DecisionRow, J6DocumentFormat, J6DraftEnvelope,
  J6DraftOpenRequest, J6LegalProfileStatus, J6LinkRow, J6MemberRow, J6PoolKind, J6PoolRow, J6PreviewKind,
  J6PreviewPage, J6PreviewReceipt, J6ProfileKind, J6SourceRow, J6TaxDecision,
} from '@/api/nonprofitTaxJ6Types'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { formInput, formTextarea, formLabel } from '@/components/ui/formStyles'
import { centsText, parseCents } from '@/utils/nonprofitTaxDraft'
import J6SourceFindings from './J6SourceFindings.vue'

const props = defineProps<{ supplierId: number; periodId: number; canManage: boolean; reloadKey: number }>()
const { t, te, locale } = useI18n()
const PAGE_LIMIT = 100
const expenseTitles = ['cost', 'nonbusiness_cost', 'excluded_cost', 'withholding_interest_cost']
type Tab = 'source' | 'decisions' | 'activities' | 'pools' | 'links' | 'preview' | 'history'
type DecisionFact = { key: string; type: 'string' | 'number' | 'boolean'; value: string }

const loading = ref(false)
const busy = ref(false)
const error = ref('')
const stale = ref(false)
const current = ref<J6CurrentSourceHeader | null>(null)
const envelope = ref<J6DraftEnvelope | null>(null)
const history = ref<J6AssessmentHistoryPage>({ head: { assessment_id: null, revision_no: 0, status: null }, rows: [], after: null })
const latest = ref<J6AssessmentSummary | null>(null)
const historyAction = ref<{ kind: 'withdraw' | 'convert'; row: J6AssessmentSummary; key: string } | null>(null)
const historyReason = ref('')
const historyAcknowledged = ref(false)
const historyCursor = ref<string | null>(null)
const historyBack = ref<(string | null)[]>([])
const active = ref<Tab>('source')
const profileKind = ref<J6ProfileKind | ''>('')
const profileEvidence = ref('')
const entityKind = ref('')
const manualMethod = ref(false)
const methodBasis = ref('')
const methodEvidence = ref('')
const legalProfile = ref<J6LegalProfileStatus | null>(null)
// Stav profilu je načtený; bez něj nejde poznat, zda postavení převezme server.
const legalKnown = ref(false)

const sourceRows = ref<J6SourceRow[]>([])
const sourceFindings = ref<{ line_id: number; code: string }[]>([])
const sourceAfter = ref<string | null>(null)
const sourceCursor = ref<string | null>(null)
const sourceBack = ref<(string | null)[]>([])
const decisions = ref<J6DecisionRow[]>([])
const activities = ref<J6ActivityRow[]>([])
const pools = ref<J6PoolRow[]>([])
const links = ref<J6LinkRow[]>([])
const members = ref<J6MemberRow[]>([])
const memberAfter = ref<string | null>(null)
const memberCursor = ref<string | null>(null)
const memberBack = ref<(string | null)[]>([])
const collectionAfter = ref<string | null>(null)
const collectionCursor = ref<string | null>(null)
const collectionBack = ref<(string | null)[]>([])

const decision = ref<{ source_id: string; payload: J6TaxDecision }>({
  source_id: '',
  payload: { title: '', evidence: '', final_regime: '', final_method: '' },
})
const decisionFacts = ref<DecisionFact[]>([])
const decisionSource = ref<J6SourceRow | null>(null)
const deductibleAmount = ref('')
const deductibleError = ref('')
const deductibleErrorId = useId()
const activityDraft = ref({ activity_kind_id: '', evidence: '' })
const activityCohortCode = ref('')
const activityCohortEvidence = ref('')
const poolDraft = ref({ pool_kind: 'revenue' as J6PoolKind, anchor: '', evidence: '', review: false,
  reviewEvidence: '', reviewBasis: '', reviewAdjustment: 0 })
const linkDraft = ref({ source_id: '', cost_id: '', amount_cents: 0, deductible_funded_cents: 0, evidence: '' })
const memberPoolKind = ref<J6PoolKind>('revenue')
const memberAnchor = ref('')
const memberId = ref('')

const preview = ref<J6PreviewReceipt | null>(null)
const previewKind = ref<J6PreviewKind>('result')
const previewPage = ref<J6PreviewPage | null>(null)
const previewPageNo = ref(0)
const reason = ref('')
const acknowledged = ref(false)
const confirmRetry = ref<{ draftId: number; body: { expected_revision: number; expected_assessment_id: number | null; reason: string; idempotency_key: string } } | null>(null)
const selectedAssessment = ref<number | null>(null)
const frozenSummary = ref<Record<string, unknown> | null>(null)
const frozenRows = ref<Record<string, unknown>[]>([])
const frozenKind = ref<'source' | 'decision' | 'result' | 'coverage' | 'usage'>('source')
const frozenAfter = ref<string | null>(null)
const frozenCursor = ref<string | null>(null)
const frozenBack = ref<(string | null)[]>([])

const draft = computed(() => envelope.value?.draft ?? null)
const capabilities = computed(() => envelope.value?.capabilities ?? null)
const editable = computed(() => props.canManage && !!capabilities.value?.can_edit && !stale.value)
const decisionSourceRow = computed(() => {
  const id = decision.value.source_id
  return decisionSource.value?.id === id ? decisionSource.value : sourceRows.value.find(row => row.id === id)
})
const isExpenseDecision = computed(() => decisionSourceRow.value
  ? decisionSourceRow.value.kind === 'expense'
  : expenseTitles.includes(decision.value.payload.title.trim()))
const deductibleIsFixedZero = computed(() => isExpenseDecision.value
  && (decisionSourceRow.value?.mandatory_non_deductible || decision.value.payload.title.trim() === 'excluded_cost'))
watch([deductibleAmount, decisionSourceRow, () => decision.value.source_id, () => decision.value.payload.title], () => {
  deductibleError.value = ''
})
// S potvrzeným právním profilem DPPO převezme postavení i podklad server (J6LegalProfileMatch).
const profileLocked = computed(() => !!legalProfile.value?.current)
const legalState = computed(() => legalProfile.value?.state ?? null)
const methodValid = computed(() => !manualMethod.value || (methodBasis.value.trim().length > 0 && methodEvidence.value.trim().length > 0))
const profileValid = computed(() => (legalKnown.value || !props.canManage) && methodValid.value
  && (profileLocked.value || (!!profileKind.value && profileEvidence.value.trim().length > 0)))
const canPinLegalProfile = computed(() => !!draft.value && editable.value && !!legalProfile.value?.pinnable)
// Konflikt s modulem SVJ bez potvrzeného profilu: reset převezme jen postavení vybrané ve formuláři.
const svjModuleConflict = computed(() => legalState.value === 'conflict' && !legalProfile.value?.current)
const resetFixesConflict = computed(() => !svjModuleConflict.value
  || profileKind.value === 'svj' || (profileKind.value === 'documented' && entityKind.value === 'svj'))
// Profil, který nejde ověřit: server odmítne založení, reset, náhled i potvrzení (J6NonprofitTaxAction::legalState).
const legalUnavailable = computed(() => legalState.value === 'unavailable' || !!legalProfile.value?.error)
const legalUnavailableReason = computed(() => {
  const failure = legalProfile.value?.error
  if (!failure) return ''
  return te('nonprofitTaxJ6.legalProfile.errors.' + failure.code) ? t('nonprofitTaxJ6.legalProfile.errors.' + failure.code) : failure.message
})
const entityKindLabel = computed(() => profileLocked.value && entityKind.value ? t('nonprofitTaxJ6.profiles.' + entityKind.value) : entityKind.value)
const draftPostureLabel = computed(() => {
  const profile = draft.value?.header.profile
  const kind = profile?.kind === 'documented' ? (profile.entity_kind ?? 'documented') : (profile?.kind ?? 'documented')
  return t('nonprofitTaxJ6.profiles.' + kind)
})
const lockedRevision = computed(() => legalProfile.value?.pinned?.revision_no ?? legalProfile.value?.current?.revision_no ?? 0)
const knownErrors = new Set([
  'nonprofit_tax_period_locked', 'nonprofit_tax_draft_changed', 'nonprofit_tax_draft_source_not_captured',
  'nonprofit_tax_assessment_changed', 'nonprofit_tax_idempotency_conflict', 'nonprofit_tax_cursor_stale',
  'nonprofit_tax_legacy_history_requires_migration', 'nonprofit_tax_sources_incomplete',
  'nonprofit_tax_reconciliation_failed', 'nonprofit_tax_review_required', 'nonprofit_tax_gift_election_locked',
  'nonprofit_tax_facts_incomplete', 'nonprofit_tax_framework_unsupported', 'nonprofit_tax_preview_not_found',
  'nonprofit_tax_legal_profile_conflict', 'nonprofit_tax_legal_profile_mismatch', 'nonprofit_tax_legal_profile_missing',
])
// Generace hlášky (každá nová hláška) a generace stavu obrazovky (přehled, akce); obě zneplatní čekající obnovu profilu.
let errorGeneration = 0
let screenGeneration = 0
const legalProfileErrors = new Set(['nonprofit_tax_legal_profile_conflict', 'nonprofit_tax_legal_profile_mismatch', 'nonprofit_tax_legal_profile_missing'])

function codeOf(value: unknown): string | null {
  if (!value || typeof value !== 'object') return null
  const response = (value as { response?: { data?: unknown } }).response
  const data = response?.data
  if (!data || typeof data !== 'object') return null
  const errorValue = (data as { error?: unknown }).error
  if (typeof errorValue === 'string') return errorValue
  if (errorValue && typeof errorValue === 'object' && typeof (errorValue as { code?: unknown }).code === 'string') {
    return (errorValue as { code: string }).code
  }
  return typeof (data as { code?: unknown }).code === 'string' ? (data as { code: string }).code : null
}
function report(value: unknown) {
  const code = codeOf(value)
  if (code === 'nonprofit_tax_cursor_stale' || code === 'nonprofit_tax_draft_changed' || code === 'nonprofit_tax_assessment_changed') {
    stale.value = true
    invalidatePages()
  }
  if (code === 'nonprofit_tax_preview_not_found') {
    preview.value = null
    previewPage.value = null
    previewPageNo.value = 0
    acknowledged.value = false
  }
  const translated = code && knownErrors.has(code) ? t('nonprofitTaxJ6.errors.' + code) : undefined
  const message = code === 'nonprofit_tax_facts_incomplete' ? apiErrorMessage(value, translated) : translated ?? apiErrorMessage(value)
  // Nová hláška nahrazuje výsledky dřívějších obnov stavu profilu.
  const generation = ++errorGeneration
  // Profil se mezitím změnil (potvrzení v jiném okně): obnovit stav profilu a pole postavení.
  if (code && legalProfileErrors.has(code)) {
    const conflict = code === 'nonprofit_tax_legal_profile_conflict'
    // Konflikt vysvětlí box stavu profilu; chybový pruh jen tehdy, když se stav nepodaří obnovit.
    error.value = conflict ? '' : message
    void refreshLegalProfile().then((refreshed) => {
      // Mezitím přišla jiná hláška, přehled nebo akce: výsledek už nepatří k tomuto stavu obrazovky.
      if (generation !== errorGeneration || refreshed === 'discarded') return
      // U zastaralého konceptu radí pruh „obnovte přehled“; rada k resetu nebo opakování by mu odporovala.
      if (refreshed === 'stale') error.value = ''
      // Bez obnoveného stavu neplatí „formulář jsme obnovili“: rada obnovit přehled.
      else if (refreshed === 'failed') error.value = code === 'nonprofit_tax_legal_profile_mismatch' ? t('nonprofitTaxJ6.legalProfile.refreshFailed') : message
      else if (conflict && legalState.value !== 'conflict') error.value = message
    })
    return
  }
  error.value = message
}
/** Smaže hlášku na začátku akce nebo přehledu a zneplatní čekající obnovu stavu profilu. */
function clearError() {
  errorGeneration++
  screenGeneration++
  error.value = ''
}
function requestBody(): J6DraftOpenRequest {
  let body: J6DraftOpenRequest = {}
  if (!profileLocked.value) {
    if (!profileKind.value) throw new Error('nonprofitTaxJ6.profileRequired')
    const profile: NonNullable<J6DraftOpenRequest['profile']> = { kind: profileKind.value, evidence: profileEvidence.value.trim() }
    if (profileKind.value === 'documented' && entityKind.value.trim()) profile.entity_kind = entityKind.value.trim()
    body = { profile }
  }
  if (!manualMethod.value) return body
  const period = current.value?.header.period
  if (!period) throw new Error('nonprofitTaxJ6.periodRequired')
  return { ...body, method: { mode: 'documented_manual', valid_from: period.starts_on, valid_to: period.ends_on,
    legal_basis: methodBasis.value.trim(), evidence: methodEvidence.value.trim() } }
}
/**
 * Pole postavení. Se zamčeným profilem postavení, které převezme založení nebo reset konceptu
 * (postavení konceptu v konfliktu ukazuje box stavu profilu); doložení je z konceptu, v konfliktu
 * a bez konceptu prázdné (převezme se z profilu). Bez profilu podle konceptu; bez profilu
 * i konceptu zůstává rozepsaný ruční výběr.
 */
function syncProfileFields() {
  const header = draft.value?.header
  const legal = legalProfile.value?.current
  if (legal) {
    profileKind.value = manualMethod.value ? 'documented' : legal.kind
    entityKind.value = manualMethod.value ? legal.kind : ''
    profileEvidence.value = header && legalState.value !== 'conflict' ? header.profile.evidence : ''
    return
  }
  if (!header) return
  profileKind.value = header.profile.kind
  profileEvidence.value = header.profile.evidence
  entityKind.value = header.profile.entity_kind ?? ''
}
/**
 * Obnoví stav profilu po chybě akce. 'discarded' = během požadavku se změnila firma, období,
 * místní koncept nebo proběhl přehled či akce (výsledek patří jinému stavu); novější hlášku
 * chrání generace hlášek v report();
 * 'stale' = koncept na serveru změnila jiná operace.
 */
async function refreshLegalProfile(): Promise<'ok' | 'failed' | 'discarded' | 'stale'> {
  if (!props.canManage) return 'failed'
  const supplier = props.supplierId
  const period = props.periodId
  const draftKey = () => envelope.value ? envelope.value.draft.id + ':' + envelope.value.draft.revision_no : null
  const startedFor = draftKey()
  const startedGeneration = screenGeneration
  const changed = () => supplier !== props.supplierId || period !== props.periodId || draftKey() !== startedFor
    || screenGeneration !== startedGeneration
  try {
    const mine = await nonprofitTaxJ6Api.currentDraft(supplier, period)
    if (changed()) return 'discarded'
    // Stav profilu by patřil jiné hlavičce, než jakou obrazovka ukazuje.
    if (startedFor !== null && (mine.draft ? mine.draft.id + ':' + mine.draft.revision_no : null) !== startedFor) {
      stale.value = true
      return 'stale'
    }
    legalProfile.value = mine.legal_profile
    legalKnown.value = true
    if (envelope.value) envelope.value = { ...envelope.value, legal_profile: mine.legal_profile }
    if (!draft.value || profileLocked.value) syncProfileFields()
    return 'ok'
  } catch {
    // Chybu akce hlásí report(); obnova stavu profilu je jen doplněk.
    return changed() ? 'discarded' : 'failed'
  }
}
function invalidatePages() {
  sourceRows.value = []
  sourceFindings.value = []
  sourceAfter.value = null
  sourceCursor.value = null
  sourceBack.value = []
  decisions.value = []
  activities.value = []
  pools.value = []
  links.value = []
  members.value = []
  memberAfter.value = null
  memberCursor.value = null
  memberBack.value = []
  collectionAfter.value = null
  collectionCursor.value = null
  collectionBack.value = []
  preview.value = null
  previewPage.value = null
  previewPageNo.value = 0
  acknowledged.value = false
  frozenRows.value = []
  frozenAfter.value = null
  frozenCursor.value = null
  frozenBack.value = []
}
function adopt(next: J6DraftEnvelope) {
  envelope.value = next
  legalProfile.value = next.legal_profile
  legalKnown.value = true
  stale.value = false
  confirmRetry.value = null
  invalidatePages()
}
function idempotencyKey() {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) return crypto.randomUUID()
  return 'j6-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2)
}

let loadedContext = ''
async function loadOverview() {
  const supplier = props.supplierId
  const period = props.periodId
  // Jiná firma nebo období: rozepsané postavení (třeba převzaté z profilu předchozího období) nepatří sem.
  // Návrat do téže záložky (KeepAlive) rozepsaný ruční výběr zachová.
  if (loadedContext !== supplier + ':' + period) {
    loadedContext = supplier + ':' + period
    profileKind.value = ''
    profileEvidence.value = ''
    entityKind.value = ''
    manualMethod.value = false
    methodBasis.value = ''
    methodEvidence.value = ''
  }
  loading.value = true
  clearError()
  stale.value = false
  envelope.value = null
  decisionSource.value = null
  invalidatePages()
  historyCursor.value = null
  historyBack.value = []
  historyAction.value = null
  legalProfile.value = null
  legalKnown.value = false
  try {
    const [summary, firstHistory, mine] = await Promise.all([
      nonprofitTaxJ6Api.periodSummary(supplier, period),
      nonprofitTaxJ6Api.history(supplier, period, null, PAGE_LIMIT),
      props.canManage ? nonprofitTaxJ6Api.currentDraft(supplier, period) : Promise.resolve(null),
    ])
    if (supplier !== props.supplierId || period !== props.periodId) return
    current.value = summary.source
    latest.value = summary.latest
    history.value = firstHistory
    legalProfile.value = mine?.legal_profile ?? null
    legalKnown.value = !!mine
    if (mine?.draft && mine.capabilities) {
      adopt({ draft: mine.draft, capabilities: mine.capabilities, legal_profile: mine.legal_profile })
      // Metodu nastavit před poli postavení: zamčený výběr z ní odvozuje cíl resetu.
      manualMethod.value = !!mine.draft.header.method
      methodBasis.value = mine.draft.header.method?.legal_basis ?? ''
      methodEvidence.value = mine.draft.header.method?.evidence ?? ''
      syncProfileFields()
      await loadSource(null)
    } else {
      syncProfileFields()
    }
  } catch (value) { report(value) }
  finally { loading.value = false }
}
const headConfirmedId = computed(() => history.value.head.status === 'confirmed' ? history.value.head.assessment_id : null)
async function openDraft() {
  if (!profileValid.value || busy.value) return
  busy.value = true
  clearError()
  try {
    adopt(await nonprofitTaxJ6Api.openDraft(props.supplierId, props.periodId, requestBody()))
    syncProfileFields()
    active.value = 'source'
    await loadSource(null)
  } catch (value) { report(value) }
  finally { busy.value = false }
}
async function resetDraft() {
  if (!draft.value || busy.value || !profileValid.value || !resetFixesConflict.value || legalUnavailable.value
    || !window.confirm(t('nonprofitTaxJ6.resetWarning'))) return
  busy.value = true
  clearError()
  try {
    adopt(await nonprofitTaxJ6Api.resetDraft(props.supplierId, props.periodId, draft.value.id, {
      expected_revision: draft.value.revision_no,
      ...requestBody(),
    }))
    syncProfileFields()
    await loadSource(null)
  } catch (value) { report(value) }
  finally { busy.value = false }
}
/** Připne aktuální revizi právního profilu se stejným postavením; rozhodnutí konceptu zůstanou. */
async function pinLegalProfile() {
  if (!draft.value || !canPinLegalProfile.value || busy.value) return
  busy.value = true
  clearError()
  try {
    adopt(await nonprofitTaxJ6Api.pinLegalProfile(props.supplierId, props.periodId, draft.value.id, {
      expected_revision: draft.value.revision_no,
    }))
    syncProfileFields()
    await loadSource(null)
  } catch (value) { report(value) }
  finally { busy.value = false }
}
async function loadSource(cursor: string | null) {
  if (!draft.value) return
  try {
    const page = await nonprofitTaxJ6Api.draftSource(props.supplierId, props.periodId, draft.value.id, cursor, PAGE_LIMIT)
    sourceRows.value = page.rows
    sourceFindings.value = page.findings
    sourceAfter.value = page.after
    sourceCursor.value = cursor
  } catch (value) { report(value) }
}
async function sourceNext() {
  if (!sourceAfter.value) return
  sourceBack.value.push(sourceCursor.value)
  await loadSource(sourceAfter.value)
}
async function sourcePrevious() {
  const cursor = sourceBack.value.pop()
  if (cursor === undefined) return
  await loadSource(cursor)
}

async function selectTab(tab: Tab) {
  active.value = tab
  collectionCursor.value = null
  collectionBack.value = []
  if (!draft.value || tab === 'source' || tab === 'preview' || tab === 'history') return
  await loadCollection(tab, null)
}
async function loadCollection(tab: Tab, cursor: string | null) {
  if (!draft.value) return
  try {
    if (tab === 'decisions') {
      const page = await nonprofitTaxJ6Api.decisions(props.supplierId, props.periodId, draft.value.id, cursor, PAGE_LIMIT)
      decisions.value = page.rows; collectionAfter.value = page.after
    } else if (tab === 'activities') {
      const page = await nonprofitTaxJ6Api.activities(props.supplierId, props.periodId, draft.value.id, cursor, PAGE_LIMIT)
      activities.value = page.rows; collectionAfter.value = page.after
    } else if (tab === 'pools') {
      const page = await nonprofitTaxJ6Api.fundingPools(props.supplierId, props.periodId, draft.value.id, cursor, PAGE_LIMIT)
      pools.value = page.rows; collectionAfter.value = page.after
    } else if (tab === 'links') {
      const page = await nonprofitTaxJ6Api.fundingLinks(props.supplierId, props.periodId, draft.value.id, cursor, PAGE_LIMIT)
      links.value = page.rows; collectionAfter.value = page.after
    }
    collectionCursor.value = cursor
  } catch (value) { report(value) }
}
async function collectionNext() {
  if (!collectionAfter.value) return
  collectionBack.value.push(collectionCursor.value)
  await loadCollection(active.value, collectionAfter.value)
}
async function collectionPrevious() {
  const cursor = collectionBack.value.pop()
  if (cursor === undefined) return
  await loadCollection(active.value, cursor)
}
function editDecision(row: J6DecisionRow) {
  decision.value = { source_id: row.source_id, payload: { ...row.payload } }
  deductibleAmount.value = Number.isSafeInteger(row.payload.deductible_cents) ? centsText(row.payload.deductible_cents) : ''
  deductibleError.value = ''
  decisionFacts.value = Object.entries(row.payload.facts ?? {}).map(([key, value]) => ({
    key,
    type: typeof value === 'boolean' ? 'boolean' : typeof value === 'number' ? 'number' : 'string',
    value: String(value),
  }))
}
function assessSource(row: J6SourceRow) {
  decisionSource.value = row
  decision.value = { source_id: row.id, payload: { title: '', evidence: '', final_regime: '', final_method: '' } }
  decisionFacts.value = []
  deductibleAmount.value = ''
  deductibleError.value = ''
  void selectTab('decisions')
}
async function mutate(run: () => Promise<J6DraftEnvelope>, reload: Tab) {
  if (!draft.value || busy.value) return
  busy.value = true
  clearError()
  try {
    adopt(await run())
    active.value = reload
    await loadCollection(reload, null)
  } catch (value) { report(value) }
  finally { busy.value = false }
}
function saveDecision() {
  if (!draft.value || !editable.value || busy.value || !decision.value.source_id || !decision.value.payload.title || !decision.value.payload.evidence.trim()) return
  const payload = { ...decision.value.payload }
  if (isExpenseDecision.value) {
    if (deductibleIsFixedZero.value) {
      payload.deductible_cents = 0
    } else {
      if (!deductibleAmount.value.trim()) {
        deductibleError.value = t('nonprofitTaxJ6.deductibleRequired')
        return
      }
      try {
        payload.deductible_cents = parseCents(deductibleAmount.value)
      } catch {
        deductibleError.value = t('nonprofitTaxJ6.deductibleInvalid')
        return
      }
      const sourceAmount = decisionSourceRow.value?.amount_cents
      if (sourceAmount !== undefined && (Math.abs(payload.deductible_cents) > Math.abs(sourceAmount) || payload.deductible_cents * Math.sign(sourceAmount) < 0)) {
        deductibleError.value = t('nonprofitTaxJ6.deductibleRange')
        return
      }
    }
  }
  payload.facts = Object.fromEntries(decisionFacts.value.filter(fact => fact.key.trim()).map(fact => [
    fact.key.trim(),
    fact.type === 'boolean' ? fact.value === 'true' : fact.type === 'number' ? Number(fact.value) : fact.value,
  ]))
  const body = { expected_revision: draft.value.revision_no,
    upserts: [{ source_id: decision.value.source_id, payload }], deletes: [] }
  void mutate(() => nonprofitTaxJ6Api.patchDecisions(props.supplierId, props.periodId, draft.value!.id, body), 'decisions')
}
function deleteDecision(sourceId: string) {
  if (!draft.value) return
  const body = { expected_revision: draft.value.revision_no, upserts: [], deletes: [{ source_id: sourceId }] }
  void mutate(() => nonprofitTaxJ6Api.patchDecisions(props.supplierId, props.periodId, draft.value!.id, body), 'decisions')
}
function saveActivity() {
  if (!draft.value || !activityDraft.value.activity_kind_id || !activityDraft.value.evidence.trim()) return
  const payload = { evidence: activityDraft.value.evidence.trim(),
    ...(activityCohortCode.value.trim() ? { cohorts: { [activityCohortCode.value.trim()]: activityCohortEvidence.value.trim() } } : {}) }
  const body = { expected_revision: draft.value.revision_no,
    upserts: [{ activity_kind_id: activityDraft.value.activity_kind_id, payload }], deletes: [] }
  void mutate(() => nonprofitTaxJ6Api.patchActivities(props.supplierId, props.periodId, draft.value!.id, body), 'activities')
}
function editActivity(row: J6ActivityRow) {
  activityDraft.value = { activity_kind_id: row.activity_kind_id, evidence: row.payload.evidence }
  const first = Object.entries(row.payload.cohorts ?? {})[0]
  activityCohortCode.value = first?.[0] ?? ''
  activityCohortEvidence.value = first?.[1] ?? ''
}
function deleteActivity(id: string) {
  if (!draft.value) return
  const body = { expected_revision: draft.value.revision_no, upserts: [], deletes: [{ activity_kind_id: id }] }
  void mutate(() => nonprofitTaxJ6Api.patchActivities(props.supplierId, props.periodId, draft.value!.id, body), 'activities')
}
function savePool() {
  if (!draft.value || !poolDraft.value.anchor || !poolDraft.value.evidence.trim()) return
  const payload = { evidence: poolDraft.value.evidence.trim(),
    ...(poolDraft.value.review ? { prior_period_review: { evidence: poolDraft.value.reviewEvidence.trim(),
      legal_basis: poolDraft.value.reviewBasis.trim(), adjustment_cents: Number(poolDraft.value.reviewAdjustment) } } : {}) }
  const body = { expected_revision: draft.value.revision_no,
    upserts: [{ pool_kind: poolDraft.value.pool_kind, anchor: poolDraft.value.anchor, payload }], deletes: [] }
  void mutate(() => nonprofitTaxJ6Api.patchFundingPools(props.supplierId, props.periodId, draft.value!.id, body), 'pools')
}
function editPool(row: J6PoolRow) {
  poolDraft.value = { pool_kind: row.pool_kind, anchor: row.anchor, evidence: row.payload.evidence,
    review: !!row.payload.prior_period_review,
    reviewEvidence: row.payload.prior_period_review?.evidence ?? '',
    reviewBasis: row.payload.prior_period_review?.legal_basis ?? '',
    reviewAdjustment: row.payload.prior_period_review?.adjustment_cents ?? 0 }
}
function deletePool(row: J6PoolRow) {
  if (!draft.value) return
  const body = { expected_revision: draft.value.revision_no, upserts: [], deletes: [{ pool_kind: row.pool_kind, anchor: row.anchor }] }
  void mutate(() => nonprofitTaxJ6Api.patchFundingPools(props.supplierId, props.periodId, draft.value!.id, body), 'pools')
}
function saveLink() {
  if (!draft.value || !linkDraft.value.source_id || !linkDraft.value.cost_id || !linkDraft.value.evidence.trim()) return
  const payload = { amount_cents: Number(linkDraft.value.amount_cents), evidence: linkDraft.value.evidence.trim(),
    deductible_funded_cents: Number(linkDraft.value.deductible_funded_cents) }
  const body = { expected_revision: draft.value.revision_no,
    upserts: [{ source_id: linkDraft.value.source_id, cost_id: linkDraft.value.cost_id, payload }], deletes: [] }
  void mutate(() => nonprofitTaxJ6Api.patchFundingLinks(props.supplierId, props.periodId, draft.value!.id, body), 'links')
}
function deleteLink(row: J6LinkRow) {
  if (!draft.value) return
  const body = { expected_revision: draft.value.revision_no, upserts: [], deletes: [{ source_id: row.source_id, cost_id: row.cost_id }] }
  void mutate(() => nonprofitTaxJ6Api.patchFundingLinks(props.supplierId, props.periodId, draft.value!.id, body), 'links')
}
async function loadMembers(cursor: string | null = null) {
  if (!draft.value || !memberAnchor.value) return
  try {
    const page = await nonprofitTaxJ6Api.fundingMembers(props.supplierId, props.periodId, draft.value.id,
      memberPoolKind.value, memberAnchor.value, cursor, PAGE_LIMIT)
    members.value = page.rows
    memberAfter.value = page.after
    memberCursor.value = cursor
  } catch (value) { report(value) }
}
async function membersNext() {
  if (!memberAfter.value) return
  memberBack.value.push(memberCursor.value)
  await loadMembers(memberAfter.value)
}
async function membersPrevious() {
  const cursor = memberBack.value.pop()
  if (cursor === undefined) return
  await loadMembers(cursor)
}
async function changeMember(id: string, remove = false) {
  if (!draft.value || !memberAnchor.value || !id) return
  busy.value = true
  try {
    adopt(await nonprofitTaxJ6Api.patchFundingMembers(props.supplierId, props.periodId, draft.value.id,
      memberPoolKind.value, memberAnchor.value, {
        expected_revision: draft.value.revision_no,
        upserts: remove ? [] : [id],
        deletes: remove ? [id] : [],
      }))
    active.value = 'pools'
    await loadCollection('pools', null)
    await loadMembers(null)
  } catch (value) { report(value) }
  finally { busy.value = false }
}

async function createPreview() {
  if (!draft.value || busy.value) return
  busy.value = true
  clearError()
  try {
    preview.value = await nonprofitTaxJ6Api.preview(props.supplierId, props.periodId, draft.value.id, draft.value.revision_no)
    previewKind.value = 'result'
    previewPageNo.value = 0
    await loadPreviewPage()
    active.value = 'preview'
  } catch (value) { report(value) }
  finally { busy.value = false }
}
async function loadPreviewPage() {
  if (!draft.value || !preview.value) return
  try {
    previewPage.value = await nonprofitTaxJ6Api.previewPage(props.supplierId, props.periodId, draft.value.id,
      preview.value.preview_token, previewKind.value, previewPageNo.value)
  } catch (value) { report(value) }
}
async function confirmAssessment(retry = false) {
  if (!draft.value || !acknowledged.value || !reason.value.trim() || busy.value) return
  if (!retry || !confirmRetry.value || confirmRetry.value.draftId !== draft.value.id) {
    confirmRetry.value = { draftId: draft.value.id, body: { expected_revision: draft.value.revision_no,
      expected_assessment_id: headConfirmedId.value, reason: reason.value.trim(), idempotency_key: idempotencyKey() } }
  }
  busy.value = true
  clearError()
  try {
    await nonprofitTaxJ6Api.confirm(props.supplierId, props.periodId, confirmRetry.value.draftId, confirmRetry.value.body)
    confirmRetry.value = null
    envelope.value = null
    await loadOverview()
  } catch (value) {
    const status = (value as { response?: { status?: number } })?.response?.status
    if (status && status < 500) confirmRetry.value = null
    report(value)
  } finally { busy.value = false }
}

async function loadHistory(cursor: string | null) {
  try {
    history.value = await nonprofitTaxJ6Api.history(props.supplierId, props.periodId, cursor, PAGE_LIMIT)
    historyCursor.value = cursor
  } catch (value) {
    if (cursor !== null && codeOf(value) === 'nonprofit_tax_cursor_stale') {
      // Mezitím vznikla nová revize: kurzor patří staré hlavě historie, načteme ji od začátku.
      historyBack.value = []
      // Jen nová hláška: listování historií nemění koncept, obnovený stav profilu dál platí.
      errorGeneration++
      error.value = t('nonprofitTaxJ6.historyChanged')
      await loadHistory(null)
      return
    }
    report(value)
  }
}
const isPaged = (row: J6AssessmentSummary) => row.storage_version === 'paged_v2'
const canWithdraw = (row: J6AssessmentSummary) => props.canManage && isPaged(row) && row.status === 'confirmed'
  && row.id === history.value.head.assessment_id
const canConvert = (row: J6AssessmentSummary) => props.canManage && !isPaged(row) && !!row.source_input_hash
function startHistoryAction(kind: 'withdraw' | 'convert', row: J6AssessmentSummary) {
  historyAction.value = { kind, row, key: idempotencyKey() }
  historyReason.value = ''
  historyAcknowledged.value = false
}
async function runHistoryAction() {
  const action = historyAction.value
  if (!action || busy.value || !historyAcknowledged.value || !historyReason.value.trim()) return
  busy.value = true
  clearError()
  try {
    if (action.kind === 'withdraw') {
      await nonprofitTaxJ6Api.withdraw(props.supplierId, props.periodId, action.row.id,
        { reason: historyReason.value.trim(), idempotency_key: action.key })
    } else {
      await nonprofitTaxJ6Api.convert(props.supplierId, props.periodId, action.row.id, {
        expected_storage_version: action.row.storage_version,
        expected_input_hash: action.row.source_input_hash ?? '', reason: historyReason.value.trim() })
    }
    historyAction.value = null
    await loadOverview()
  } catch (value) { report(value) }
  finally { busy.value = false }
}
function money(cents: number | null | undefined) {
  if (typeof cents !== 'number') return '-'
  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(cents / 100)
}
function saveBlob(data: Blob, name: string) {
  const url = URL.createObjectURL(data)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  link.click()
  URL.revokeObjectURL(url)
}
async function downloadDocument(id: number, format: J6DocumentFormat) {
  try {
    const response = await nonprofitTaxJ6Api.document(props.supplierId, id, format)
    saveBlob(response.data as Blob, 'danove-posouzeni-' + id + '.' + format)
  } catch (value) { report(value) }
}
async function historyNext() {
  if (!history.value.after) return
  historyBack.value.push(historyCursor.value)
  await loadHistory(history.value.after)
}
async function historyPrevious() {
  const cursor = historyBack.value.pop()
  if (cursor === undefined) return
  await loadHistory(cursor)
}
async function openAssessment(id: number) {
  selectedAssessment.value = id
  frozenCursor.value = null
  frozenBack.value = []
  try {
    frozenSummary.value = await nonprofitTaxJ6Api.assessment(props.supplierId, id) as unknown as Record<string, unknown>
    frozenKind.value = 'source'
    await loadFrozen(null)
  } catch (value) { report(value) }
}
async function loadFrozen(cursor: string | null) {
  if (!selectedAssessment.value) return
  try {
    if (frozenKind.value === 'source') {
      const page = await nonprofitTaxJ6Api.frozenSource(props.supplierId, selectedAssessment.value, cursor, PAGE_LIMIT)
      frozenRows.value = page.rows as unknown as Record<string, unknown>[]
      frozenAfter.value = page.after
    } else {
      const page = await nonprofitTaxJ6Api.frozenPage(props.supplierId, selectedAssessment.value, frozenKind.value, cursor, PAGE_LIMIT)
      frozenRows.value = page.rows
      frozenAfter.value = page.after
    }
    frozenCursor.value = cursor
  } catch (value) { report(value) }
}
async function selectFrozen(kind: typeof frozenKind.value) {
  frozenKind.value = kind
  frozenBack.value = []
  await loadFrozen(null)
}
async function frozenNext() {
  if (!frozenAfter.value) return
  frozenBack.value.push(frozenCursor.value)
  await loadFrozen(frozenAfter.value)
}
async function frozenPrevious() {
  const cursor = frozenBack.value.pop()
  if (cursor === undefined) return
  await loadFrozen(cursor)
}
async function downloadExport(id: number) {
  try {
    const response = await nonprofitTaxJ6Api.export(props.supplierId, id)
    saveBlob(response.data as Blob, 'danove-posouzeni-' + id + '.ndjson')
  } catch (value) { report(value) }
}

const actions = computed<ActionItem[]>(() => [
  { key: 'open', label: t('nonprofitTaxJ6.openDraft'), icon: 'plus', tier: 'primary', variant: 'primary',
    show: !draft.value, disabled: !profileValid.value || busy.value || !props.canManage || legalUnavailable.value, run: openDraft },
  { key: 'preview', label: t('nonprofitTaxJ6.preview'), icon: 'eye', tier: 'primary', variant: 'primary',
    show: !!draft.value, disabled: !capabilities.value?.can_preview || stale.value || busy.value || legalState.value === 'conflict' || legalUnavailable.value, run: createPreview },
  { key: 'reset', label: t('nonprofitTaxJ6.resetDraft'), icon: 'cycle', tier: 'overflow', variant: 'warning',
    show: !!draft.value && props.canManage, disabled: !editable.value || busy.value || !profileValid.value || !resetFixesConflict.value || legalUnavailable.value, run: resetDraft },
  { key: 'refresh', label: t('nonprofitTaxJ6.reload'), icon: 'cycle', tier: 'secondary', run: loadOverview },
])

watch(manualMethod, () => {
  // Bez konceptu nebo se zamčeným profilem ukáže výběr, co převezme založení nebo reset s ruční metodou.
  if (!draft.value || profileLocked.value) syncProfileFields()
})
watch(() => props.reloadKey, () => { void loadOverview() })
onMounted(() => { void loadOverview() })
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold">{{ t('nonprofitTaxJ6.title') }}</h1>
      <p class="mt-2 max-w-4xl text-sm text-neutral-600">{{ t('nonprofitTaxJ6.scope') }}</p>
    </header>
    <ActionBar :actions="actions" />
    <p v-if="error" role="alert" class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-danger-700">{{ error }}</p>
    <p v-if="stale" class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-warning-800">{{ t('nonprofitTaxJ6.stale') }}</p>

    <section v-if="current" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold">{{ t('nonprofitTaxJ6.currentSource') }}</h2>
          <p class="text-sm text-neutral-600">{{ current.header.period.starts_on }} - {{ current.header.period.ends_on }} · {{ current.header.ruleset }}</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
          <span class="rounded-full bg-neutral-100 px-3 py-1">{{ t('nonprofitTaxJ6.rows', { count: current.row_count }) }}</span>
          <span class="rounded-full bg-neutral-100 px-3 py-1">{{ t('nonprofitTaxJ6.findings', { count: current.finding_count }) }}</span>
        </div>
      </div>
      <p v-if="latest" data-test="latest" class="text-sm">
        {{ t('nonprofitTaxJ6.latestRevision', { revision: latest.revision_no }) }} · {{ t('nonprofitTaxJ6.status.' + latest.status) }}
        <template v-if="latest.summary"> · {{ t('nonprofitTaxJ6.assessedResult') }}: <span class="font-mono">{{ money(latest.summary.assessed_result_cents) }}</span></template>
        <template v-if="latest.conversion_required"> · {{ t('nonprofitTaxJ6.conversionRequired') }}</template>
        <template v-if="latest.legal_profile"> · <span data-test="latest-legal-profile">{{ t('nonprofitTaxJ6.legalProfile.latest', { revision: latest.legal_profile.revision_no }) }}</span></template>
      </p>
      <J6SourceFindings v-if="current.finding_count > 0" :key="current.input_hash" :current-hash="current.input_hash" :supplier-id="props.supplierId" :period-id="props.periodId" />
      <div v-if="props.canManage" data-test="legal-profile-status" role="status"
        :class="['rounded-lg border p-3 text-sm', legalState === 'conflict' ? 'border-warning-300 bg-warning-50 text-warning-800' : 'border-neutral-200 bg-neutral-50 text-neutral-700']">
        <template v-if="legalState === 'conflict'">
          <p class="font-medium">{{ t('nonprofitTaxJ6.legalProfile.conflictTitle') }}</p>
          <p v-if="svjModuleConflict" class="mt-1">{{ t('nonprofitTaxJ6.legalProfile.svjModule', { draft: draftPostureLabel }) }}</p>
          <p v-else class="mt-1">{{ t('nonprofitTaxJ6.legalProfile.conflict', { draft: draftPostureLabel, profile: t('nonprofitTaxJ6.profiles.' + (legalProfile?.current?.kind ?? 'documented')) }) }}</p>
        </template>
        <p v-else-if="legalState === 'pinned_same_kind_outdated'">{{ t('nonprofitTaxJ6.legalProfile.outdated', { pinned: legalProfile?.pinned?.revision_no ?? 0, current: legalProfile?.current?.revision_no ?? 0 }) }}</p>
        <p v-else-if="legalState === 'unavailable' || legalProfile?.error" data-test="legal-profile-error">{{ t('nonprofitTaxJ6.legalProfile.unavailable', { message: legalUnavailableReason }) }}</p>
        <p v-else-if="legalState === 'manual' && legalProfile?.current && legalProfile.pinnable">{{ t('nonprofitTaxJ6.legalProfile.unpinned', { current: legalProfile.current.revision_no }) }}</p>
        <p v-else-if="legalState === 'manual' && legalProfile?.current">{{ t('nonprofitTaxJ6.legalProfile.unverified', { current: legalProfile.current.revision_no }) }}</p>
        <p v-else-if="profileLocked">{{ t('nonprofitTaxJ6.legalProfile.adopted', { revision: lockedRevision }) }}</p>
        <p v-else>{{ t('nonprofitTaxJ6.legalProfile.none') }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-3">
          <button v-if="legalState === 'conflict'" type="button" data-test="legal-profile-reset" :class="btnOutline('warning')" class="whitespace-nowrap" :disabled="!editable || busy || !profileValid || !resetFixesConflict || legalUnavailable" @click="resetDraft">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.cycle" /></svg>{{ t('nonprofitTaxJ6.resetDraft') }}
          </button>
          <button v-if="canPinLegalProfile" type="button" data-test="legal-profile-pin" :class="btnOutline('success')" class="whitespace-nowrap" :disabled="busy" @click="pinLegalProfile">
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('nonprofitTaxJ6.legalProfile.pin') }}
          </button>
          <RouterLink :to="{ name: 'accounting-nonprofit-dppo', query: { period: String(props.periodId) } }" data-test="legal-profile-link" class="whitespace-nowrap text-xs font-medium text-primary-600 hover:underline">
            {{ profileLocked ? t('nonprofitTaxJ6.legalProfile.open') : t('nonprofitTaxJ6.legalProfile.confirm') }} →
          </RouterLink>
        </div>
      </div>
      <div class="grid gap-3 md:grid-cols-2">
        <label :class="formLabel">{{ t('nonprofitTaxJ6.profile') }}
          <select v-model="profileKind" data-test="profile-kind" :class="formInput" class="mt-1" :disabled="profileLocked || (!!draft && !editable)">
            <option value="">{{ t('nonprofitTaxJ6.choose') }}</option>
            <option v-for="kind in ['narrow_vpp','broad_vpp','non_vpp','svj','documented']" :key="kind" :value="kind">{{ t('nonprofitTaxJ6.profiles.' + kind) }}</option>
          </select>
        </label>
        <label v-if="profileKind === 'documented'" class="text-sm">{{ t('nonprofitTaxJ6.entityKind') }}
          <input v-if="profileLocked" :value="entityKindLabel" data-test="entity-kind" :class="formInput" class="mt-1" disabled />
          <input v-else v-model="entityKind" data-test="entity-kind" maxlength="100" :class="formInput" class="mt-1" />
        </label>
        <label :class="formLabel" class="md:col-span-2">{{ t('nonprofitTaxJ6.profileEvidence') }}
          <textarea v-model="profileEvidence" data-test="profile-evidence" rows="2" maxlength="2000" :class="formTextarea" class="mt-1"
            :disabled="profileLocked" :placeholder="profileLocked && !profileEvidence ? t('nonprofitTaxJ6.legalProfile.evidenceFromProfile') : undefined" />
        </label>
        <label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="manualMethod" type="checkbox" />{{ t('nonprofitTaxJ6.manualMethod') }}</label>
        <template v-if="manualMethod">
          <label :class="formLabel">{{ t('nonprofitTaxJ6.methodBasis') }}<textarea v-model="methodBasis" rows="2" maxlength="2000" :class="formTextarea" class="mt-1" /></label>
          <label :class="formLabel">{{ t('nonprofitTaxJ6.methodEvidence') }}<textarea v-model="methodEvidence" rows="2" maxlength="2000" :class="formTextarea" class="mt-1" /></label>
        </template>
      </div>
    </section>

    <template v-if="draft">
      <section class="rounded-lg border border-neutral-200 bg-surface p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div><h2 class="font-semibold">{{ t('nonprofitTaxJ6.draft', { id: draft.id }) }}</h2><p class="text-sm text-neutral-600">{{ t('nonprofitTaxJ6.revision', { revision: draft.revision_no }) }}</p></div>
          <span :class="capabilities?.source_complete ? 'text-success-700' : 'text-warning-700'">{{ capabilities?.source_complete ? t('nonprofitTaxJ6.sourceComplete') : t('nonprofitTaxJ6.sourceIncomplete') }}</span>
        </div>
      </section>
      <nav class="flex flex-wrap gap-2" :aria-label="t('nonprofitTaxJ6.sections')">
        <button v-for="tab in (['source','decisions','activities','pools','links','preview','history'] as const)" :key="tab" type="button"
          :class="active === tab ? 'rounded-md bg-primary-600 px-3 py-2 text-sm text-white' : btnOutline('neutral')" @click="selectTab(tab)">
          {{ t('nonprofitTaxJ6.tabs.' + tab) }}
        </button>
      </nav>

      <section v-if="active === 'source'" class="space-y-3">
        <article v-for="row in sourceRows" :key="row.id" class="rounded-lg border border-neutral-200 bg-surface p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div><strong>{{ row.account_code }} · {{ row.date }}</strong><p class="text-xs text-neutral-600">{{ row.id }} · {{ row.kind }} · {{ row.tax_regime ?? t('nonprofitTaxJ6.unknown') }}</p></div>
            <div class="flex flex-wrap items-center gap-2"><span class="font-mono">{{ (row.amount_cents / 100).toFixed(2) }}</span><button type="button" :class="btnOutline('primary')" :disabled="!editable" @click="assessSource(row)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.edit" /></svg>{{ t('nonprofitTaxJ6.assess') }}</button></div>
          </div>
          <p v-for="finding in sourceFindings.filter(item => item.line_id === row.line_id)" :key="finding.code" class="mt-2 text-sm text-warning-700">{{ t('nonprofitTaxJ6.finding') }}: {{ t('nonprofitTax.findings.' + finding.code) }}</p>
        </article>
        <p v-if="!sourceRows.length" class="text-sm text-neutral-600">{{ t('nonprofitTaxJ6.empty') }}</p>
        <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="!sourceBack.length" @click="sourcePrevious"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" data-test="source-next" :class="btnOutline('neutral')" :disabled="!sourceAfter" @click="sourceNext"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div>
      </section>

      <section v-else-if="active === 'decisions'" class="space-y-4">
        <div class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3">
          <h2 class="font-semibold">{{ t('nonprofitTaxJ6.decisionEditor') }}</h2>
          <div class="grid gap-3 md:grid-cols-2">
            <label :class="formLabel">{{ t('nonprofitTaxJ6.sourceId') }}<input v-model="decision.source_id" :class="formInput" class="mt-1" /></label>
            <label :class="formLabel">{{ t('nonprofitTaxJ6.titleField') }}<input v-model="decision.payload.title" :class="formInput" class="mt-1" /></label>
            <label :class="formLabel">{{ t('nonprofitTaxJ6.regime') }}<select v-model="decision.payload.final_regime" :class="formInput" class="mt-1"><option value="">{{ t('nonprofitTaxJ6.choose') }}</option><option v-for="value in ['subject','exempt','out_of_scope']" :key="value" :value="value">{{ value }}</option></select></label>
            <label :class="formLabel">{{ t('nonprofitTaxJ6.finalMethod') }}<select v-model="decision.payload.final_method" :class="formInput" class="mt-1"><option value="">{{ t('nonprofitTaxJ6.choose') }}</option><option v-for="value in ['return','none','final_withholding']" :key="value" :value="value">{{ value }}</option></select></label>
            <label :class="formLabel">{{ t('nonprofitTaxJ6.legalBasis') }}<input v-model="decision.payload.legal_basis" maxlength="2000" :class="formInput" class="mt-1" /></label>
            <div v-if="isExpenseDecision">
              <label :class="formLabel">
                {{ t('nonprofitTaxJ6.deductibleBeforeFunding') }}
                <input :value="deductibleIsFixedZero ? '0.00' : deductibleAmount" inputmode="decimal" :class="formInput" class="mt-1"
                  :readonly="deductibleIsFixedZero || !editable" :required="!deductibleIsFixedZero && editable"
                  :aria-invalid="deductibleError ? 'true' : undefined" :aria-describedby="deductibleError ? deductibleErrorId : undefined"
                  @input="deductibleAmount = ($event.target as HTMLInputElement).value" />
              </label>
              <p v-if="deductibleError" :id="deductibleErrorId" role="alert" class="mt-1 text-sm text-danger-700">{{ deductibleError }}</p>
            </div>
            <label :class="formLabel">{{ t('nonprofitTaxJ6.cohort') }}<input v-model="decision.payload.cohort" :class="formInput" class="mt-1" /></label>
            <label :class="formLabel" class="md:col-span-2">{{ t('nonprofitTaxJ6.evidence') }}<textarea v-model="decision.payload.evidence" rows="2" maxlength="2000" :class="formTextarea" class="mt-1" /></label>
          </div>
          <fieldset class="space-y-2">
            <legend class="text-sm font-medium">{{ t('nonprofitTaxJ6.structuredFacts') }}</legend>
            <div v-for="(fact, index) in decisionFacts" :key="index" class="grid gap-2 md:grid-cols-[1fr_10rem_1fr_auto]">
              <input v-model="fact.key" :placeholder="t('nonprofitTaxJ6.factKey')" :class="formInput" />
              <select v-model="fact.type" :class="formInput"><option value="string">string</option><option value="number">number</option><option value="boolean">boolean</option></select>
              <select v-if="fact.type === 'boolean'" v-model="fact.value" :class="formInput"><option value="true">{{ t('nonprofitTaxJ6.yes') }}</option><option value="false">{{ t('nonprofitTaxJ6.no') }}</option></select>
              <input v-else v-model="fact.value" :type="fact.type === 'number' ? 'number' : 'text'" :class="formInput" />
              <button type="button" :class="btnOutline('danger')" @click="decisionFacts.splice(index, 1)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTaxJ6.remove') }}</button>
            </div>
            <button type="button" :class="btnOutline('neutral')" @click="decisionFacts.push({ key: '', type: 'string', value: '' })"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('nonprofitTaxJ6.addFact') }}</button>
          </fieldset>
          <button type="button" :class="btnOutline('success')" :disabled="!editable" @click="saveDecision"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('nonprofitTaxJ6.save') }}</button>
        </div>
        <article v-for="row in decisions" :key="row.source_id" class="rounded-lg border border-neutral-200 bg-surface p-4">
          <div class="flex flex-wrap items-start justify-between gap-2"><div><strong>{{ row.source_id }}</strong><p class="text-sm">{{ row.payload.title }} · {{ row.payload.final_regime }}</p><p class="text-sm text-neutral-600">{{ row.payload.evidence }}</p></div><div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" @click="editDecision(row)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.edit" /></svg>{{ t('nonprofitTaxJ6.edit') }}</button><button type="button" :class="btnOutline('danger')" :disabled="!editable" @click="deleteDecision(row.source_id)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTaxJ6.remove') }}</button></div></div>
        </article>
        <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="!collectionBack.length" @click="collectionPrevious"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="!collectionAfter" @click="collectionNext"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div>
      </section>

      <section v-else-if="active === 'activities'" class="space-y-3">
        <div class="grid gap-3 rounded-lg border border-neutral-200 bg-surface p-4 md:grid-cols-2"><label :class="formLabel">{{ t('nonprofitTaxJ6.activityKind') }}<input v-model="activityDraft.activity_kind_id" :class="formInput" class="mt-1" /></label><label :class="formLabel">{{ t('nonprofitTaxJ6.evidence') }}<textarea v-model="activityDraft.evidence" rows="2" :class="formTextarea" class="mt-1" /></label><label :class="formLabel">{{ t('nonprofitTaxJ6.cohort') }}<input v-model="activityCohortCode" :class="formInput" class="mt-1" /></label><label :class="formLabel">{{ t('nonprofitTaxJ6.cohortEvidence') }}<textarea v-model="activityCohortEvidence" rows="2" :class="formTextarea" class="mt-1" /></label><button type="button" :class="btnOutline('success')" :disabled="!editable" @click="saveActivity"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('nonprofitTaxJ6.save') }}</button></div>
        <article v-for="row in activities" :key="row.activity_kind_id" class="rounded-lg border border-neutral-200 bg-surface p-4"><div class="flex flex-wrap justify-between gap-2"><div><strong>{{ row.activity_kind_id }}</strong><p class="text-sm text-neutral-600">{{ row.payload.evidence }}</p></div><div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" @click="editActivity(row)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.edit" /></svg>{{ t('nonprofitTaxJ6.edit') }}</button><button type="button" :class="btnOutline('danger')" :disabled="!editable" @click="deleteActivity(row.activity_kind_id)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTaxJ6.remove') }}</button></div></div></article>
        <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="!collectionBack.length" @click="collectionPrevious"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="!collectionAfter" @click="collectionNext"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div>
      </section>

      <section v-else-if="active === 'pools'" class="space-y-4">
        <div class="grid gap-3 rounded-lg border border-neutral-200 bg-surface p-4 md:grid-cols-2"><label :class="formLabel">{{ t('nonprofitTaxJ6.poolKind') }}<select v-model="poolDraft.pool_kind" :class="formInput" class="mt-1"><option value="revenue">revenue</option><option value="expense">expense</option></select></label><label :class="formLabel">{{ t('nonprofitTaxJ6.anchor') }}<input v-model="poolDraft.anchor" :class="formInput" class="mt-1" /></label><label :class="formLabel" class="md:col-span-2">{{ t('nonprofitTaxJ6.evidence') }}<textarea v-model="poolDraft.evidence" rows="2" :class="formTextarea" class="mt-1" /></label><label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="poolDraft.review" type="checkbox" />{{ t('nonprofitTaxJ6.priorReview') }}</label><template v-if="poolDraft.review"><label :class="formLabel">{{ t('nonprofitTaxJ6.legalBasis') }}<input v-model="poolDraft.reviewBasis" :class="formInput" class="mt-1" /></label><label :class="formLabel">{{ t('nonprofitTaxJ6.adjustmentCents') }}<input v-model.number="poolDraft.reviewAdjustment" type="number" :class="formInput" class="mt-1" /></label><label :class="formLabel" class="md:col-span-2">{{ t('nonprofitTaxJ6.reviewEvidence') }}<textarea v-model="poolDraft.reviewEvidence" rows="2" :class="formTextarea" class="mt-1" /></label></template><button type="button" :class="btnOutline('success')" :disabled="!editable" @click="savePool"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('nonprofitTaxJ6.save') }}</button></div>
        <article v-for="row in pools" :key="row.pool_kind + ':' + row.anchor" class="rounded-lg border border-neutral-200 bg-surface p-4"><div class="flex flex-wrap justify-between gap-2"><div><strong>{{ row.pool_kind }} · {{ row.anchor }}</strong><p class="text-sm text-neutral-600">{{ row.payload.evidence }}</p></div><div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" @click="editPool(row)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.edit" /></svg>{{ t('nonprofitTaxJ6.edit') }}</button><button type="button" :class="btnOutline('danger')" :disabled="!editable" @click="deletePool(row)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTaxJ6.remove') }}</button></div></div></article>
        <div class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3"><h3 class="font-semibold">{{ t('nonprofitTaxJ6.poolMembers') }}</h3><div class="grid gap-3 md:grid-cols-3"><select v-model="memberPoolKind" :class="formInput"><option value="revenue">revenue</option><option value="expense">expense</option></select><input v-model="memberAnchor" :placeholder="t('nonprofitTaxJ6.anchor')" :class="formInput" /><button type="button" :class="btnOutline('neutral')" @click="loadMembers(null)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.search" /></svg>{{ t('nonprofitTaxJ6.loadMembers') }}</button></div><div class="flex flex-wrap gap-2"><input v-model="memberId" :placeholder="t('nonprofitTaxJ6.memberId')" :class="formInput" /><button type="button" :class="btnOutline('success')" :disabled="!editable" @click="changeMember(memberId)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ t('nonprofitTaxJ6.addMember') }}</button></div><div v-for="row in members" :key="row.member_id" class="flex flex-wrap items-center justify-between gap-2 border-t border-neutral-100 py-2"><code>{{ row.member_id }}</code><button type="button" :class="btnOutline('danger')" :disabled="!editable" @click="changeMember(row.member_id, true)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTaxJ6.remove') }}</button></div><div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="!memberBack.length" @click="membersPrevious"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="!memberAfter" @click="membersNext"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div></div>
        <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="!collectionBack.length" @click="collectionPrevious"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="!collectionAfter" @click="collectionNext"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div>
      </section>

      <section v-else-if="active === 'links'" class="space-y-3">
        <div class="grid gap-3 rounded-lg border border-neutral-200 bg-surface p-4 md:grid-cols-2"><label :class="formLabel">{{ t('nonprofitTaxJ6.sourceId') }}<input v-model="linkDraft.source_id" :class="formInput" class="mt-1" /></label><label :class="formLabel">{{ t('nonprofitTaxJ6.costId') }}<input v-model="linkDraft.cost_id" :class="formInput" class="mt-1" /></label><label :class="formLabel">{{ t('nonprofitTaxJ6.amountCents') }}<input v-model.number="linkDraft.amount_cents" type="number" :class="formInput" class="mt-1" /></label><label :class="formLabel">{{ t('nonprofitTaxJ6.deductibleFundedCents') }}<input v-model.number="linkDraft.deductible_funded_cents" type="number" :class="formInput" class="mt-1" /></label><label :class="formLabel" class="md:col-span-2">{{ t('nonprofitTaxJ6.evidence') }}<textarea v-model="linkDraft.evidence" rows="2" :class="formTextarea" class="mt-1" /></label><button type="button" :class="btnOutline('success')" :disabled="!editable" @click="saveLink"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('nonprofitTaxJ6.save') }}</button></div>
        <article v-for="row in links" :key="row.source_id + ':' + row.cost_id" class="rounded-lg border border-neutral-200 bg-surface p-4"><div class="flex flex-wrap justify-between gap-2"><div><strong>{{ row.source_id }} → {{ row.cost_id }}</strong><p class="text-sm">{{ row.payload.amount_cents }} · {{ row.payload.evidence }}</p></div><button type="button" :class="btnOutline('danger')" :disabled="!editable" @click="deleteLink(row)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.trash" /></svg>{{ t('nonprofitTaxJ6.remove') }}</button></div></article>
        <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="!collectionBack.length" @click="collectionPrevious"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="!collectionAfter" @click="collectionNext"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div>
      </section>

      <section v-else-if="active === 'preview'" class="space-y-4">
        <p v-if="!preview" class="rounded-lg border border-neutral-200 bg-surface p-4 text-sm text-neutral-600">{{ t('nonprofitTaxJ6.noPreview') }}</p>
        <template v-else>
          <div class="rounded-lg border border-neutral-200 bg-surface p-4"><h2 class="font-semibold">{{ t('nonprofitTaxJ6.previewSummary') }}</h2><pre class="mt-3 overflow-auto text-xs">{{ JSON.stringify(preview.summary, null, 2) }}</pre></div>
          <div class="flex flex-wrap gap-2"><button v-for="kind in (['decision','result','coverage','usage','funding_reference','funding_history'] as const)" :key="kind" type="button" :class="previewKind === kind ? 'rounded-md bg-primary-600 px-3 py-2 text-sm text-white' : btnOutline('neutral')" @click="previewKind = kind; previewPageNo = 0; loadPreviewPage()">{{ t('nonprofitTaxJ6.previewKinds.' + kind) }}</button></div>
          <div class="rounded-lg border border-neutral-200 bg-surface p-4"><pre class="overflow-auto text-xs">{{ JSON.stringify(previewPage?.rows ?? [], null, 2) }}</pre><div class="mt-3 flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="previewPageNo === 0" @click="previewPageNo--; loadPreviewPage()"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="previewPage?.next_page == null" @click="previewPageNo = previewPage?.next_page ?? previewPageNo; loadPreviewPage()"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div></div>
          <div v-if="props.canManage" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3"><p class="text-sm text-warning-700">{{ t('nonprofitTaxJ6.freshConfirm') }}</p><label :class="formLabel">{{ t('nonprofitTaxJ6.reason') }}<textarea v-model="reason" data-test="confirm-reason" rows="2" maxlength="2000" :class="formTextarea" class="mt-1" /></label><label class="flex items-start gap-2 text-sm"><input v-model="acknowledged" data-test="confirm-ack" type="checkbox" class="mt-1" /><span>{{ t('nonprofitTaxJ6.acknowledge') }}</span></label><div class="flex flex-wrap gap-2"><button type="button" data-test="confirm" :class="btnOutline('success')" :disabled="!reason.trim() || !acknowledged || busy" @click="confirmAssessment(false)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('nonprofitTaxJ6.confirm') }}</button><button v-if="confirmRetry" type="button" data-test="confirm-retry" :class="btnOutline('warning')" @click="confirmAssessment(true)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.cycle" /></svg>{{ t('nonprofitTaxJ6.retryExact') }}</button></div></div>
        </template>
      </section>
    </template>

    <section v-if="active === 'history' || !draft" class="space-y-3">
      <h2 class="text-lg font-semibold">{{ t('nonprofitTaxJ6.history') }}</h2>
      <article v-for="row in history.rows" :key="row.id" data-test="history-row" class="rounded-lg border border-neutral-200 bg-surface p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="space-y-1">
            <div class="flex flex-wrap items-center gap-2">
              <strong>{{ t('nonprofitTaxJ6.revision', { revision: row.revision_no }) }}</strong>
              <span :class="row.status === 'confirmed' ? 'rounded-full bg-success-50 px-2 py-0.5 text-xs text-success-700' : 'rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700'">{{ t('nonprofitTaxJ6.status.' + row.status) }}</span>
              <span v-if="!isPaged(row)" class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-800">{{ t('nonprofitTaxJ6.conversionRequired') }}</span>
            </div>
            <p class="text-sm text-neutral-600">{{ row.created_at }} · {{ row.reason }}</p>
            <p v-if="row.summary" class="text-sm">{{ t('nonprofitTaxJ6.assessedResult') }}: <span class="font-mono">{{ money(row.summary.assessed_result_cents) }}</span></p>
          </div>
          <div class="flex flex-wrap gap-2">
            <template v-if="isPaged(row)">
              <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" @click="openAssessment(row.id)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.eye" /></svg>{{ t('nonprofitTaxJ6.openEvidence') }}</button>
              <button v-for="format in (['pdf', 'xlsx'] as const)" :key="format" type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" :data-test="'download-' + format" @click="downloadDocument(row.id, format)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.download" /></svg>{{ t('nonprofitTaxJ6.download.' + format) }}</button>
              <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" @click="downloadExport(row.id)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.download" /></svg>{{ t('nonprofitTaxJ6.downloadNdjson') }}</button>
            </template>
            <button v-if="canWithdraw(row)" type="button" data-test="withdraw" class="whitespace-nowrap" :class="btnOutline('warning')" :disabled="busy" @click="startHistoryAction('withdraw', row)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.uturn" /></svg>{{ t('nonprofitTaxJ6.withdraw') }}</button>
            <button v-if="canConvert(row)" type="button" data-test="convert" class="whitespace-nowrap" :class="btnOutline('primary')" :disabled="busy" @click="startHistoryAction('convert', row)"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.cycle" /></svg>{{ t('nonprofitTaxJ6.convert') }}</button>
          </div>
        </div>
        <div v-if="historyAction && historyAction.row.id === row.id" data-test="history-action" class="mt-4 space-y-3 border-t border-neutral-200 pt-4">
          <p class="text-sm text-warning-700">{{ t('nonprofitTaxJ6.' + historyAction.kind + 'Hint') }}</p>
          <label :class="formLabel">{{ t('nonprofitTaxJ6.actionReason') }}<textarea v-model="historyReason" data-test="history-reason" rows="2" maxlength="500" :class="formTextarea" class="mt-1" /></label>
          <div class="flex flex-wrap items-center gap-3">
            <label class="flex items-start gap-2 text-sm"><input v-model="historyAcknowledged" data-test="history-ack" type="checkbox" class="mt-1" /><span>{{ t('nonprofitTaxJ6.' + historyAction.kind + 'Acknowledge') }}</span></label>
            <button type="button" data-test="history-confirm" class="whitespace-nowrap" :class="historyAction.kind === 'withdraw' ? btnOutline('warning') : btnOutline('primary')" :disabled="busy || !historyAcknowledged || !historyReason.trim()" @click="runHistoryAction"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('nonprofitTaxJ6.' + historyAction.kind) }}</button>
            <button type="button" class="whitespace-nowrap" :class="btnOutline('neutral')" @click="historyAction = null"><svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('nonprofitTaxJ6.cancel') }}</button>
          </div>
        </div>
      </article>
      <p v-if="!history.rows.length" class="text-sm text-neutral-600">{{ t('nonprofitTaxJ6.noHistory') }}</p>
      <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="!historyBack.length" @click="historyPrevious"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" data-test="history-next" :class="btnOutline('neutral')" :disabled="!history.after" @click="historyNext"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div>
      <div v-if="selectedAssessment && frozenSummary" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3"><h3 class="font-semibold">{{ t('nonprofitTaxJ6.frozenAssessment', { id: selectedAssessment }) }}</h3><pre class="overflow-auto text-xs">{{ JSON.stringify(frozenSummary, null, 2) }}</pre><div class="flex flex-wrap gap-2"><button v-for="kind in (['source','decision','result','coverage','usage'] as const)" :key="kind" type="button" :class="frozenKind === kind ? 'rounded-md bg-primary-600 px-3 py-2 text-sm text-white' : btnOutline('neutral')" @click="selectFrozen(kind)">{{ t('nonprofitTaxJ6.previewKinds.' + kind) }}</button></div><pre class="overflow-auto text-xs">{{ JSON.stringify(frozenRows, null, 2) }}</pre><div class="flex flex-wrap gap-2"><button type="button" :class="btnOutline('neutral')" :disabled="!frozenBack.length" @click="frozenPrevious"><svg class="h-4 w-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.previous') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="!frozenAfter" @click="frozenNext"><svg class="h-4 w-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('nonprofitTaxJ6.next') }}</button></div></div>
    </section>

    <p v-if="loading" class="text-sm text-neutral-600">{{ t('nonprofitTaxJ6.loading') }}</p>
  </div>
</template>

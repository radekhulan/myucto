<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  epoSubmissionsApi,
  type ArtifactUploadResult,
  type EpoArtifact,
  type EpoAttempt,
  type EpoFolder,
  type EpoMessage,
  type EpoReceiptHint,
  type EpoReceiptSummary,
  type EpoSigningCredential,
  type SubmissionStatus,
  type TaxSubmission,
  type TaxSubmissionStats,
} from '@/api/epoSubmissions'
import { authApi } from '@/api/auth'
import { getCredential, isWebAuthnAvailable } from '@/security/webauthn'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { hasUnresolvedProductionDirectAttempt } from '@/utils/epoAttemptState'
import {
  canOfferHandoffLink,
  loadEpoHandoffLinks,
  saveEpoHandoffLinks,
  type CachedEpoHandoffLink,
} from '@/utils/epoHandoffCache'
import {
  ICONS,
  btnFilled,
  btnFilledSm,
  btnOutline,
  btnOutlineSm,
} from '@/components/ui/buttonStyles'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'

defineProps<{ embedded?: boolean }>()

const { t, locale } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplier = useSupplierStore()

const items = ref<TaxSubmission[]>([])
const loading = ref(false)
const error = ref('')
const search = ref('')
const statusFilter = ref<SubmissionStatus | 'all'>('all')
const formFilter = ref('all')
const page = ref(1)
const perPage = 50
/** Počet řádků odpovídajících filtru (celý archiv, ne stránka). */
const total = ref(0)
/** Souhrn nad celým viditelným archivem — počítá ho server, ne načtená stránka. */
const stats = ref<TaxSubmissionStats>({ total: 0, waiting: 0, submitted: 0, problems: 0 })
/** Kódy výkazů v archivu pro nabídku filtru; nezávisí na aktuální stránce. */
const formCodes = ref<string[]>([])
const expandedId = ref<number | null>(null)
const handoffBusy = ref<Set<number>>(new Set())
const uploadBusy = ref(false)
const uploadProgress = ref(0)
const dragging = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const handoffLinks = ref<Record<number, CachedEpoHandoffLink>>({})
const manualDate = ref('')
const manualRef = ref('')
/** Otevřený panel „Označit jako podané" po přečtení údajů z nahraných souborů. */
const manualOpen = ref(false)
/** Odkud se předvyplnilo — dodejka je důkaz, PDF opis jen odečtený text. */
const prefillSource = ref<'receipt' | 'pdf' | null>(null)
const manualBusy = ref(false)
const credentials = ref<EpoSigningCredential[]>([])
const selectedCredentialId = ref<number | null>(null)
const credentialModalOpen = ref(false)
const credentialBusy = ref(false)
const stepPassword = ref('')
const stepTotpCode = ref('')
const directBusy = ref(false)
const directMessages = ref<Record<number, EpoMessage[]>>({})
const submitModalOpen = ref(false)
const submitMode = ref<'test' | 'submit'>('submit')
const submitAttemptId = ref<number | null>(null)
const submitPassword = ref('')
const submitTotpCode = ref('')
const recoveryModalOpen = ref(false)
const recoveryMode = ref<'confirmation' | 'not_submitted' | 'state_password'>('confirmation')
const recoveryAttemptId = ref<number | null>(null)
const recoveryFile = ref<File | null>(null)
const recoveryNote = ref('')
const recoveryVerified = ref(false)
/**
 * Odhalená hesla pro dotaz na stav, jen v paměti záložky. Záměrně se nikam neukládají —
 * po reloadu je potřeba se ověřit znovu.
 */
const revealedPassword = ref<Record<number, string>>({})
const passwordBusy = computed(() => directBusy.value)
const recoveryPassword = ref('')
const recoveryTotpCode = ref('')

// Účelový step-up (passkey / TOTP proof) pro tři nezávislé dialogy: správa
// certifikátu, přímé podání a dohledání výsledku. Proof je jednorázový, takže
// se drží zvlášť pro každý z nich a po odeslání se zahazuje.
const passkeySupported = isWebAuthnAvailable()
const stepPasskeyToken = ref('')
const submitPasskeyToken = ref('')
const recoveryPasskeyToken = ref('')
const passkeyBusy = ref('')

const hasPasskey = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)
const hasTotp = computed(() => auth.user?.totp_enabled === true)

async function verifyEpoPasskey(target: 'step' | 'submit' | 'recovery') {
  if (!passkeySupported) return
  passkeyBusy.value = target
  try {
    const flow = await authApi.passkeyStepUpOptions('epo.certificate')
    const credential = await getCredential(flow.public_key)
    const proof = await authApi.passkeyStepUpVerify(flow.flow_token, 'epo.certificate', credential)
    if (target === 'step') stepPasskeyToken.value = proof
    else if (target === 'submit') submitPasskeyToken.value = proof
    else recoveryPasskeyToken.value = proof
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('reports.submissions.step_up_passkey_failed')))
  } finally {
    passkeyBusy.value = ''
  }
}

function stepProof() {
  return {
    password: stepPassword.value || undefined,
    totp_code: stepTotpCode.value.trim() || undefined,
    step_up_token: stepPasskeyToken.value || undefined,
  }
}

function submitProof() {
  return {
    password: submitPassword.value || undefined,
    totp_code: submitTotpCode.value.trim() || undefined,
    step_up_token: submitPasskeyToken.value || undefined,
  }
}

function recoveryProof() {
  return {
    password: recoveryPassword.value || undefined,
    totp_code: recoveryTotpCode.value.trim() || undefined,
    step_up_token: recoveryPasskeyToken.value || undefined,
  }
}

/**
 * Passkey proof nahradí heslo i TOTP naráz; bez něj platí původní cesta
 * heslo (+ TOTP, má-li ho účet zapnuté).
 */
function stepUpReady(password: string, totpCode: string, passkeyToken: string): boolean {
  if (passkeyToken !== '') return true
  if (password === '') return false
  return !hasTotp.value || /^\d{6}$/.test(totpCode.trim())
}

const submitStepUpReady = computed(() =>
  stepUpReady(submitPassword.value, submitTotpCode.value, submitPasskeyToken.value),
)
const recoveryStepUpReady = computed(() =>
  stepUpReady(recoveryPassword.value, recoveryTotpCode.value, recoveryPasskeyToken.value),
)

const settingsOpen = ref(false)
const settingsBusy = ref(false)
const settingsSaving = ref(false)
const folders = ref<EpoFolder[]>([])
const vatRootFolderId = ref<number | null>(null)
const incomeRootFolderId = ref<number | null>(null)
const epoEnvironment = ref<'production' | 'test' | null>(null)

const canWrite = computed(() => auth.canWrite('reports.submit'))
const canDelete = computed(() => canWrite.value && auth.canWrite('reports.export'))
const selected = computed(() => items.value.find(item => item.id === expandedId.value) ?? null)
const submitEnvironment = computed<'production' | 'test' | null>(() => {
  if (submitMode.value === 'test') return epoEnvironment.value
  return selected.value?.attempts.find(attempt => attempt.id === submitAttemptId.value)?.epo_environment
    ?? epoEnvironment.value
})
const enabledCredentials = computed(() =>
  credentials.value.filter(credential => credential.enabled_for_supplier && credential.valid_now),
)
const credentialStepUpMissing = computed(() => {
  if (stepPasskeyToken.value) return ''
  if (!stepPassword.value) return t('reports.submissions.step_up_password_missing')
  if (hasTotp.value && !/^\d{6}$/.test(stepTotpCode.value.trim())) {
    return t('reports.submissions.step_up_totp_missing')
  }
  return ''
})

/**
 * Filtrování i hledání dělá server (viz `epoSubmissionsApi.list`), takže `items`
 * UŽ JE výsledek filtru. Klientský filtr nad stránkou by po zavedení stránkování
 * lhal stejně jako dřív: sáhl by jen na načtených pár desítek řádků.
 */
const filtered = computed(() => items.value)

const formOptions = computed(() =>
  formCodes.value.map(code => ({ code, label: formCodeLabel(code) })),
)

const folderOptions = computed(() => {
  const byId = new Map(folders.value.map(folder => [folder.id, folder]))
  const label = (folder: EpoFolder): string => {
    const names = [folder.name]
    const seen = new Set<number>([folder.id])
    let parentId = folder.parent_id
    while (parentId !== null && byId.has(parentId) && !seen.has(parentId)) {
      const parent = byId.get(parentId)!
      names.unshift(parent.name)
      seen.add(parentId)
      parentId = parent.parent_id
    }
    return names.join(' / ')
  }
  return folders.value
    .map(folder => ({ id: folder.id, label: label(folder) }))
    .sort((a, b) => a.label.localeCompare(b.label, locale.value))
})

/** Odpovědi si číslujeme: pomalejší dřívější požadavek nesmí přepsat novější. */
let loadSeq = 0

async function load(showLoader = true) {
  if (showLoader) loading.value = true
  error.value = ''
  const seq = ++loadSeq
  try {
    const result = await epoSubmissionsApi.list({
      status: statusFilter.value,
      form_code: formFilter.value,
      q: search.value,
      limit: perPage,
      offset: (page.value - 1) * perPage,
    })
    if (seq !== loadSeq) return
    items.value = result.data
    total.value = result.meta.total
    stats.value = result.meta.stats
    formCodes.value = result.meta.form_codes
    // Smazání posledního řádku stránky nesmí nechat uživatele stát na prázdnu.
    if (items.value.length === 0 && page.value > 1) {
      page.value = Math.max(1, Math.ceil(result.meta.total / perPage))
      await load(false)
      return
    }
    reconcileHandoffLinks()
  } catch (e) {
    if (seq !== loadSeq) return
    error.value = apiErrorMessage(e)
  } finally {
    if (seq === loadSeq) loading.value = false
  }
}

/** Změna filtru vždy vrací na první stránku — jinak by uživatel skončil za koncem výpisu. */
watch([statusFilter, formFilter], () => {
  page.value = 1
  void load(false)
})

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    void load(false)
  }, 300)
})

watch(page, () => {
  void load(false)
})

function toggleDetail(item: TaxSubmission) {
  expandedId.value = expandedId.value === item.id ? null : item.id
  // Co už je u podání doložené, má přednost: nejdřív samotné podání, pak dodejka
  // a teprve nakonec PDF opis. Bez toho účetní přepisovala podací číslo, které
  // aplikace měla přečtené a zobrazovala ho o dva panely výš.
  const summary = receipt(item)
  const hint = pdfHint(item)
  manualRef.value = item.submission_ref ?? summary?.reference ?? hint?.reference ?? ''
  manualDate.value = toLocalInput(
    item.submitted_at ?? summary?.submitted_at ?? hint?.submitted_at ?? new Date().toISOString(),
  )
  prefillSource.value = item.submission_ref || item.submitted_at
    ? null
    : (summary?.reference || summary?.submitted_at ? 'receipt' : (hint ? 'pdf' : null))
  manualOpen.value = false
}

function formCodeLabel(code: string): string {
  const key = ({
    dphdp3: 'form_dphdp3',
    dphkh1: 'form_dphkh1',
    dphshv: 'form_dphshv',
    ossei1: 'form_ossei1',
    dpfdp5: 'form_dpfdp5',
    dpfdp7: 'form_dpfdp7',
    dppdp9: 'form_dppdp9',
    dpzvd6: 'form_dpzvd6',
    dpsvd2: 'form_dpsvd2',
  } as Record<string, string>)[code]
  return key ? t(`reports.submissions.${key}`) : code
}

// Přehled je společný pro daně i mzdy a z kódu formuláře to účetní nepozná.
// Vyúčtování závislé činnosti a srážkové daně vzniká ve mzdách, jen odchází
// touž cestou jako přiznání — proto se odlišuje štítkem, ne vlastní stránkou.
const PAYROLL_FORMS = new Set(['dpzvd6', 'dpsvd2'])

function isPayrollForm(code: string): boolean {
  return PAYROLL_FORMS.has(code)
}

// Písmeno varianty je EPO kód a KAŽDÝ formulář má vlastní sadu — „N" je
// u kontrolního hlášení následné, u souhrnného hlášení taky následné, ale
// „B" souhrnné hlášení nezná vůbec (má R). Společná mapa by tiše lhala,
// proto se páruje dvojice formulář + kód a neznámá kombinace zůstane holým
// písmenem místo vymyšleného popisku.
const VARIANT_LABELS: Record<string, Record<string, string>> = {
  dphdp3: { B: 'variant_radne', O: 'variant_opravne', D: 'variant_dodatecne', E: 'variant_dodatecne_opravne' },
  dphkh1: { B: 'variant_radne', O: 'variant_radne_opravne', N: 'variant_nasledne', E: 'variant_nasledne_opravne' },
  dphshv: { R: 'variant_radne', N: 'variant_nasledne' },
}

function variantLabel(item: TaxSubmission): string {
  const code = item.form_variant ?? ''
  if (!code) return ''
  const key = VARIANT_LABELS[item.form_code]?.[code]
  return key ? t(`reports.submissions.${key}`) : code
}

function periodLabel(item: TaxSubmission): string {
  if (item.period_month !== null) return `${item.period_year}-${String(item.period_month).padStart(2, '0')}`
  if (item.period_quarter !== null) return `${item.period_year} Q${item.period_quarter}`
  return String(item.period_year)
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString(locale.value)
}

function toLocalInput(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const shifted = new Date(date.getTime() - date.getTimezoneOffset() * 60000)
  return shifted.toISOString().slice(0, 16)
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KiB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MiB`
}

function latestAttempt(item: TaxSubmission): EpoAttempt | null {
  return item.attempts[0] ?? null
}

/** Nedokončený asistovaný pokus, který ještě může skončit podáním na portálu. */
const ASSISTED_OPEN_STATUSES = ['prepared', 'handoff_created', 'awaiting_confirmation']
/** Založený, ale neotevřený odkaz — portál ho po pár minutách stejně nevezme. */
const ASSISTED_PREPARED_WINDOW_MS = 5 * 60_000
/**
 * Záchranné okno pro pokusy BEZ `handoff_expires_at`. Bez něj by takový záznam
 * platil navždy a přímé podání by zůstalo zašedlé už napořád (portál nám reálnou
 * životnost odkazu neřekne, takže se řídíme poslední změnou stavu).
 */
const ASSISTED_FALLBACK_WINDOW_MS = 30 * 60_000

/** Okamžik, do kterého má smysl asistovaný pokus považovat za živý. */
function assistedAttemptDeadline(attempt: EpoAttempt): number {
  if (attempt.handoff_expires_at) {
    return new Date(attempt.handoff_expires_at).getTime()
  }
  if (attempt.status === 'prepared') {
    return new Date(attempt.requested_at).getTime() + ASSISTED_PREPARED_WINDOW_MS
  }
  return new Date(attempt.updated_at || attempt.requested_at).getTime() + ASSISTED_FALLBACK_WINDOW_MS
}

/** Asistovaná předání, u kterých ještě běží okno platnosti. */
function liveAssistedAttempts(item: TaxSubmission): EpoAttempt[] {
  return item.attempts.filter(attempt =>
    attempt.epo_environment === 'production'
    && attempt.channel === 'epo_assisted'
    && ASSISTED_OPEN_STATUSES.includes(attempt.status)
    && assistedAttemptDeadline(attempt) > Date.now(),
  )
}

function hasActiveAttempt(item: TaxSubmission): boolean {
  return liveAssistedAttempts(item).length > 0
}

/**
 * Asistované předání, které smí blokovat PŘÍMÉ podání.
 *
 * Rozpracované předání na portálu blokovat má — dvakrát podané hlášení je horší
 * problém než zašedlé tlačítko. Jenže když uživatel POTÉ vědomě spustil přímý
 * test se ZAREP a ten prošel, rozhodl se pro druhou cestu; starší asistovaný
 * záznam už jen visí v tabulce a jeho okno by tlačítko drželo zašedlé, aniž by
 * se s tím dalo cokoliv udělat. Novější úspěšný test proto starší asistovaný
 * pokus překlápí na neaktuální. Ochrana proti dvojímu ODESLÁNÍ tím nemizí — tu
 * drží `hasUnresolvedDirectAttempt()` a `hasUnresolvedProductionDirectAttempt()`
 * nad skutečně odeslanými pokusy, plus stejná brána na backendu.
 */
function blockingAssistedAttempt(item: TaxSubmission): EpoAttempt | null {
  const supersededBefore = lastPassedDirectTestAt(item)
  return liveAssistedAttempts(item).find(attempt =>
    // `>=`, ne `>`: časy v DB mají vteřinovou přesnost a při shodě musí vyhrát
    // opatrnější varianta (blokovat). Stejnou hranici drží i backendová brána.
    supersededBefore === null
    || new Date(attempt.requested_at).getTime() >= supersededBefore,
  ) ?? null
}

/**
 * Čas posledního ÚSPĚŠNÉHO přímého testu v aktuálním prostředí. Počítá se z časů,
 * ne z pořadí v poli — API sice vrací pokusy sestupně (`ORDER BY a.created_at DESC,
 * a.id DESC`), ale opřít o to blokaci ostrého podání by byla zbytečná past.
 */
function lastPassedDirectTestAt(item: TaxSubmission): number | null {
  const times = item.attempts
    .filter(attempt =>
      attempt.channel === 'epo_direct'
      && attempt.status === 'test_passed'
      && (epoEnvironment.value === null || attempt.epo_environment === epoEnvironment.value),
    )
    .map(attempt => new Date(attempt.requested_at).getTime())
    .filter(time => !Number.isNaN(time))
  return times.length > 0 ? Math.max(...times) : null
}

/**
 * Odkaz, který je pořád v okně platnosti a míří na nezměněný podklad — jediný,
 * který smíme nabídnout k otevření. Vyčerpaný záznam v cache zůstává (kvůli
 * `attemptId` pro nahrávané artefakty), ale jako odkaz už se netváří.
 */
function pendingHandoffLink(item: TaxSubmission): CachedEpoHandoffLink | null {
  const link = handoffLinks.value[item.id]
  return canOfferHandoffLink(link, item.xml_sha256) ? link! : null
}

/**
 * OSS přiznání se obecnou cestou EPO podat nedá: portál písemnost sice rozpozná,
 * ale odmítne ji hláškou, že uživatel musí být přihlášený v aplikaci MOSS/OSS.
 * Platí to pro OBA kanály — asistované předání i přímé podání se ZAREP míří na týž
 * endpoint `/dpr/epo_podani`, takže se lámou o stejnou podmínku. Jediná pravdivá
 * cesta je stáhnout XML a nahrát ho v MOSS/OSS, a tak se tu skryje handoff, panel
 * přímého podání i jeho akce. Backend oba kanály odmítá taky (`moss_oss_only`).
 */
function isMossOssForm(item: TaxSubmission): boolean {
  return item.form_code === 'ossei1'
}

function canHandoff(item: TaxSubmission): boolean {
  return canWrite.value
    && !isMossOssForm(item)
    && epoEnvironment.value !== null
    && item.validation_status === 'passed'
    && !['submitted', 'accepted'].includes(item.status)
    && !hasUnresolvedProductionDirectAttempt(item.attempts)
    && (!hasActiveAttempt(item) || !pendingHandoffLink(item))
    && !handoffBusy.value.has(item.id)
}

function hasUnresolvedDirectAttempt(item: TaxSubmission): boolean {
  return item.attempts.some(attempt =>
    attempt.channel === 'epo_direct'
    && attempt.epo_environment === epoEnvironment.value
    && (
      ['submitting', 'processing', 'uncertain'].includes(attempt.status)
      || (attempt.status === 'confirmed' && attempt.epo_environment === 'production')
    ),
  )
}

function latestDirectAttempt(item: TaxSubmission): EpoAttempt | null {
  return item.attempts.find(attempt =>
    attempt.channel === 'epo_direct'
    && (epoEnvironment.value === null || attempt.epo_environment === epoEnvironment.value),
  ) ?? null
}

function canOfferDirectActions(item: TaxSubmission): boolean {
  return canWrite.value
    && !isMossOssForm(item)
    && epoEnvironment.value !== null
    && enabledCredentials.value.length > 0
    && selectedCredentialId.value !== null
    && item.validation_status === 'passed'
    && !['submitted', 'accepted'].includes(item.status)
}

function canDirectTest(item: TaxSubmission): boolean {
  return canOfferDirectActions(item)
    && !hasUnresolvedDirectAttempt(item)
    && !directBusy.value
}

function canResolveDirectAttempt(attempt: EpoAttempt | null): boolean {
  if (!attempt || attempt.channel !== 'epo_direct' || attempt.refresh_available) return false
  if (attempt.status === 'uncertain') return true
  if (attempt.status !== 'submitting') return false
  return new Date(attempt.updated_at).getTime() + 15 * 60_000 <= Date.now()
}

function canDirectSubmit(item: TaxSubmission): boolean {
  return canOfferDirectActions(item)
    && latestDirectAttempt(item)?.status === 'test_passed'
    && !blockingAssistedAttempt(item)
    && !directBusy.value
}

/**
 * Pravdivý důvod, proč je „Podepsat a podat" zašedlé. Obecná hláška uživatele
 * nechá hádat, tak se rozpadá na konkrétní překážky v pořadí, v jakém je řeší
 * `canOfferDirectActions()` a `canDirectSubmit()`.
 */
function directSubmitDisabledReason(item: TaxSubmission): string | undefined {
  if (canDirectSubmit(item) || directBusy.value) return undefined
  if (!canOfferDirectActions(item)) {
    if (!canWrite.value) return t('reports.submissions.direct_reason_readonly')
    if (isMossOssForm(item)) return t('reports.submissions.direct_reason_moss_oss')
    if (epoEnvironment.value === null) return t('reports.submissions.direct_hint_unknown')
    if (enabledCredentials.value.length === 0 || selectedCredentialId.value === null) {
      return t('reports.submissions.direct_reason_no_credential')
    }
    if (item.validation_status !== 'passed') return t('reports.submissions.direct_reason_validation')
    return t('reports.submissions.direct_reason_already_submitted')
  }
  if (hasUnresolvedDirectAttempt(item)) {
    return t('reports.submissions.direct_reason_unresolved')
  }
  const direct = latestDirectAttempt(item)
  if (!direct) return t('reports.submissions.direct_reason_test_missing')
  if (direct.status !== 'test_passed') {
    return t('reports.submissions.direct_reason_test_required', { status: lifecycleLabel(direct.status) })
  }
  const blocking = blockingAssistedAttempt(item)
  if (blocking) {
    return t('reports.submissions.direct_reason_handoff_active', {
      status: lifecycleLabel(blocking.status),
      until: formatDate(blocking.handoff_expires_at),
    })
  }
  return t('reports.submissions.direct_hint_unknown')
}

function handoffButtonLabel(item: TaxSubmission): string {
  if (handoffBusy.value.has(item.id)) return t('common.loading')
  return hasActiveAttempt(item) && !pendingHandoffLink(item)
    ? t('reports.submissions.replace_epo_link')
    : t('reports.submissions.open_epo')
}

function lifecycleClass(status: string): string {
  if (['submitted', 'accepted', 'confirmed'].includes(status)) return 'bg-success-50 text-success-700 border-success-500/30'
  if (['rejected', 'failed', 'test_failed', 'uncertain'].includes(status)) return 'bg-danger-50 text-danger-600 border-danger-500/30'
  if (['awaiting_confirmation', 'handoff_created', 'testing', 'submitting', 'processing'].includes(status)) return 'bg-warning-50 text-warning-700 border-warning-500/30'
  if (status === 'test_passed') return 'bg-success-50 text-success-700 border-success-500/30'
  return 'bg-neutral-100 text-neutral-600 border-neutral-200'
}

function validationClass(status: string): string {
  if (status === 'passed') return 'bg-success-50 text-success-700 border-success-500/30'
  if (status === 'failed') return 'bg-danger-50 text-danger-600 border-danger-500/30'
  return 'bg-neutral-100 text-neutral-600 border-neutral-200'
}

function artifactClass(status: string): string {
  if (status === 'valid') return 'bg-success-50 text-success-700'
  if (status === 'warning') return 'bg-warning-50 text-warning-700'
  if (status === 'invalid') return 'bg-danger-50 text-danger-600'
  return 'bg-neutral-100 text-neutral-600'
}

function lifecycleLabel(status: string): string {
  return t(`reports.submissions.lifecycle_${status}`)
}

function artifactKindLabel(kind: string): string {
  return t(`reports.submissions.artifact_${kind}`)
}

/**
 * Složka v Dokumentech, kam podání ukládá své soubory.
 *
 * Bere se z artefaktů, ne z nastavení: cílová složka se odvozuje od období a typu
 * výkazu, takže nastavení říká jen kořen. Nejčastější složka vyhrává — kdyby se
 * archiv mezi pokusy přenastavil, odkaz vede tam, kde je většina důkazů.
 */
function artifactsFolderId(item: TaxSubmission): number | null {
  const counts = new Map<number, number>()
  for (const artifact of item.artifacts) {
    if (artifact.folder_id == null) continue
    counts.set(artifact.folder_id, (counts.get(artifact.folder_id) ?? 0) + 1)
  }
  let best: number | null = null
  let bestCount = 0
  for (const [folderId, count] of counts) {
    if (count > bestCount) { best = folderId; bestCount = count }
  }
  return best
}

/**
 * Shrnutí dodejky, které si aplikace odložila při rozbalení potvrzenky.
 *
 * Primárně visí na čitelném přepisu (`confirmation_xml`), protože právě z něj pochází.
 * Záložně se bere z ověření samotné P7S: u ručně nahrané dodejky se rozbalení může
 * nepovést (chybějící OpenSSL CMS, zkomolený soubor), ale podací číslo a rozhodný čas
 * z ověření známe — a nechat kvůli tomu panel prázdný by účetní poslalo do hex editoru.
 */
function receipt(item: TaxSubmission): EpoReceiptSummary | null {
  for (const kind of ['confirmation_xml', 'confirmation_p7s'] as const) {
    for (const artifact of item.artifacts) {
      if (artifact.artifact_kind !== kind) continue
      const summary = artifact.verification?.receipt
      if (summary && Object.keys(summary).length) return summary
    }
  }
  return null
}

/**
 * Co se přečetlo z PDF opisu. Je to jen odečtený text, ne důkaz — používá se
 * k předvyplnění tehdy, když dodejka chybí.
 */
function pdfHint(item: TaxSubmission): EpoReceiptHint | null {
  for (const artifact of item.artifacts) {
    if (artifact.artifact_kind !== 'receipt_pdf') continue
    const hint = artifact.verification?.hint
    if (hint && (hint.reference || hint.submitted_at)) return hint
  }
  return null
}

/**
 * Zprávy z ověřovacího testu bez `TEST_REZIM`.
 *
 * EPO tuhle informativní hlášku vrací u KAŽDÉHO úspěšného testu — je to konstatování,
 * že test nic nepodává. Vedle už potvrzeného podání ale čte jako „vaše hlášení nebylo
 * přijato", což je přesný opak pravdy. Ostatní hlášky (varování z věcných kontrol)
 * zůstávají, ty nesou informaci.
 */
function testProtocolMessages(item: TaxSubmission): EpoMessage[] {
  const messages = directMessages.value[item.id] ?? latestDirectAttempt(item)?.test_messages ?? []
  return messages.filter(m => (m.code ?? '').toUpperCase() !== 'TEST_REZIM')
}

/**
 * Co se z artefaktu povedlo PŘEČÍST — na rozdíl od {@link artifactVerificationHint}
 * to není problém, ale nález. Proto vlastní funkce i vlastní, neutrální styl:
 * v amber řádku vedle chyb podpisu by odečtené podací číslo vypadalo jako závada.
 */
function artifactReadHint(artifact: EpoArtifact): string {
  if (artifact.artifact_kind !== 'receipt_pdf') return ''
  const hint = artifact.verification?.hint
  return hint?.reference
    ? t('reports.submissions.pdf_hint_reference', { reference: hint.reference })
    : ''
}

function artifactVerificationHint(artifact: EpoArtifact): string {
  if (!artifact.verification) return ''
  if (artifact.artifact_kind === 'epo_xml') {
    if (artifact.verification.snapshot_sha256_match !== false) return ''
    const diff = artifact.verification.diff
    if (diff?.comparable && diff.form_match === false) {
      return t('reports.submissions.verify_problem_xml_other_form', {
        form: (diff.form_code ?? '?').toUpperCase(),
      })
    }
    if (diff?.comparable && diff.difference_count > 0) {
      return t('reports.submissions.verify_problem_xml_diff', { count: diff.difference_count })
    }
    return t('reports.submissions.verify_problem_xml_mismatch')
  }
  if (artifact.artifact_kind !== 'confirmation_p7s') return ''
  const verification = artifact.verification
  if (!verification.signature_valid) return t('reports.submissions.verify_problem_signature')
  if (!verification.chain_valid) return t('reports.submissions.verify_problem_chain')
  if (!verification.is_confirmation) return t('reports.submissions.verify_problem_format')
  if (verification.form_match === false) return t('reports.submissions.verify_problem_form')
  if (verification.form_match == null) return t('reports.submissions.verify_problem_form_unknown')
  if (verification.content_match === false) return t('reports.submissions.verify_problem_content')
  if (!verification.epo_signer_valid) return t('reports.submissions.verify_problem_signer')
  return ''
}

function handoffCacheScope(): { userId: number; supplierId: number } | null {
  const userId = auth.user?.id ?? 0
  const supplierId = supplier.currentSupplierId
  return userId > 0 && supplierId > 0 ? { userId, supplierId } : null
}

function restoreHandoffLinks() {
  const scope = handoffCacheScope()
  if (!scope) return
  handoffLinks.value = loadEpoHandoffLinks(
    localStorage,
    scope.userId,
    scope.supplierId,
  )
}

function persistHandoffLinks() {
  const scope = handoffCacheScope()
  if (!scope) return
  handoffLinks.value = saveEpoHandoffLinks(
    localStorage,
    scope.userId,
    scope.supplierId,
    handoffLinks.value,
  )
}

function reconcileHandoffLinks() {
  const remaining = Object.fromEntries(
    Object.entries(handoffLinks.value).filter(([submissionId, link]) => {
      const item = items.value.find(candidate => candidate.id === Number(submissionId))
      return item?.attempts.some(attempt =>
        attempt.id === link.attemptId
        && attempt.channel === 'epo_assisted'
        && attempt.epo_environment === 'production'
        && attempt.status === 'awaiting_confirmation',
      ) === true
    }),
  )
  if (Object.keys(remaining).length !== Object.keys(handoffLinks.value).length) {
    handoffLinks.value = remaining
    persistHandoffLinks()
  }
}

async function createHandoff(item: TaxSubmission) {
  if (!canHandoff(item)) return
  const popup = window.open('', '_blank')
  if (popup) popup.opener = null
  handoffBusy.value = new Set(handoffBusy.value).add(item.id)
  try {
    const replaceActive = hasActiveAttempt(item) && !pendingHandoffLink(item)
    const result = await epoSubmissionsApi.handoff(item.id, replaceActive)
    // Otisk podkladu se ukládá spolu s odkazem: jakmile se snapshot přepočítá,
    // starý odkaz míří na neaktuální písemnost a přestane se nabízet.
    handoffLinks.value = {
      ...handoffLinks.value,
      [item.id]: {
        url: result.url,
        expiresAt: result.expires_at,
        attemptId: result.attempt_id,
        xmlSha256: item.xml_sha256,
      },
    }
    persistHandoffLinks()
    expandedId.value = item.id
    manualDate.value = toLocalInput(new Date().toISOString())
    manualRef.value = ''
    await load(false)
    if (popup) {
      popup.location.replace(result.url)
      // Otevřeli jsme ji, takže je z pohledu portálu spotřebovaná. Když pop-up
      // neprošel, k otevření nedošlo a odkaz zůstane nabídnutý.
      markHandoffConsumed(item.id)
    } else {
      toast.warning(t('reports.submissions.popup_blocked'))
    }
    toast.success(t('reports.submissions.handoff_created'))
  } catch (e) {
    popup?.close()
    toast.error(apiErrorMessage(e))
    await load(false)
  } finally {
    const next = new Set(handoffBusy.value)
    next.delete(item.id)
    handoffBusy.value = next
  }
}

function openPendingHandoff(item: TaxSubmission) {
  const link = handoffLinks.value[item.id]
  if (!link) return
  if (!canOfferHandoffLink(link, item.xml_sha256)) {
    // Buď uplynulo okno platnosti, nebo se podklad mezitím přepočítal. Záznam
    // necháváme kvůli attemptId pro nahrávané artefakty, ale odkaz už nenabízíme.
    toast.warning(t('reports.submissions.handoff_stale_hint'))
    return
  }
  window.open(link.url, '_blank', 'noopener,noreferrer')
  markHandoffConsumed(item.id)
}

/**
 * URL do EPO je jednorázová — portál ji spotřebuje prvním otevřením. Jakmile jsme
 * ji otevřeli, přestaneme ji nabízet, ať uživatel místo nefunkčního „Pokračovat“
 * dostane nabídku vytvořit nový odkaz. Záznam nemažeme kvůli `attemptId`, přes
 * který se párují nahrané artefakty.
 */
function markHandoffConsumed(submissionId: number) {
  const link = handoffLinks.value[submissionId]
  if (!link || link.consumedAt !== undefined) return
  handoffLinks.value = {
    ...handoffLinks.value,
    [submissionId]: { ...link, consumedAt: new Date().toISOString() },
  }
  persistHandoffLinks()
}

async function uploadFiles(files: File[]) {
  const item = selected.value
  if (!item || files.length === 0 || uploadBusy.value) return
  const allowed = files.filter(file => /\.(xml|pdf|p7s|p7m)$/i.test(file.name))
  if (allowed.length !== files.length) {
    toast.warning(t('reports.submissions.upload_types'))
  }
  if (allowed.length === 0) return

  uploadBusy.value = true
  uploadProgress.value = 0
  try {
    const result = await epoSubmissionsApi.uploadArtifacts(
      item.id,
      allowed,
      handoffLinks.value[item.id]?.attemptId,
      value => {
        uploadProgress.value = value
      },
    )
    toast.success(t('reports.submissions.upload_done', { count: result.created.length }))
    if (result.errors.length > 0) {
      toast.warning(t('reports.submissions.upload_partial', { count: result.errors.length }))
    }
    applyReadValues(result.confirmation, result.hint)
    await load(false)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    uploadBusy.value = false
    uploadProgress.value = 0
    dragging.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

function onDrop(event: DragEvent) {
  dragging.value = false
  if (event.dataTransfer?.files) {
    void uploadFiles(Array.from(event.dataTransfer.files))
  }
}

function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  if (input.files) void uploadFiles(Array.from(input.files))
}

/**
 * Přenese do formuláře „Označit jako podané", co aplikace z nahraných souborů přečetla.
 *
 * Přepisuje se jen prázdné pole, ne to, co už uživatel vyplnil — jinak by nahrání
 * dalšího souboru přemazalo ručně opravenou hodnotu. Rozlišení zdroje je záměrné:
 * z dodejky je to podepsaný údaj, z PDF opisu jen odečtený text.
 */
function applyReadValues(
  confirmation: ArtifactUploadResult['confirmation'],
  hint: ArtifactUploadResult['hint'],
): void {
  const reference = confirmation?.reference ?? hint?.reference ?? null
  const submittedAt = confirmation?.submitted_at ?? hint?.submitted_at ?? null
  if (!reference && !submittedAt) return

  prefillSource.value = confirmation?.reference || confirmation?.submitted_at ? 'receipt' : 'pdf'
  if (reference && !manualRef.value.trim()) manualRef.value = reference
  if (submittedAt) manualDate.value = toLocalInput(submittedAt)
  manualOpen.value = true

  if (confirmation?.status_query_available) {
    toast.success(t('reports.submissions.state_password_stored'))
  }
}

async function markSubmittedManually() {
  const item = selected.value
  if (!item || !manualDate.value || manualBusy.value) return
  manualBusy.value = true
  try {
    const row = await epoSubmissionsApi.markSubmitted(item.id, manualDate.value, manualRef.value.trim())
    toast.success(t('reports.submissions.marked_submitted'))
    // Podání spouští zúčtování DPH (343). Když se nezaúčtovalo — typicky dodatečné
    // přiznání do zamčeného období — nesmí to zapadnout: rozdíl daně by v hlavní knize
    // chyběl a nikdo by se to nedozvěděl.
    // Varujeme jen u stavů, kdy doklad CHYBÍ, ač vzniknout měl. `posted`, nulová daň
    // ani firma mimo podvojné účetnictví problém nejsou.
    const clearing = row?.vat_clearing
    const clearingFailed = ['skipped', 'error', 'skipped_missing_accounts', 'skipped_flat_vat_account']
    if (clearing && clearingFailed.includes(clearing.status)) {
      toast.warning(t('reports.submissions.vat_clearing_skipped', {
        reason: clearing.error || clearing.reason || clearing.status,
      }))
    }
    await load(false)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    manualBusy.value = false
  }
}

async function loadCredentials() {
  if (!canWrite.value) return
  try {
    credentials.value = await epoSubmissionsApi.credentials()
    if (
      selectedCredentialId.value === null
      || !enabledCredentials.value.some(item => item.id === selectedCredentialId.value)
    ) {
      selectedCredentialId.value = enabledCredentials.value[0]?.id ?? null
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function openCredentialModal() {
  credentialModalOpen.value = true
  credentialBusy.value = true
  try {
    await loadCredentials()
  } finally {
    credentialBusy.value = false
  }
}

function resetCredentialSecrets() {
  stepPassword.value = ''
  stepTotpCode.value = ''
  stepPasskeyToken.value = ''
}

function requireCredentialStepUp(): boolean {
  if (!credentialStepUpMissing.value) return true
  toast.error(credentialStepUpMissing.value)
  return false
}

async function toggleCredential(credential: EpoSigningCredential) {
  if (credentialBusy.value) return
  if (credential.enabled_for_supplier && credential.linked_supplier_profiles_count > 0) {
    toast.error(t('reports.submissions.credential_used_by_company_profiles'))
    return
  }
  if (!requireCredentialStepUp()) return
  credentialBusy.value = true
  try {
    const result = await epoSubmissionsApi.setCredentialSupplier(
      credential.id,
      !credential.enabled_for_supplier,
      stepProof(),
    )
    credentials.value = result.credentials
    resetCredentialSecrets()
    if (!credential.enabled_for_supplier) selectedCredentialId.value = credential.id
    else if (selectedCredentialId.value === credential.id) {
      selectedCredentialId.value = enabledCredentials.value[0]?.id ?? null
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    credentialBusy.value = false
  }
}

async function deleteCredential(credential: EpoSigningCredential) {
  if (credential.linked_profiles_count > 0) {
    toast.error(t('reports.submissions.credential_used_by_profiles'))
    return
  }
  if (!requireCredentialStepUp()) return
  if (!confirm(t('reports.submissions.credential_delete_confirm'))) return
  credentialBusy.value = true
  try {
    await epoSubmissionsApi.deleteCredential(
      credential.id,
      stepProof(),
    )
    resetCredentialSecrets()
    await loadCredentials()
    toast.success(t('reports.submissions.credential_deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    credentialBusy.value = false
  }
}

function openTestModal(item: TaxSubmission) {
  if (!canDirectTest(item) || selectedCredentialId.value === null) return
  expandedId.value = item.id
  submitMode.value = 'test'
  submitAttemptId.value = null
  submitPassword.value = ''
  submitTotpCode.value = ''
  submitPasskeyToken.value = ''
  submitModalOpen.value = true
}

async function testDirect() {
  const item = selected.value
  if (!item || !canDirectTest(item) || selectedCredentialId.value === null) return
  directBusy.value = true
  try {
    const result = await epoSubmissionsApi.testDirect(
      item.id,
      selectedCredentialId.value,
      submitProof(),
    )
    directMessages.value = { ...directMessages.value, [item.id]: result.messages }
    submitModalOpen.value = false
    submitPassword.value = ''
    submitTotpCode.value = ''
    submitPasskeyToken.value = ''
    await load(false)
    if (result.passed) {
      toast.success(t(
        result.environment === 'test'
          ? 'reports.submissions.direct_test_passed_sandbox'
          : 'reports.submissions.direct_test_passed',
      ))
    }
    else toast.warning(t('reports.submissions.direct_test_failed'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await load(false)
  } finally {
    directBusy.value = false
  }
}

function openSubmitModal(item: TaxSubmission) {
  const attempt = latestDirectAttempt(item)
  if (!attempt || attempt.status !== 'test_passed') return
  expandedId.value = item.id
  submitMode.value = 'submit'
  submitAttemptId.value = attempt.id
  submitPassword.value = ''
  submitTotpCode.value = ''
  submitPasskeyToken.value = ''
  submitModalOpen.value = true
}

async function submitDirect() {
  const item = selected.value
  if (!item || submitAttemptId.value === null || directBusy.value) return
  directBusy.value = true
  try {
    const result = await epoSubmissionsApi.submitDirect(
      item.id,
      submitAttemptId.value,
      submitProof(),
    )
    submitModalOpen.value = false
    submitPassword.value = ''
    submitTotpCode.value = ''
    submitPasskeyToken.value = ''
    await load(false)
    if (result.status === 'processing') toast.warning(t('reports.submissions.direct_processing'))
    else if (result.environment === 'test') toast.success(t('reports.submissions.direct_sandbox_submitted'))
    else toast.success(t('reports.submissions.direct_submitted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await load(false)
  } finally {
    directBusy.value = false
  }
}

function confirmDirectOperation() {
  if (submitMode.value === 'test') void testDirect()
  else void submitDirect()
}

async function refreshDirectStatus(item: TaxSubmission, forced: EpoAttempt | null = null) {
  const attempt = forced ?? latestDirectAttempt(item)
  if (!attempt || directBusy.value) return
  directBusy.value = true
  try {
    await epoSubmissionsApi.refreshDirectStatus(item.id, attempt.id)
    await load(false)
    toast.success(t('reports.submissions.status_refreshed'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    directBusy.value = false
  }
}

/**
 * Pokus, ke kterému EPO vydalo heslo pro dotaz na stav.
 *
 * Bez ohledu na kanál: heslo i podací číslo vydává EPO v dodejce, a ta u asistovaného
 * podání dorazí ručním nahráním. Omezení na přímý kanál znamenalo, že účetní, která
 * dodejku nahrála, se ke stavu ani k opisu na portálu nedostala.
 */
function statePasswordAttemptId(item: TaxSubmission): number | null {
  return item.attempts.find(a => a.status_query_available)?.id ?? null
}

/** Asistované předání, u kterého jde dotáhnout stav z dodejky. */
function assistedStatusAttempt(item: TaxSubmission): EpoAttempt | null {
  return item.attempts.find(a =>
    a.channel === 'epo_assisted'
    && a.status_query_available
    && (epoEnvironment.value === null || a.epo_environment === epoEnvironment.value),
  ) ?? null
}

/**
 * Otevře odhalení hesla. Reuse modalu je záměrný: je to táž třída akce jako obnova
 * potvrzenky nebo uvolnění pokusu — vyžaduje čerstvé ověření a nechává auditní stopu.
 */
function openStatePassword(item: TaxSubmission) {
  const attemptId = statePasswordAttemptId(item)
  if (attemptId === null) return
  expandedId.value = item.id
  recoveryMode.value = 'state_password'
  recoveryAttemptId.value = attemptId
  recoveryFile.value = null
  recoveryNote.value = ''
  recoveryVerified.value = false
  recoveryPassword.value = ''
  recoveryTotpCode.value = ''
  recoveryPasskeyToken.value = ''
  recoveryModalOpen.value = true
}

function openRecoveryModal(item: TaxSubmission, mode: 'confirmation' | 'not_submitted') {
  const attempt = latestDirectAttempt(item)
  if (!attempt) return
  expandedId.value = item.id
  recoveryMode.value = mode
  recoveryAttemptId.value = attempt.id
  recoveryFile.value = null
  recoveryNote.value = ''
  recoveryVerified.value = false
  recoveryPassword.value = ''
  recoveryTotpCode.value = ''
  recoveryPasskeyToken.value = ''
  recoveryModalOpen.value = true
}

function onRecoveryFile(event: Event) {
  recoveryFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

async function confirmRecovery() {
  const item = selected.value
  if (!item || recoveryAttemptId.value === null || directBusy.value) return
  directBusy.value = true
  try {
    if (recoveryMode.value === 'state_password') {
      const result = await epoSubmissionsApi.revealStatePassword(
        item.id,
        recoveryAttemptId.value,
        recoveryProof(),
      )
      revealedPassword.value = { ...revealedPassword.value, [item.id]: result.state_password }
      recoveryModalOpen.value = false
      recoveryPassword.value = ''
      recoveryTotpCode.value = ''
      recoveryPasskeyToken.value = ''
      return
    }
    if (recoveryMode.value === 'confirmation') {
      if (!recoveryFile.value) return
      await epoSubmissionsApi.recoverDirectConfirmation(
        item.id,
        recoveryAttemptId.value,
        recoveryFile.value,
        recoveryProof(),
      )
      toast.success(t('reports.submissions.confirmation_recovered'))
    } else {
      await epoSubmissionsApi.resolveDirectNotSubmitted(
        item.id,
        recoveryAttemptId.value,
        recoveryNote.value,
        recoveryProof(),
      )
      toast.success(t('reports.submissions.attempt_released'))
    }
    recoveryModalOpen.value = false
    recoveryPassword.value = ''
    recoveryTotpCode.value = ''
    recoveryPasskeyToken.value = ''
    await load(false)
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await load(false)
  } finally {
    directBusy.value = false
  }
}

async function deleteItem(item: TaxSubmission) {
  // Smazat jde všechno, co nemá doložené podání. U nedořešeného předání aplikace neví,
  // jestli uživatel v portálu EPO nakonec podal, takže mu to řekneme — ale nevymáháme
  // po něm text. Auditní stopa rozliší vědomé zahození od skutečného ověření.
  const question = item.delete_needs_acknowledgement
    ? t('reports.submissions.delete_confirm_unresolved')
    : t('reports.submissions.delete_confirm')
  if (!confirm(question)) return
  const note: string | undefined = undefined
  try {
    await epoSubmissionsApi.remove(item.id, note)
    if (expandedId.value === item.id) expandedId.value = null
    await load(false)
    toast.success(t('common.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

/**
 * Akci ukazujeme vždy, když má uživatel právo mazat — i když je snapshot zamčený.
 * Chybějící tlačítko nechávalo uživatele hádat, PROČ mazání nejde; zašedlé tlačítko
 * s větou v `disabledReason` je tu tou lepší variantou.
 */
function deleteDisabledReason(item: TaxSubmission): string | undefined {
  switch (item.delete_blocker) {
    case 'submitted_snapshot':
      return t('reports.submissions.delete_blocked_submitted')
    case 'delivered_attempt':
      return t('reports.submissions.delete_blocked_delivered')
    default:
      return undefined
  }
}

async function openSettings() {
  settingsOpen.value = true
  settingsBusy.value = true
  try {
    const settings = await epoSubmissionsApi.settings()
    vatRootFolderId.value = settings.vat_root_folder_id
    incomeRootFolderId.value = settings.income_tax_root_folder_id
    folders.value = settings.folders
    epoEnvironment.value = settings.epo_environment
  } catch (e) {
    toast.error(apiErrorMessage(e))
    settingsOpen.value = false
  } finally {
    settingsBusy.value = false
  }
}

async function saveSettings() {
  settingsSaving.value = true
  try {
    await epoSubmissionsApi.updateSettings({
      vat_root_folder_id: vatRootFolderId.value,
      income_tax_root_folder_id: incomeRootFolderId.value,
    })
    settingsOpen.value = false
    toast.success(t('common.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    settingsSaving.value = false
  }
}

async function loadEpoEnvironment() {
  try {
    const settings = await epoSubmissionsApi.settings()
    epoEnvironment.value = settings.epo_environment
  } catch {
    epoEnvironment.value = null
  }
}

/**
 * Akce nad vybraným podáním pro sdílený ActionBar.
 *
 * Podmínky jsou převzaté 1:1 z původních tlačítek — viditelnost pěti z osmi jede
 * přes stavové helpery nad `attempts[]` a rozejít se s nimi by znamenalo nabízet
 * podání, které EPO odmítne. Proto se tu nic „nezjednodušuje".
 *
 * Pořadí drží scénář: podat → pokračovat na EPO → ověřit/stáhnout. Zotavovací
 * akce (obnovit stav, dohledat potvrzení, vyřešit nepodáno) patří do dropdownu:
 * používají se výjimečně a v hlavičce by rozmělnily to hlavní. Smazání je
 * `advanced`, aby se nedalo trefit omylem.
 */
const submissionActions = computed<ActionItem[]>(() => {
  const s = selected.value
  if (!s) return []
  const attempt = latestDirectAttempt(s)

  return [
    {
      key: 'submit_direct',
      label: t(epoEnvironment.value === 'test' ? 'reports.submissions.submit_direct_sandbox' : 'reports.submissions.submit_direct'),
      icon: 'send',
      tier: canDirectSubmit(s) ? 'primary' : 'secondary',
      variant: 'success',
      show: canOfferDirectActions(s),
      disabled: !canDirectSubmit(s),
      disabledReason: directSubmitDisabledReason(s),
      run: () => openSubmitModal(s),
    },
    {
      key: 'continue_epo',
      label: t('reports.submissions.continue_epo'),
      icon: 'send',
      tier: 'primary',
      variant: 'warning',
      show: !!pendingHandoffLink(s),
      run: () => openPendingHandoff(s),
    },
    {
      // v-else-if k předchozí položce: neotevřený handoff má přednost před založením nového.
      key: 'handoff',
      label: handoffButtonLabel(s),
      icon: 'send',
      tier: 'primary',
      variant: 'primary',
      show: !pendingHandoffLink(s) && canHandoff(s),
      run: () => createHandoff(s),
    },
    {
      key: 'run_test',
      label: t('reports.submissions.run_direct_test'),
      icon: 'badgeCheck',
      tier: 'secondary',
      variant: 'primary',
      show: canOfferDirectActions(s),
      disabled: !canDirectTest(s),
      run: () => openTestModal(s),
    },
    {
      key: 'download_xml',
      label: t('reports.submissions.download_xml'),
      icon: 'download',
      tier: 'secondary',
      href: epoSubmissionsApi.xmlUrl(s.id),
    },
    {
      key: 'refresh_status',
      label: t('reports.submissions.refresh_epo_status'),
      icon: 'cycle',
      tier: 'overflow',
      show: attempt?.refresh_available,
      disabled: directBusy.value,
      run: () => refreshDirectStatus(s),
    },
    {
      // Asistované předání s nahranou dodejkou. Vlastní položka, protože předchozí
      // visí na přímém pokusu — kdyby se sloučily, u snapshotu s oběma kanály by
      // se tlačítko vázalo k jinému pokusu, než ke kterému patří podací číslo.
      key: 'refresh_status_assisted',
      label: t('reports.submissions.refresh_epo_status'),
      icon: 'cycle',
      tier: 'overflow',
      show: !attempt?.refresh_available && assistedStatusAttempt(s) !== null,
      disabled: directBusy.value,
      run: () => refreshDirectStatus(s, assistedStatusAttempt(s)),
    },
    {
      key: 'recover_confirmation',
      label: t('reports.submissions.recover_confirmation'),
      icon: 'upload',
      tier: 'overflow',
      variant: 'warning',
      show: attempt?.confirmation_recovery_available
        && ['submitting', 'processing', 'confirmed', 'uncertain'].includes(attempt?.status ?? ''),
      disabled: directBusy.value,
      run: () => openRecoveryModal(s, 'confirmation'),
    },
    {
      key: 'resolve_not_submitted',
      label: t('reports.submissions.resolve_not_submitted'),
      icon: 'x',
      tier: 'overflow',
      variant: 'danger',
      show: canResolveDirectAttempt(attempt),
      disabled: directBusy.value,
      run: () => openRecoveryModal(s, 'not_submitted'),
    },
    {
      key: 'delete',
      label: t('common.delete'),
      icon: 'trash',
      tier: 'advanced',
      variant: 'danger',
      show: canDelete.value,
      disabled: !s.deletable && !s.delete_needs_acknowledgement,
      disabledReason: deleteDisabledReason(s),
      run: () => deleteItem(s),
    },
  ]
})

onMounted(async () => {
  restoreHandoffLinks()
  await Promise.all([load(), loadCredentials(), loadEpoEnvironment()])
})
</script>

<template>
  <div class="max-w-7xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.submissions.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1 max-w-3xl">{{ t('reports.submissions.subtitle') }}</p>
        <p class="text-sm text-neutral-500 mt-2 max-w-3xl">{{ t('reports.submissions.channels_hint') }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-if="canWrite" type="button" :class="btnOutline('primary')" @click="openCredentialModal">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2Zm10-10V7a4 4 0 0 0-8 0v4"/>
          </svg>
          {{ t('reports.submissions.certificates') }}
        </button>
        <button v-if="canWrite" type="button" :class="btnOutline('neutral')" @click="openSettings">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 1 0 0 4m0-4a2 2 0 1 1 0 4m-6 8a2 2 0 1 0 0-4m0 4v2m0-6V4m12 14a2 2 0 1 0 0-4m0 4v2m0-6V4"/>
          </svg>
          {{ t('reports.submissions.settings') }}
        </button>
        <button type="button" :class="btnOutline('neutral')" @click="load()">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>
    </div>

    <div
      v-if="epoEnvironment === 'test'"
      class="rounded-lg border border-warning-500/40 bg-warning-50 p-4 flex gap-3 text-warning-900"
    >
      <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/>
      </svg>
      <div class="text-sm">
        <p class="font-medium">{{ t('reports.submissions.epo_test_environment_title') }}</p>
        <p class="mt-1 text-warning-800">{{ t('reports.submissions.epo_test_environment_hint') }}</p>
      </div>
    </div>
    <div
      v-else-if="epoEnvironment === null"
      class="rounded-lg border border-danger-500/40 bg-danger-50 p-4 flex gap-3 text-danger-700"
    >
      <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.7 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/>
      </svg>
      <div class="text-sm">
        <p class="font-medium">{{ t('reports.submissions.epo_environment_unknown_title') }}</p>
        <p class="mt-1">{{ t('reports.submissions.epo_environment_unknown_hint') }}</p>
      </div>
    </div>

    <div class="rounded-lg border border-primary-500/25 bg-primary-50 p-4 flex gap-3">
      <svg class="w-5 h-5 text-primary-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
      </svg>
      <div class="text-sm text-primary-900">
        <p class="font-medium">{{ t('reports.submissions.direct_title') }}</p>
        <p class="mt-1 text-primary-800">
          {{ t(
            epoEnvironment === 'test'
              ? 'reports.submissions.direct_hint_sandbox'
              : epoEnvironment === 'production'
                ? 'reports.submissions.direct_hint'
                : 'reports.submissions.direct_hint_unknown'
          ) }}
        </p>
        <p class="mt-1 text-primary-700">{{ t('reports.submissions.assisted_alternative') }}</p>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="bg-surface border border-neutral-200 rounded-lg p-3">
        <div class="text-xs text-neutral-500">{{ t('reports.submissions.stat_total') }}</div>
        <div class="text-2xl font-semibold mt-1">{{ stats.total }}</div>
      </div>
      <div class="bg-surface border border-warning-500/25 rounded-lg p-3">
        <div class="text-xs text-warning-700">{{ t('reports.submissions.stat_waiting') }}</div>
        <div class="text-2xl font-semibold text-warning-700 mt-1">{{ stats.waiting }}</div>
      </div>
      <div class="bg-surface border border-success-500/25 rounded-lg p-3">
        <div class="text-xs text-success-700">{{ t('reports.submissions.stat_submitted') }}</div>
        <div class="text-2xl font-semibold text-success-700 mt-1">{{ stats.submitted }}</div>
      </div>
      <div class="bg-surface border border-danger-500/25 rounded-lg p-3">
        <div class="text-xs text-danger-600">{{ t('reports.submissions.stat_problems') }}</div>
        <div class="text-2xl font-semibold text-danger-600 mt-1">{{ stats.problems }}</div>
      </div>
    </div>

    <div class="flex flex-wrap gap-2">
      <input v-model="search" type="search"
        class="h-9 min-w-56 flex-1 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
        :placeholder="t('reports.submissions.search_placeholder')">
      <select v-model="statusFilter" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        <option value="all">{{ t('reports.submissions.filter_all_statuses') }}</option>
        <option value="downloaded">{{ lifecycleLabel('downloaded') }}</option>
        <option value="submitted">{{ lifecycleLabel('submitted') }}</option>
        <option value="accepted">{{ lifecycleLabel('accepted') }}</option>
        <option value="rejected">{{ lifecycleLabel('rejected') }}</option>
      </select>
      <select v-model="formFilter" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        <option value="all">{{ t('reports.submissions.filter_all_forms') }}</option>
        <option v-for="option in formOptions" :key="option.code" :value="option.code">{{ option.label }}</option>
      </select>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg p-10 text-center text-sm text-neutral-500">
      {{ t('common.loading') }}…
    </div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-600 rounded-lg p-4 text-sm">{{ error }}</div>
    <EmptyState v-else-if="filtered.length === 0" boxed accent="neutral" icon="doc" :title="t('reports.submissions.empty')" />

    <template v-else>
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-4 py-3">{{ t('reports.submissions.form') }}</th>
              <th class="text-left px-3 py-3">{{ t('reports.submissions.period') }}</th>
              <th class="text-left px-3 py-3">{{ t('reports.submissions.generated_at') }}</th>
              <th class="text-left px-3 py-3">{{ t('reports.submissions.lifecycle') }}</th>
              <th class="text-left px-3 py-3">XSD</th>
              <th class="text-right px-4 py-3">{{ t('reports.submissions.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in filtered" :key="item.id"
              class="hover:bg-neutral-50 cursor-pointer"
              :class="{ 'bg-primary-50/50': expandedId === item.id }"
              @click="toggleDetail(item)">
              <td class="px-4 py-3">
                <div class="font-medium">
                  {{ formCodeLabel(item.form_code) }}
                  <span v-if="isPayrollForm(item.form_code)"
                  class="ml-2 inline-flex rounded-full border border-primary-200 bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                  {{ t('reports.submissions.origin_payroll') }}
                </span>
                </div>
                <div class="text-xs text-neutral-400 mt-0.5">
                  <span class="font-mono">#{{ item.id }}</span>
                  <template v-if="variantLabel(item)"> · {{ variantLabel(item) }}</template>
                </div>
              </td>
              <td class="px-3 py-3 font-mono text-xs">{{ periodLabel(item) }}</td>
              <td class="px-3 py-3 text-xs text-neutral-500">{{ formatDate(item.generated_at) }}</td>
              <td class="px-3 py-3">
                <span :class="['inline-flex rounded-full border px-2 py-0.5 text-xs font-medium', lifecycleClass(item.status)]">
                  {{ lifecycleLabel(item.status) }}
                </span>
                <div
                  v-if="!['submitted', 'accepted'].includes(item.status) && latestAttempt(item)?.status === 'awaiting_confirmation'"
                  class="text-xs text-warning-700 mt-1"
                >
                  {{ lifecycleLabel('awaiting_confirmation') }}
                </div>
              </td>
              <td class="px-3 py-3">
                <span :class="['inline-flex rounded-full border px-2 py-0.5 text-xs font-medium', validationClass(item.validation_status)]">
                  {{ t(`reports.submissions.status_${item.validation_status}`) }}
                </span>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap justify-end gap-2" @click.stop>
                  <button v-if="pendingHandoffLink(item)" type="button" :class="btnFilledSm('warning')" @click="openPendingHandoff(item)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send"/>
                    </svg>
                    {{ t('reports.submissions.continue_epo') }}
                  </button>
                  <button v-else-if="canHandoff(item)" type="button" :class="btnFilledSm('primary')" @click="createHandoff(item)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send"/>
                    </svg>
                    {{ handoffButtonLabel(item) }}
                  </button>
                  <button v-else type="button" :class="btnOutlineSm('neutral')" @click="toggleDetail(item)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc"/>
                    </svg>
                    {{ t('common.detail') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="md:hidden space-y-3">
        <button v-for="item in filtered" :key="item.id" type="button"
          class="w-full text-left bg-surface border rounded-lg p-4"
          :class="expandedId === item.id ? 'border-primary-500' : 'border-neutral-200'"
          @click="toggleDetail(item)">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="font-medium">
                {{ formCodeLabel(item.form_code) }}
                <span v-if="isPayrollForm(item.form_code)"
                  class="ml-2 inline-flex rounded-full border border-primary-200 bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
                  {{ t('reports.submissions.origin_payroll') }}
                </span>
              </div>
              <div class="text-xs text-neutral-500 mt-1">{{ periodLabel(item) }} · {{ formatDate(item.generated_at) }}</div>
            </div>
            <span :class="['inline-flex rounded-full border px-2 py-0.5 text-xs font-medium', lifecycleClass(item.status)]">
              {{ lifecycleLabel(item.status) }}
            </span>
          </div>
          <div class="flex items-center justify-between mt-3">
            <span :class="['inline-flex rounded-full border px-2 py-0.5 text-xs font-medium', validationClass(item.validation_status)]">
              XSD · {{ t(`reports.submissions.status_${item.validation_status}`) }}
            </span>
            <span class="text-xs text-neutral-500">{{ item.artifacts.length }} {{ t('reports.submissions.files') }}</span>
          </div>
        </button>
      </div>

      <PaginationBar v-model:page="page" :per-page="perPage" :total="total" />
    </template>

    <section v-if="selected" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="px-4 py-3 border-b border-neutral-200 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="font-semibold">{{ formCodeLabel(selected.form_code) }} · {{ periodLabel(selected) }}</h2>
          <p class="text-xs text-neutral-500 mt-1">{{ t('reports.submissions.snapshot') }} #{{ selected.id }}</p>
        </div>
        <!-- Až osm akcí nad jedním podáním porušovalo konvenci „max 3 a zbytek
             do …". ActionBar cap řeší sám a drží pořadí: podat → pokračovat na
             EPO → test/XML, zotavovací akce a mazání v dropdownu. -->
        <ActionBar :actions="submissionActions" />
      </div>

      <div class="p-4 grid lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 space-y-4">
          <div v-if="selected.validation_status !== 'passed'" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3">
            <div class="font-medium text-danger-700 text-sm">{{ t('reports.submissions.preflight_failed') }}</div>
            <ul v-if="selected.validation_errors.length" class="mt-2 list-disc pl-5 text-xs text-danger-600 space-y-1">
              <li v-for="validationError in selected.validation_errors" :key="validationError">{{ validationError }}</li>
            </ul>
          </div>

          <div
            v-if="isMossOssForm(selected)"
            class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 flex flex-wrap items-start justify-between gap-3"
          >
            <div class="min-w-0">
              <div class="font-medium text-warning-800 text-sm">{{ t('reports.submissions.moss_oss_title') }}</div>
              <p class="text-xs text-warning-700 mt-1">{{ t('reports.submissions.moss_oss_hint') }}</p>
            </div>
            <a :href="epoSubmissionsApi.xmlUrl(selected.id)" :class="btnFilled('warning')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download"/>
              </svg>
              {{ t('reports.submissions.download_xml') }}
            </a>
          </div>

          <!-- U MOSS/OSS písemností panel nesvítí vůbec: sliboval by cestu, kterou
               portál odmítne, a přitom hned nad ním stojí opačné sdělení. -->
          <div v-if="!isMossOssForm(selected)" class="rounded-lg border border-primary-500/25 bg-primary-50/60 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 class="font-medium text-sm text-primary-900">{{ t('reports.submissions.direct_panel_title') }}</h3>
                <p class="text-xs text-primary-800 mt-1">
                  {{ t(
                    epoEnvironment === 'test'
                      ? 'reports.submissions.direct_panel_hint_sandbox'
                      : epoEnvironment === 'production'
                        ? 'reports.submissions.direct_panel_hint'
                        : 'reports.submissions.direct_panel_hint_unknown'
                  ) }}
                </p>
              </div>
              <button v-if="enabledCredentials.length === 0 && canWrite" type="button" :class="btnOutlineSm('primary')" @click="openCredentialModal">
                {{ t('reports.submissions.add_certificate') }}
              </button>
            </div>
            <label v-if="enabledCredentials.length > 0" class="block text-xs text-neutral-600 mt-3">
              {{ t('reports.submissions.signing_certificate') }}
              <select v-model="selectedCredentialId" class="mt-1 w-full h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm">
                <option v-for="credential in enabledCredentials" :key="credential.id" :value="credential.id">
                  {{ credential.label }} · {{ credential.subject_dn }}
                </option>
              </select>
            </label>
            <div v-else class="mt-3 rounded border border-warning-500/30 bg-warning-50 p-3 text-xs text-warning-800">
              {{ t('reports.submissions.no_enabled_certificate') }}
            </div>

            <div v-if="latestDirectAttempt(selected)" class="mt-3 flex flex-wrap items-center gap-2">
              <span :class="['rounded-full border px-2 py-0.5 text-xs font-medium', lifecycleClass(latestDirectAttempt(selected)?.status ?? 'prepared')]">
                {{ lifecycleLabel(latestDirectAttempt(selected)?.status ?? 'prepared') }}
              </span>
              <span v-if="latestDirectAttempt(selected)?.tested_at" class="text-xs text-neutral-500">
                {{ formatDate(latestDirectAttempt(selected)?.tested_at ?? null) }}
              </span>
              <span v-if="latestDirectAttempt(selected)?.next_poll_at" class="text-xs text-neutral-500">
                {{ t('reports.submissions.next_status_check') }} {{ formatDate(latestDirectAttempt(selected)?.next_poll_at ?? null) }}
              </span>
            </div>
            <div v-if="testProtocolMessages(selected).length" class="mt-3 text-xs text-neutral-500">
              {{ t('reports.submissions.test_protocol_heading') }}
            </div>
            <ul
              v-if="testProtocolMessages(selected).length"
              class="mt-1 space-y-2"
            >
              <li
                v-for="(message, index) in testProtocolMessages(selected)"
                :key="`${message.code}-${index}`"
                class="rounded border px-3 py-2 text-xs"
                :class="['S', 'K', 'E'].includes(message.type ?? '') ? 'border-danger-500/30 bg-danger-50 text-danger-700' : 'border-warning-500/30 bg-warning-50 text-warning-800'"
              >
                <div class="font-medium">{{ message.code || message.type || t('reports.submissions.epo_message') }}</div>
                <div class="mt-0.5">{{ message.text }}</div>
                <div v-if="message.field || message.section || message.line" class="mt-1 opacity-75">
                  {{ [message.section, message.field, message.line].filter(Boolean).join(' · ') }}
                </div>
              </li>
            </ul>
          </div>

          <div v-if="pendingHandoffLink(selected)" class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 flex flex-wrap items-center justify-between gap-3">
            <div>
              <div class="font-medium text-warning-800 text-sm">{{ t('reports.submissions.handoff_ready') }}</div>
              <div class="text-xs text-warning-700 mt-1">{{ t('reports.submissions.handoff_window') }}</div>
            </div>
            <button type="button" :class="btnFilled('warning')" @click="openPendingHandoff(selected)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send"/>
              </svg>
              {{ t('reports.submissions.continue_epo') }}
            </button>
          </div>
          <!-- Okno platnosti uplynulo, nebo se podklad přepočítal. Odkaz už nenabízíme —
               místo toho nabídneme vytvoření nového, což je levné a vždycky funguje. -->
          <div v-else-if="handoffLinks[selected.id]" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 flex flex-wrap items-center justify-between gap-3">
            <div>
              <div class="font-medium text-neutral-800 text-sm">{{ t('reports.submissions.handoff_stale_title') }}</div>
              <div class="text-xs text-neutral-600 mt-1">{{ t('reports.submissions.handoff_stale_hint') }}</div>
            </div>
            <button v-if="canHandoff(selected)" type="button" :class="btnOutline('primary')" @click="createHandoff(selected)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send"/>
              </svg>
              {{ t('reports.submissions.replace_epo_link') }}
            </button>
          </div>

          <div>
            <h3 class="font-medium text-sm">{{ t('reports.submissions.upload_title') }}</h3>
            <p class="text-xs text-neutral-500 mt-1">{{ t('reports.submissions.upload_hint') }}</p>
            <input ref="fileInput" type="file" multiple class="hidden"
              accept=".xml,.pdf,.p7s,.p7m"
              @change="onFileChange">
            <button type="button"
              class="mt-3 w-full min-h-32 rounded-lg border-2 border-dashed p-5 text-center transition"
              :class="dragging ? 'border-primary-500 bg-primary-50' : 'border-neutral-300 hover:border-primary-400 hover:bg-neutral-50'"
              :disabled="!canWrite || uploadBusy"
              @click="fileInput?.click()"
              @dragenter.prevent="dragging = true"
              @dragover.prevent="dragging = true"
              @dragleave.prevent="dragging = false"
              @drop.prevent="onDrop">
              <svg class="w-8 h-8 mx-auto text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload"/>
              </svg>
              <div class="font-medium text-sm mt-2">{{ t('reports.submissions.drop_files') }}</div>
              <div class="text-xs text-neutral-500 mt-1">{{ t('reports.submissions.upload_types') }}</div>
              <div v-if="uploadBusy" class="mt-3">
                <div class="h-1.5 rounded-full bg-neutral-200 overflow-hidden">
                  <div class="h-full bg-primary-600 transition-all" :style="{ width: `${uploadProgress}%` }"></div>
                </div>
                <div class="text-xs text-neutral-500 mt-1">{{ uploadProgress }} %</div>
              </div>
            </button>
          </div>

          <div>
            <div class="flex items-center justify-between gap-3">
              <h3 class="font-medium text-sm">{{ t('reports.submissions.artifacts_title') }}</h3>
              <div class="flex items-center gap-3">
                <RouterLink
                  v-if="artifactsFolderId(selected)"
                  :to="{ name: 'documents', query: { folder: String(artifactsFolderId(selected)) } }"
                  class="text-xs text-primary-600 hover:text-primary-700 hover:underline inline-flex items-center gap-1"
                >
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                  </svg>
                  {{ t('reports.submissions.open_documents_folder') }}
                </RouterLink>
                <span class="text-xs text-neutral-500">{{ selected.artifacts.length }}</span>
              </div>
            </div>
            <div v-if="selected.artifacts.length === 0" class="mt-2 text-sm text-neutral-500 border border-dashed border-neutral-300 rounded-lg p-4">
              {{ t('reports.submissions.no_artifacts') }}
            </div>
            <div v-else class="mt-2 divide-y divide-neutral-100 border border-neutral-200 rounded-lg">
              <div v-for="artifact in selected.artifacts" :key="artifact.id" class="p-3 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-sm truncate">{{ artifact.original_name }}</span>
                    <span :class="['rounded px-1.5 py-0.5 text-xs', artifactClass(artifact.verification_status)]">
                      {{ t(`reports.submissions.verify_${artifact.verification_status}`) }}
                    </span>
                  </div>
                  <div class="text-xs text-neutral-500 mt-1">
                    {{ artifactKindLabel(artifact.artifact_kind) }} · {{ formatSize(artifact.size_bytes) }} · {{ formatDate(artifact.created_at) }}
                  </div>
                  <div v-if="artifact.verification?.reference" class="text-xs text-success-700 mt-1">
                    {{ t('reports.submissions.reference') }}: {{ artifact.verification.reference }}
                  </div>
                  <div v-if="artifactVerificationHint(artifact)" class="text-xs text-warning-700 mt-1">
                    {{ artifactVerificationHint(artifact) }}
                  </div>
                  <div v-if="artifactReadHint(artifact)" class="text-xs text-neutral-600 mt-1">
                    {{ artifactReadHint(artifact) }}
                  </div>
                </div>
                <div class="flex gap-2">
                  <a :href="epoSubmissionsApi.artifactDownloadUrl(selected.id, artifact.id)" :class="btnOutlineSm('neutral')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download"/>
                    </svg>
                    {{ t('common.download') }}
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>

        <aside class="space-y-4">
          <div class="rounded-lg border border-neutral-200 p-3">
            <h3 class="font-medium text-sm">{{ t('reports.submissions.snapshot_details') }}</h3>
            <dl class="mt-3 space-y-2 text-xs">
              <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('reports.submissions.size') }}</dt><dd>{{ formatSize(selected.xml_size_bytes) }}</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('reports.submissions.generated_at') }}</dt><dd class="text-right">{{ formatDate(selected.generated_at) }}</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('reports.submissions.submitted_at') }}</dt><dd class="text-right">{{ formatDate(selected.submitted_at) }}</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('reports.submissions.reference') }}</dt><dd class="text-right">{{ selected.submission_ref || '—' }}</dd></div>
            </dl>
            <div class="mt-3">
              <div class="text-xs text-neutral-500">SHA-256</div>
              <code class="block mt-1 text-[11px] break-all rounded bg-neutral-50 p-2">{{ selected.xml_sha256 }}</code>
            </div>
          </div>

          <!-- Údaje z rozbalené dodejky: to podstatné z potvrzenky bez otevírání XML. -->
          <div v-if="receipt(selected)" class="rounded-lg border border-neutral-200 p-3">
            <div class="flex items-center justify-between gap-2">
              <h3 class="font-medium text-sm">{{ t('reports.submissions.receipt_title') }}</h3>
              <span
                v-if="receipt(selected)?.zarep"
                class="rounded-full border border-success-500/30 bg-success-50 px-2 py-0.5 text-[11px] font-medium text-success-700"
              >ZAREP</span>
            </div>
            <dl class="mt-3 space-y-2 text-xs">
              <div v-if="receipt(selected)?.reference" class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t('reports.submissions.reference') }}</dt>
                <dd class="text-right tabular-nums">{{ receipt(selected)?.reference }}</dd>
              </div>
              <div v-if="receipt(selected)?.submitted_at" class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t('reports.submissions.receipt_decisive_time') }}</dt>
                <dd class="text-right">{{ formatDate(receipt(selected)?.submitted_at ?? null) }}</dd>
              </div>
              <div v-if="receipt(selected)?.office_code" class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t('reports.submissions.receipt_office') }}</dt>
                <dd class="text-right tabular-nums">{{ receipt(selected)?.office_code }}</dd>
              </div>
              <div v-if="receipt(selected)?.submitted_name" class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t('reports.submissions.receipt_file') }}</dt>
                <dd class="text-right break-all">{{ receipt(selected)?.submitted_name }}</dd>
              </div>
            </dl>

            <div v-if="receipt(selected)?.submitted_by" class="mt-3 border-t border-neutral-100 pt-3">
              <div class="text-xs text-neutral-500">{{ t('reports.submissions.receipt_submitted_by') }}</div>
              <div class="mt-1 text-xs font-medium text-neutral-800">{{ receipt(selected)?.submitted_by?.common_name }}</div>
              <div class="text-[11px] text-neutral-500">
                <template v-if="receipt(selected)?.submitted_by?.issuer">{{ receipt(selected)?.submitted_by?.issuer }}</template>
                <template v-if="receipt(selected)?.submitted_by?.serial_number"> · {{ receipt(selected)?.submitted_by?.serial_number }}</template>
              </div>
            </div>

            <div v-if="receipt(selected)?.seal" class="mt-3 border-t border-neutral-100 pt-3">
              <div class="text-xs text-neutral-500">{{ t('reports.submissions.receipt_seal') }}</div>
              <div class="mt-1 text-xs text-neutral-800">{{ receipt(selected)?.seal?.common_name }}</div>
              <div class="text-[11px] text-neutral-500">
                <template v-if="receipt(selected)?.seal?.issuer">{{ receipt(selected)?.seal?.issuer }}</template>
                <template v-if="receipt(selected)?.seal?.valid_to"> · {{ t('reports.submissions.receipt_valid_to') }} {{ receipt(selected)?.seal?.valid_to }}</template>
              </div>
            </div>

            <!-- Heslo pro dotaz na stav: bez něj se na Daňovém portálu nedá stáhnout opis.
                 Odhaluje se vědomě, přes step-up a se záznamem v auditu. -->
            <div v-if="statePasswordAttemptId(selected)" class="mt-3 border-t border-neutral-100 pt-3">
              <div class="text-xs text-neutral-500">{{ t('reports.submissions.state_password') }}</div>
              <div class="mt-1 flex items-center gap-2">
                <code v-if="revealedPassword[selected.id]" class="rounded bg-neutral-50 px-2 py-1 text-xs tracking-wider">{{ revealedPassword[selected.id] }}</code>
                <span v-else class="text-xs text-neutral-400 tracking-widest">••••••••</span>
                <button
                  v-if="!revealedPassword[selected.id]"
                  type="button"
                  class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 hover:underline disabled:opacity-50"
                  :disabled="passwordBusy"
                  @click="openStatePassword(selected)"
                >{{ t('reports.submissions.state_password_reveal') }}</button>
              </div>
              <div class="mt-1 text-[11px] text-neutral-400">{{ t('reports.submissions.state_password_hint') }}</div>
            </div>
          </div>

          <div class="rounded-lg border border-neutral-200 p-3">
            <h3 class="font-medium text-sm">{{ t('reports.submissions.attempts_title') }}</h3>
            <div v-if="selected.attempts.length === 0" class="text-xs text-neutral-500 mt-2">{{ t('reports.submissions.no_attempts') }}</div>
            <ol v-else class="mt-3 space-y-3">
              <li v-for="attempt in selected.attempts" :key="attempt.id" class="relative pl-4 border-l border-neutral-200">
                <div class="flex flex-wrap items-center gap-2">
                  <span :class="['rounded-full border px-2 py-0.5 text-xs font-medium', lifecycleClass(attempt.status)]">
                    {{ lifecycleLabel(attempt.status) }}
                  </span>
                  <span class="rounded bg-neutral-100 px-1.5 py-0.5 text-[11px] text-neutral-600">
                    {{ attempt.channel === 'epo_direct' ? t('reports.submissions.channel_direct') : t('reports.submissions.channel_assisted') }}
                  </span>
                  <span
                    v-if="attempt.epo_environment === 'test'"
                    class="rounded border border-warning-500/30 bg-warning-50 px-1.5 py-0.5 text-[11px] text-warning-800"
                  >
                    {{ t('reports.submissions.environment_test') }}
                  </span>
                  <span class="text-xs text-neutral-400">#{{ attempt.id }}</span>
                </div>
                <div class="text-xs text-neutral-500 mt-1">{{ formatDate(attempt.requested_at) }}</div>
                <div v-if="attempt.remote_submission_ref" class="text-xs text-success-700 mt-1">
                  {{ t('reports.submissions.reference') }}: {{ attempt.remote_submission_ref }}
                </div>
                <div v-if="attempt.remote_status?.stav_podapl_text" class="text-xs text-neutral-600 mt-1">
                  {{ attempt.remote_status.stav_podapl_text }}
                </div>
                <div v-if="attempt.error_message" class="text-xs text-danger-600 mt-1">{{ attempt.error_message }}</div>
                <div v-if="attempt.resolution_code" class="text-xs text-warning-700 mt-1">
                  {{ t('reports.submissions.resolution_recorded') }} · {{ formatDate(attempt.resolved_at) }}
                  <span v-if="attempt.resolution_note"> — {{ attempt.resolution_note }}</span>
                </div>
              </li>
            </ol>
          </div>

          <details v-if="canWrite && !['submitted', 'accepted'].includes(selected.status)"
            :open="manualOpen" class="rounded-lg border border-neutral-200 p-3"
            @toggle="manualOpen = ($event.target as HTMLDetailsElement).open">
            <summary class="cursor-pointer font-medium text-sm">{{ t('reports.submissions.manual_fallback') }}</summary>
            <p class="text-xs text-neutral-500 mt-2">{{ t('reports.submissions.manual_fallback_hint') }}</p>
            <p v-if="prefillSource" class="mt-2 rounded border px-2 py-1.5 text-xs"
              :class="prefillSource === 'receipt'
                ? 'border-success-500/30 bg-success-50 text-success-800'
                : 'border-warning-500/30 bg-warning-50 text-warning-800'">
              {{ prefillSource === 'receipt'
                ? t('reports.submissions.prefilled_from_receipt')
                : t('reports.submissions.prefilled_from_pdf') }}
            </p>
            <label class="block text-xs text-neutral-600 mt-3">
              {{ t('reports.submissions.submitted_at') }}
              <input v-model="manualDate" type="datetime-local" class="mt-1 w-full h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm">
            </label>
            <label class="block text-xs text-neutral-600 mt-3">
              {{ t('reports.submissions.reference') }}
              <input v-model="manualRef" type="text" class="mt-1 w-full h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm">
            </label>
            <button type="button" :class="btnOutline('warning')" class="mt-3 w-full justify-center"
              :disabled="manualBusy || !manualDate" @click="markSubmittedManually">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check"/>
              </svg>
              {{ t('reports.submissions.mark_submitted') }}
            </button>
          </details>
        </aside>
      </div>
    </section>

    <div v-if="settingsOpen" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="settingsOpen = false">
      <div class="w-full max-w-xl rounded-xl bg-surface shadow-xl border border-neutral-200">
        <div class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between">
          <div>
            <h2 class="font-semibold">{{ t('reports.submissions.settings_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-1">{{ t('reports.submissions.settings_hint') }}</p>
          </div>
          <button type="button" class="p-2 text-neutral-500 hover:text-neutral-900" @click="settingsOpen = false">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/>
            </svg>
          </button>
        </div>
        <div class="p-5 space-y-4">
          <div v-if="settingsBusy" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}…</div>
          <template v-else>
            <label class="block text-sm">
              <span class="font-medium">{{ t('reports.submissions.vat_root_folder') }}</span>
              <span class="block text-xs text-neutral-500 mt-1">{{ t('reports.submissions.vat_root_hint') }}</span>
              <select v-model="vatRootFolderId" class="mt-2 w-full h-10 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
                <option :value="null">{{ t('reports.submissions.folder_auto') }}</option>
                <option v-for="folder in folderOptions" :key="folder.id" :value="folder.id">{{ folder.label }}</option>
              </select>
            </label>
            <label class="block text-sm">
              <span class="font-medium">{{ t('reports.submissions.income_root_folder') }}</span>
              <span class="block text-xs text-neutral-500 mt-1">{{ t('reports.submissions.income_root_hint') }}</span>
              <select v-model="incomeRootFolderId" class="mt-2 w-full h-10 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
                <option :value="null">{{ t('reports.submissions.folder_auto') }}</option>
                <option v-for="folder in folderOptions" :key="folder.id" :value="folder.id">{{ folder.label }}</option>
              </select>
            </label>
          </template>
        </div>
        <div class="px-5 py-4 border-t border-neutral-200 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="settingsOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('primary')" :disabled="settingsBusy || settingsSaving" @click="saveSettings">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check"/>
            </svg>
            {{ t('common.save') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="credentialModalOpen" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="credentialModalOpen = false">
      <div class="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-xl bg-surface shadow-xl border border-neutral-200">
        <div class="sticky top-0 z-10 bg-surface px-5 py-4 border-b border-neutral-200 flex items-center justify-between">
          <div>
            <h2 class="font-semibold">{{ t('reports.submissions.credentials_title') }}</h2>
            <p class="text-xs text-neutral-500 mt-1">{{ t('reports.submissions.credentials_hint') }}</p>
          </div>
          <button type="button" class="p-2 text-neutral-500 hover:text-neutral-900" @click="credentialModalOpen = false">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/>
            </svg>
          </button>
        </div>

        <div class="p-5 space-y-5">
          <div class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-xs text-warning-800">
            {{ t('reports.submissions.credentials_security') }}
          </div>

          <div class="grid sm:grid-cols-2 gap-3 rounded-lg border border-neutral-200 p-4">
            <div v-if="hasPasskey" class="sm:col-span-2 flex flex-wrap items-center gap-3">
              <div v-if="stepPasskeyToken" class="text-sm font-medium text-success-600">
                ✓ {{ t('reports.submissions.step_up_passkey_verified') }}
              </div>
              <template v-else-if="passkeySupported">
                <button type="button" :class="btnOutlineSm('primary')"
                  :disabled="passkeyBusy === 'step' || credentialBusy" @click="verifyEpoPasskey('step')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock"/>
                  </svg>
                  {{ passkeyBusy === 'step' ? t('auth.passkey_verifying') : t('reports.submissions.step_up_verify_passkey') }}
                </button>
                <span class="text-xs text-neutral-500">{{ t('reports.submissions.step_up_or_password') }}</span>
              </template>
              <p v-else class="text-xs text-warning-700">{{ t('reports.submissions.step_up_passkey_unsupported') }}</p>
            </div>
            <template v-if="!stepPasskeyToken">
              <label class="text-xs text-neutral-600">
                {{ t('reports.submissions.current_password') }}
                <input v-model="stepPassword" type="password" autocomplete="current-password"
                  class="mt-1 w-full h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm">
              </label>
              <label class="text-xs text-neutral-600">
                {{ t('reports.submissions.totp_optional') }}
                <input v-model="stepTotpCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                  class="mt-1 w-full h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm">
              </label>
            </template>
            <p class="sm:col-span-2 text-xs text-neutral-500">{{ t('reports.submissions.step_up_hint') }}</p>
            <p v-if="credentialStepUpMissing" class="sm:col-span-2 text-xs text-warning-700">
              {{ credentialStepUpMissing }}
            </p>
          </div>

          <div>
            <h3 class="font-medium text-sm">{{ t('reports.submissions.saved_certificates') }}</h3>
            <div v-if="credentialBusy && credentials.length === 0" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}…</div>
            <div v-else-if="credentials.length === 0" class="mt-2 rounded-lg border border-dashed border-neutral-300 p-4 text-sm text-neutral-500">
              {{ t('reports.submissions.no_certificates') }}
            </div>
            <div v-else class="mt-2 space-y-3">
              <div v-for="credential in credentials" :key="credential.id" class="rounded-lg border border-neutral-200 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="font-medium text-sm">{{ credential.label }}</div>
                    <div class="text-xs text-neutral-600 mt-1 break-words">{{ credential.subject_dn }}</div>
                    <div class="text-xs text-neutral-500 mt-1">
                      {{ t('reports.submissions.valid_until') }} {{ formatDate(credential.valid_to) }}
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                      <span
                        class="rounded-full border px-2 py-0.5 text-[11px]"
                        :class="credential.epo_verified ? 'border-success-500/30 bg-success-50 text-success-700' : 'border-warning-500/30 bg-warning-50 text-warning-800'"
                      >
                        {{ credential.epo_verified ? t('reports.submissions.certificate_epo_verified') : t('reports.submissions.certificate_epo_unverified') }}
                      </span>
                      <span v-if="!credential.valid_now" class="rounded-full border border-danger-500/30 bg-danger-50 px-2 py-0.5 text-[11px] text-danger-700">
                        {{ t('reports.submissions.certificate_expired') }}
                      </span>
                      <span v-if="credential.linked_profiles_count > 0" class="rounded-full border border-primary-500/30 bg-primary-50 px-2 py-0.5 text-[11px] text-primary-700">
                        {{ t('reports.submissions.used_by_signing_profiles', { count: credential.linked_profiles_count }) }}
                      </span>
                    </div>
                    <code class="block text-[10px] text-neutral-400 break-all mt-1">{{ credential.fingerprint_sha256 }}</code>
                  </div>
                  <span
                    class="rounded-full border px-2 py-0.5 text-xs"
                    :class="credential.enabled_for_supplier ? 'border-success-500/30 bg-success-50 text-success-700' : 'border-neutral-200 bg-neutral-100 text-neutral-600'"
                  >
                    {{ credential.enabled_for_supplier ? t('reports.submissions.enabled_for_company') : t('reports.submissions.disabled_for_company') }}
                  </span>
                </div>
                <div
                  v-if="!credential.ik_mpsv_present"
                  class="mt-3 rounded border border-warning-500/25 bg-warning-50 px-3 py-2 text-xs text-warning-800"
                >
                  {{ t('reports.submissions.ik_mpsv_warning') }}
                </div>
                <div class="mt-3 flex flex-wrap gap-2">
                  <button type="button" :class="btnOutlineSm(credential.enabled_for_supplier ? 'warning' : 'success')"
                    :disabled="credentialBusy || (credential.enabled_for_supplier && credential.linked_supplier_profiles_count > 0)"
                    @click="toggleCredential(credential)">
                    {{ credential.enabled_for_supplier ? t('reports.submissions.disable_for_company') : t('reports.submissions.enable_for_company') }}
                  </button>
                  <button type="button" :class="btnOutlineSm('danger')" :disabled="credentialBusy || credential.linked_profiles_count > 0" @click="deleteCredential(credential)">
                    {{ t('common.delete') }}
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div class="border-t border-neutral-200 pt-5">
            <h3 class="font-medium text-sm">{{ t('reports.submissions.upload_moved_title') }}</h3>
            <p class="text-xs text-neutral-500 mt-1">{{ t('reports.submissions.upload_moved_hint') }}</p>
            <RouterLink to="/admin/electronic-signatures" :class="btnOutline('primary')" class="mt-3"
              @click="credentialModalOpen = false">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock"/>
              </svg>
              {{ t('reports.submissions.upload_moved_link') }}
            </RouterLink>
          </div>
        </div>
      </div>
    </div>

    <div v-if="recoveryModalOpen" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="recoveryModalOpen = false">
      <div class="w-full max-w-lg rounded-xl bg-surface shadow-xl border border-neutral-200">
        <div class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between">
          <div>
            <h2 class="font-semibold">
              {{ t(recoveryMode === 'confirmation' ? 'reports.submissions.recover_confirmation_title' : 'reports.submissions.resolve_not_submitted_title') }}
            </h2>
            <p class="text-xs text-neutral-500 mt-1">
              {{ t(
                recoveryMode === 'confirmation'
                  ? 'reports.submissions.recover_confirmation_hint'
                  : recoveryMode === 'state_password'
                    ? 'reports.submissions.state_password_hint'
                    : 'reports.submissions.resolve_not_submitted_hint'
              ) }}
            </p>
          </div>
          <button type="button" class="p-2 text-neutral-500 hover:text-neutral-900" @click="recoveryModalOpen = false">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/>
            </svg>
          </button>
        </div>
        <div class="p-5 space-y-4">
          <div v-if="recoveryMode === 'state_password'" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-600">
            {{ t('reports.submissions.state_password_reveal_note') }}
          </div>
          <div v-else-if="recoveryMode === 'not_submitted'" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
            {{ t('reports.submissions.resolve_not_submitted_warning') }}
          </div>
          <label v-if="recoveryMode === 'confirmation'" class="block text-sm">
            {{ t('reports.submissions.confirmation_file') }}
            <input type="file" accept=".p7s,.p7m" class="mt-2 block w-full text-sm" @change="onRecoveryFile">
          </label>
          <template v-else-if="recoveryMode === 'not_submitted'">
            <label class="block text-sm">
              {{ t('reports.submissions.resolution_note') }}
              <textarea v-model="recoveryNote" rows="3" maxlength="500" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2"/>
            </label>
            <label class="flex items-start gap-2 text-sm">
              <input v-model="recoveryVerified" type="checkbox" class="mt-0.5">
              <span>{{ t('reports.submissions.resolve_not_submitted_check') }}</span>
            </label>
          </template>
          <div v-if="hasPasskey" class="flex flex-wrap items-center gap-3">
            <div v-if="recoveryPasskeyToken" class="text-sm font-medium text-success-600">
              ✓ {{ t('reports.submissions.step_up_passkey_verified') }}
            </div>
            <template v-else-if="passkeySupported">
              <button type="button" :class="btnOutlineSm('primary')"
                :disabled="passkeyBusy === 'recovery' || directBusy" @click="verifyEpoPasskey('recovery')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock"/>
                </svg>
                {{ passkeyBusy === 'recovery' ? t('auth.passkey_verifying') : t('reports.submissions.step_up_verify_passkey') }}
              </button>
              <span class="text-xs text-neutral-500">{{ t('reports.submissions.step_up_or_password') }}</span>
            </template>
            <p v-else class="text-sm text-warning-700">{{ t('reports.submissions.step_up_passkey_unsupported') }}</p>
          </div>
          <template v-if="!recoveryPasskeyToken">
            <label class="block text-sm">
              {{ t('reports.submissions.current_password') }}
              <input v-model="recoveryPassword" type="password" autocomplete="current-password" class="mt-1 w-full h-10 rounded-md border border-neutral-300 bg-surface px-3">
            </label>
            <label class="block text-sm">
              {{ t('reports.submissions.totp_optional') }}
              <input v-model="recoveryTotpCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="mt-1 w-full h-10 rounded-md border border-neutral-300 bg-surface px-3">
            </label>
          </template>
        </div>
        <div class="px-5 py-4 border-t border-neutral-200 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="recoveryModalOpen = false">{{ t('common.cancel') }}</button>
          <button
            type="button"
            :class="btnFilled(recoveryMode === 'confirmation' ? 'warning' : recoveryMode === 'state_password' ? 'primary' : 'danger')"
            :disabled="directBusy || !recoveryStepUpReady || (recoveryMode === 'confirmation' ? !recoveryFile : recoveryMode === 'not_submitted' ? (!recoveryVerified || recoveryNote.trim().length < 10) : false)"
            @click="confirmRecovery"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="recoveryMode === 'confirmation' ? ICONS.upload : ICONS.check"/>
            </svg>
            {{ t(
              recoveryMode === 'confirmation'
                ? 'reports.submissions.verify_confirmation'
                : recoveryMode === 'state_password'
                  ? 'reports.submissions.state_password_reveal'
                  : 'reports.submissions.release_attempt'
            ) }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="submitModalOpen" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="submitModalOpen = false">
      <div class="w-full max-w-lg rounded-xl bg-surface shadow-xl border border-neutral-200">
        <div class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between">
          <div>
            <h2 class="font-semibold">
              {{ t(
                submitMode === 'test'
                  ? 'reports.submissions.direct_test_title'
                  : submitEnvironment === 'test'
                    ? 'reports.submissions.submit_direct_sandbox_title'
                    : 'reports.submissions.submit_direct_title'
              ) }}
            </h2>
            <p class="text-xs text-neutral-500 mt-1">
              {{ t(
                submitMode === 'test'
                  ? 'reports.submissions.direct_test_reauth_hint'
                  : submitEnvironment === 'test'
                    ? 'reports.submissions.submit_direct_sandbox_hint'
                    : 'reports.submissions.submit_direct_hint'
              ) }}
            </p>
          </div>
          <button type="button" class="p-2 text-neutral-500 hover:text-neutral-900" @click="submitModalOpen = false">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/>
            </svg>
          </button>
        </div>
        <div class="p-5 space-y-4">
          <div
            v-if="submitMode === 'submit'"
            :class="[
              'rounded-lg border p-3 text-sm',
              submitEnvironment === 'test'
                ? 'border-warning-500/30 bg-warning-50 text-warning-800'
                : 'border-danger-500/30 bg-danger-50 text-danger-700',
            ]"
          >
            {{ t(submitEnvironment === 'test' ? 'reports.submissions.submit_direct_sandbox_warning' : 'reports.submissions.submit_direct_legal_warning') }}
          </div>
          <div v-else class="rounded-lg border border-primary-500/30 bg-primary-50 p-3 text-sm text-primary-800">
            {{ t('reports.submissions.direct_test_signature_warning') }}
          </div>
          <div v-if="hasPasskey" class="flex flex-wrap items-center gap-3">
            <div v-if="submitPasskeyToken" class="text-sm font-medium text-success-600">
              ✓ {{ t('reports.submissions.step_up_passkey_verified') }}
            </div>
            <template v-else-if="passkeySupported">
              <button type="button" :class="btnOutlineSm('primary')"
                :disabled="passkeyBusy === 'submit' || directBusy" @click="verifyEpoPasskey('submit')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock"/>
                </svg>
                {{ passkeyBusy === 'submit' ? t('auth.passkey_verifying') : t('reports.submissions.step_up_verify_passkey') }}
              </button>
              <span class="text-xs text-neutral-500">{{ t('reports.submissions.step_up_or_password') }}</span>
            </template>
            <p v-else class="text-sm text-warning-700">{{ t('reports.submissions.step_up_passkey_unsupported') }}</p>
          </div>
          <template v-if="!submitPasskeyToken">
            <label class="block text-sm">
              {{ t('reports.submissions.current_password') }}
              <input v-model="submitPassword" type="password" autocomplete="current-password"
                class="mt-1 w-full h-10 rounded-md border border-neutral-300 bg-surface px-3">
            </label>
            <label class="block text-sm">
              {{ t('reports.submissions.totp_optional') }}
              <input v-model="submitTotpCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                class="mt-1 w-full h-10 rounded-md border border-neutral-300 bg-surface px-3">
            </label>
          </template>
        </div>
        <div class="px-5 py-4 border-t border-neutral-200 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="submitModalOpen = false">{{ t('common.cancel') }}</button>
          <button
            type="button"
            :class="btnFilled(submitMode === 'test' || submitEnvironment === 'test' ? 'primary' : 'danger')"
            :disabled="directBusy || !submitStepUpReady"
            @click="confirmDirectOperation"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send"/>
            </svg>
            {{ t(
              submitMode === 'test'
                ? 'reports.submissions.run_direct_test'
                : submitEnvironment === 'test'
                  ? 'reports.submissions.submit_now_sandbox'
                  : 'reports.submissions.submit_now'
            ) }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

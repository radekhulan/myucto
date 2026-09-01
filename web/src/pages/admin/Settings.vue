<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { settingsApi, type Supplier, type SelfCopyType, type SelfCopyMode, type NumberSeriesSide, type NaceCode, type NaceResolved, type VatStatusHistoryEntry, type VatStatusCollision, type VatStatusSavePayload, type VatStatusState, type VatRegistrationCheck, type VatStatusS79Suggest, type TaxRepresentationHistoryEntry, type TaxRepresentationSavePayload } from '@/api/settings'
import { adminApi, type SampleDataStatus } from '@/api/admin'
import { clientsApi } from '@/api/clients'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { renderVarsymbolTemplate, hasCounterPlaceholder, templatesCollide } from '@/utils/varsymbol'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import AutomationPolicyBox from '@/components/settings/AutomationPolicyBox.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SupplierDomainsSettings from '@/components/settings/SupplierDomainsSettings.vue'
import { appIsoDate } from '@/utils/date'

const { t } = useI18n()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const router = useRouter()
const supplierStore = useSupplierStore()
const auth = useAuthStore()

// Po uložení propsat změny do supplier store (brief z /me) — jinak editor faktur
// čte stale is_vat_payer/defaulty až do hard refreshe (issue #94).
function syncSupplierStore(s: Supplier) {
  supplierStore.patchSupplier(s.id, {
    company_name: s.company_name,
    ic: s.ic,
    is_vat_payer: s.is_vat_payer,
    is_identified: s.is_identified ?? false,
    oss_enabled: s.oss_enabled ?? false,
    taxpayer_type: s.taxpayer_type ?? null,
    default_payment_due_days: s.default_payment_due_days,
    default_payment_due_unit: s.default_payment_due_unit,
    default_prices_include_vat: s.default_prices_include_vat,
    auto_send_reminders: s.auto_send_reminders,
    payment_thanks_enabled: s.payment_thanks_enabled,
    payment_thanks_default_checked: s.payment_thanks_default_checked,
    stock_enabled: s.stock_enabled ?? false,
    accounting_enabled: s.accounting_enabled ?? true,
    payroll_enabled: s.payroll_enabled ?? false,
  })
}

const supplier = ref<Supplier | null>(null)
const loading = ref(true)
type SettingsTab = 'company' | 'documents' | 'accounting'
const tabs: SettingsTab[] = ['company', 'documents', 'accounting']
// Na záložku se dá odkázat zvenčí (?tab=accounting) — odjinud v aplikaci sem
// vedou rady typu „zapněte účetnictví v Nastavení", a ty musí skončit u toho
// přepínače, ne na první záložce.
//
// Query je zdroj pravdy obou směrů: přepnutí záložky ji zapíše (adresa jde
// poslat i uložit do záložek) a watch ji čte zpátky. Bez toho čtení by odkaz
// z jiné záložky TÉHOŽ Nastavení neudělal nic — routa se nemění, komponenta se
// nepřemountuje a jednorázové čtení při setupu už dávno proběhlo.
function tabFromQuery(q: unknown): SettingsTab {
  const v = String(q ?? '')
  // Záložka „Pokročilé" zanikla a její obsah je dole v „Daně a účetnictví".
  // Starý odkaz proto nesmí spadnout na výchozí kartu firmy — poslal by
  // uživatele úplně jinam, než kam mířil.
  if (v === 'advanced') {
    return 'accounting'
  }
  return tabs.includes(v as SettingsTab) ? v as SettingsTab : 'company'
}
const tab = ref<SettingsTab>(tabFromQuery(router.currentRoute.value.query.tab))

watch(() => router.currentRoute.value.query.tab, q => { tab.value = tabFromQuery(q) })

function switchTab(v: SettingsTab) {
  if (tab.value === v) return
  tab.value = v
  void router.replace({ query: { ...router.currentRoute.value.query, tab: v } })
}

// Kolik dní zbývá ze zkušebního období. Jen pro trial — u zaplacené licence
// není co odpočítávat a po vypršení mluví jiná hláška.
const licenseTrialDaysLeft = computed<number | null>(() => {
  const endsAt = auth.license?.trial_ends_at
  if (auth.license?.state !== 'trial' || !endsAt) return null
  return Math.max(0, Math.ceil((endsAt * 1000 - Date.now()) / 86_400_000))
})

// Práh dní pro první upomínku — preset (3 / týden / měsíc) + „vlastní". Stejný „sticky custom"
// idiom jako dueSelectValue níže: flag drží „vlastní" i když hodnota náhodou odpovídá presetu,
// jinak by getter spadl zpět na preset a číselný input by se nikdy neukázal.
const REMINDER_DAYS_PRESETS = [3, 7, 30]
const reminderCustom = ref(false)
const reminderDaysSelect = computed<number | 'custom'>({
  get() {
    if (reminderCustom.value) return 'custom'
    const d = supplier.value?.reminder_days_after_due ?? 3
    return REMINDER_DAYS_PRESETS.includes(d) ? d : 'custom'
  },
  set(v) {
    reminderCustom.value = (v === 'custom')
    if (v !== 'custom' && supplier.value) supplier.value.reminder_days_after_due = v
  },
})

// ARES → spisová značka (commercial_register) podle IČ
const crLoading = ref(false)
async function loadCommercialRegister() {
  if (blockDemoMutation()) return
  const ic = (supplier.value?.ic || '').replace(/\D/g, '')
  if (!/^\d{8}$/.test(ic)) { toast.error(t('supplier.ares_invalid_ic')); return }
  crLoading.value = true
  try {
    const r = await clientsApi.lookupAres(ic)
    if (r.found && r.data?.commercial_register && supplier.value) {
      supplier.value.commercial_register = r.data.commercial_register
      toast.success(t('settings.commercial_register_loaded'))
    } else if (r.found && r.data?.taxpayer_type === 'fo') {
      // OSVČ (fyzická osoba) není v obchodním rejstříku → spisová značka neexistuje.
      // Není to chyba (issue #76), jen neutrální info.
      toast.info(t('settings.commercial_register_none_fo'))
    } else {
      toast.error(t('settings.commercial_register_not_found'))
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('supplier.ares_failed'))
  } finally {
    crLoading.value = false
  }
}

// Live preview pro číslování faktur — okamžitá zpětná vazba pod každým polem.
// Chybějící counter → červený error; jinak „Náhled: JD2026-01".
function validateAndPreview(template: string | null) {
  const tmpl = (template ?? '').trim()
  if (tmpl === '') return { error: '', preview: '' }
  if (!hasCounterPlaceholder(tmpl)) return { error: t('settings.numbering_must_have_counter'), preview: '' }
  return { error: '', preview: renderVarsymbolTemplate(tmpl, new Date(), 1) }
}
// Výchozí splatnost — UI preset ('7' / '14' / 'month' / 'custom') je odvozen z dvojice
// (default_payment_due_days, default_payment_due_unit). 'month' znamená přesně 1 kalendářní
// měsíc (days=1, unit='month'); 'custom' nechá volný číselný input v dnech.
type DuePreset = '7' | '14' | 'month' | 'custom'
// 'custom' musí být „sticky" i když hodnota náhodou odpovídá presetu (7/14) — jinak
// by getter spadl zpět na preset a číselný input by se nikdy neukázal.
const dueCustom = ref(false)
const dueSelectValue = computed<DuePreset>({
  get() {
    if (!supplier.value) return '7'
    if (dueCustom.value) return 'custom'
    const d = supplier.value.default_payment_due_days
    const u = supplier.value.default_payment_due_unit
    if (u === 'month' && d === 1) return 'month'
    if (u === 'days' && d === 7)  return '7'
    if (u === 'days' && d === 14) return '14'
    return 'custom'
  },
  set(v: DuePreset) {
    if (!supplier.value) return
    dueCustom.value = (v === 'custom')
    if (v === '7') {
      supplier.value.default_payment_due_days = 7
      supplier.value.default_payment_due_unit = 'days'
    } else if (v === '14') {
      supplier.value.default_payment_due_days = 14
      supplier.value.default_payment_due_unit = 'days'
    } else if (v === 'month') {
      supplier.value.default_payment_due_days = 1
      supplier.value.default_payment_due_unit = 'month'
    } else {
      supplier.value.default_payment_due_unit = 'days'
      // days zachovat — pokud byl 7/14 user dostane editovatelnou hodnotu k úpravě
    }
  },
})

const invoicePreview        = computed(() => validateAndPreview(supplier.value?.invoice_number_format ?? null).preview)
const invoiceFormatError    = computed(() => validateAndPreview(supplier.value?.invoice_number_format ?? null).error)
const proformaPreview       = computed(() => validateAndPreview(supplier.value?.proforma_number_format ?? null).preview)
const proformaFormatError   = computed(() => validateAndPreview(supplier.value?.proforma_number_format ?? null).error)
const creditNotePreview     = computed(() => validateAndPreview(supplier.value?.credit_note_number_format ?? null).preview)
const creditNoteFormatError = computed(() => validateAndPreview(supplier.value?.credit_note_number_format ?? null).error)
const purchasePreview       = computed(() => validateAndPreview(supplier.value?.purchase_invoice_number_format ?? null).preview)
const purchaseFormatError   = computed(() => validateAndPreview(supplier.value?.purchase_invoice_number_format ?? null).error)

// MZ-03: identifikátory odvodů zaměstnavatele drží mzdový modul jen tehdy, když je
// zapnutý. U vypnutých Mezd (i u OSVČ) zůstávají legacy pole na firmě jediným zdrojem —
// čte je detekce odvodů v bance a šablony bankovních pravidel.
const employerIdentifiersInPayroll = computed(() =>
  supplier.value?.taxpayer_type === 'po' && supplier.value?.payroll_enabled === true)

// Featura G (private/REAL_data_followup_UX.md) — preventivní varování na kolizi VS
// mezi číselnými řadami. Dva zdroje:
//   1) ŽIVÁ kontrola supplier-wide šablon (invoice/proforma/credit_note) nad AKTUÁLNĚ
//      rozepsanou hodnotou formuláře — vidí kolizi ještě PŘED uložením.
//   2) `supplier.number_series_collisions` z backendu (VarsymbolSeriesCollisionChecker) —
//      navíc pokrývá per-client přepsání (ClientForm), která tenhle formulář nevidí.
// Sloučeno a odduplikováno podle (typ/klient) páru, ať se stejná kolize neukáže dvakrát.
interface SeriesWarning { key: string; aLabel: string; bLabel: string }

function numberSeriesTypeLabel(type: 'invoice' | 'proforma' | 'credit_note'): string {
  return t(`settings.numbering_type_${type}`)
}

function numberSeriesSideLabel(side: NumberSeriesSide): string {
  const typeLabel = numberSeriesTypeLabel(side.type)
  if (side.client_name) return `${typeLabel} (${side.client_name})`
  if (side.revenue_category_name) {
    return `${typeLabel} (${t('settings.numbering_scope_revenue_category')}: ${side.revenue_category_name})`
  }
  return typeLabel
}

const numberSeriesWarnings = computed<SeriesWarning[]>(() => {
  const seen = new Set<string>()
  const warnings: SeriesWarning[] = []
  const push = (aLabel: string, bLabel: string) => {
    const key = [aLabel, bLabel].sort().join(' | ')
    if (seen.has(key)) return
    seen.add(key)
    warnings.push({ key, aLabel, bLabel })
  }

  const fb = supplier.value?.cfg_varsymbol_fallback
  const effective = (tpl: string | null | undefined, fallback: string | undefined): string => {
    const trimmed = (tpl ?? '').trim()
    return trimmed !== '' ? trimmed : (fallback ?? '')
  }
  const live: Array<{ type: 'invoice' | 'proforma' | 'credit_note'; template: string }> = [
    { type: 'invoice', template: effective(supplier.value?.invoice_number_format, fb?.invoice) },
    { type: 'proforma', template: effective(supplier.value?.proforma_number_format, fb?.proforma) },
    { type: 'credit_note', template: effective(supplier.value?.credit_note_number_format, fb?.credit_note) },
  ]
  for (let i = 0; i < live.length; i++) {
    for (let j = i + 1; j < live.length; j++) {
      if (live[i].template && live[j].template && templatesCollide(live[i].template, live[j].template)) {
        push(numberSeriesTypeLabel(live[i].type), numberSeriesTypeLabel(live[j].type))
      }
    }
  }

  for (const c of supplier.value?.number_series_collisions ?? []) {
    push(numberSeriesSideLabel(c.a), numberSeriesSideLabel(c.b))
  }

  return warnings
})

// Kopie odchozích e-mailů dodavateli (migrace 0102) — UI stav 'inherit' znamená
// „klíč v self_copy chybí" = živý fallback na cfg flagy (vzor číslování faktur).
// Explicitní volba klíč zapíše; zpět na 'inherit' ho smaže. Prázdný objekt → null.
function selfCopyComputed(ct: SelfCopyType) {
  return computed<SelfCopyMode | 'inherit'>({
    get: () => supplier.value?.self_copy?.[ct] ?? 'inherit',
    set: (v) => {
      if (!supplier.value) return
      const sc = { ...(supplier.value.self_copy ?? {}) }
      if (v === 'inherit') delete sc[ct]
      else sc[ct] = v
      supplier.value.self_copy = Object.keys(sc).length ? sc : null
    },
  })
}
const selfCopyDocuments = selfCopyComputed('documents')
const selfCopyReminders = selfCopyComputed('reminders')
const selfCopyApprovals = selfCopyComputed('approvals')

/** Efektivní cfg hodnota pro volbu „dle konfigurace" — u schvalování může mít
 *  žádost a upomínka v cfg různé flagy, pak ukážeme obě. */
function selfCopyFallbackLabel(ct: SelfCopyType): string {
  const fb = supplier.value?.cfg_self_copy_fallback
  if (!fb) return ''
  const lbl = (m: SelfCopyMode) => m === 'off' ? t('settings.self_copy.mode_off') : m.toUpperCase()
  if (ct === 'approvals' && fb.approvals !== fb.approval_reminders) {
    return t('settings.self_copy.inherit_split', { request: lbl(fb.approvals), reminder: lbl(fb.approval_reminders) })
  }
  return lbl(fb[ct])
}

// Audit 2026-07 (G5): stav accounting_mode při načtení — potřeba k detekci
// přechodu tax_evidence → double_entry v saveSupplier() (confirm dialog).
const originalAccountingMode = ref<'tax_evidence' | 'double_entry' | null>(null)

// ── CZ-NACE našeptávač nad číselníkem ČINNOSTI ─────────────────────────────
const naceItems = ref<NaceCode[]>([])
const naceLoading = ref(false)
const naceResolved = ref<NaceResolved | null>(null)

const naceOptions = computed(() =>
  naceItems.value.map(i => ({ value: i.code, label: `${i.display} — ${i.name}` })))

// Vybraná hodnota bývá mimo aktuální výsledky hledání (načtení stránky, po filtru),
// proto ji SearchableSelect dostává zvlášť — jinak by pole vypadalo prázdné.
const naceSelected = computed(() => {
  const code = supplier.value?.cz_nace_code
  if (!code) return null
  const r = naceResolved.value
  return { value: code, label: r?.name ? `${r.display} — ${r.name}` : code }
})

async function searchNace(q: string) {
  naceLoading.value = true
  try {
    naceItems.value = await settingsApi.searchNaceCodes(q, 25)
  } catch {
    naceItems.value = []
  } finally { naceLoading.value = false }
}

function pickNace(code: string | null) {
  if (!supplier.value) return
  supplier.value.cz_nace_code = code
  const hit = code !== null ? naceItems.value.find(i => i.code === code) : undefined
  // Našeptávač nabízí jen kódy platné k dnešku, takže vybraný je vždy `active`.
  // Stav ručně uloženého kódu dopočítá backend a vrátí ho v `cz_nace_resolved`.
  naceResolved.value = code === null || !hit
    ? null
    : { code, display: hit.display, name: hit.name, status: 'active', valid_to: null }
}

async function load() {
  loading.value = true
  try {
    supplier.value = await settingsApi.getSupplier()
    // Stav uloženého CZ-NACE proti číselníku počítá backend — expirovaný kód je
    // tak vidět hned při načtení, ne až z náhledu přiznání.
    naceResolved.value = supplier.value.cz_nace_resolved ?? null
    originalAccountingMode.value = supplier.value.accounting_mode ?? 'tax_evidence'
  } finally { loading.value = false }
  loadSampleStatus()
  loadVatRegistrationCheck()
}

onMounted(load)

// ── Ukázková (sample) data — sekce se zobrazí jen když nějaká evidovaná existují (issue #162) ──
const sampleStatus = ref<SampleDataStatus | null>(null)
const showSampleConfirm = ref(false)
const sampleDeleting = ref(false)

async function loadSampleStatus() {
  try {
    sampleStatus.value = await adminApi.sampleDataStatus()
  } catch {
    sampleStatus.value = null  // 403 (ne-admin) / chyba → sekci nezobrazuj
  }
}

const sampleSummaryLine = computed(() => {
  const c = sampleStatus.value?.counts ?? {}
  const parts: string[] = []
  const push = (n: number, key: string) => { if (n > 0) parts.push(`${n} ${t(key)}`) }
  push((c.client ?? 0) + (c.vendor ?? 0), 'settings.sample_data.unit_clients')
  push((c.invoice ?? 0) + (c.credit_note ?? 0), 'settings.sample_data.unit_invoices')
  push(c.purchase_invoice ?? 0, 'settings.sample_data.unit_purchase')
  push(c.project ?? 0, 'settings.sample_data.unit_projects')
  return parts.join(', ')
})

async function removeSampleData() {
  if (blockDemoMutation()) return
  sampleDeleting.value = true
  try {
    await adminApi.deleteSampleData()
    toast.success(t('settings.sample_data.removed'))
    showSampleConfirm.value = false
    await loadSampleStatus()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    sampleDeleting.value = false
  }
}

async function saveSupplier() {
  if (blockDemoMutation()) return
  if (!supplier.value) return
  // Klient-side guard pro varsymbol formáty — stejná pravidla jako backend, ale uživatel
  // dostane okamžitou zpětnou vazbu (hláška u pole) místo toastu, který zmizí.
  const errs = [invoiceFormatError.value, proformaFormatError.value, creditNoteFormatError.value].filter(Boolean)
  if (errs.length > 0) {
    toast.error(errs[0])
    return
  }
  // E2: firma s historií musí přejít řízeným průvodcem, který režim přepne až
  // po úspěšném dry-runu a doúčtování. Zbytek formuláře se ale MUSÍ uložit ještě
  // před odchodem — jinak uživateli po návratu z průvodce zmizí i to, co
  // s režimem nesouvisí (typicky zaškrtnuté „Vést účetnictví"), a nechápe proč.
  let handOffToActivation = false
  if (originalAccountingMode.value === 'tax_evidence' && supplier.value.accounting_mode === 'double_entry') {
    try {
      const preview = await settingsApi.getModeSwitchPreview()
      if (preview.total > 0) {
        handOffToActivation = true
        supplier.value.accounting_mode = originalAccountingMode.value
      }
    } catch (e: any) {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
      return
    }
  }
  try {
    supplier.value = await settingsApi.updateSupplier({
      company_name: supplier.value.company_name,
      display_name: supplier.value.display_name,
      street: supplier.value.street,
      city: supplier.value.city,
      zip: supplier.value.zip,
      ic: supplier.value.ic,
      dic: supplier.value.dic,
      // Plátcovství DPH + identifikovaná osoba se od VH-01 mění výhradně přes
      // blok „Plátcovství DPH" (historie) v tabě Daně a účetnictví.
      email: supplier.value.email,
      phone: supplier.value.phone,
      web: supplier.value.web,
      tagline: supplier.value.tagline,
      commercial_register: supplier.value.commercial_register,
      default_payment_due_days: supplier.value.default_payment_due_days,
      default_payment_due_unit: supplier.value.default_payment_due_unit,
      default_prices_include_vat: supplier.value.default_prices_include_vat,
      default_hourly_rate: supplier.value.default_hourly_rate,
      auto_send_reminders: supplier.value.auto_send_reminders,
      reminder_days_after_due: supplier.value.reminder_days_after_due,
      payment_thanks_enabled: supplier.value.payment_thanks_enabled,
      payment_thanks_auto_send: supplier.value.payment_thanks_auto_send,
      payment_thanks_default_checked: supplier.value.payment_thanks_default_checked,
      payment_thanks_attach_paid_pdf: supplier.value.payment_thanks_attach_paid_pdf,
      self_copy: supplier.value.self_copy ?? null,
      auto_generate_recurring: supplier.value.auto_generate_recurring,
      embed_isdoc: supplier.value.embed_isdoc,
      invoice_qr_include_due_date: supplier.value.invoice_qr_include_due_date,
      purchase_invoice_qr_include_due_date: supplier.value.purchase_invoice_qr_include_due_date,
      proforma_payment_document: supplier.value.proforma_payment_document,
      pohoda_account_code: supplier.value.pohoda_account_code,
      pohoda_centre_code: supplier.value.pohoda_centre_code,
      pohoda_activity_code: supplier.value.pohoda_activity_code,
      pohoda_contract_code: supplier.value.pohoda_contract_code,
      pohoda_accounting_code: supplier.value.pohoda_accounting_code,
      invoice_number_format: supplier.value.invoice_number_format,
      proforma_number_format: supplier.value.proforma_number_format,
      credit_note_number_format: supplier.value.credit_note_number_format,
      purchase_invoice_number_format: supplier.value.purchase_invoice_number_format,
      invoice_number_period: supplier.value.invoice_number_period,
      // Sklad (Epic SKLAD) — nezávislé na accounting_mode.
      stock_enabled: supplier.value.stock_enabled ?? false,
      stock_auto_issue: supplier.value.stock_auto_issue ?? true,
      stock_in_transit_from: supplier.value.stock_in_transit_from ?? 'sent',
      // Tax settings (EPO výkazy DPH/KH)
      // Účetní režim posíláme jen když ho uživatel opravdu přepnul. Server na
      // něm má navěšenou historii režimu i kontrolu právní formy, takže
      // nezměněná hodnota v každém uložení je zbytečný zápis a u převzaté firmy
      // dokonce zámek celého nastavení (myinvoice#265).
      ...(supplier.value.accounting_mode && supplier.value.accounting_mode !== originalAccountingMode.value
        ? { accounting_mode: supplier.value.accounting_mode }
        : {}),
      // „Vést účetnictví" (1179) — opt-out účetní nadstavby v menu; na licenci bez vlivu.
      accounting_enabled: supplier.value.accounting_enabled ?? true,
      // „Vést mzdy" (1187, opt-in od 1290) — výchozí vypnuto jako sklad; licence bez vlivu.
      payroll_enabled: supplier.value.payroll_enabled ?? false,
      // Auto-post hook (A2) — auto-zaúčtování FV/PF; účinek jen v double_entry.
      auto_post_invoices: supplier.value.auto_post_invoices ?? false,
      auto_post_purchases: supplier.value.auto_post_purchases ?? false,
      taxpayer_type: (supplier.value as any).taxpayer_type ?? null,
      vat_period: (supplier.value as any).vat_period ?? null,
      flat_tax_band: (supplier.value as any).flat_tax_band ?? 'none',
      oss_enabled: (supplier.value as any).oss_enabled ?? false,
      oss_valid_from: (supplier.value as any).oss_valid_from ?? null,
      oss_valid_to: (supplier.value as any).oss_valid_to ?? null,
      oss_identification_country: (supplier.value as any).oss_identification_country ?? null,
      oss_return_currency: (supplier.value as any).oss_return_currency ?? 'EUR',
      financial_office_code: (supplier.value as any).financial_office_code ?? null,
      workplace_code: (supplier.value as any).workplace_code ?? null,
      cz_nace_code: (supplier.value as any).cz_nace_code ?? null,
      data_box_id: (supplier.value as any).data_box_id ?? null,
      data_box_type: (supplier.value as any).data_box_type ?? null,
      sest_jmeno: (supplier.value as any).sest_jmeno ?? null,
      sest_prijmeni: (supplier.value as any).sest_prijmeni ?? null,
      sest_telefon: (supplier.value as any).sest_telefon ?? null,
      sest_email: (supplier.value as any).sest_email ?? null,
      sest_funkce: (supplier.value as any).sest_funkce ?? null,
      // Doplňky pro DPH/KH XML VetaP
      street_number_pop: (supplier.value as any).street_number_pop ?? null,
      street_number_orient: (supplier.value as any).street_number_orient ?? null,
      opr_jmeno: (supplier.value as any).opr_jmeno ?? null,
      opr_prijmeni: (supplier.value as any).opr_prijmeni ?? null,
      opr_postaveni: (supplier.value as any).opr_postaveni ?? null,
      cssz_vsdp: (supplier.value as any).cssz_vsdp ?? null,
      cssz_ossz_code: (supplier.value as any).cssz_ossz_code ?? null,
      health_insurance_number: (supplier.value as any).health_insurance_number ?? null,
    })
    syncSupplierStore(supplier.value)
    originalAccountingMode.value = supplier.value.accounting_mode ?? 'tax_evidence'
    if (handOffToActivation) {
      // Uloženo je vše kromě režimu; ten přepne až průvodce po doúčtování.
      toast.info(t('activation.error.backfill_required'))
      await router.push({ name: 'accounting-activation' })
      return
    }
    toast.success(t('common.saved'))
  } catch (e: any) {
    if (e?.response?.status === 409 && e?.response?.data?.error?.code === 'backfill_required') {
      toast.info(t('activation.error.backfill_required'))
      await router.push({ name: 'accounting-activation' })
      return
    }
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function saveAutoPostFlags(): Promise<void> {
  if (blockDemoMutation()) return
  if (!supplier.value) return
  const updated = await settingsApi.updateSupplier({
    auto_post_invoices: supplier.value.auto_post_invoices ?? false,
    auto_post_purchases: supplier.value.auto_post_purchases ?? false,
  })
  supplier.value.auto_post_invoices = updated.auto_post_invoices ?? false
  supplier.value.auto_post_purchases = updated.auto_post_purchases ?? false
}

// ── Plátcovství DPH v čase (VH-01) ──────────────────────────────────────────
// CRUD historie je okamžitá akce přes API (vzor měnových účtů), NE součást
// společného Uložit — každá změna hned přepočítá živou cache na backendu.
type VatStatusKind = 'payer' | 'non_payer' | 'identified'
const VAT_BASELINE_DATE = '1900-01-01'
const todayIso = () => appIsoDate()

const vatHistory = computed<VatStatusHistoryEntry[]>(() => supplier.value?.vat_status_history ?? [])

function vatStatusKind(payer: boolean, identified: boolean): VatStatusKind {
  return payer ? 'payer' : identified ? 'identified' : 'non_payer'
}
function vatKindLabel(kind: VatStatusKind): string {
  return t(`settings.vat_status.state_${kind}`)
}
const vatCurrentStateLabel = computed(() => {
  if (!supplier.value) return ''
  return vatKindLabel(vatStatusKind(supplier.value.is_vat_payer, supplier.value.is_identified ?? false))
})

const vatFormOpen = ref(false)
const vatFormEditingId = ref<number | null>(null)   // null = nový řádek
const vatFormDate = ref(todayIso())
const vatFormKind = ref<VatStatusKind>('payer')
const vatFormNote = ref('')
const vatSaving = ref(false)

function openVatForm(entry?: VatStatusHistoryEntry) {
  vatFormEditingId.value = entry?.id ?? null
  vatFormDate.value = entry ? entry.effective_from : todayIso()
  vatFormKind.value = entry ? vatStatusKind(entry.is_vat_payer, entry.is_identified) : 'payer'
  vatFormNote.value = entry?.note ?? ''
  vatFormOpen.value = true
}
function closeVatForm() {
  vatFormOpen.value = false
  vatFormEditingId.value = null
}

// 409 retro-guard flow: BE vrátí výčet kolizí (zamčená období / podaná
// přiznání), uživatel je potvrdí a akce se zopakuje s acknowledge=true.
const vatConflicts = ref<VatStatusCollision[]>([])
const vatPendingAction = ref<null | { kind: 'save'; payload: VatStatusSavePayload } | { kind: 'delete'; id: number }>(null)

function applyVatState(state: VatStatusState) {
  if (!supplier.value) return
  supplier.value.vat_status_history = state.vat_status_history
  supplier.value.is_vat_payer = state.is_vat_payer
  supplier.value.is_identified = state.is_identified
  syncSupplierStore(supplier.value)
}

async function submitVatForm(acknowledge = false) {
  if (blockDemoMutation()) return
  if (!supplier.value) return
  const payload: VatStatusSavePayload = {
    effective_from: vatFormDate.value,
    is_vat_payer: vatFormKind.value === 'payer',
    is_identified: vatFormKind.value === 'identified',
    note: vatFormNote.value.trim() || null,
    ...(acknowledge ? { acknowledge: true } : {}),
  }
  vatSaving.value = true
  try {
    const state = await settingsApi.saveVatStatus(payload)
    applyVatState(state)
    // VH-07: přechod plátcovství (0→1 / 1→0) s účinností <= dnes → nenásilná
    // výzva k evidenci korekce odpočtu § 79/§ 79a (ř. 45) s odkazem na agendu.
    vatS79Suggest.value = state.suggest_s79 ?? null
    vatConflicts.value = []
    vatPendingAction.value = null
    closeVatForm()
    toast.success(t('common.saved'))
    loadVatRegistrationCheck()
  } catch (e: any) {
    if (e?.response?.status === 409 && e?.response?.data?.error?.code === 'vat_status_locked_conflict') {
      vatConflicts.value = e.response.data.error.collisions ?? []
      vatPendingAction.value = { kind: 'save', payload }
      return
    }
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    vatSaving.value = false
  }
}

const vatDeleteEntry = ref<VatStatusHistoryEntry | null>(null)

async function confirmVatDelete(acknowledge = false) {
  if (blockDemoMutation()) return
  const entry = vatDeleteEntry.value
  if (!entry) return
  vatSaving.value = true
  try {
    applyVatState(await settingsApi.deleteVatStatus(entry.id, acknowledge))
    vatConflicts.value = []
    vatPendingAction.value = null
    vatDeleteEntry.value = null
    toast.success(t('settings.vat_status.deleted'))
  } catch (e: any) {
    if (e?.response?.status === 409 && e?.response?.data?.error?.code === 'vat_status_locked_conflict') {
      vatConflicts.value = e.response.data.error.collisions ?? []
      vatPendingAction.value = { kind: 'delete', id: entry.id }
      return
    }
    vatDeleteEntry.value = null
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    vatSaving.value = false
  }
}

async function acknowledgeVatConflicts() {
  const pending = vatPendingAction.value
  if (!pending) return
  if (pending.kind === 'save') await submitVatForm(true)
  else await confirmVatDelete(true)
}

function dismissVatConflicts() {
  const wasDelete = vatPendingAction.value?.kind === 'delete'
  vatConflicts.value = []
  vatPendingAction.value = null
  if (wasDelete) vatDeleteEntry.value = null
}

// ── § 6 hlídač obratu + § 79 hint (VH-07) ───────────────────────────────────
// Banner „obrat překročen — stáváš se plátcem k datu" nad blokem historie;
// akce předvyplní běžný formulář přidání řádku (včetně acknowledge flow).
const vatRegCheck = ref<VatRegistrationCheck | null>(null)
const vatS79Suggest = ref<VatStatusS79Suggest | null>(null)

async function loadVatRegistrationCheck() {
  try {
    vatRegCheck.value = await settingsApi.getVatRegistrationCheck()
  } catch {
    vatRegCheck.value = null
  }
}

const vatRegBanner = computed(() => {
  if (!supplier.value || supplier.value.is_vat_payer) return null
  const c = vatRegCheck.value
  return c && (c.status === 'exceeded_low' || c.status === 'exceeded_high') ? c : null
})

const fmtCzk = (v: number) => `${Math.round(v).toLocaleString('cs-CZ')} Kč`

function openVatFormFromRegistration() {
  const c = vatRegBanner.value
  if (!c?.becomes_payer_on) return
  vatFormEditingId.value = null
  vatFormDate.value = c.becomes_payer_on
  vatFormKind.value = 'payer'
  vatFormNote.value = t('settings.vat_status.registration_note')
  vatFormOpen.value = true
}

function vatCollisionLabel(c: VatStatusCollision): string {
  if (c.type === 'locked_period') {
    return t('settings.vat_status.collision_locked_period', { status: c.period_status ?? '' })
  }
  if (c.type === 'date_lock') {
    return t('settings.vat_status.collision_date_lock', { date: c.locked_until ?? '' })
  }
  const period = c.period_month
    ? `${String(c.period_month).padStart(2, '0')}/${c.period_year}`
    : c.period_quarter
      ? `Q${c.period_quarter}/${c.period_year}`
      : String(c.period_year ?? '')
  return t('settings.vat_status.collision_tax_submission', { form: c.form_code ?? '', period })
}

// ── Zastoupení daňovým poradcem (§29/2 DŘ) ──────────────────────────────────
// Stejný vzor jako Plátcovství DPH výše (VH-01): CRUD historie je okamžitá akce
// přes API, NE součást společného Uložit. Bez retro-guardu (viz migrace 1662 —
// zastoupení nic neúčtuje, jen dan_por/pln_moc/zast_* v XML přiznání).
const TAX_REP_BASELINE_DATE = '1900-01-01'
const taxRepHistory = computed<TaxRepresentationHistoryEntry[]>(() => supplier.value?.tax_representation_history ?? [])
const taxRepCurrent = computed<TaxRepresentationHistoryEntry | null>(() => {
  const today = todayIso()
  const due = taxRepHistory.value.filter(e => e.effective_from <= today)
  return due.length ? due[due.length - 1] : null
})
const taxRepCurrentLabel = computed(() => {
  const current = taxRepCurrent.value
  if (!current || !current.represented) return t('settings.tax_representation.state_none')
  return current.type === 'P'
    ? (current.company_name ?? '')
    : `${current.first_name ?? ''} ${current.last_name ?? ''}`.trim()
})

const taxRepFormOpen = ref(false)
const taxRepFormEditingId = ref<number | null>(null)
const taxRepFormDate = ref(todayIso())
const taxRepFormRepresented = ref(false)
const taxRepFormType = ref<'F' | 'P'>('F')
const taxRepFormFirstName = ref('')
const taxRepFormLastName = ref('')
const taxRepFormCompanyName = ref('')
const taxRepFormIco = ref('')
const taxRepFormEvNumber = ref('')
const taxRepFormPoaDate = ref('')
const taxRepFormNote = ref('')
const taxRepSaving = ref(false)

function openTaxRepForm(entry?: TaxRepresentationHistoryEntry) {
  taxRepFormEditingId.value = entry?.id ?? null
  taxRepFormDate.value = entry ? entry.effective_from : todayIso()
  taxRepFormRepresented.value = entry?.represented ?? false
  taxRepFormType.value = entry?.type ?? 'F'
  taxRepFormFirstName.value = entry?.first_name ?? ''
  taxRepFormLastName.value = entry?.last_name ?? ''
  taxRepFormCompanyName.value = entry?.company_name ?? ''
  taxRepFormIco.value = entry?.ico ?? ''
  taxRepFormEvNumber.value = entry?.ev_number ?? ''
  taxRepFormPoaDate.value = entry?.power_of_attorney_granted_on ?? ''
  taxRepFormNote.value = entry?.note ?? ''
  taxRepFormOpen.value = true
}
function closeTaxRepForm() {
  taxRepFormOpen.value = false
  taxRepFormEditingId.value = null
}

function applyTaxRepState(history: TaxRepresentationHistoryEntry[]) {
  if (!supplier.value) return
  supplier.value.tax_representation_history = history
}

async function submitTaxRepForm() {
  if (blockDemoMutation()) return
  if (!supplier.value) return
  const payload: TaxRepresentationSavePayload = {
    effective_from: taxRepFormDate.value,
    represented: taxRepFormRepresented.value,
    ...(taxRepFormRepresented.value ? {
      type: taxRepFormType.value,
      first_name: taxRepFormType.value === 'F' ? (taxRepFormFirstName.value.trim() || null) : null,
      last_name: taxRepFormType.value === 'F' ? (taxRepFormLastName.value.trim() || null) : null,
      company_name: taxRepFormType.value === 'P' ? (taxRepFormCompanyName.value.trim() || null) : null,
      ico: taxRepFormType.value === 'P' ? (taxRepFormIco.value.trim() || null) : null,
      ev_number: taxRepFormEvNumber.value.trim() || null,
      power_of_attorney_granted_on: taxRepFormPoaDate.value.trim() || null,
    } : {}),
    note: taxRepFormNote.value.trim() || null,
  }
  taxRepSaving.value = true
  try {
    const state = await settingsApi.saveTaxRepresentation(payload)
    applyTaxRepState(state.tax_representation_history)
    closeTaxRepForm()
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    taxRepSaving.value = false
  }
}

const taxRepDeleteEntry = ref<TaxRepresentationHistoryEntry | null>(null)

async function confirmTaxRepDelete() {
  if (blockDemoMutation()) return
  const entry = taxRepDeleteEntry.value
  if (!entry) return
  taxRepSaving.value = true
  try {
    const state = await settingsApi.deleteTaxRepresentation(entry.id)
    applyTaxRepState(state.tax_representation_history)
    taxRepDeleteEntry.value = null
    toast.success(t('settings.tax_representation.deleted'))
  } catch (e: any) {
    taxRepDeleteEntry.value = null
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    taxRepSaving.value = false
  }
}

</script>

<template>
  <div class="max-w-5xl">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('settings.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('settings.subtitle') }}</p>
      </div>
      <button v-if="!loading && supplier" type="button" @click="saveSupplier" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ t('settings.save_supplier') }}
      </button>
    </div>

    <nav class="flex flex-wrap gap-1 border-b border-neutral-200 mb-5" :aria-label="t('settings.tabs_label')">
      <button
        v-for="item in tabs"
        :key="item"
        type="button"
        class="cursor-pointer px-4 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors"
        :class="tab === item
          ? 'border-primary-600 text-primary-700'
          : 'border-transparent text-neutral-600 hover:text-neutral-900 hover:border-neutral-300'"
        @click="switchTab(item)"
      >
        {{ t(`settings.tab_${item}`) }}
      </button>
    </nav>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="supplier" class="space-y-6">
      <!-- Supplier -->
      <section v-if="tab === 'company' || tab === 'documents'" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
          {{ tab === 'company' ? t('settings.supplier') : t('settings.documents_section') }}
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <template v-if="tab === 'company'">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.company_name') }} *</label>
            <input v-model="supplier.company_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.display_name') }}</label>
            <input v-model="supplier.display_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.street') }}</label>
            <input v-model="supplier.street" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.zip') }}</label>
              <input v-model="supplier.zip" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.city') }}</label>
              <input v-model="supplier.city" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.ic') }}</label>
            <input v-model="supplier.ic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.dic') }}</label>
            <input v-model="supplier.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.email') }} *</label>
            <input v-model="supplier.email" type="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.phone') }}</label>
            <input v-model="supplier.phone" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.web') }}</label>
            <input v-model="supplier.web" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.tagline') }}</label>
            <input v-model="supplier.tagline" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="md:col-span-2">
            <div class="flex items-center justify-between mb-1 gap-2">
              <label class="block text-sm font-medium text-neutral-700">{{ t('settings.commercial_register') }}</label>
              <button type="button" @click="loadCommercialRegister" :disabled="crLoading || !supplier.ic"
                :class="[btnOutline('primary'), 'shrink-0']">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                {{ crLoading ? '…' : t('settings.commercial_register_load_ares') }}
              </button>
            </div>
            <input v-model="supplier.commercial_register" type="text"
              :placeholder="t('settings.commercial_register_placeholder')"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.commercial_register_hint') }}</p>
          </div>
          </template>
          <template v-else>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_due_label') }}</label>
            <div class="flex gap-2">
              <select v-model="dueSelectValue" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" :class="dueSelectValue === 'custom' ? 'w-40' : 'w-full'">
                <option value="7">{{ t('settings.default_due_preset_7') }}</option>
                <option value="14">{{ t('settings.default_due_preset_14') }}</option>
                <option value="month">{{ t('settings.default_due_preset_month') }}</option>
                <option value="custom">{{ t('settings.default_due_preset_custom') }}</option>
              </select>
              <div v-if="dueSelectValue === 'custom'" class="flex items-center gap-2 flex-1">
                <input v-model.number="supplier.default_payment_due_days" type="number" min="0" class="w-24 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <span class="text-sm text-neutral-500">{{ t('settings.default_due_custom_days_suffix') }}</span>
              </div>
            </div>
            <p v-if="dueSelectValue === 'month'" class="text-xs text-neutral-500 mt-1">{{ t('settings.default_due_month_hint') }}</p>
          </div>
          <div v-if="supplier.is_vat_payer" class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.default_prices_include_vat" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span class="font-medium">{{ t('settings.default_prices_include_vat') }}</span>
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.default_prices_include_vat_hint') }}</p>
          </div>
          <div class="md:col-span-2 border-t border-neutral-200 pt-4 space-y-3">
            <div>
              <h3 class="text-sm font-semibold text-neutral-700">{{ t('settings.payment_qr_due_date_title') }}</h3>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.payment_qr_due_date_hint') }}</p>
            </div>
            <label class="flex items-start gap-2 text-sm">
              <input v-model="supplier.invoice_qr_include_due_date" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
              <span>
                <span class="font-medium text-neutral-700">{{ t('settings.invoice_qr_include_due_date') }}</span>
                <span class="block text-xs text-neutral-500 mt-0.5">{{ t('settings.invoice_qr_include_due_date_hint') }}</span>
              </span>
            </label>
            <label class="flex items-start gap-2 text-sm">
              <input v-model="supplier.purchase_invoice_qr_include_due_date" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
              <span>
                <span class="font-medium text-neutral-700">{{ t('settings.purchase_invoice_qr_include_due_date') }}</span>
                <span class="block text-xs text-neutral-500 mt-0.5">{{ t('settings.purchase_invoice_qr_include_due_date_hint') }}</span>
              </span>
            </label>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_hourly_rate') }} ({{ supplier.default_currency }})</label>
            <input v-model.number="supplier.default_hourly_rate" type="number" step="0.01" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.auto_send_reminders" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.auto_send_reminders') }}
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.auto_send_reminders_hint') }}</p>
          </div>
          <div class="md:col-span-2" v-if="supplier.auto_send_reminders">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.reminder_days_after_due') }}</label>
            <div class="flex items-center gap-2 flex-wrap">
              <select v-model="reminderDaysSelect" class="h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="3">{{ t('settings.reminder_days_preset.d3') }}</option>
                <option :value="7">{{ t('settings.reminder_days_preset.week') }}</option>
                <option :value="30">{{ t('settings.reminder_days_preset.month') }}</option>
                <option value="custom">{{ t('settings.reminder_days_preset.custom') }}</option>
              </select>
              <template v-if="reminderDaysSelect === 'custom'">
                <input v-model.number="supplier.reminder_days_after_due" type="number" min="1" max="365"
                       class="w-24 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <span class="text-sm text-neutral-500">{{ t('settings.reminder_days_unit') }}</span>
              </template>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.reminder_days_after_due_hint') }}</p>
          </div>
          <div class="md:col-span-2 border-t border-neutral-200 pt-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.payment_thanks_enabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span class="font-medium">{{ t('settings.payment_thanks_enabled') }}</span>
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.payment_thanks_enabled_hint') }}</p>
            <div v-if="supplier.payment_thanks_enabled" class="ml-6 mt-2 space-y-2">
              <label class="flex items-center gap-2 text-sm">
                <input v-model="supplier.payment_thanks_auto_send" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('settings.payment_thanks_auto_send') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model="supplier.payment_thanks_default_checked" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('settings.payment_thanks_default_checked') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model="supplier.payment_thanks_attach_paid_pdf" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('settings.payment_thanks_attach_paid_pdf') }}
              </label>
            </div>
          </div>
          <div class="md:col-span-2 border-t border-neutral-200 pt-3">
            <p class="text-sm font-medium text-neutral-700">{{ t('settings.self_copy.title') }}</p>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.self_copy.hint', { email: supplier.email }) }}</p>
            <div class="mt-2 grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.self_copy.type_documents') }}</label>
                <select v-model="selfCopyDocuments" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                  <option value="inherit">{{ t('settings.self_copy.inherit', { value: selfCopyFallbackLabel('documents') }) }}</option>
                  <option value="off">{{ t('settings.self_copy.mode_off') }}</option>
                  <option value="cc">{{ t('settings.self_copy.mode_cc') }}</option>
                  <option value="bcc">{{ t('settings.self_copy.mode_bcc') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.self_copy.type_reminders') }}</label>
                <select v-model="selfCopyReminders" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                  <option value="inherit">{{ t('settings.self_copy.inherit', { value: selfCopyFallbackLabel('reminders') }) }}</option>
                  <option value="off">{{ t('settings.self_copy.mode_off') }}</option>
                  <option value="cc">{{ t('settings.self_copy.mode_cc') }}</option>
                  <option value="bcc">{{ t('settings.self_copy.mode_bcc') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.self_copy.type_approvals') }}</label>
                <select v-model="selfCopyApprovals" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                  <option value="inherit">{{ t('settings.self_copy.inherit', { value: selfCopyFallbackLabel('approvals') }) }}</option>
                  <option value="off">{{ t('settings.self_copy.mode_off') }}</option>
                  <option value="cc">{{ t('settings.self_copy.mode_cc') }}</option>
                  <option value="bcc">{{ t('settings.self_copy.mode_bcc') }}</option>
                </select>
              </div>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.self_copy.approvals_note') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.auto_generate_recurring" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.auto_generate_recurring') }}
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.auto_generate_recurring_hint') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.embed_isdoc" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.embed_isdoc') }}
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.embed_isdoc_hint') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.proforma_payment_document') }}</label>
            <select v-model="supplier.proforma_payment_document"
                    class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="always_tax_document">{{ t('settings.proforma_payment_document_tax') }}</option>
              <option value="final_on_full_payment">{{ t('settings.proforma_payment_document_final') }}</option>
              <option value="manual">{{ t('settings.proforma_payment_document_manual') }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.proforma_payment_document_hint') }}</p>
          </div>
          </template>
        </div>

      </section>

      <!--
        H-30 — spravovaná instalace: doménu tu zákazník nezaloží, protože
        certifikát ani směrování pro cizí hostname na cizí infrastruktuře nikdo
        nezřídí. Plocha se proto nenabízí, ale POJMENUJE se: prázdné místo by
        vypadalo jako chybějící funkce. API to odmítá i bez UI (409).
      -->
      <section
        v-if="tab === 'company' && auth.isManagedInstallation && auth.domainsFeatureEnabled && auth.canRead('settings.domains')"
        class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm"
      >
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('domains.title') }}</h2>
        <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 flex gap-2.5">
          <svg class="w-4 h-4 mt-0.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
          <div>
            <div class="font-medium text-neutral-800">{{ t('domains.managed_title') }}</div>
            <p class="mt-0.5">{{ t('domains.managed_desc') }}</p>
          </div>
        </div>
      </section>
      <SupplierDomainsSettings v-else-if="tab === 'company' && auth.domainsFeatureEnabled && auth.canRead('settings.domains')" />

      <!-- Číslování faktur — samostatný box -->
      <section v-if="tab === 'documents'" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.numbering_section') }}</h2>
        <div>
          <h3 class="sr-only">{{ t('settings.numbering_section') }}</h3>
          <p class="text-xs text-neutral-500 mb-1">{{ t('settings.numbering_hint_intro') }}</p>
          <ul class="text-xs text-neutral-500 mb-3 space-y-0.5 ml-2">
            <li><code class="bg-neutral-100 px-1 rounded">{YYYY}</code> &mdash; {{ t('settings.numbering_hint_yyyy') }} <span class="text-neutral-400">(2026)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{YY}</code> &mdash; {{ t('settings.numbering_hint_yy') }} <span class="text-neutral-400">(26)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{MM}</code> &mdash; {{ t('settings.numbering_hint_mm') }} <span class="text-neutral-400">(05)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{CC}</code>, <code class="bg-neutral-100 px-1 rounded">{CCC}</code>&hellip; &mdash; {{ t('settings.numbering_hint_c') }} <span class="text-neutral-400">(01, 001…)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{PP}</code> &mdash; {{ t('settings.numbering_hint_pp') }} <span class="text-neutral-400">(PF/PN/KU/KN/NU/NN)</span></li>
          </ul>
          <div v-if="numberSeriesWarnings.length" class="mb-3 rounded-md border border-warning-300 bg-warning-50 px-3 py-2">
            <p class="text-xs font-medium text-warning-700">{{ t('settings.numbering_collision_warning') }}</p>
            <ul class="text-xs text-warning-700 mt-1 space-y-0.5">
              <li v-for="w in numberSeriesWarnings" :key="w.key">
                <strong>{{ w.aLabel }}</strong> &times; <strong>{{ w.bLabel }}</strong>
              </li>
            </ul>
            <p class="text-xs text-warning-600 mt-1">{{ t('settings.numbering_collision_hint') }}</p>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.invoice_number_format') }}</label>
              <input v-model="supplier.invoice_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.invoice || '{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="invoiceFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="invoiceFormatError" class="text-xs text-danger-500 mt-1">{{ invoiceFormatError }}</p>
              <p v-else-if="invoicePreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ invoicePreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.invoice_number_period') }}</label>
              <select v-model="supplier.invoice_number_period" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                <option value="year">{{ t('settings.numbering_period_year') }}</option>
                <option value="month">{{ t('settings.numbering_period_month') }}</option>
                <option value="none">{{ t('settings.numbering_period_none') }}</option>
              </select>
              <p class="text-xs text-neutral-400 mt-1">{{ t('settings.invoice_number_period_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.proforma_number_format') }}</label>
              <input v-model="supplier.proforma_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.proforma || '9{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="proformaFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="proformaFormatError" class="text-xs text-danger-500 mt-1">{{ proformaFormatError }}</p>
              <p v-else-if="proformaPreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ proformaPreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.credit_note_number_format') }}</label>
              <input v-model="supplier.credit_note_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.credit_note || '7{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="creditNoteFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="creditNoteFormatError" class="text-xs text-danger-500 mt-1">{{ creditNoteFormatError }}</p>
              <p v-else-if="creditNotePreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ creditNotePreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.purchase_invoice_number_format') }}</label>
              <input v-model="supplier.purchase_invoice_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.purchase || '{PP}{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="purchaseFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="purchaseFormatError" class="text-xs text-danger-500 mt-1">{{ purchaseFormatError }}</p>
              <p v-else-if="purchasePreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ purchasePreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
              <p class="text-xs text-neutral-400 mt-1">{{ t('settings.purchase_invoice_number_format_hint') }}</p>
            </div>
          </div>
        </div>

      </section>

      <!--
        Licenční moduly — účetnictví, mzdy, sklad a OSS mají společný rám, protože
        mají společnou podmínku: po zkušebních dvou měsících je API všech čtyř
        zavřené (CommercialFeatureAccess). Čtyři samostatné karty vedle sebe
        vypadaly jako čtyři nezávislá nastavení a nikde nebylo vidět, že jde
        o placenou nadstavbu.
      -->
      <div v-if="tab === 'accounting'" class="rounded-lg border-2 p-4 space-y-4"
           :class="auth.hasCommercialFeatures ? 'border-primary-300 bg-primary-50/30' : 'border-warning-400 bg-warning-50/50'">
        <header class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ t('settings.licensed_modules.title') }}</h2>
            <p class="text-xs text-neutral-600 mt-1 max-w-3xl">
              {{ t('settings.licensed_modules.hint') }}
              <span v-if="licenseTrialDaysLeft !== null" class="font-medium">
                {{ t('settings.licensed_modules.trial_left', { days: licenseTrialDaysLeft }) }}
              </span>
              <span v-else-if="!auth.hasCommercialFeatures" class="font-medium text-warning-800">
                {{ t('settings.licensed_modules.expired') }}
              </span>
            </p>
          </div>
          <RouterLink to="/activation/purchase" :class="btnOutline('primary')">
            {{ t('license.banner_cta') }}
          </RouterLink>
        </header>

      <!--
        Vést účetnictví (1179) — firemní opt-out účetní nadstavby. Box je první v záložce,
        protože rozhoduje o tom, jestli má zbytek účetních nastavení pod ním vůbec smysl.
      -->
      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
          {{ t('settings.accounting_enabled.title') }}
        </h2>
        <label class="flex items-start gap-2" :class="auth.hasCommercialFeatures ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'">
          <input v-model="supplier.accounting_enabled" type="checkbox" :disabled="!auth.hasCommercialFeatures" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
          <span>
            <span class="font-medium">{{ t('settings.accounting_enabled.label') }}</span>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('settings.accounting_enabled.hint') }}</p>
          </span>
        </label>
        <p v-if="supplier.accounting_enabled === false && auth.hasCommercialFeatures" class="text-xs text-warning-600 mt-2">
          {{ t('settings.accounting_enabled.off_note') }}
        </p>

        <!-- Režim účetnictví je podnastavení tohoto modulu, ne daňový údaj do EPO:
             s vypnutou nadstavbou nemá co ovlivnit (a přepnutí na podvojné by jen
             tiše naseedovalo osnovu). Ukazuje se proto až po zapnutí. -->
        <div v-if="supplier.accounting_enabled !== false" class="mt-4 pt-4 border-t border-neutral-200 max-w-md">
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.accounting_mode') }}</label>
          <select v-model="supplier.accounting_mode" :disabled="!auth.hasCommercialFeatures"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-60">
            <option value="tax_evidence">{{ t('settings.accounting_mode_tax_evidence') }}</option>
            <!-- Volba se bez licence nabízí zamčená, ne skrytá: právnická osoba
                 jinak nemá jak zjistit, že účetnictví aplikace umí a co pro ně
                 potřebuje (myinvoice#265). -->
            <option
              value="double_entry"
              :disabled="!auth.hasCommercialFeatures && supplier.accounting_mode !== 'double_entry'"
            >{{ t('settings.accounting_mode_double_entry') }}</option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">{{ t('settings.accounting_mode_hint') }}</p>
          <p v-if="originalAccountingMode === 'tax_evidence' && supplier.accounting_mode === 'double_entry'"
             class="text-xs text-warning-600 mt-1">
            {{ t('settings.accounting_mode_switch_backfill_hint') }}
          </p>
        </div>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.payroll_enabled.title') }}</h2>
        <!-- Alfa upozornění stojí NAD přepínačem záměrně: mzdy počítají odvody
             a podání, kde chyba stojí penále, takže se o stavu modulu musí
             uživatel dozvědět dřív, než ho zapne, ne až v nápovědě pod ním. -->
        <p class="mb-4 rounded-md border border-warning-300 bg-warning-50 px-3 py-2 text-xs text-warning-800">
          {{ t('settings.payroll_enabled.alpha_warning') }}
        </p>
        <label class="flex items-start gap-2" :class="auth.hasCommercialFeatures ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'">
          <input v-model="supplier.payroll_enabled" type="checkbox" :disabled="!auth.hasCommercialFeatures" class="mt-0.5 rounded border-neutral-300 text-payroll-600" />
          <span>
            <span class="font-medium">{{ t('settings.payroll_enabled.label') }}</span>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('settings.payroll_enabled.hint') }}</p>
          </span>
        </label>
        <p v-if="supplier.payroll_enabled === false && auth.hasCommercialFeatures" class="text-xs text-warning-600 mt-2">
          {{ t('settings.payroll_enabled.off_note') }}
        </p>
      </section>

      <!-- Sklad (Epic SKLAD) — samostatný box, nezávislé na accounting_mode.
           Bez licence se NESKRÝVÁ, jen zamkne: schovaný přepínač vypadá jako
           chybějící funkce, ne jako funkce za licencí. -->
      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
          {{ t('stock.settings.enable_label') }}
        </h2>
        <div class="space-y-3" :class="auth.hasCommercialFeatures ? '' : 'opacity-60'">
          <label class="flex items-start gap-2" :class="auth.hasCommercialFeatures ? 'cursor-pointer' : 'cursor-not-allowed'">
            <input v-model="supplier.stock_enabled" type="checkbox" :disabled="!auth.hasCommercialFeatures" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
            <span>
              <span class="font-medium">{{ t('stock.settings.enable_label') }}</span>
              <p class="text-xs text-neutral-500 mt-0.5">{{ t('stock.settings.enable_hint') }}</p>
            </span>
          </label>
          <label v-if="supplier.stock_enabled" class="flex items-start gap-2 cursor-pointer ml-6">
            <input v-model="supplier.stock_auto_issue" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
            <span>
              <span class="font-medium">{{ t('stock.settings.auto_issue_label') }}</span>
              <p class="text-xs text-neutral-500 mt-0.5">{{ t('stock.settings.auto_issue_hint') }}</p>
            </span>
          </label>
          <div v-if="supplier.stock_enabled" class="ml-6">
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('stock.settings.in_transit_from_label') }}</label>
            <select v-model="supplier.stock_in_transit_from" class="w-full max-w-sm h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="sent">{{ t('stock.settings.in_transit_from_sent') }}</option>
              <option value="confirmed">{{ t('stock.settings.in_transit_from_confirmed') }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('stock.settings.in_transit_from_hint') }}</p>
          </div>
        </div>
      </section>

      <!-- OSS (One Stop Shop) — čtvrtá modulová karta ve stejném vzoru jako účetnictví/mzdy/sklad.
           Údaje o identifikaci a platnosti mají smysl jen se zapnutým režimem, proto se
           odkrývají stejně jako vnořená „Automatická výdejka" u skladu. -->
      <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.oss_section') }}</h2>
        <div class="space-y-3" :class="auth.hasCommercialFeatures ? '' : 'opacity-60'">
          <label class="flex items-start gap-2" :class="auth.hasCommercialFeatures ? 'cursor-pointer' : 'cursor-not-allowed'">
            <input v-model="(supplier as any).oss_enabled" type="checkbox" :disabled="!auth.hasCommercialFeatures" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
            <span>
              <span class="font-medium">{{ t('settings.oss_enabled') }}</span>
              <p class="text-xs text-neutral-500 mt-0.5">{{ t('settings.oss_hint') }}</p>
            </span>
          </label>
          <div v-if="(supplier as any).oss_enabled" class="grid grid-cols-1 md:grid-cols-4 gap-3 ml-6">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.oss_identification_country') }}</label>
              <input v-model="(supplier as any).oss_identification_country" type="text" maxlength="2" placeholder="CZ"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.oss_return_currency') }}</label>
              <input v-model="(supplier as any).oss_return_currency" type="text" maxlength="3" placeholder="EUR"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.oss_valid_from') }}</label>
              <input v-model="(supplier as any).oss_valid_from" type="date"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.oss_valid_to') }}</label>
              <input v-model="(supplier as any).oss_valid_to" type="date"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>
        </div>
      </section>
      </div>
      <!-- /licenční moduly -->

      <!-- Daňové nastavení (EPO výkazy DPH/KH/DPFO/DPPO) — samostatný box -->
      <section v-if="tab === 'accounting'" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.tax_section') }}</h2>
        <div>
          <h3 class="sr-only">{{ t('settings.tax_section') }}</h3>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.tax_hint') }}</p>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <!-- Auto-post hook (A2) — jen v podvojném účetnictví (jinak se doklady neúčtují) -->
            <div v-if="auth.hasCommercialFeatures && supplier.accounting_mode === 'double_entry'" class="md:col-span-2 space-y-2">
              <label class="flex items-start gap-2 cursor-pointer">
                <input v-model="(supplier as any).auto_post_invoices" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
                <span>
                  <span class="text-sm text-neutral-800">{{ t('settings.auto_post_invoices') }}</span>
                  <span class="block text-xs text-neutral-500">{{ t('settings.auto_post_invoices_hint') }}</span>
                </span>
              </label>
              <label class="flex items-start gap-2 cursor-pointer">
                <input v-model="(supplier as any).auto_post_purchases" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
                <span>
                  <span class="text-sm text-neutral-800">{{ t('settings.auto_post_purchases') }}</span>
                  <span class="block text-xs text-neutral-500">{{ t('settings.auto_post_purchases_hint') }}</span>
                </span>
              </label>
            </div>
            <AutomationPolicyBox
              v-if="auth.hasCommercialFeatures && supplier.accounting_mode === 'double_entry'"
              class="md:col-span-2"
              :save-additional="saveAutoPostFlags"
            />
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.taxpayer_type') }}</label>
              <select v-model="supplier.taxpayer_type" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">— {{ t('common.unset') }} —</option>
                <option value="fo">{{ t('settings.taxpayer_fo') }}</option>
                <option value="po">{{ t('settings.taxpayer_po') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_period') }}</label>
              <select v-model="supplier.vat_period" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">— {{ t('common.unset') }} —</option>
                <option value="monthly">{{ t('settings.vat_monthly') }}</option>
                <option value="quarterly">{{ t('settings.vat_quarterly') }}</option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.vat_period_hint') }}</p>
            </div>
            <!-- Plátcovství DPH v čase (VH-01) — CRUD historie je okamžitá akce
                 přes API (vzor bloku OSS/měn), NE součást společného Uložit. -->
            <div class="md:col-span-2 border border-neutral-200 rounded-md p-3">
              <div class="flex items-start justify-between gap-2 flex-wrap">
                <div>
                  <p class="text-sm font-medium text-neutral-700">{{ t('settings.vat_status.title') }}</p>
                  <p class="text-xs text-neutral-500 mt-0.5">
                    {{ t('settings.vat_status.current') }}: <strong class="text-neutral-700">{{ vatCurrentStateLabel }}</strong>
                  </p>
                </div>
                <button type="button" @click="openVatForm()" :class="btnOutline('primary')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  {{ t('settings.vat_status.add') }}
                </button>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.vat_status.hint') }}</p>
              <!-- § 6 hlídač obratu (VH-07) — obrat překročil limit, plátcovství vzniká ze zákona. -->
              <div v-if="vatRegBanner" class="mt-3 border border-warning-500/40 bg-warning-50 rounded-md p-3">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                  <div>
                    <p class="text-sm font-medium text-warning-700">{{ t('settings.vat_status.s6_banner_title') }}</p>
                    <p class="text-xs text-neutral-600 mt-1">
                      {{ t('settings.vat_status.s6_banner_text', {
                        turnover: fmtCzk(vatRegBanner.turnover),
                        limit: fmtCzk(vatRegBanner.status === 'exceeded_high' ? vatRegBanner.limit_high : vatRegBanner.limit_low),
                        date: vatRegBanner.becomes_payer_on ?? '',
                      }) }}
                    </p>
                    <p v-if="vatRegBanner.application_deadline" class="text-xs text-neutral-600 mt-0.5">
                      {{ vatRegBanner.application_deadline_basis === 'statutory'
                        ? t('settings.vat_status.s6_deadline_statutory', { deadline: vatRegBanner.application_deadline })
                        : t('settings.vat_status.s6_deadline_informative', { deadline: vatRegBanner.application_deadline }) }}
                    </p>
                  </div>
                  <button type="button" @click="openVatFormFromRegistration" :class="btnFilled('primary')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                    {{ t('settings.vat_status.s6_banner_action') }}
                  </button>
                </div>
              </div>
              <!-- § 79/§ 79a hint po uloženém přechodu plátcovství (VH-07). -->
              <div v-if="vatS79Suggest" class="mt-3 border border-primary-200 bg-primary-50 rounded-md p-3 flex items-start justify-between gap-3">
                <div>
                  <p class="text-sm font-medium text-primary-800">{{ t('settings.vat_status.s79_prompt_title') }}</p>
                  <p class="text-xs text-neutral-600 mt-1">
                    {{ vatS79Suggest.kind === 'registration'
                      ? t('settings.vat_status.s79_prompt_registration', { date: vatS79Suggest.effective_on })
                      : t('settings.vat_status.s79_prompt_deregistration', { date: vatS79Suggest.effective_on }) }}
                  </p>
                  <RouterLink
                    :to="{ name: 'reports-vat-corrections', query: { tab: 's79', kind: vatS79Suggest.kind, effective_on: vatS79Suggest.effective_on } }"
                    class="inline-block mt-1 text-xs font-medium text-primary-700 hover:underline">
                    {{ t('settings.vat_status.s79_prompt_link') }} →
                  </RouterLink>
                </div>
                <button type="button" @click="vatS79Suggest = null"
                  class="cursor-pointer p-1 text-neutral-400 hover:text-neutral-600" :title="t('common.close')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                </button>
              </div>
              <div class="overflow-x-auto mt-3">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-left text-xs text-neutral-500 border-b border-neutral-200">
                      <th class="py-1.5 pr-3 font-medium">{{ t('settings.vat_status.col_from') }}</th>
                      <th class="py-1.5 pr-3 font-medium">{{ t('settings.vat_status.col_state') }}</th>
                      <th class="py-1.5 pr-3 font-medium">{{ t('settings.vat_status.col_note') }}</th>
                      <th class="py-1.5 w-20"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="entry in vatHistory" :key="entry.id" class="border-b border-neutral-100">
                      <td class="py-1.5 pr-3 font-mono whitespace-nowrap">
                        <template v-if="entry.effective_from === VAT_BASELINE_DATE">{{ t('settings.vat_status.baseline') }}</template>
                        <template v-else>{{ entry.effective_from }}</template>
                        <span v-if="entry.effective_from > todayIso()" class="ml-1 text-xs text-warning-600 font-sans">
                          {{ t('settings.vat_status.future_badge') }}
                        </span>
                      </td>
                      <td class="py-1.5 pr-3">{{ vatKindLabel(vatStatusKind(entry.is_vat_payer, entry.is_identified)) }}</td>
                      <td class="py-1.5 pr-3 text-neutral-500">{{ entry.note || '—' }}</td>
                      <td class="py-1.5 text-right whitespace-nowrap">
                        <button type="button" @click="openVatForm(entry)"
                          class="cursor-pointer p-1 text-neutral-400 hover:text-primary-600" :title="t('common.edit')">
                          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                        </button>
                        <button v-if="entry.effective_from !== VAT_BASELINE_DATE" type="button" @click="vatDeleteEntry = entry"
                          class="cursor-pointer p-1 text-neutral-400 hover:text-danger-600" :title="t('common.delete')">
                          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                        </button>
                      </td>
                    </tr>
                    <EmptyState v-if="vatHistory.length === 0" dense icon="clipboardCheck" :colspan="4" :title="t('settings.vat_status.empty')" />
                  </tbody>
                </table>
              </div>
              <div v-if="vatFormOpen" class="mt-3 border-t border-neutral-200 pt-3">
                <p class="text-xs font-medium text-neutral-700 mb-2">
                  {{ vatFormEditingId === null ? t('settings.vat_status.form_add_title') : t('settings.vat_status.form_edit_title') }}
                </p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_status.form_date') }}</label>
                    <input v-model="vatFormDate" type="date" :disabled="vatFormEditingId !== null"
                      class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100 disabled:text-neutral-500" />
                    <p v-if="vatFormEditingId !== null" class="text-xs text-neutral-400 mt-1">{{ t('settings.vat_status.form_date_locked_hint') }}</p>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_status.form_state') }}</label>
                    <select v-model="vatFormKind" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                      <option value="payer">{{ t('settings.vat_status.state_payer') }}</option>
                      <option value="non_payer">{{ t('settings.vat_status.state_non_payer') }}</option>
                      <option value="identified">{{ t('settings.vat_status.state_identified') }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.vat_status.form_note') }}</label>
                    <input v-model="vatFormNote" type="text" maxlength="255"
                      class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
                  </div>
                </div>
                <p v-if="vatFormKind === 'identified'" class="text-xs text-neutral-500 mt-2">{{ t('settings.is_identified_hint') }}</p>
                <div class="flex justify-end gap-2 mt-3">
                  <button type="button" @click="closeVatForm" :disabled="vatSaving" :class="btnOutline('neutral')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                    {{ t('common.cancel') }}
                  </button>
                  <button type="button" @click="submitVatForm()" :disabled="vatSaving" :class="btnFilled('primary')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                    {{ vatSaving ? '…' : t('common.save') }}
                  </button>
                </div>
              </div>
            </div>
            <div v-if="!supplier.is_vat_payer">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.flat_tax_band') }}</label>
              <select v-model="(supplier as any).flat_tax_band" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="none">{{ t('settings.flat_tax_none') }}</option>
                <option value="band1">{{ t('settings.flat_tax_band1') }}</option>
                <option value="band2">{{ t('settings.flat_tax_band2') }}</option>
                <option value="band3">{{ t('settings.flat_tax_band3') }}</option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.flat_tax_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.financial_office_code') }}</label>
              <input v-model="supplier.financial_office_code" type="text" maxlength="8" placeholder="451"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.financial_office_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.workplace_code') }}</label>
              <input v-model="supplier.workplace_code" type="text" maxlength="8"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <!-- Zastoupení daňovým poradcem (§29/2 DŘ) — CRUD historie je okamžitá akce
                 přes API (vzor bloku Plátcovství DPH výše), NE součást společného Uložit. -->
            <div class="md:col-span-2 border border-neutral-200 rounded-md p-3">
              <div class="flex items-start justify-between gap-2 flex-wrap">
                <div>
                  <p class="text-sm font-medium text-neutral-700">{{ t('settings.tax_representation.title') }}</p>
                  <p class="text-xs text-neutral-500 mt-0.5">
                    {{ t('settings.tax_representation.current') }}: <strong class="text-neutral-700">{{ taxRepCurrentLabel }}</strong>
                  </p>
                </div>
                <button type="button" @click="openTaxRepForm()" :class="btnOutline('primary')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  {{ t('settings.tax_representation.add') }}
                </button>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.tax_representation.hint') }}</p>
              <div class="overflow-x-auto mt-3">
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-left text-xs text-neutral-500 border-b border-neutral-200">
                      <th class="py-1.5 pr-3 font-medium">{{ t('settings.tax_representation.col_from') }}</th>
                      <th class="py-1.5 pr-3 font-medium">{{ t('settings.tax_representation.col_state') }}</th>
                      <th class="py-1.5 pr-3 font-medium">{{ t('settings.tax_representation.col_note') }}</th>
                      <th class="py-1.5 w-20"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="entry in taxRepHistory" :key="entry.id" class="border-b border-neutral-100">
                      <td class="py-1.5 pr-3 font-mono whitespace-nowrap">
                        <template v-if="entry.effective_from === TAX_REP_BASELINE_DATE">{{ t('settings.tax_representation.baseline') }}</template>
                        <template v-else>{{ entry.effective_from }}</template>
                        <span v-if="entry.effective_from > todayIso()" class="ml-1 text-xs text-warning-600 font-sans">
                          {{ t('settings.vat_status.future_badge') }}
                        </span>
                      </td>
                      <td class="py-1.5 pr-3">
                        <template v-if="!entry.represented">{{ t('settings.tax_representation.state_none') }}</template>
                        <template v-else-if="entry.type === 'P'">{{ entry.company_name }}</template>
                        <template v-else>{{ entry.first_name }} {{ entry.last_name }}</template>
                      </td>
                      <td class="py-1.5 pr-3 text-neutral-500">{{ entry.note || '—' }}</td>
                      <td class="py-1.5 text-right whitespace-nowrap">
                        <button type="button" @click="openTaxRepForm(entry)"
                          class="cursor-pointer p-1 text-neutral-400 hover:text-primary-600" :title="t('common.edit')">
                          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                        </button>
                        <button type="button" @click="taxRepDeleteEntry = entry"
                          class="cursor-pointer p-1 text-neutral-400 hover:text-danger-600" :title="t('common.delete')">
                          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                        </button>
                      </td>
                    </tr>
                    <EmptyState v-if="taxRepHistory.length === 0" dense icon="clipboardCheck" :colspan="4" :title="t('settings.tax_representation.empty')" />
                  </tbody>
                </table>
              </div>
              <div v-if="taxRepFormOpen" class="mt-3 border-t border-neutral-200 pt-3">
                <p class="text-xs font-medium text-neutral-700 mb-2">
                  {{ taxRepFormEditingId === null ? t('settings.tax_representation.form_add_title') : t('settings.tax_representation.form_edit_title') }}
                </p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_date') }}</label>
                    <input v-model="taxRepFormDate" type="date" :disabled="taxRepFormEditingId !== null"
                      class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100 disabled:text-neutral-500" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_represented') }}</label>
                    <select v-model="taxRepFormRepresented" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                      <option :value="false">{{ t('settings.tax_representation.state_none') }}</option>
                      <option :value="true">{{ t('settings.tax_representation.state_represented') }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_note') }}</label>
                    <input v-model="taxRepFormNote" type="text" maxlength="255"
                      class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
                  </div>
                </div>
                <template v-if="taxRepFormRepresented">
                  <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
                    <div>
                      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_type') }}</label>
                      <select v-model="taxRepFormType" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                        <option value="F">{{ t('settings.tax_representation.type_f') }}</option>
                        <option value="P">{{ t('settings.tax_representation.type_p') }}</option>
                      </select>
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_ev_number') }}</label>
                      <input v-model="taxRepFormEvNumber" type="text" maxlength="36"
                        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_poa_date') }}</label>
                      <input v-model="taxRepFormPoaDate" type="date"
                        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                    </div>
                  </div>
                  <div v-if="taxRepFormType === 'F'" class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                    <div>
                      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_first_name') }}</label>
                      <input v-model="taxRepFormFirstName" type="text" maxlength="20"
                        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_last_name') }}</label>
                      <input v-model="taxRepFormLastName" type="text" maxlength="36"
                        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
                    </div>
                  </div>
                  <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                    <div>
                      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_company_name') }}</label>
                      <input v-model="taxRepFormCompanyName" type="text" maxlength="255"
                        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
                    </div>
                    <div>
                      <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.tax_representation.form_ico') }}</label>
                      <input v-model="taxRepFormIco" type="text" maxlength="10"
                        class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                    </div>
                  </div>
                </template>
                <div class="flex justify-end gap-2 mt-3">
                  <button type="button" @click="closeTaxRepForm" :disabled="taxRepSaving" :class="btnOutline('neutral')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                    {{ t('common.cancel') }}
                  </button>
                  <button type="button" @click="submitTaxRepForm()" :disabled="taxRepSaving" :class="btnFilled('primary')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                    {{ taxRepSaving ? '…' : t('common.save') }}
                  </button>
                </div>
              </div>
            </div>
            <!-- U OSVČ jsou to osobní identifikátory a patří sem vždy. U právnické osoby je
                 kanonickým zdrojem mzdový modul — ale jen dokud běží: s vypnutými Mzdami by
                 VS zaměstnavatele neměl kde bydlet, a detekce odvodů v bance i šablony
                 pravidel pak čtou zpátky tahle pole. -->
            <template v-if="!employerIdentifiersInPayroll">
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">
                  {{ supplier.taxpayer_type === 'po' ? t('settings.cssz_vsdp_employer') : t('settings.cssz_vsdp') }}
                </label>
                <input v-model="supplier.cssz_vsdp" type="text" maxlength="20"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.cssz_ossz_code') }}</label>
                <input v-model="supplier.cssz_ossz_code" type="text" maxlength="3" inputmode="numeric"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">
                  {{ supplier.taxpayer_type === 'po' ? t('settings.health_insurance_number_employer') : t('settings.health_insurance_number') }}
                </label>
                <input v-model="supplier.health_insurance_number" type="text" maxlength="20"
                  class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <p v-if="supplier.taxpayer_type === 'po'"
                class="md:col-span-2 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600">
                {{ t('settings.payroll_employer_identifiers_legacy_hint') }}
              </p>
            </template>
            <p v-else class="md:col-span-2 rounded-md border border-payroll-500/30 bg-payroll-50 px-3 py-2 text-xs text-neutral-600">
              {{ t('settings.payroll_employer_identifiers_hint') }}
            </p>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.cz_nace_code') }}</label>
              <!-- Našeptávač nad číselníkem ČINNOSTI: nabízí jen kódy platné k dnešku.
                   ARES eviduje klasifikaci ještě podle NACE rev. 2, kdežto číselník EPO
                   je od 1. 1. 2026 na rev. 2.1 — prefill z ARES tak často přinese kód,
                   na který portál hlásí propustnou chybu 30, a tady se najde nástupce. -->
              <SearchableSelect
                :model-value="supplier.cz_nace_code ?? null"
                remote
                :loading="naceLoading"
                :options="naceOptions"
                :selected-option="naceSelected"
                :placeholder="t('settings.cz_nace_placeholder')"
                :no-results-label="t('settings.cz_nace_no_results')"
                @search="searchNace"
                @update:model-value="pickNace"
              />
              <!-- Stav uloženého kódu proti číselníku. Nic z toho neblokuje uložení
                   ani podání — snapshot číselníku může zestárnout. -->
              <p v-if="naceResolved?.status === 'expired'" class="text-xs text-amber-600 mt-1">
                {{ t('settings.cz_nace_expired', { code: naceResolved.display, date: naceResolved.valid_to }) }}
              </p>
              <p v-else-if="naceResolved?.status === 'unknown'" class="text-xs text-amber-600 mt-1">
                {{ t('settings.cz_nace_unknown', { code: naceResolved.code }) }}
              </p>
              <p v-else class="text-xs text-neutral-500 mt-1">{{ t('settings.cz_nace_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.data_box_id') }}</label>
              <input v-model="supplier.data_box_id" type="text" maxlength="16"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.data_box_type') }}</label>
              <select v-model="supplier.data_box_type" class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">{{ t('settings.data_box_type_none') }}</option>
                <option value="FO">{{ t('settings.data_box_type_fo') }}</option>
                <option value="PFO">{{ t('settings.data_box_type_pfo') }}</option>
                <option value="PO">{{ t('settings.data_box_type_po') }}</option>
                <option value="OVM">{{ t('settings.data_box_type_ovm') }}</option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.data_box_type_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.street_number_pop') }}</label>
              <input v-model="supplier.street_number_pop" type="text" maxlength="20" placeholder="1104"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('settings.street_number_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.street_number_orient') }}</label>
              <input v-model="supplier.street_number_orient" type="text" maxlength="20" placeholder="36"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>

          <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mt-5 mb-2">{{ t('settings.opr_section') }}</h4>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.opr_hint') }}</p>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.opr_jmeno') }}</label>
              <input v-model="supplier.opr_jmeno" type="text" maxlength="60"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.opr_prijmeni') }}</label>
              <input v-model="supplier.opr_prijmeni" type="text" maxlength="60"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.opr_postaveni') }}</label>
              <input v-model="supplier.opr_postaveni" type="text" maxlength="60" placeholder="jednatel"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>

          <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mt-5 mb-2">{{ t('settings.sest_section') }}</h4>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.sest_hint') }}</p>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_jmeno') }}</label>
              <input v-model="supplier.sest_jmeno" type="text" maxlength="100"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_prijmeni') }}</label>
              <input v-model="supplier.sest_prijmeni" type="text" maxlength="100"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_funkce') }}</label>
              <input v-model="supplier.sest_funkce" type="text" maxlength="80"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_telefon') }}</label>
              <input v-model="supplier.sest_telefon" type="text" maxlength="40"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.sest_email') }}</label>
              <input v-model="supplier.sest_email" type="email" maxlength="120"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
        </div>

      </section>

      <!-- Pohoda XML export config (volitelné) — samostatný box -->
      <section v-if="tab === 'accounting'" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.pohoda_section') }}</h2>
        <div>
          <h3 class="sr-only">{{ t('settings.pohoda_section') }}</h3>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.pohoda_hint') }}</p>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_account_code') }}</label>
              <input v-model="supplier.pohoda_account_code" type="text" placeholder="KB" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_centre_code') }}</label>
              <input v-model="supplier.pohoda_centre_code" type="text" placeholder="STR1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_activity_code') }}</label>
              <input v-model="supplier.pohoda_activity_code" type="text" placeholder="ACT1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_contract_code') }}</label>
              <input v-model="supplier.pohoda_contract_code" type="text" placeholder="ZAK1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_accounting_code') }}</label>
              <input v-model="supplier.pohoda_accounting_code" type="text" placeholder="300" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>
        </div>

      </section>

      <!-- Ukázková data — jen pokud nějaká evidovaná existují (issue #162) -->
      <section v-if="tab === 'accounting' && sampleStatus?.has" class="bg-surface border border-warning-500/40 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-warning-600 mb-2">{{ t('settings.sample_data.title') }}</h2>
        <p class="text-sm text-neutral-600 mb-1">{{ t('settings.sample_data.description') }}</p>
        <p v-if="sampleSummaryLine" class="text-xs text-neutral-500 mb-4">{{ t('settings.sample_data.contains') }}: {{ sampleSummaryLine }}</p>
        <button type="button" @click="showSampleConfirm = true"
          :class="btnOutline('danger')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ t('settings.sample_data.remove_button') }}
        </button>
      </section>

      <div class="flex justify-end border-t border-neutral-200 pt-4">
        <button type="button" @click="saveSupplier" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('settings.save_supplier') }}
        </button>
      </div>
    </div>

    <!-- Potvrzení odebrání ukázkových dat -->
    <div v-if="showSampleConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-6">
        <div class="flex items-start gap-3 mb-4">
          <div class="w-10 h-10 rounded-full bg-danger-50 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 text-danger-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0L3.34 16a2 2 0 001.73 3z"/></svg>
          </div>
          <div>
            <h3 class="text-base font-semibold text-neutral-900">{{ t('settings.sample_data.confirm_title') }}</h3>
            <p class="text-sm text-neutral-600 mt-1">{{ t('settings.sample_data.confirm_text') }}</p>
            <p v-if="sampleSummaryLine" class="text-xs text-neutral-500 mt-2">{{ sampleSummaryLine }}</p>
          </div>
        </div>
        <div class="flex justify-end gap-2">
          <button type="button" @click="showSampleConfirm = false" :disabled="sampleDeleting"
            :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="removeSampleData" :disabled="sampleDeleting"
            :class="btnFilled('danger')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ sampleDeleting ? '…' : t('settings.sample_data.confirm_button') }}
          </button>
        </div>
      </div>
    </div>

    <!-- VH-01: potvrzení smazání řádku historie plátcovství -->
    <div v-if="vatDeleteEntry && !vatConflicts.length" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-6">
        <h3 class="text-base font-semibold text-neutral-900">{{ t('settings.vat_status.delete_confirm_title') }}</h3>
        <p class="text-sm text-neutral-600 mt-1">
          {{ t('settings.vat_status.delete_confirm_text', {
            date: vatDeleteEntry.effective_from,
            state: vatKindLabel(vatStatusKind(vatDeleteEntry.is_vat_payer, vatDeleteEntry.is_identified)),
          }) }}
        </p>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" @click="vatDeleteEntry = null" :disabled="vatSaving" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="confirmVatDelete()" :disabled="vatSaving" :class="btnFilled('danger')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ vatSaving ? '…' : t('common.delete') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Potvrzení smazání řádku historie zastoupení daňovým poradcem (§29/2 DŘ) -->
    <div v-if="taxRepDeleteEntry" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-6">
        <h3 class="text-base font-semibold text-neutral-900">{{ t('settings.tax_representation.delete_confirm_title') }}</h3>
        <p class="text-sm text-neutral-600 mt-1">
          {{ t('settings.tax_representation.delete_confirm_text', { date: taxRepDeleteEntry.effective_from }) }}
        </p>
        <div class="flex justify-end gap-2 mt-4">
          <button type="button" @click="taxRepDeleteEntry = null" :disabled="taxRepSaving" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="confirmTaxRepDelete()" :disabled="taxRepSaving" :class="btnFilled('danger')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ taxRepSaving ? '…' : t('common.delete') }}
          </button>
        </div>
      </div>
    </div>

    <!-- VH-01: retro-guard 409 — výčet kolizí (zamčená období / podaná přiznání) + acknowledge -->
    <div v-if="vatConflicts.length && vatPendingAction" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg p-6">
        <div class="flex items-start gap-3 mb-3">
          <div class="w-10 h-10 rounded-full bg-warning-50 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 text-warning-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
          </div>
          <div>
            <h3 class="text-base font-semibold text-neutral-900">{{ t('settings.vat_status.conflict_title') }}</h3>
            <p class="text-sm text-neutral-600 mt-1">{{ t('settings.vat_status.conflict_text') }}</p>
          </div>
        </div>
        <ul class="text-sm text-neutral-700 space-y-1 mb-4 max-h-56 overflow-y-auto border border-neutral-200 rounded-md p-3">
          <li v-for="(c, i) in vatConflicts" :key="i" class="flex items-start gap-2">
            <span class="text-warning-600 mt-0.5">•</span>
            <span>{{ vatCollisionLabel(c) }}</span>
          </li>
        </ul>
        <p class="text-xs text-neutral-500 mb-4">{{ t('settings.vat_status.conflict_hint') }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" @click="dismissVatConflicts" :disabled="vatSaving" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="acknowledgeVatConflicts" :disabled="vatSaving" :class="btnFilled('danger')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ vatSaving ? '…' : t('settings.vat_status.conflict_acknowledge') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollStatutoryEvidence,
  type PayrollStatutoryEvidenceFrozenRun,
  type PayrollStatutoryEvidenceRow,
  type PayrollStatutoryEvidenceSection,
} from '@/api/payroll'
import { btnFilled, btnFilledSm, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import CountrySelect from '@/components/ui/CountrySelect.vue'
import { formatDate, formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { loadDefaultHealthInsurerCode } from '@/composables/usePayrollDefaultInsurer'
import { useAuthStore } from '@/stores/auth'
import { healthInsurerOptions } from '@/utils/healthInsurers'
import {
  applyFieldChange,
  currentRow,
  currentRows,
  CUSTOM_REASON,
  crownsToMinorUnits,
  defaultRow,
  minorUnitsToCrowns,
  evidenceDetailFields,
  primaryFields,
  reasonLabelKey,
  reasonOptions,
  rowIssues,
  sectionIssues,
  statutoryText,
  STATUTORY_SECTIONS,
  type StatutoryFieldSpec,
  type StatutoryFormContext,
  type StatutoryIssue,
  type StatutorySectionSpec,
} from './statutoryEvidenceForm'
import DateInput from '@/components/ui/DateInput.vue'
import { fieldSelector, revealField } from '@/utils/revealField'
import PayrollStatutoryBulkDefaultsDialog from '@/components/payroll/PayrollStatutoryBulkDefaultsDialog.vue'
import PayrollHealthInsurerNoticePanel from '@/components/payroll/PayrollHealthInsurerNoticePanel.vue'
import { usePersonCardSaveSection } from './personCardSave'

/**
 * Zákonná evidence osoby.
 *
 * Není to „další formulář": bez prohlášení k dani, daňové rezidence,
 * příslušnosti k sociálnímu a zdravotnímu pojištění a evidence slevy
 * pracujícího důchodce shodí mzdový běh celý zákonný výpočet do ručního
 * posouzení. Panel proto neukazuje obecnou nápovědu, ale konkrétní seznam
 * toho, co k danému měsíci chybí — a pojmenovává to stejnými důvody, jaké
 * hlásí výpočet.
 *
 * Slevy na dani (§ 35ba) jsou jediná sekce, která výpočet neblokuje, a přesto
 * je peněžní: bez zaevidované slevy na poplatníka odvede zaměstnanec
 * s podepsaným prohlášením o 2 570 Kč měsíčně víc. Vede se po druzích slevy,
 * takže na rozdíl od ostatních sekcí má víc souběžných řad a prázdno v ní
 * není chybějící údaj.
 *
 * Hodnoty jako `czech_regime_verified` nebo `insurer_status = verified` jsou
 * PRÁVNÍ SKUTEČNOSTI, ne přepínače. Ke každé se proto váže doklad; lidské
 * vysvětlení má vlastní pole. Kdo doklad nemá, zvolí variantu „neověřeno" —
 * ta jde uložit taky, jen zůstane vidět jako blokátor.
 *
 * ## Proč to nejsou tabulky pod sebou
 *
 * Panel byl zeď šesti stejných bloků po pěti polích a odpověď na jedinou
 * otázku, kterou má uživatel („co u téhle osoby teď platí"), se v něm musela
 * hledat očima. Rozvržení proto vede shora dolů podle toho, jak často se to
 * potřebuje:
 *
 * 1. **Nahoře stav.** U každé sekce jeden řádek: co platí k vybranému měsíci
 *    a od kdy. Historie i editace jsou pod tím, sbalené.
 * 2. **Doklad je nepovinný, tak nezabírá plochu.** Odkaz na podklad, ID
 *    dokumentu a poznámka jdou pod „Doplnit podklad".
 * 3. **Zamčený řádek nabízí AKCI, ne zašedlá pole.** Buď se založí nová verze
 *    od prvního dne po hranici zmrazení, nebo se otevře k opravě mzda, která
 *    hranici drží — obojí přímo odsud, protože jinak uživatel jen čte, že něco
 *    nejde, a nedozví se, jak to udělat.
 *
 * Sbalená historie ale nesmí schovat editaci: prázdná sekce nabízí v hlavičce
 * rovnou „Přidat záznam" (otevře předvyplněný záznam a postaví kurzor do
 * prvního povinného pole), vyplněná „Upravit", které zapne editaci JEN té
 * sekce. Společné „Upravit evidenci" dole otevře všechny sekce.
 *
 * Ukládá se pořád jedním mechanismem (společná lišta karty, mimo kartu
 * tlačítko dole). Lišta pod rozpracovanou sekcí „Neuloženo, Uložit" volá
 * přesně totéž uložení; jen ho staví tam, kde se zrovna píše, protože kdo
 * přidal záznam, viděl jinak dole jen další „Přidat záznam" a změnu neuložil.
 *
 * Běžný český zaměstnanec ale nemá co vyplňovat: „Přidat záznam" rovnou
 * nabídne rezidenta CZ, český sociální i zdravotní režim a pojišťovnu, u které
 * je osoba vedená. Co plyne z jiné odpovědi, se neptáme, a doklad se vybírá
 * z typických důvodů — kanonickou referenci vygeneruje formulář. Pravidla
 * (a jejich vazba na serverový validátor) jsou v `statutoryEvidenceForm.ts`.
 */
const props = defineProps<{
  personId: number
  canWrite: boolean
  /** Nejdřívější nástup osoby — výchozí záznamy mají platit od něj, ne od zvoleného měsíce. */
  employmentStartOn?: string | null
}>()

// UI-24: po uložení musí nadřazená karta přepočítat bannery chybějících údajů,
// jinak dál hlásí „Chybí: daňová rezidence" až do F5.
const emit = defineEmits<{ saved: [] }>()

const SECTIONS = STATUTORY_SECTIONS

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const loading = ref(true)
const saving = ref(false)
/**
 * Sekce v editaci. Editace je po sekcích: „Upravit" u jedné sekce nesmí
 * odemknout celou stránku, jinak uživatel neví, kde právě píše.
 */
const editingSections = reactive(new Set<string>())
const editing = computed(() => editingSections.size > 0)
const correcting = ref(false)
const loadError = ref('')
const saveError = ref('')
const evidence = ref<PayrollStatutoryEvidence | null>(null)
const drafts = ref<Record<string, PayrollStatutoryEvidenceRow[]>>({})
const employerInsurerCode = ref<string | null>(null)

const insurerOptions = healthInsurerOptions()

/** Evidence se vyhodnocuje k měsíci; výchozí je ten, ve kterém uživatel je. */
/*
 * Evidence se vyhodnocuje ke konci běžného měsíce — u člověka, který teprve
 * nastoupí, ale k datu nástupu. Jinak panel k 30. 9. hlásil „chybí 4 údaje"
 * u osoby s nástupem 15. 10., jejíž výchozí evidence platí až od října (Q8-48).
 */
function defaultEffectiveOn(): string {
  const end = monthEnd(new Date())
  const start = props.employmentStartOn ?? null
  return start !== null && start > end ? start : end
}
const effectiveOn = ref(defaultEffectiveOn())

/**
 * Sekce, které uživatel sám rozbalil. Výchozí stav se počítá (viz
 * `historyOpen`) — jakmile ale někdo klikne, rozhoduje jeho volba, jinak by mu
 * panel sekci pod rukama zase zavřel při každém přepočtu.
 */
const historyToggled = reactive<Record<string, boolean>>({})

function monthEnd(date: Date): string {
  const end = new Date(date.getFullYear(), date.getMonth() + 1, 0)
  const month = String(end.getMonth() + 1).padStart(2, '0')
  return `${end.getFullYear()}-${month}-${String(end.getDate()).padStart(2, '0')}`
}

function monthStart(iso: string): string {
  return `${iso.slice(0, 7)}-01`
}

function nextDay(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number)
  const date = new Date(Date.UTC(year ?? 1970, (month ?? 1) - 1, (day ?? 1) + 1))
  return date.toISOString().slice(0, 10)
}

const blockers = computed(() => evidence.value?.blockers ?? [])
const frozenThrough = computed(() => evidence.value?.frozen_through ?? null)

/**
 * První den, od kterého se smí zapsat nová verze. Hranice zmrazení je vždycky
 * poslední den měsíce, takže je to první den měsíce následujícího — a přesně
 * to je popisek tlačítka „Změnit od …".
 */
const unlockDay = computed(
  () => (frozenThrough.value === null ? null : nextDay(frozenThrough.value)),
)

/**
 * Běhy, které hranici drží a dají se otevřít k opravě. Otevřít jeden nestačí,
 * když měsíc pokrývá víc běhů (běh na účtárnu) — hranice by zůstala, kde byla.
 */
const correctableRuns = computed<PayrollStatutoryEvidenceFrozenRun[]>(() =>
  (evidence.value?.frozen_runs ?? []).filter(run => run.command !== null
    && (run.command === 'reopen'
      ? auth.canWrite('payroll.reopen')
      : auth.canWrite('payroll.review'))),
)

/**
 * Reference na základy u jiného zaměstnavatele pro volbu plátce doplatku minima.
 * Bere se z rozepsané sekce, ne z uloženého stavu: základ i volba plátce jdou
 * zapsat jedním uložením a server je validuje spolu.
 */
const otherEmployerBases = computed(() => drafts.value.health_other_employer_bases ?? [])

/**
 * Zvolit lze jen zaměstnavatele, který má za TENTÝŽ měsíc doložený vyměřovací
 * základ — jinak zápis odmítne validátor snímku.
 */
function employerReferencesFor(row: PayrollStatutoryEvidenceRow): string[] {
  const period = statutoryText(row, 'period_start')
  return otherEmployerBases.value
    .filter(base => period === '' || statutoryText(base, 'period_start') === period)
    .map(base => statutoryText(base, 'employer_reference'))
    .filter(reference => reference !== '')
}

/**
 * Výchozí pojišťovna pro předvyplnění. Nejdřív ta, u které je osoba vedená —
 * ta je v už načtené evidenci, takže nestojí ani jeden request navíc. Teprve
 * když osoba historii nemá, sáhne se po výchozí pojišťovně zaměstnavatele
 * (načtená nejvýš jednou za běh aplikace, ne na každou kartu).
 */
const personInsurerCode = computed(() => {
  let latest: { from: string; code: string } | null = null
  for (const row of drafts.value.health_coverages ?? []) {
    const code = statutoryText(row, 'insurer_code')
    if (code === '') continue
    const from = statutoryText(row, 'effective_from')
    if (latest === null || from >= latest.from) latest = { from, code }
  }
  return latest?.code ?? null
})

const defaultInsurerCode = computed(() => personInsurerCode.value ?? employerInsurerCode.value)

/** Den dosažení důchodového věku, je-li z data narození jednoznačný (server). */
const pensionAgeSuggestion = computed(() => evidence.value?.derived?.pension_age_suggestion ?? null)

function contextFor(row: PayrollStatutoryEvidenceRow): StatutoryFormContext {
  return {
    effectiveOn: effectiveOn.value,
    defaultInsurerCode: defaultInsurerCode.value,
    employerReferences: employerReferencesFor(row),
    pensionAgeSuggestion: pensionAgeSuggestion.value,
  }
}

/** Sekce, kde „Platí od/do" říká málo (u důchodu „Pobírán od", u věku den dosažení). */
const INTERVAL_LABELS: Partial<Record<PayrollStatutoryEvidenceSection, ReadonlyArray<'effective_from' | 'effective_to'>>> = {
  social_pension_age: ['effective_from'],
  social_pensions: ['effective_from', 'effective_to'],
}

function intervalLabel(section: StatutorySectionSpec, edge: 'effective_from' | 'effective_to'): string {
  return INTERVAL_LABELS[section.key]?.includes(edge) === true
    ? t(`payroll.people.statutory_evidence.${edge}_label.${section.key}`)
    : t(`payroll.people.statutory_evidence.${edge}`)
}

function rowsOf(section: StatutorySectionSpec): PayrollStatutoryEvidenceRow[] {
  return drafts.value[section.key] ?? []
}

function isFrozen(section: StatutorySectionSpec, row: PayrollStatutoryEvidenceRow): boolean {
  if (section.unfrozen === true) return false
  const start = section.kind === 'month' ? row.period_start : row.effective_from
  return typeof start === 'string'
    && frozenThrough.value !== null
    && start <= frozenThrough.value
}

/**
 * Řádky platné k vybranému měsíci — ty, o kterých mluví přehled nahoře.
 * U slev na dani jich může být víc (jedna od každého druhu).
 */
function effectiveRows(section: StatutorySectionSpec): PayrollStatutoryEvidenceRow[] {
  return currentRows(section, rowsOf(section), effectiveOn.value)
}

/**
 * Řádek, na kterém má smysl nabídnout „Změnit od …": platí k hranici zmrazení
 * a nic novějšího za ním ještě nestojí. Nabízet to u každého zamčeného řádku
 * by znamenalo šest stejných tlačítek, z nichž pět dělá totéž. U souběžných
 * řad (slevy po druzích) se to posuzuje uvnitř druhu — jinak by novou verzi
 * nabídla jen jedna sleva ze dvou.
 */
function offersNewVersion(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): boolean {
  const boundary = frozenThrough.value
  if (boundary === null || section.kind !== 'interval' || section.unfrozen === true) return false
  const peers = scopePeers(section, row)
  if (currentRow(section, peers, boundary) !== row) return false
  return !peers.some(other => statutoryText(other, 'effective_from') > boundary)
}

/** Řádky téže souběžné řady; u nescopované sekce celá sekce. */
function scopePeers(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): PayrollStatutoryEvidenceRow[] {
  const scopeKey = section.scopeKey
  if (scopeKey === undefined) return rowsOf(section)
  const scope = statutoryText(row, scopeKey)
  return rowsOf(section).filter(other => statutoryText(other, scopeKey) === scope)
}

/**
 * Sleva na poplatníka vzniká podpisem prohlášení (server: TaxpayerCreditEntitlement),
 * ne řádkem nároku. Přehled slev ji proto ukáže i bez řádku — jinak by svítilo
 * „Žádná sleva" u zaměstnance, kterému ji mzda uplatní.
 */
function derivedTaxpayerCredit(section: StatutorySectionSpec): boolean {
  return section.key === 'tax_credit_claims'
    && evidence.value?.derived?.taxpayer_credit === true
    && !effectiveRows(section).some(row => statutoryText(row, 'credit_kind') === 'taxpayer')
}

/**
 * Upozornění k uložené větě prohlášení, jejíž začátek neodpovídá dni podpisu
 * podle § 38k ZDP. Posuzuje server (TaxDeclarationEffectiveMonth); uložení
 * neblokuje, účetní může mít doklad, který server nezná.
 */
function declarationCheckText(section: StatutorySectionSpec, row: PayrollStatutoryEvidenceRow): string {
  if (section.key !== 'tax_declarations' || typeof row.id !== 'number') return ''
  const check = evidence.value?.derived?.tax_declaration_checks?.find(item => item.id === row.id)
  if (!check) return ''
  if (check.legal_from === null) return t('payroll.people.statutory_evidence.declaration_check.next_year')
  return t(`payroll.people.statutory_evidence.declaration_check.${check.warning}`, {
    month: formatPeriod(check.legal_from.slice(0, 7)),
  })
}

function summaryLabel(section: StatutorySectionSpec): string {
  const rows = effectiveRows(section)
  if (derivedTaxpayerCredit(section)) {
    return [
      t('payroll.people.statutory_evidence.derived_taxpayer_credit'),
      ...rows.map(row => t(
        `payroll.people.statutory_evidence.option.${section.summaryKey}.`
        + statutoryText(row, section.summaryKey),
      )),
    ].join(', ')
  }
  if (rows.length === 0) {
    // Záznam je, jen k vybranému měsíci už neplatí (typicky zadaný jen na
    // měsíc brigády u trvající dohody). „Nezadáno" by tvrdilo, že se uložení
    // nepovedlo.
    const endedOn = section.kind === 'month' || section.optional === true
      ? null
      : rowsOf(section)
        .map(row => statutoryText(row, 'effective_to'))
        .filter(to => /^\d{4}-\d{2}-\d{2}$/.test(to) && to < effectiveOn.value)
        .sort()
        .at(-1) ?? null
    if (endedOn !== null) {
      return t('payroll.people.statutory_evidence.current_ended', {
        day: formatDate(endedOn),
        month: formatDate(effectiveOn.value),
      })
    }
    return t(section.optional === true
      ? `payroll.people.statutory_evidence.${section.emptyKey ?? 'current_none_claimed'}`
      : 'payroll.people.statutory_evidence.current_missing')
  }
  if (section.summaryRaw === true) {
    return rows
      .map((row) => {
        const reference = statutoryText(row, section.summaryKey)
        const base = statutoryText(row, 'assessment_base_minor_units')
        return /^\d+$/.test(base)
          ? `${reference} (${formatMoneyMinor(Number(base))})`
          : reference
      })
      .join(', ')
  }
  return rows
    .map(row => t(
      `payroll.people.statutory_evidence.option.${section.summaryKey}.`
      + statutoryText(row, section.summaryKey),
    ))
    .join(', ')
}

/**
 * Rozepsaný text částky. Pole ukazuje koruny, řádek nese haléře. Bez
 * vlastního textu by se neplatný vstup („12,3,4") při překreslení ztratil
 * a uživatel by neviděl, co opravit.
 */
const moneyDrafts = reactive(new WeakMap<PayrollStatutoryEvidenceRow, Record<string, string>>())

function moneyText(row: PayrollStatutoryEvidenceRow, key: string): string {
  return moneyDrafts.get(row)?.[key] ?? minorUnitsToCrowns(statutoryText(row, key))
}

function onMoneyInput(row: PayrollStatutoryEvidenceRow, key: string, event: Event) {
  const typed = (event.target as HTMLInputElement).value
  moneyDrafts.set(row, { ...(moneyDrafts.get(row) ?? {}), [key]: typed })
  // Neplatný vstup se do řádku propíše tak, jak je; formulář ho nahlásí
  // (`amount_invalid`) a uložení zablokuje; tichá nula by lhala.
  row[key] = typed.trim() === '' ? null : (crownsToMinorUnits(typed) ?? typed)
}

/** Doplněk přehledu — u zdravotního pojištění pojišťovna, jinak nic. */
function summaryDetail(section: StatutorySectionSpec): string {
  const key = section.summaryDetailKey
  const row = effectiveRows(section)[0]
  if (key === undefined || row === undefined) return ''
  const code = statutoryText(row, key)
  if (code === '') return ''
  return insurerOptions.find(option => option.value === code)?.label ?? code
}

function summaryFrom(section: StatutorySectionSpec): string {
  const key = section.kind === 'month' ? 'period_start' : 'effective_from'
  const from = effectiveRows(section)
    .map(row => statutoryText(row, key))
    .filter(value => value !== '')
    .sort()[0]
  return from === undefined ? '' : formatDate(from)
}

/**
 * Neověřeno i nevyplněno shodí zákonný výpočet — v přehledu vypadají stejně.
 * U nepovinné sekce (slevy) je prázdno naopak legitimní stav: kdo žádnou
 * neuplatňuje, nemá co doplnit a varování by ho jen posílalo hledat chybu.
 */
function summaryIsBlocking(section: StatutorySectionSpec): boolean {
  const rows = effectiveRows(section)
  if (rows.length === 0) return section.optional !== true
  const key = section.verificationKey ?? section.summaryKey
  return rows.some(row => statutoryText(row, key) === 'unverified')
}

/**
 * Sbalená historie se otevře sama tam, kde je co řešit: sekce bez záznamu,
 * sekce s chybou formuláře, nebo právě rozepsaná změna.
 */
function historyOpen(section: StatutorySectionSpec): boolean {
  const toggled = historyToggled[section.key]
  if (toggled !== undefined) return toggled
  return summaryIsBlocking(section)
    || sectionIssues(section, rowsOf(section)).length > 0
    || rowsOf(section).some(row => issuesFor(section, row).length > 0)
}

function onHistoryToggle(section: StatutorySectionSpec, event: Event) {
  historyToggled[section.key] = (event.target as HTMLDetailsElement).open
}

/** Vrátí zobrazení k počítanému výchozímu stavu (viz `historyOpen`). */
function resetSectionToggles() {
  for (const key of Object.keys(historyToggled)) delete historyToggled[key]
}

const sectionElements: Record<string, HTMLElement | null> = {}

function setSectionRef(key: string, element: unknown) {
  sectionElements[key] = element instanceof HTMLElement ? element : null
}

/** Kurzor rovnou do prvního pole, které jde v sekci vyplnit. */
async function focusSection(key: string) {
  await nextTick()
  const field = sectionElements[key]?.querySelector<HTMLElement>(
    'input:not([disabled]), select:not([disabled])',
  )
  field?.focus()
}

/**
 * „Upravit evidenci" musí něco UDĚLAT.
 *
 * Pole leží uvnitř sbalených boxů „Historie a záznamy"; samotné přepnutí
 * `editing` proto u vyplněné sekce nezměnilo nic, co by uživatel viděl —
 * jen se dole vyměnila tlačítka. Vstup do editace tedy zároveň rozbalí
 * všechny sekce, aby bylo vidět, do čeho se píše.
 */
function startEditing() {
  for (const section of SECTIONS) {
    editingSections.add(section.key)
    historyToggled[section.key] = true
  }
}

function isEditing(section: StatutorySectionSpec): boolean {
  return editingSections.has(section.key)
}

/**
 * Cesta „chci změnit tenhle údaj" → vstupní pole na jedno kliknutí: tlačítko
 * u sekce zapne editaci právě té sekce, rozbalí ji a postaví kurzor do
 * prvního pole. Ukládá se dál jedním společným uložením, server bere celý
 * cílový stav jedním zápisem.
 */
function editSection(section: StatutorySectionSpec) {
  historyToggled[section.key] = true
  editingSections.add(section.key)
  void focusSection(section.key)
}

/**
 * Pole, na které má po otevření nového záznamu skočit kurzor, když je
 * předvyplněné. U zdravotního pojištění je to pojišťovna, kvůli ní sem
 * uživatel z varování přišel, jurisdikci má předvyplněnou správně.
 */
const PREFERRED_FOCUS: Partial<Record<PayrollStatutoryEvidenceSection, string>> = {
  health_coverages: 'insurer_code',
}

/** Kurzor do řádku: preferované pole, jinak první prázdné, jinak první vůbec. */
async function focusRow(section: StatutorySectionSpec, index: number) {
  await nextTick()
  const container = sectionElements[section.key]?.querySelector<HTMLElement>(
    `[data-test="row-${section.key}-${index}"] [data-row-primary]`,
  )
  if (!container) return
  const controls = Array.from(container.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
    'input:not([disabled]):not([type="hidden"]), select:not([disabled])',
  ))
  const preferredKey = PREFERRED_FOCUS[section.key]
  const preferred = preferredKey === undefined
    ? undefined
    : controls.find(control => control.dataset.test === `${section.key}-${index}-${preferredKey}`)
  const target = preferred
    ?? controls.find(control => control.value === '')
    ?? controls[0]
  target?.focus({ preventScroll: true })
}

/** Výchozí „Platí od" nového záznamu. */
function newRowStart(section: StatutorySectionSpec): string {
  // Do sekce s historií navazuje nový záznam od vybraného měsíce; měsíční
  // evidence se vede k vybranému měsíci vždycky.
  if (section.kind === 'month' || rowsOf(section).length > 0) return monthStart(effectiveOn.value)
  // Prázdná sekce platí od začátku vztahu (jako hromadné doplnění), ne
  // od náhodně zvoleného měsíce, jinak by vznikla díra od nástupu.
  const candidate = defaultsEffectiveOn.value
  const unlock = unlockDay.value
  return unlock !== null && candidate < unlock ? unlock : candidate
}

/**
 * „Přidat záznam" otevře editaci jen té sekce, založí předvyplněný záznam
 * a postaví kurzor do prvního pole, které je potřeba doplnit.
 */
function startAdd(section: StatutorySectionSpec) {
  editingSections.add(section.key)
  historyToggled[section.key] = true
  addRow(section)
  void focusRow(section, rowsOf(section).length - 1)
}

/** Zahodí rozepsané změny jedné sekce a zavře její editaci. */
function discardSection(section: StatutorySectionSpec) {
  drafts.value[section.key] = JSON.parse(baselines.value[section.key] ?? '[]')
  editingSections.delete(section.key)
  delete historyToggled[section.key]
  saveError.value = ''
}

function sectionDirty(section: StatutorySectionSpec): boolean {
  return JSON.stringify(rowsOf(section)) !== (baselines.value[section.key] ?? '[]')
}

/** Záznamy sekce jsou vyplněné tak, že je formulář pustí na server. */
function sectionReady(section: StatutorySectionSpec): boolean {
  const rows = rowsOf(section)
  return sectionIssues(section, rows).length === 0
    && rows.every(row => issuesFor(section, row).length === 0)
}

const dirtySections = computed(() => SECTIONS.filter(section => sectionDirty(section)))

const hintExpanded = reactive<Record<string, boolean>>({})

/**
 * Bloky pod nadpisy skupin. Pořadí uvnitř skupin drží `STATUTORY_SECTIONS`;
 * sekce, kterou by sem nikdo nezařadil, spadne do „Ostatní", ne z obrazovky.
 */
const GROUPS: ReadonlyArray<{ key: string; sections: readonly PayrollStatutoryEvidenceSection[] }> = [
  { key: 'tax', sections: ['tax_declarations', 'tax_residences', 'tax_credit_claims'] },
  {
    key: 'social',
    sections: ['social_jurisdictions', 'social_discount_claims', 'social_pension_age', 'social_pensions'],
  },
  {
    key: 'health',
    sections: [
      'health_coverages',
      'health_month_evidence',
      'health_minimum_reductions',
      'health_other_employer_bases',
    ],
  },
]

const sectionGroups = computed(() => {
  const grouped = new Set<string>(GROUPS.flatMap(group => group.sections))
  const groups = GROUPS.map(group => ({
    key: group.key,
    sections: SECTIONS.filter(section => group.sections.includes(section.key)),
  }))
  const other = SECTIONS.filter(section => !grouped.has(section.key))
  if (other.length > 0) groups.push({ key: 'other', sections: other })
  return groups.filter(group => group.sections.length > 0)
})

/**
 * Doskok z varování („chybí zdravotní pojišťovna"). U prázdné sekce rovnou
 * otevře nový záznam, u vyplněné její editaci. V obou případech s kurzorem
 * v poli, kvůli kterému uživatel přišel. Vysvícení samotné sekce nestačilo:
 * účetní klikla na „Upravit" a „jakoby se nic nestalo".
 */
const pendingReveal = ref<string | null>(null)

async function revealSection(key: string): Promise<boolean> {
  const section = SECTIONS.find(item => item.key === key)
  if (section === undefined) return false
  if (root.value instanceof HTMLDetailsElement) root.value.open = true
  if (loading.value) {
    pendingReveal.value = key
    return true
  }
  historyToggled[section.key] = true
  let index: number | null = null
  if (props.canWrite) {
    const rows = rowsOf(section)
    if (rows.length === 0) {
      startAdd(section)
      index = 0
    } else {
      editingSections.add(section.key)
      const current = effectiveRows(section)[0]
      index = current === undefined ? rows.length - 1 : rows.indexOf(current)
    }
  }
  await nextTick()
  const element = sectionElements[section.key]
  if (element) revealField(fieldSelector(`statutory.${section.key}`), element.parentElement ?? document)
  if (index !== null) {
    const target = index
    await focusRow(section, target)
    // revealField dává kurzor prvnímu poli sekce se zpožděním; tady má
    // skončit v poli, které uživatel přišel doplnit.
    window.setTimeout(() => { void focusRow(section, target) }, 400)
  }
  return true
}

defineExpose({ revealSection })

/** Doklad je nepovinný — rozbalí se jen tam, kde už něco nese. */
function evidenceDetailFilled(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): boolean {
  return statutoryText(row, 'evidence_note') !== ''
    || evidenceDetailFields(section, row)
      .some(field => statutoryText(row, field.key) !== '')
}

function fieldValue(row: PayrollStatutoryEvidenceRow, key: string): string {
  return statutoryText(row, key)
}

function setField(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  key: string,
  next: string,
) {
  row[key] = next === '' ? null : next
  applyFieldChange(section, row, key, contextFor(row))
}

function onSelect(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  key: string,
  event: Event,
) {
  setField(section, row, key, (event.target as HTMLSelectElement).value)
}

function onInput(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  key: string,
  event: Event,
) {
  setField(section, row, key, (event.target as HTMLInputElement).value)
}

/**
 * Datumové pole nemá `v-model` (hodnota se čte přes fieldValue), takže si sahá
 * pro potvrzenou hodnotu do `change`. DateInput ji na rozdíl od nativního pole
 * posílá rovnou jako ISO řetězec, ne v události — rozepsaný nesmysl se tím
 * do řádku vůbec nedostane.
 */
function onDateChange(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  key: string,
  value: string,
) {
  setField(section, row, key, value)
}

const customReferenceEditors = reactive(
  new WeakMap<PayrollStatutoryEvidenceRow, Set<string>>(),
)

function usesCustomReference(row: PayrollStatutoryEvidenceRow, key: string): boolean {
  return customReferenceEditors.get(row)?.has(key) ?? false
}

/** Prázdno, typický důvod, nebo „jiné", když v řádku je vlastní označení. */
function reasonSelection(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  field: StatutoryFieldSpec,
): string {
  if (usesCustomReference(row, field.key)) return CUSTOM_REASON
  const current = statutoryText(row, field.key)
  if (current === '') return ''
  return reasonOptions(section.key, field.key, row).includes(current)
    ? current
    : CUSTOM_REASON
}

function onReason(
  row: PayrollStatutoryEvidenceRow,
  field: StatutoryFieldSpec,
  event: Event,
) {
  const selected = (event.target as HTMLSelectElement).value
  const customFields = customReferenceEditors.get(row) ?? new Set<string>()
  if (selected === CUSTOM_REASON) customFields.add(field.key)
  else customFields.delete(field.key)
  customReferenceEditors.set(row, customFields)
  row[field.key] = selected === CUSTOM_REASON || selected === '' ? null : selected
}

function hydrate(value: PayrollStatutoryEvidence) {
  evidence.value = value
  const next: Record<string, PayrollStatutoryEvidenceRow[]> = {}
  for (const section of SECTIONS) {
    next[section.key] = (value.sections[section.key] ?? []).map(row => ({ ...row }))
  }
  drafts.value = next
  const nextBaselines: Record<string, string> = {}
  for (const section of SECTIONS) nextBaselines[section.key] = JSON.stringify(next[section.key])
  baselines.value = nextBaselines
}

const root = ref<HTMLElement | null>(null)
/** Uložený stav po sekcích. Podle něj se pozná, KTERÝ blok má neuložené změny. */
const baselines = ref<Record<string, string>>({})
const dirty = computed(() => dirtySections.value.length > 0)
const cardSave = usePersonCardSaveSection({
  // Lišta „Neuložené změny" jmenuje konkrétní blok, ne celou evidenci;
  // „Zákonná evidence osoby" neříkala, kde změna leží.
  label: () => dirtySections.value.length > 0
    ? dirtySections.value
      .map(section => t(`payroll.people.statutory_evidence.section.${section.key}`))
      .join(', ')
    : t('payroll.people.statutory_evidence.title'),
  dirty: () => dirty.value,
  save,
  discard: cancel,
  focus: () => root.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }),
})
const managed = cardSave.managed

/**
 * Uložení z lišty pod sekcí. Na kartě je to TOTÉŽ společné uložení jako
 * lišta dole (uloží i rozepsané sekce jiných panelů a zastaví se na první
 * chybě), mimo kartu totéž jako tlačítko dole. Druhá cesta ukládání nevzniká.
 */
async function requestSave() {
  if (cardSave.saveAll !== undefined) await cardSave.saveAll()
  else await save()
}

/**
 * Běžný zaměstnanec — rezident ČR v českém pojištění — nemá v zákonné
 * evidenci co rozhodovat. Místo pěti ručních záznamů stačí jedno potvrzení
 * v dialogu hromadného doplnění, zúženém na tuhle osobu: náhled ukáže, co
 * přesně doplní (od data nástupu, ne od prvního dne měsíce) a u koho to
 * nejde (cizí prvek, chybějící pojišťovna).
 */
const defaultsOpen = ref(false)
const defaultsEffectiveOn = computed(() => {
  const start = props.employmentStartOn ?? null
  return start !== null && start < effectiveOn.value
    ? monthStart(start)
    : monthStart(effectiveOn.value)
})
const defaultsAvailable = computed(() => props.canWrite
  && !editing.value
  && blockers.value.length > 0)

function onDefaultsApplied() {
  void load()
  emit('saved')
}

function addRow(section: StatutorySectionSpec) {
  const rows = rowsOf(section)
  const row = defaultRow(section, newRowStart(section), {
    effectiveOn: effectiveOn.value,
    defaultInsurerCode: defaultInsurerCode.value,
    employerReferences: [],
    pensionAgeSuggestion: pensionAgeSuggestion.value,
  })
  drafts.value[section.key] = [...rows, row]
}

/**
 * Nová verze právní skutečnosti od prvního dne po hranici zmrazení.
 *
 * Zamčenou minulost nechává být — jen ji uzavře dnem hranice a od dalšího dne
 * pokračuje kopií jejích hodnot, kterou už uživatel může upravit. Tuhle úvahu
 * musel dosud udělat sám: panel jen napsal, že je historie uzavřená, a nechal
 * pole zašedlá.
 */
function changeFromNextPeriod(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
) {
  const boundary = frozenThrough.value
  const from = unlockDay.value
  if (boundary === null || from === null || section.kind !== 'interval') return
  const rows = rowsOf(section)
  // Zdroj je řádek, u kterého uživatel klikl — u souběžných řad (slevy po
  // druzích) je jen v něm vidět, které z nich se má nová verze týkat.
  const source = currentRow(section, scopePeers(section, row), boundary)
  const next: PayrollStatutoryEvidenceRow = source === null
    ? defaultRow(section, from, {
      effectiveOn: effectiveOn.value,
      defaultInsurerCode: defaultInsurerCode.value,
      employerReferences: [],
    })
    : { ...source }
  delete next.id
  delete next.row_version
  next.effective_from = from
  next.effective_to = null
  if (source !== null) source.effective_to = boundary
  drafts.value[section.key] = [...rows, next]
  editingSections.add(section.key)
  historyToggled[section.key] = true
}

/**
 * Otevře k opravě VŠECHNY běhy, které hranici drží.
 *
 * Otevřít jen jeden by hranici neposunul (drží ji maximum období), takže by
 * tlačítko vypadalo jako by nic neudělalo. Důvod se neptá — jedna účetní
 * v jedné firmě ho píše sama sobě; do historie běhu jde věta, která říká,
 * odkud oprava vzešla.
 */
async function openRunsForCorrection() {
  const runs = correctableRuns.value
  if (runs.length === 0 || correcting.value) return
  correcting.value = true
  try {
    for (const run of runs) {
      await payrollApi.commandRun(
        run.id,
        run.command === 'reopen' ? 'reopen' : 'request_correction',
        {
          row_version: run.row_version,
          reason: t('payroll.people.statutory_evidence.correction_reason'),
        },
        crypto.randomUUID(),
      )
    }
    toast.success(t('payroll.people.statutory_evidence.run_opened'))
    await load()
  } catch (exception) {
    toast.error(apiErrorMessage(
      exception,
      t('payroll.people.statutory_evidence.run_open_failed'),
    ))
  } finally {
    correcting.value = false
  }
}

function removeRow(section: StatutorySectionSpec, index: number) {
  const rows = [...rowsOf(section)]
  rows.splice(index, 1)
  drafts.value[section.key] = rows
}

function issuesFor(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): StatutoryIssue[] {
  return rowIssues(section, row, contextFor(row))
}

/** Vše, co formulář umí zachytit dřív, než to odmítne server. */
const issues = computed<Array<{ section: PayrollStatutoryEvidenceSection; issue: StatutoryIssue }>>(() => {
  const found: Array<{ section: PayrollStatutoryEvidenceSection; issue: StatutoryIssue }> = []
  for (const section of SECTIONS) {
    const rows = rowsOf(section)
    for (const issue of sectionIssues(section, rows)) found.push({ section: section.key, issue })
    for (const row of rows) {
      for (const issue of issuesFor(section, row)) found.push({ section: section.key, issue })
    }
  }
  return found
})

function issueText(issue: StatutoryIssue): string {
  const params: Record<string, string> = { ...(issue.params ?? {}) }
  if (params.label !== undefined) params.label = t(params.label)
  return t(`payroll.people.statutory_evidence.issue.${issue.key}`, params)
}

async function load() {
  loading.value = true
  loadError.value = ''
  saveError.value = ''
  try {
    hydrate(await payrollApi.statutoryEvidence(props.personId, effectiveOn.value))
  } catch (exception) {
    loadError.value = apiErrorMessage(
      exception,
      t('payroll.people.statutory_evidence.load_failed'),
    )
  } finally {
    loading.value = false
  }
  const pending = pendingReveal.value
  pendingReveal.value = null
  if (pending !== null && loadError.value === '') await revealSection(pending)
}

function cancel() {
  editingSections.clear()
  saveError.value = ''
  resetSectionToggles()
  if (evidence.value) hydrate(evidence.value)
}

async function save(): Promise<boolean> {
  if (saving.value) return false
  saveError.value = ''
  // Chyby, které formulář zná, nemá smysl posílat na server — ten by z nich
  // vrátil jednu obecnější a uživatel by hledal, které pole ji způsobilo.
  if (issues.value.length > 0) {
    saveError.value = t('payroll.people.statutory_evidence.issues_block_save')
    return false
  }
  saving.value = true
  try {
    const sections = {} as Record<PayrollStatutoryEvidenceSection, PayrollStatutoryEvidenceRow[]>
    for (const section of SECTIONS) {
      sections[section.key] = rowsOf(section).map(row => ({ ...row }))
    }
    const savedKeys = dirtySections.value.map(section => section.key)
    hydrate(await payrollApi.saveStatutoryEvidence(props.personId, {
      effective_on: effectiveOn.value,
      sections,
    }))
    editingSections.clear()
    resetSectionToggles()
    // Uložený blok zůstane rozbalený: uživatel vidí, co se uložilo, a pod tím
    // „Přidat další záznam", kdyby jich potřeboval víc.
    for (const key of savedKeys) historyToggled[key] = true
    toast.success(t('payroll.people.statutory_evidence.saved'))
    emit('saved')
    return true
  } catch (exception) {
    // Server jmenuje konkrétní důvod (překryv, díra v řadě, chybějící doklad,
    // uzavřené období) — obecná hláška by ho jen zakryla.
    saveError.value = apiErrorMessage(
      exception,
      t('payroll.people.statutory_evidence.save_failed'),
    )
    return false
  } finally {
    saving.value = false
  }
}

watch(() => props.personId, () => { editingSections.clear(); resetSectionToggles(); void load() })
watch(effectiveOn, () => { editingSections.clear(); resetSectionToggles(); void load() })
// Datum nástupu může dorazit až po prvním vykreslení karty; výchozí den se
// posune jen tehdy, když ho uživatel ještě sám nezměnil.
watch(() => props.employmentStartOn, (_start, previous) => {
  const previousDefault = (() => {
    const end = monthEnd(new Date())
    return previous != null && previous > end ? previous : end
  })()
  if (effectiveOn.value === previousDefault) effectiveOn.value = defaultEffectiveOn()
})
onMounted(() => {
  void load()
  void loadDefaultHealthInsurerCode().then((code) => { employerInsurerCode.value = code })
})
</script>

<template>
  <details
    ref="root"
    class="group scroll-mt-24 rounded-lg border border-payroll-500/30 bg-surface"
    data-test="statutory-evidence"
    :open="blockers.length > 0"
  >
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
      <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.statutory_evidence.title') }}
        </span>
        <span class="mt-0.5 block text-xs text-neutral-500">
          {{ t('payroll.people.statutory_evidence.subtitle') }}
        </span>
      </span>
      <span
        v-if="blockers.length > 0"
        class="shrink-0 rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-800"
        data-test="statutory-evidence-badge"
      >{{ t('payroll.people.statutory_evidence.blocked_badge', { count: blockers.length }, blockers.length) }}</span>
    </summary>

    <div class="border-t border-neutral-200 p-3">
      <div v-if="loading" class="h-24 animate-pulse rounded-lg bg-neutral-100" />

      <p
        v-else-if="loadError"
        class="rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
        role="alert"
        data-test="statutory-evidence-load-error"
      >{{ loadError }}</p>

      <template v-else>
        <label class="mb-3 block text-xs text-neutral-600">
          {{ t('payroll.people.statutory_evidence.effective_on') }}
          <DateInput
            v-model="effectiveOn"
            class="mt-1 block rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm"
            data-test="statutory-evidence-effective-on" />
          <span class="mt-1 block text-neutral-500">
            {{ t('payroll.people.statutory_evidence.effective_on_hint') }}
          </span>
        </label>

        <div
          v-if="blockers.length > 0"
          class="mb-3 rounded-md border border-warning-500/30 bg-warning-50 p-2 text-xs text-warning-800"
          data-test="statutory-evidence-blockers"
        >
          <p class="font-medium">{{ t('payroll.people.statutory_evidence.blockers_title') }}</p>
          <ul class="mt-1 list-disc space-y-0.5 pl-4">
            <li v-for="blocker in blockers" :key="blocker">
              {{ t(`payroll.people.statutory_evidence.blocker.${blocker}`) }}
            </li>
          </ul>
          <p class="mt-1">{{ t('payroll.people.statutory_evidence.blockers_consequence') }}</p>
        </div>
        <p
          v-else
          class="mb-3 rounded-md bg-success-50 px-3 py-2 text-xs text-success-800"
          data-test="statutory-evidence-complete"
        >{{ t('payroll.people.statutory_evidence.complete') }}</p>

        <p
          v-if="frozenThrough"
          class="mb-3 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
          data-test="statutory-evidence-frozen"
        >{{ t('payroll.people.statutory_evidence.frozen_hint', { day: frozenThrough }) }}</p>

        <div class="space-y-6">
          <div
            v-for="group in sectionGroups"
            :key="group.key"
            :data-test="`group-${group.key}`"
          >
            <h3 class="mb-2 border-b border-neutral-200 pb-1 text-xs font-semibold uppercase tracking-wide text-neutral-500">
              {{ t(`payroll.people.statutory_evidence.group.${group.key}`) }}
            </h3>
            <div class="space-y-3">
              <section
                v-for="section in group.sections"
                :key="section.key"
                class="scroll-mt-24 overflow-hidden rounded-lg border bg-surface shadow-sm"
                :class="isEditing(section)
                  ? 'border-payroll-500/40 border-l-4 border-l-payroll-500'
                  : 'border-neutral-200'"
                :data-test="`section-${section.key}`"
                :data-editing="isEditing(section) ? 'true' : undefined"
                :ref="element => setSectionRef(section.key, element)"
                :data-a1-field="`statutory.${section.key}`"
              >
                <!--
                  Hlavička bloku: název, stav (co teď platí a od kdy) a akce
                  vpravo. „Co teď platí" je otázka devíti z deseti návštěv, takže
                  stojí nad historií.
                -->
                <div
                  class="flex flex-wrap items-start justify-between gap-2 px-3 py-2.5"
                  :class="isEditing(section) ? 'bg-payroll-50' : ''"
                >
                  <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                      <h4 class="text-sm font-semibold text-neutral-900">
                        {{ t(`payroll.people.statutory_evidence.section.${section.key}`) }}
                      </h4>
                      <span class="inline-flex flex-wrap items-center gap-2" :data-test="`current-${section.key}`">
                        <span
                          class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                          :class="summaryIsBlocking(section)
                            ? 'bg-warning-100 text-warning-800'
                            : 'bg-success-50 text-success-800'"
                        >{{ summaryLabel(section) }}<template v-if="summaryDetail(section)"> · {{ summaryDetail(section) }}</template></span>
                        <span v-if="summaryFrom(section)" class="text-xs text-neutral-500">
                          {{ t('payroll.people.statutory_evidence.current_from', { day: summaryFrom(section) }) }}
                        </span>
                      </span>
                      <span
                        v-if="isEditing(section)"
                        class="inline-block rounded-full bg-payroll-100 px-2 py-0.5 text-xs font-medium text-neutral-800"
                      >{{ t('payroll.people.statutory_evidence.editing_badge') }}</span>
                    </div>
                    <p class="mt-1 flex items-start gap-1 text-xs text-neutral-500">
                      <span
                        :class="hintExpanded[section.key] ? '' : 'line-clamp-1'"
                        :data-test="`hint-${section.key}`"
                      >{{ t(`payroll.people.statutory_evidence.section_hint.${section.key}`) }}</span>
                      <button
                        type="button"
                        class="shrink-0 whitespace-nowrap font-medium text-primary-600 hover:underline"
                        :aria-expanded="hintExpanded[section.key] === true"
                        :data-test="`hint-toggle-${section.key}`"
                        @click="hintExpanded[section.key] = !hintExpanded[section.key]"
                      >{{ hintExpanded[section.key]
                        ? t('payroll.people.statutory_evidence.hint_less')
                        : t('payroll.people.statutory_evidence.hint_more') }}</button>
                    </p>
                    <p
                      v-if="section.key === 'social_pension_age' && (drafts[section.key] ?? []).length === 0"
                      class="mt-1 text-xs text-neutral-600"
                      data-test="pension-age-suggestion"
                    >{{ pensionAgeSuggestion !== null
                      ? t('payroll.people.statutory_evidence.pension_age_suggestion', { day: formatDate(pensionAgeSuggestion) })
                      : t('payroll.people.statutory_evidence.pension_age_no_suggestion') }}</p>
                  </div>
                  <div v-if="canWrite && !isEditing(section)" class="flex shrink-0 flex-wrap items-start gap-2">
                    <!--
                      Prázdná sekce nemá co upravovat. „Upravit" tu jen přeplo
                      režim a uživatel pak přehlédl malé „Přidat záznam" dole.
                      Proto rovnou přidání, u chybějícího údaje jako hlavní akce.
                    -->
                    <button
                      v-if="(drafts[section.key] ?? []).length === 0"
                      type="button"
                      :class="[summaryIsBlocking(section) ? btnFilledSm('primary') : btnOutlineSm('primary'), 'whitespace-nowrap']"
                      :disabled="saving"
                      :aria-label="t('payroll.people.statutory_evidence.add_section_aria', {
                        section: t(`payroll.people.statutory_evidence.section.${section.key}`),
                      })"
                      :data-test="`add-first-${section.key}`"
                      @click="startAdd(section)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                      {{ t('payroll.people.statutory_evidence.add_row') }}
                    </button>
                    <button
                      v-else
                      type="button"
                      :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
                      :disabled="saving"
                      :aria-label="t('payroll.people.statutory_evidence.edit_section_aria', {
                        section: t(`payroll.people.statutory_evidence.section.${section.key}`),
                      })"
                      :data-test="`edit-${section.key}`"
                      @click="editSection(section)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                      {{ t('payroll.people.statutory_evidence.edit_section') }}
                    </button>
                  </div>
                </div>

                <details
                  class="border-t border-neutral-200"
                  :open="historyOpen(section)"
                  :data-test="`history-${section.key}`"
                  @toggle="onHistoryToggle(section, $event)"
                >
                  <summary class="cursor-pointer list-none px-3 py-1.5 text-xs font-medium text-neutral-600">
                    {{ t('payroll.people.statutory_evidence.history', { count: (drafts[section.key] ?? []).length }, (drafts[section.key] ?? []).length) }}
                  </summary>

                  <div class="px-3 pb-3">
                    <p
                      v-if="(drafts[section.key] ?? []).length === 0"
                      class="mt-2 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
                    >{{ t('payroll.people.statutory_evidence.empty') }}</p>

                    <div
                      v-for="(row, index) in drafts[section.key] ?? []"
                      :key="`${section.key}-${row.id ?? `new-${index}`}`"
                      class="mt-2 rounded-md border p-2"
                      :class="isFrozen(section, row)
                        ? 'border-neutral-200 bg-neutral-50'
                        : isEditing(section)
                          ? 'border-payroll-500/40 bg-payroll-50'
                          : 'border-neutral-200'"
                      :data-test="`row-${section.key}-${index}`"
                    >
                      <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3" data-row-primary>
                        <label v-if="section.kind === 'month'" class="block text-xs text-neutral-600">
                          {{ t('payroll.people.statutory_evidence.period_start') }}
                          <DateInput
                            v-model="row.period_start"
                            :disabled="!isEditing(section) || saving || isFrozen(section, row)"
                            :data-test="`${section.key}-${index}-period_start`"
                            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100" />
                        </label>
                        <template v-else>
                          <label class="block text-xs text-neutral-600">
                            {{ intervalLabel(section, 'effective_from') }}
                            <DateInput
                              v-model="row.effective_from"
                              :disabled="!isEditing(section) || saving || isFrozen(section, row)"
                              :data-test="`${section.key}-${index}-effective_from`"
                              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100" />
                          </label>
                          <label v-if="section.single !== true" class="block text-xs text-neutral-600">
                            {{ intervalLabel(section, 'effective_to') }}
                            <DateInput
                              v-model="row.effective_to"
                              :disabled="!isEditing(section) || saving"
                              :data-test="`${section.key}-${index}-effective_to`"
                              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100" />
                          </label>
                        </template>

                        <label
                          v-for="field in primaryFields(section, row)"
                          :key="field.key"
                          class="block text-xs text-neutral-600"
                        >
                          {{ t(`payroll.people.statutory_evidence.field.${field.key}`) }}

                          <select
                            v-if="field.kind === 'enum'"
                            :value="fieldValue(row, field.key)"
                            :disabled="!isEditing(section) || saving"
                            :data-test="`${section.key}-${index}-${field.key}`"
                            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                            @change="onSelect(section, row, field.key, $event)"
                          >
                            <option v-for="option in field.options" :key="option" :value="option">
                              {{ t(`payroll.people.statutory_evidence.option.${field.key}.${option}`) }}
                            </option>
                          </select>

                          <CountrySelect
                            v-else-if="field.kind === 'country'"
                            :model-value="fieldValue(row, field.key)"
                            :disabled="!isEditing(section) || saving"
                            :clearable="false"
                            required
                            accent="payroll"
                            class="mt-1 block"
                            :data-test="`${section.key}-${index}-${field.key}`"
                            @update:model-value="setField(section, row, field.key, $event)"
                          />

                          <select
                            v-else-if="field.kind === 'insurer'"
                            :value="fieldValue(row, field.key)"
                            :disabled="!isEditing(section) || saving"
                            :data-test="`${section.key}-${index}-${field.key}`"
                            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                            @change="onSelect(section, row, field.key, $event)"
                          >
                            <option value="">{{ t('payroll.people.statutory_evidence.insurer_unset') }}</option>
                            <option v-for="insurer in insurerOptions" :key="insurer.value" :value="insurer.value">
                              {{ insurer.label }}
                            </option>
                          </select>

                          <select
                            v-else-if="field.kind === 'employer'"
                            :value="fieldValue(row, field.key)"
                            :disabled="!isEditing(section) || saving"
                            :data-test="`${section.key}-${index}-${field.key}`"
                            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                            @change="onSelect(section, row, field.key, $event)"
                          >
                            <option value="">{{ t('payroll.people.statutory_evidence.employer_none') }}</option>
                            <option
                              v-for="reference in employerReferencesFor(row)"
                              :key="reference"
                              :value="reference"
                            >{{ reference }}</option>
                          </select>

                          <input
                            v-else-if="field.kind === 'reference'"
                            :value="fieldValue(row, field.key)"
                            type="text"
                            :disabled="!isEditing(section) || saving || isFrozen(section, row)"
                            :placeholder="t('payroll.people.statutory_evidence.employer_reference_placeholder')"
                            :data-test="`${section.key}-${index}-${field.key}`"
                            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                            @input="onInput(section, row, field.key, $event)"
                          >

                          <span v-else-if="field.kind === 'money'" class="mt-1 flex items-center gap-1">
                            <input
                              :value="moneyText(row, field.key)"
                              type="text"
                              inputmode="decimal"
                              :disabled="!isEditing(section) || saving"
                              :data-test="`${section.key}-${index}-${field.key}`"
                              class="w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-right text-sm tabular-nums disabled:bg-neutral-100"
                              @input="onMoneyInput(row, field.key, $event)"
                            >
                            <span class="shrink-0 text-neutral-500">{{ t('payroll.people.statutory_evidence.currency_czk') }}</span>
                          </span>

                          <DateInput
                            v-else-if="field.kind === 'date'"
                            :model-value="fieldValue(row, field.key)"
                            :disabled="!isEditing(section) || saving"
                            :data-test="`${section.key}-${index}-${field.key}`"
                            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                            @change="onDateChange(section, row, field.key, $event)"
                          />
                        </label>
                      </div>

                      <!--
                        Doklad a poznámka jsou NEPOVINNÉ a zabíraly většinu plochy
                        řádku. Sbalí se; otevřou se samy tam, kde už něco nesou.
                      -->
                      <details
                        class="mt-2 rounded-md border border-neutral-200 bg-surface"
                        :open="evidenceDetailFilled(section, row)"
                        :data-test="`evidence-details-${section.key}-${index}`"
                      >
                        <summary class="cursor-pointer list-none px-2 py-1 text-xs text-neutral-600">
                          {{ t('payroll.people.statutory_evidence.evidence_details') }}
                        </summary>
                        <div class="grid grid-cols-1 gap-2 border-t border-neutral-200 p-2 sm:grid-cols-2 lg:grid-cols-3">
                          <label
                            v-for="field in evidenceDetailFields(section, row)"
                            :key="field.key"
                            class="block text-xs text-neutral-600"
                          >
                            {{ t(`payroll.people.statutory_evidence.field.${field.key}`) }}

                            <input
                              v-if="field.kind === 'document'"
                              :value="fieldValue(row, field.key)"
                              type="number"
                              min="1"
                              inputmode="numeric"
                              :disabled="!isEditing(section) || saving || isFrozen(section, row)"
                              :data-test="`${section.key}-${index}-${field.key}`"
                              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                              @input="onInput(section, row, field.key, $event)"
                            >

                            <template v-else>
                              <select
                                :value="reasonSelection(section, row, field)"
                                :disabled="!isEditing(section) || saving"
                                :data-test="`${section.key}-${index}-${field.key}-reason`"
                                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                                @change="onReason(row, field, $event)"
                              >
                                <option value="">
                                  {{ t('payroll.people.statutory_evidence.reference_optional') }}
                                </option>
                                <option
                                  v-for="reason in reasonOptions(section.key, field.key, row)"
                                  :key="reason"
                                  :value="reason"
                                >{{ t(`payroll.people.statutory_evidence.reason.${reasonLabelKey(reason)}`) }}</option>
                                <option :value="CUSTOM_REASON">
                                  {{ t('payroll.people.statutory_evidence.reason_custom') }}
                                </option>
                              </select>
                              <template v-if="reasonSelection(section, row, field) === CUSTOM_REASON">
                                <input
                                  v-model="row[field.key]"
                                  type="text"
                                  :disabled="!isEditing(section) || saving"
                                  :placeholder="t('payroll.people.statutory_evidence.reference_placeholder')"
                                  :data-test="`${section.key}-${index}-${field.key}`"
                                  class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                                >
                                <span class="mt-0.5 block text-neutral-400">
                                  {{ t('payroll.people.statutory_evidence.reference_hint') }}
                                </span>
                              </template>
                            </template>
                          </label>

                          <label class="block text-xs text-neutral-600 sm:col-span-2 lg:col-span-3">
                            {{ t('payroll.people.statutory_evidence.evidence_note') }}
                            <input
                              v-model="row.evidence_note"
                              type="text"
                              :disabled="!isEditing(section) || saving"
                              :placeholder="t('payroll.people.statutory_evidence.evidence_note_placeholder')"
                              :data-test="`${section.key}-${index}-evidence_note`"
                              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-2 py-1 text-sm disabled:bg-neutral-100"
                            >
                          </label>
                        </div>
                      </details>

                      <ul
                        v-if="issuesFor(section, row).length > 0"
                        class="mt-2 list-disc space-y-0.5 rounded-md border border-warning-500/30 bg-warning-50 py-1.5 pl-6 pr-2 text-xs text-warning-800"
                        :data-test="`issues-${section.key}-${index}`"
                      >
                        <li v-for="issue in issuesFor(section, row)" :key="issue.key">
                          {{ issueText(issue) }}
                        </li>
                      </ul>
                      <p
                        v-if="declarationCheckText(section, row)"
                        class="mt-2 rounded-md border border-warning-500/30 bg-warning-50 px-2 py-1.5 text-xs text-warning-800"
                        :data-test="`declaration-check-${index}`"
                      >{{ declarationCheckText(section, row) }}</p>

                      <!--
                        Zamčený řádek dostane AKCI, ne jen konstatování. Bez ní si
                        uživatel musel sám odvodit, že smí založit novou verzi, nebo
                        odejít jinam otevřít mzdu k opravě.
                      -->
                      <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <span v-if="isFrozen(section, row)" class="text-xs text-neutral-500">
                          {{ t('payroll.people.statutory_evidence.row_frozen') }}
                        </span>
                        <span v-else />
                        <div class="flex flex-wrap gap-2">
                          <button
                            v-if="canWrite && offersNewVersion(section, row)"
                            type="button"
                            :class="btnOutlineSm('accent')"
                            :disabled="saving"
                            :data-test="`change-from-${section.key}`"
                            @click="changeFromNextPeriod(section, row)"
                          >{{ t('payroll.people.statutory_evidence.change_from', { day: formatDate(unlockDay ?? '') }) }}</button>
                          <button
                            v-if="canWrite && isFrozen(section, row) && correctableRuns.length > 0"
                            type="button"
                            :class="btnOutlineSm('warning')"
                            :disabled="correcting || saving"
                            :data-test="`open-run-${section.key}`"
                            @click="openRunsForCorrection"
                          >{{ t('payroll.people.statutory_evidence.open_run') }}</button>
                          <button
                            v-if="isEditing(section) && !isFrozen(section, row)"
                            type="button"
                            :class="btnOutline('danger')"
                            :disabled="saving"
                            :data-test="`remove-${section.key}-${index}`"
                            @click="removeRow(section, index)"
                          >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                            {{ t('payroll.people.statutory_evidence.remove_row') }}
                          </button>
                        </div>
                      </div>
                    </div>

                    <p
                      v-for="issue in sectionIssues(section, drafts[section.key] ?? [])"
                      :key="issue.key"
                      class="mt-2 rounded-md border border-warning-500/30 bg-warning-50 px-2 py-1.5 text-xs text-warning-800"
                      :data-test="`issues-${section.key}`"
                    >{{ issueText(issue) }}</p>

                    <!--
                      Další záznam až pod existujícími a až po uložení: dokud je
                      v sekci neuložená změna, hlavní krok je Uložit v liště níž,
                      ne další „Přidat záznam", které by ji jen odsunulo.
                    -->
                    <button
                      v-if="canWrite && !sectionDirty(section)
                        && !(section.single === true && (drafts[section.key] ?? []).length > 0)
                        && (isEditing(section) || (drafts[section.key] ?? []).length > 0)"
                      type="button"
                      :class="['mt-2 whitespace-nowrap', btnOutlineSm('primary')]"
                      :disabled="saving"
                      :data-test="`add-${section.key}`"
                      @click="startAdd(section)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                      {{ (drafts[section.key] ?? []).length > 0
                        ? t('payroll.people.statutory_evidence.add_another')
                        : t('payroll.people.statutory_evidence.add_row') }}
                    </button>
                  </div>
                </details>

                <!--
                  Lišta přímo pod rozpracovanou sekcí. Volá TOTÉŽ uložení jako
                  společná lišta dole (`requestSave`), jen ho staví tam, kde se
                  píše. Uložit je plné až ve chvíli, kdy formulář nic nehlásí.
                -->
                <div
                  v-if="isEditing(section)"
                  class="flex flex-wrap items-center justify-between gap-2 border-t px-3 py-2"
                  :class="sectionDirty(section)
                    ? 'border-warning-500/30 bg-warning-50'
                    : 'border-neutral-200 bg-neutral-50'"
                  :data-test="`inline-bar-${section.key}`"
                >
                  <span
                    v-if="sectionDirty(section)"
                    class="flex items-center gap-1.5 text-xs font-medium text-warning-800"
                  >
                    <span class="h-2 w-2 shrink-0 rounded-full bg-warning-500" aria-hidden="true" />
                    {{ sectionReady(section)
                      ? t('payroll.people.statutory_evidence.inline_unsaved')
                      : t('payroll.people.statutory_evidence.inline_unsaved_incomplete') }}
                  </span>
                  <span v-else class="text-xs text-neutral-600">
                    {{ t('payroll.people.statutory_evidence.inline_editing') }}
                  </span>
                  <div class="flex flex-wrap gap-2">
                    <button
                      type="button"
                      :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                      :disabled="saving"
                      :data-test="`inline-discard-${section.key}`"
                      @click="discardSection(section)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
                      {{ sectionDirty(section)
                        ? t('payroll.people.statutory_evidence.inline_discard')
                        : t('payroll.people.statutory_evidence.inline_close') }}
                    </button>
                    <button
                      v-if="sectionDirty(section)"
                      type="button"
                      :class="[sectionReady(section) ? btnFilledSm('primary') : btnOutlineSm('primary'), 'whitespace-nowrap']"
                      :disabled="saving"
                      :data-test="`inline-save-${section.key}`"
                      @click="requestSave"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                      {{ saving ? t('common.saving') : t('payroll.people.statutory_evidence.inline_save') }}
                    </button>
                  </div>
                  <p
                    v-if="saveError && sectionDirty(section)"
                    class="w-full text-xs text-danger-700"
                    :data-test="`inline-error-${section.key}`"
                  >{{ saveError }}</p>
                </div>
              </section>
            </div>
            <!--
              Sdělení pojišťovny zaměstnancem a potvrzení zaměstnavatele
              (§ 12 písm. b) zákona č. 48/1997 Sb.) jsou údaje věty historie
              pojišťovny, ale ukládají se vlastní cestou - nejsou součástí
              editoru výše ani jeho společného Uložit.
            -->
            <PayrollHealthInsurerNoticePanel
              v-if="group.key === 'health'"
              class="mt-3"
              :person-id="personId"
              :can-write="canWrite"
            />
          </div>
        </div>

        <p
          v-if="saveError"
          class="mt-3 rounded-md border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700"
          role="alert"
          data-test="statutory-evidence-error"
        >{{ saveError }}</p>

        <!--
          Jedno společné Uložit pro všechny sekce, přilepené dole: server
          bere celý cílový stav jedním zápisem, takže tlačítko u každé sekce by
          slibovalo dílčí uložení, které neexistuje.
        -->
        <div
          v-if="canWrite"
          class="-mx-3 -mb-3 mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-surface px-3 py-2"
          :class="managed ? '' : 'sticky bottom-[var(--app-footer-height,0px)]'"
        >
          <button
            v-if="defaultsAvailable"
            type="button"
            :class="[btnFilled('success'), 'whitespace-nowrap']"
            data-test="statutory-evidence-defaults"
            @click="defaultsOpen = true"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ t('payroll.people.card_save.statutory_defaults') }}
          </button>
          <button
            v-if="!editing"
            type="button"
            :class="btnOutline('primary')"
            data-test="start-statutory-evidence"
            @click="startEditing"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
            {{ t('payroll.people.statutory_evidence.edit') }}
          </button>
          <template v-else>
            <button
              type="button"
              :class="btnOutline('neutral')"
              :disabled="saving"
              @click="cancel"
            >{{ t('common.cancel') }}</button>
            <button
              v-if="!managed"
              type="button"
              :class="btnFilled('primary')"
              :disabled="saving"
              data-test="statutory-evidence-save"
              @click="save"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ saving ? t('common.saving') : t('common.save') }}
            </button>
          </template>
        </div>
      </template>
    </div>
    <PayrollStatutoryBulkDefaultsDialog
      v-if="defaultsOpen"
      :effective-on="defaultsEffectiveOn"
      :employee-ids="[personId]"
      @close="defaultsOpen = false"
      @applied="onDefaultsApplied"
    />
  </details>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isPohodaUploadReady, pohodaApi, type PohodaKind, type PohodaMessage, type PohodaRun, type PohodaStartParams, type PohodaStockChoice, type PohodaSystem, type PohodaToolFile, type PohodaUpload, type PohodaUploadPending } from '@/api/pohoda'
import { useToast } from '@/composables/useToast'
import { useMigrationWizard } from '@/composables/useMigrationWizard'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import type { PermissionKey } from '@/security/permissions'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import CompanyProfileBox from '@/components/settings/CompanyProfileBox.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'
import MigrationDifferences from '@/components/migration/MigrationDifferences.vue'
import { formatBytes } from '@/components/documents/docFormat'

/**
 * Průvodce převodem ze STORMWARE: export agendy → náhled a výběr roků → zkouška nanečisto
 * → ostrý převod (vybrané roky vzestupně v jednom jobu, každý rok vlastní protokol).
 * Nahraný export zůstává na serveru pod tokenem, takže obnovení stránky průvodce nevrátí
 * na začátek (token drží sessionStorage). Společný průběh průvodců je v useMigrationWizard.
 *
 * Jedna komponenta obsluhuje dva průvodce, protože server i protokol jsou stejné; liší se
 * jen to, co se převádí a jakým nástrojem se to z programu dostane ven:
 *   - `pohoda`: účetní agenda z XML exportu POHODY,
 *   - `pamica`: personalistika a mzdy z datového souboru mzdového programu PAMICA.
 * Texty se hledají nejdřív v jmenném prostoru systému a teprve pak ve sdíleném `pohoda`.
 */
const props = withDefaults(defineProps<{ system?: PohodaSystem }>(), { system: 'pohoda' })

// Vlastní token na systém: rozpracovaný převod mezd nesmí přebít rozpracovaný převod účetnictví.
const TOKEN_KEY = computed(() => `myucto.${props.system}.token`)
// PAMICA vede jen mzdy, POHODA v tomto průvodci jen účetnictví. Mzdy z datového souboru
// POHODY patří taky do průvodce PAMICA, je to tentýž mzdový modul STORMWARE.
const FORCED_KIND: Record<PohodaSystem, PohodaKind> = { pohoda: 'accounting', pamica: 'payroll' }

const { t, te, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()

/**
 * Text průvodce: nejdřív jmenný prostor systému, jinak sdílený `pohoda`.
 *
 * Prostor se skládá do literálu se šablonou schválně, ne přes proměnnou:
 * mapu `namespaces.generated.json` staví statická analýza literálů
 * (`web/scripts/i18n-usage.mjs`) a z `${props.system}.${key}` by prostor
 * `pamica` nepoznala. Průvodce PAMICA by pak ukazoval texty POHODY.
 */
function ownKey(key: string): string {
  return props.system === 'pamica' ? `pamica.${key}` : `pohoda.${key}`
}
function tt(key: string, params?: Record<string, unknown>): string {
  const own = ownKey(key)
  return te(own) ? t(own, params ?? {}) : t(`pohoda.${key}`, params ?? {})
}
/** Seznamy (`tm`) se neptají přes `te`: u pole vrací `te` nepravdu i tam, kde překlad je. */
function list(key: string): string[] {
  const own = tm(ownKey(key)) as unknown[]
  const items = Array.isArray(own) && own.length > 0 ? own : (tm(`pohoda.${key}`) as unknown[])
  return items.map(item => rt(item as Parameters<typeof rt>[0]))
}
/*
 * Export PAMICA název firmy často nenese (jen IČO). Agenda s IČO firmy, ve
 * které se pracuje, dostane název z MyÚčta, ať v tabulce nesvítí „—" u řádku,
 * který je zjevně „naše" firma.
 */
const supplierStore = useSupplierStore()
function agendaCompany(agenda: { ico: string; company: string }): string {
  if (agenda.company) return agenda.company
  const current = supplierStore.currentSupplier
  if (current && agenda.ico && agenda.ico === (current.ic ?? '').trim()) return current.company_name
  return '—'
}
const exportHelpItems = computed(() => list('export_help_items'))
const mdbHelpItems = computed(() => list('mdb_help_items'))
const sqlHelpItems = computed(() => list('sql_help_items'))
const fileHelpItems = computed(() => list('file_help_items'))

const selectedYears = ref<number[]>([])
// Druh převodu plyne ze systému průvodce; volba v UI už není.
const kind = ref<PohodaKind>(FORCED_KIND[props.system])
// Mzdy: potvrzení, že OIČ a ID PPV v PAMICA pocházejí z protokolů ČSSZ. Bez něj je převod nepřevezme.
const confirmIdentifiers = ref(false)
// Mzdy: převzatá docházka a vstupy se rovnou schválí. Zapnuté proto, že měsíce z PAMICA
// už reálně proběhly a byly podané; jako koncepty by mzdový běh nad nimi vůbec nešel spustit.
const approveTakenOver = ref(true)
// Sklad: převést stav z nejnovější agendy a volba po skladech pro karty bez druhu zásoby.
const stockEnabled = ref(true)
const stockChoices = ref<Record<string, PohodaStockChoice>>({})
const toolOpen = ref(false)
const toolFiles = ref<PohodaToolFile[] | null>(null)
const TOOL_GROUPS: Record<PohodaSystem, { key: string; names: string[] }[]> = {
  pohoda: [
    { key: 'xml', names: ['Export-Pohoda.cmd', 'Export-Pohoda.ps1', 'Export-PohodaMdb.cmd', 'Export-PohodaMdb.ps1'] },
    // Převodník účetnictví volá Export-PohodaMdb.ps1 pro majetek a mzdy, bez něj skončí chybou.
    { key: 'mdb', names: ['Export-PohodaMdbAccounting.cmd', 'Export-PohodaMdbAccounting.ps1', 'Pohoda-Common.ps1', 'Export-PohodaMdb.ps1'] },
    // Export z POHODA SQL zapisuje stejně jako převod MDB, proto potřebuje tytéž podpůrné skripty.
    { key: 'sql', names: ['Export-PohodaSQL.cmd', 'Export-PohodaSQL.ps1', 'pohoda-sql.example.json', 'Pohoda-Common.ps1', 'PohodaSql-Common.ps1', 'Export-PohodaMdb.ps1'] },
  ],
  pamica: [
    { key: 'mdb', names: ['Export-Pamica.cmd', 'Export-Pamica.ps1'] },
    { key: 'sql', names: ['Export-PamicaSQL.cmd', 'Export-PamicaSQL.ps1', 'pamica-sql.example.json', 'PohodaSql-Common.ps1', 'Export-Pamica.ps1'] },
  ],
}
const toolGroups = computed(() => TOOL_GROUPS[props.system]
  .map(group => ({ ...group, files: group.names.flatMap(name => (toolFiles.value ?? []).filter(file => file.name === name)) })))
// Hlavní skript skupiny je ten se stejným jménem jako spouštěcí .cmd; ostatní .ps1 jsou podpůrné.
function toolRole(group: { names: string[] }, name: string): string {
  if (name === 'Export-PohodaMdb.cmd') return 'tool_role_optional'
  if (name === 'Export-PohodaMdb.ps1') return 'tool_role_shared'
  if (name.endsWith('.json')) return 'tool_role_config'
  if (name.endsWith('.cmd')) return 'tool_role_launcher'
  return group.names.includes(name.replace(/\.ps1$/, '.cmd')) ? 'tool_role_script' : 'tool_role_shared'
}
const toolLoading = ref(false)
const toolDownloading = ref<string | null>(null)

const {
  currentStep, upload, file, job, jobMode, run, jobRuns, runs, busy, cancelling, confirmed, dryRunPassed,
  differencesAcceptable, differences, acceptDifferences, canImport, clearDifferences,
  uploadPercent, processing, processingProgress, processingSlow, loadError, deletingRun, jobRunning, jobSucceeded, percent,
  canGoTo, goTo, onFile, doUpload, resetUpload, retryUpload, abandonUpload, start: startJob, cancel, showRun, deleteRun, errorMessage,
} = useMigrationWizard<PohodaUpload, PohodaUploadPending, PohodaRun, PohodaStartParams>({
  // Chyba načtení náhledu má na stránce vlastní blok se „Zkusit znovu".
  inlineLoadError: true,
  api: pohodaApi,
  tokenKey: () => TOKEN_KEY.value,
  isReady: isPohodaUploadReady,
  text: tt,
  multiYear: true,
  // Předvybrané všechny roky agend s IČO firmy (a jejich pozdější roky).
  onReady: () => { selectedYears.value = [...years.value] },
  onReset: () => { selectedYears.value = [] },
  // Historie je společná pro oba průvodce, ale ukazuje se jen ta jeho. Běh bez druhu
  // je z doby před podporou mezd, tedy vždycky účetnictví.
  filterRuns: items => items.filter(r => (r.kind ?? 'accounting') === kind.value),
  // Kontroly mezd závisí na začátku vedení mezd, který převod nastavuje nebo posouvá.
  refreshPreview: true,
})

const steps = computed(() => [1, 2, 3, 4].map(number => ({ number, label: tt(`step${number}`) })))
// Průběh zpracování exportu na serveru v procentech; bez počtu kroků jen pulzující pruh.
const processingPercent = computed(() => {
  const progress = processingProgress.value
  return progress && progress.total > 0 ? Math.min(100, Math.round(progress.processed / progress.total * 100)) : null
})
const payrollWizard = computed(() => kind.value === 'payroll')
// Přehled nahraný před podporou mezd příznaky nemá: to byla vždy agenda účetnictví.
function agendaHas(agenda: { has_accounting?: boolean; has_payroll?: boolean }): boolean {
  return payrollWizard.value ? (agenda.has_payroll ?? false) : (agenda.has_accounting ?? true)
}
// V přehledu se ukazují jen agendy, které tenhle průvodce umí převést. Export z POHODY
// nese obojí; mzdy z něj patří do průvodce PAMICA a naopak.
const agendas = computed(() => (upload.value?.agendas ?? []).filter(agendaHas))
const skipped = computed(() => (upload.value?.agendas ?? []).length - agendas.value.length)
// Převést jde jen rok agendy s IČO firmy; ostatní agendy exportu jsou jen informace.
const ownAgendas = computed(() => agendas.value.filter(a => a.ico === upload.value?.supplier_ico))
interface YearOption { year: number; agenda: number; own: boolean }
/**
 * Roky k výběru vzestupně: roky agend s IČO firmy a u účetnictví i pozdější roky, do kterých
 * agenda vede doklady (`agenda` = rok agendy, která je převádí). Pozdější rok bez vlastní
 * agendy (`own: false`) jde převést jen spolu s rokem své agendy.
 */
const yearOptions = computed<YearOption[]>(() => {
  const out = new Map<number, YearOption>()
  for (const a of ownAgendas.value) out.set(a.year, { year: a.year, agenda: a.year, own: true })
  if (!payrollWizard.value) {
    for (const a of ownAgendas.value) {
      for (const later of a.counts.later_years ?? []) {
        if (!out.has(later)) out.set(later, { year: later, agenda: a.year, own: false })
      }
    }
  }
  return [...out.values()].sort((a, b) => a.year - b.year)
})
const years = computed(() => yearOptions.value.map(o => o.year))
function laterOptions(agendaYear: number): YearOption[] {
  return yearOptions.value.filter(o => !o.own && o.agenda === agendaYear)
}
function isSelected(y: number): boolean {
  return selectedYears.value.includes(y)
}
/** Zaškrtnutí pozdějšího roku vybere i rok jeho agendy, odškrtnutí agendy zruší její pozdější roky. */
function toggleYear(y: number, checked: boolean): void {
  const option = yearOptions.value.find(o => o.year === y)
  if (!option) return
  const next = new Set(selectedYears.value)
  if (checked) {
    next.add(y)
    next.add(option.agenda)
  } else {
    next.delete(y)
    if (option.own) laterOptions(y).forEach(later => next.delete(later.year))
  }
  selectedYears.value = [...next].sort((a, b) => a - b)
}
const yearsLabel = computed(() => selectedYears.value.join(', '))
// Roky, které poběží jako vlastní převod; pozdější roky agendy jdou v běhu své agendy.
const runYears = computed(() => yearOptions.value.filter(o => o.own && isSelected(o.year)).map(o => o.year))
const selectedAgendas = computed(() => ownAgendas.value.filter(a => runYears.value.includes(a.year)))
/**
 * Sklad se převádí jen z nejnovější agendy účetnictví firmy: stav platí k datu exportu
 * a starší agenda by ho založila podruhé. Nabízí se, jen když je ta agenda vybraná.
 */
const stockAgenda = computed(() => {
  if (payrollWizard.value) return null
  const newest = ownAgendas.value.reduce<typeof ownAgendas.value[number] | null>((best, a) => (best === null || a.year > best.year ? a : best), null)
  return newest && newest.stock && runYears.value.includes(newest.year) ? { year: newest.year, stock: newest.stock } : null
})
watch(stockAgenda, agenda => {
  stockChoices.value = Object.fromEntries((agenda?.stock.warehouses ?? []).map(w => [w.code, stockChoices.value[w.code] ?? w.suggested]))
}, { immediate: true })
const START_WILL_SET = 'payroll_start_will_set'
const noteFiles = computed(() => selectedAgendas.value.flatMap(a => a.files.filter(f => f.state !== 'ok').map(f => ({ ...f, year: a.year }))))
/**
 * Kontrola před převodem po vybraných rocích vzestupně. Zpráva s rokem v kontextu (období
 * pozdějšího roku agendy) patří k tomu roku a u nevybraného roku se neukazuje ani neblokuje.
 */
const preflightGroups = computed(() => {
  const source = kind.value === 'payroll' ? upload.value?.payroll_preflight : upload.value?.preflight
  const groups = new Map<number, PohodaMessage[]>(selectedYears.value.map(y => [y, []]))
  for (const agendaYear of runYears.value) {
    for (const m of source?.[String(agendaYear)] ?? []) {
      const own = typeof m.context?.year === 'number' ? m.context.year : agendaYear
      const later = Array.isArray(m.context?.years) ? (m.context.years as number[]) : null
      if (m.code === 'later_periods' && later && !later.some(isSelected)) continue
      // Začátek vedení mezd nastavuje úloha jednou za všechny roky ({@link startWillSet}).
      if (m.code === START_WILL_SET) continue
      groups.get(own)?.push(m)
    }
  }
  return [...groups.entries()].map(([year, messages]) => ({ year, messages }))
})
const preflightErrors = computed(() => preflightGroups.value.flatMap(g => g.messages).filter(m => m.level === 'error'))
// Mzdy: začátek vedení mezd leží před měsíci, které PAMICA zpracovala. Výchozí volba je
// posunout ho a převést; převod bez posunu jde jen s potvrzením (backend ho jinak odmítne).
const startBehind = computed(() => {
  if (kind.value !== 'payroll') return null
  const m = preflightGroups.value.flatMap(g => g.messages).find(x => x.code === 'payroll_start_behind_takeover')
  const c = m?.context
  if (!c || typeof c.from !== 'string' || typeof c.to !== 'string' || typeof c.last !== 'string') return null
  return { from: monthLabel(c.from), to: monthLabel(c.to), last: monthLabel(c.last) }
})
/**
 * Firma bez začátku vedení mezd: úloha ho nastaví za poslední zpracovaný měsíc
 * nejpozdějšího vybraného roku. Náhled každého roku zná jen svůj rok, proto platí
 * nejpozdější z nich.
 */
const startWillSet = computed(() => {
  if (kind.value !== 'payroll') return null
  let best: { start: string; last: string } | null = null
  for (const agendaYear of runYears.value) {
    for (const m of upload.value?.payroll_preflight?.[String(agendaYear)] ?? []) {
      const c = m.context
      if (m.code !== START_WILL_SET || typeof c?.start_period !== 'string' || typeof c?.last !== 'string') continue
      if (best === null || c.start_period > best.start) best = { start: c.start_period, last: c.last }
    }
  }
  return best && { period: monthLabel(best.start), last: monthLabel(best.last) }
})
const startDecision = ref<'advance' | 'keep'>('advance')
const keepStartConfirmed = ref(false)
const startDecisionBlocked = computed(() => startBehind.value !== null && startDecision.value === 'keep' && !keepStartConfirmed.value)
function monthLabel(period: string): string {
  return /^\d{4}-\d{2}/.test(period) ? `${period.slice(5, 7)}/${period.slice(0, 4)}` : period
}
// Stejná práva jako na backendu: mzdy zakládají osoby a vstupy už ve zkoušce nanečisto,
// ostrý převod účetnictví zapisuje deník a nastavení firmy.
const missingRights = computed(() => {
  const required: PermissionKey[] = kind.value === 'payroll'
    ? ['payroll.inputs.write', 'payroll.person.write', 'payroll.settings']
    : ['accounting.journal.write', 'settings.company.write']
  return required.filter(key => !auth.canWrite(key))
})
const rightsMessage = computed(() => tt(kind.value === 'payroll' ? 'payroll_rights_missing' : 'rights_missing', { rights: missingRights.value.join(', ') }))
const payrollRightsBlocked = computed(() => kind.value === 'payroll' && missingRights.value.length > 0)
const blocked = computed(() => selectedYears.value.length === 0 || preflightErrors.value.length > 0 || payrollRightsBlocked.value
  || startDecisionBlocked.value)
// Tlačítko je zakázané i během běžící úlohy; důvodem pak není kontrola před převodem.
const blockedReason = computed(() => jobRunning.value && !blocked.value
  ? tt(jobMode.value === 'import' ? 'import_running' : 'dry_run_running')
  : selectedYears.value.length === 0 ? tt('choose_year_first')
    : payrollRightsBlocked.value ? rightsMessage.value
      : startDecisionBlocked.value && preflightErrors.value.length === 0 ? tt('payroll_start.keep_confirm_first') : tt('preflight_blocked'))
const importDone = computed(() => jobMode.value === 'import' && !jobRunning.value && jobSucceeded.value
  && jobRuns.value.length > 0 && jobRuns.value.every(r => r.mode === 'import'))
// Roky jobu, které se po chybě nebo zrušení předchozího roku nespustily.
const yearsNotRun = computed(() => {
  const planned = jobRuns.value.map(r => r.protocol?.job_years?.years).find(Boolean) ?? []
  const done = new Set(jobRuns.value.map(r => r.agenda_year))
  return planned.filter(y => !done.has(y))
})
function statusClass(status: string): string {
  return status === 'completed' ? 'bg-success-50 text-success-600' : status === 'failed' ? 'bg-danger-50 text-danger-600'
    : status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'
}

watch(selectedYears, () => {
  dryRunPassed.value = false
  clearDifferences()
  confirmed.value = false
})
watch(kind, () => {
  dryRunPassed.value = false
  clearDifferences()
  confirmed.value = false
})
// Zkouška nanečisto platí jen pro zvolené rozhodnutí o začátku vedení mezd a volbu skladu.
watch([startDecision, keepStartConfirmed, stockEnabled, stockChoices], () => {
  dryRunPassed.value = false
  clearDifferences()
  confirmed.value = false
}, { deep: true })

function fileState(state: string): string {
  const key = `file_state.${state}`
  return te(`pohoda.${key}`) ? tt(key) : state
}

async function toggleTool(): Promise<void> {
  toolOpen.value = !toolOpen.value
  if (!toolOpen.value || toolFiles.value !== null) return
  toolLoading.value = true
  try {
    toolFiles.value = (await pohodaApi.toolFiles(props.system)).files
  } catch (error: any) {
    toolOpen.value = false
    toast.error(errorMessage(error, tt('tool_failed')))
  } finally {
    toolLoading.value = false
  }
}

async function downloadTool(name: string | null): Promise<void> {
  toolDownloading.value = name ?? '*'
  try {
    if (name === null) await pohodaApi.downloadTool(props.system)
    else await pohodaApi.downloadToolFile(name, props.system)
  } catch (error: any) {
    toast.error(errorMessage(error, tt('tool_failed')))
  } finally {
    toolDownloading.value = null
  }
}

async function start(mode: 'dry_run' | 'import'): Promise<void> {
  if (!upload.value || selectedYears.value.length === 0) return
  await startJob(mode, {
    mode,
    years: [...selectedYears.value],
    kind: kind.value,
    ...(kind.value === 'payroll' ? { confirm_identifiers: confirmIdentifiers.value, approve_taken_over: approveTakenOver.value } : {}),
    ...(startBehind.value !== null ? { start_decision: startDecision.value } : {}),
    ...(stockAgenda.value && stockEnabled.value ? { stock: { warehouses: { ...stockChoices.value } } } : {}),
  })
}

// Smazat jde jen doběhlou zkoušku nanečisto, protokol ostrého převodu zůstává.
const canWrite = computed(() => missingRights.value.length === 0)

const actions = computed<ActionItem[]>(() => {
  if (currentStep.value === 1) return [
    { key: 'upload', label: busy.value ? (uploadPercent.value !== null ? tt('uploading_percent', { percent: uploadPercent.value }) : tt('uploading')) : tt('upload'), icon: 'upload', tier: 'primary', variant: 'primary', disabled: !file.value, disabledReason: tt('choose_file_first'), loading: busy.value, run: doUpload },
  ]
  if (currentStep.value === 2) return [
    { key: 'continue', label: tt('continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: blocked.value, disabledReason: blockedReason.value, run: () => { currentStep.value = 3 } },
    { key: 'new', label: tt('new_upload'), icon: 'x', tier: 'secondary', variant: 'neutral', run: resetUpload },
  ]
  if (currentStep.value === 3) return (dryRunPassed.value || differencesAcceptable.value) && !jobRunning.value
    ? [
        { key: 'next', label: tt('continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: !canImport.value, disabledReason: tt('differences.accept_first'), run: () => { currentStep.value = 4 } },
        { key: 'rerun', label: tt('dry_run_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', run: () => { void start('dry_run') } },
      ]
    : [
        { key: 'dry', label: tt('dry_run_start'), icon: 'play', tier: 'primary', variant: 'primary', disabled: blocked.value || jobRunning.value, disabledReason: blockedReason.value, loading: busy.value, run: () => { void start('dry_run') } },
      ]
  if (importDone.value) return kind.value === 'payroll'
    ? [{ key: 'payroll', label: tt('open_payroll'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'payroll-imports' } }]
    : [
        { key: 'journal', label: tt('open_journal'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'accounting-journal' } },
        { key: 'trial', label: tt('open_trial_balance'), icon: 'chart', tier: 'secondary', variant: 'neutral', to: { name: 'accounting-trial-balance' } },
      ]
  const importReason = missingRights.value.length
    ? rightsMessage.value
    : blocked.value ? blockedReason.value : tt('import_confirm_first')
  return [
    { key: 'import', label: tt('import_start'), icon: 'play', tier: 'primary', variant: 'warning', disabled: !confirmed.value || jobRunning.value || blocked.value || missingRights.value.length > 0, disabledReason: importReason, loading: busy.value, run: () => { void start('import') } },
  ]
})
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div>
      <h1 class="text-2xl font-semibold">{{ tt('title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ tt('subtitle') }}</p>
    </div>

    <div class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="pohoda-support-notice">
      <i18n-t :keypath="te(ownKey('support_notice')) ? ownKey('support_notice') : 'pohoda.support_notice'" tag="p">
        <template #recommend><strong><i18n-t keypath="pohoda.support_notice_recommend" tag="span"><template #contact><a href="https://myucto.cz/support#placena" target="_blank" rel="noopener" class="underline hover:no-underline">{{ tt('support_notice_contact') }}</a></template></i18n-t></strong></template>
      </i18n-t>
      <RouterLink to="/admin/support" class="mt-1 inline-block font-medium underline hover:no-underline">{{ tt('support_notice_link') }}</RouterLink>
    </div>

    <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4">
      <li v-for="step in steps" :key="step.number">
        <button type="button" :disabled="!canGoTo(step.number)" class="flex w-full items-center rounded-lg border px-3 py-3 text-left text-sm transition-colors"
          :class="[step.number === currentStep ? 'border-primary-500 bg-primary-50 text-primary-700' : step.number < currentStep ? 'border-success-500/40 bg-success-50 text-success-600' : 'border-neutral-200 text-neutral-400', canGoTo(step.number) ? 'cursor-pointer hover:border-primary-400 hover:bg-primary-50' : 'cursor-default']"
          @click="goTo(step.number)">
          <span class="mr-2 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold">{{ step.number < currentStep ? '✓' : step.number }}</span>{{ step.label }}
        </button>
      </li>
    </ol>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <div v-if="busy && !upload && currentStep === 1 && !file && !processing" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>

      <template v-else-if="currentStep === 1">
        <h2 class="mb-1 text-lg font-semibold">{{ tt('upload_title') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ tt('upload_hint') }}</p>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="pohoda-export-help">
          <div :class="props.system === 'pohoda' ? 'grid gap-5 lg:grid-cols-2 xl:grid-cols-3' : 'grid gap-5 lg:grid-cols-2'">
            <div>
              <h3 class="mb-2 font-medium text-neutral-700">{{ tt('export_help_title') }}</h3>
              <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
                <li v-for="(item, i) in exportHelpItems" :key="i">{{ item }}</li>
              </ol>
            </div>
            <div v-if="props.system === 'pohoda'" data-testid="pohoda-mdb-help">
              <h3 class="mb-2 font-medium text-neutral-700">{{ tt('mdb_help_title') }}</h3>
              <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
                <li v-for="(item, i) in mdbHelpItems" :key="i">{{ item }}</li>
              </ol>
              <p class="mt-2 text-neutral-600">{{ tt('mdb_help_requirements') }}</p>
              <p class="mt-2 text-neutral-600">
                {{ tt('mdb_driver_hint') }}
                <a href="https://support.microsoft.com/en-us/access/download-and-install-microsoft-365-access-runtime" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-700 underline hover:no-underline">{{ tt('mdb_driver_link') }}</a>
                {{ tt('mdb_driver_architecture') }}
              </p>
            </div>
            <div data-testid="pohoda-sql-help">
              <h3 class="mb-2 font-medium text-neutral-700">{{ tt('sql_help_title') }}</h3>
              <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
                <li v-for="(item, i) in sqlHelpItems" :key="i">{{ item }}</li>
              </ol>
              <p class="mt-2 text-neutral-600">{{ tt('sql_help_requirements') }}</p>
              <p class="mt-2 text-neutral-600">
                {{ tt('sql_driver_hint') }}
                <a href="https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-700 underline hover:no-underline">{{ tt('sql_driver_link') }}</a>
              </p>
            </div>
          </div>
          <p class="mt-3 font-medium text-neutral-700">{{ tt('export_auto_detect') }}</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <button v-if="props.system === 'pohoda'" type="button" :class="btnOutline('primary')" :disabled="toolDownloading !== null" data-testid="pohoda-tools-download" @click="downloadTool(null)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ tt('tools_download') }}
            </button>
            <button type="button" :class="btnOutline('neutral')" :aria-expanded="toolOpen" data-testid="pohoda-tool-toggle" @click="toggleTool">
              <svg class="h-4 w-4 transition-transform" :class="toolOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
              {{ toolOpen ? tt('tool_hide') : tt('tool_show') }}
            </button>
          </div>
          <div v-if="toolOpen" class="mt-3 rounded-md border border-neutral-200 bg-surface px-3 py-3" data-testid="pohoda-tool">
            <p class="mb-2 text-neutral-600">{{ tt('tool_hint') }}</p>
            <p v-if="toolLoading" class="text-neutral-400">{{ tt('tool_loading') }}</p>
            <template v-else-if="toolFiles">
              <p v-if="!toolFiles.length" class="text-neutral-500">{{ tt('tool_empty') }}</p>
              <div v-else class="mb-3 grid gap-3 lg:grid-cols-2" :class="toolGroups.length > 2 ? 'xl:grid-cols-3' : ''">
                <section v-for="group in toolGroups" :key="group.key" class="min-w-0 rounded-md border border-neutral-200 p-3" :data-testid="`pohoda-tool-group-${group.key}`">
                  <h4 class="font-medium text-neutral-800">{{ tt(`tool_group_${group.key}_title`) }}</h4>
                  <p class="mt-1 text-sm text-neutral-600">{{ tt(`tool_group_${group.key}_hint`) }}</p>
                  <ul class="mt-2 divide-y divide-neutral-100">
                    <li v-for="f in group.files" :key="f.name" class="flex flex-wrap items-center justify-between gap-2 py-2">
                      <div v-if="f.name === 'Export-PohodaMdb.cmd'" class="basis-full py-2">
                        <h5 class="font-medium text-neutral-800">{{ tt('tool_group_support_title') }}</h5>
                        <p class="mt-1 text-sm text-neutral-600">{{ tt('tool_group_support_hint') }}</p>
                      </div>
                      <div class="min-w-0 flex-1">
                        <span class="break-all font-mono text-sm">{{ f.name }}</span>
                        <span class="ml-2 text-xs text-neutral-500">{{ formatBytes(f.size) }}</span>
                        <p class="mt-1 text-xs text-neutral-500">{{ tt(toolRole(group, f.name)) }}</p>
                      </div>
                      <button type="button" :class="[btnOutlineSm('neutral'), 'shrink-0']" :disabled="toolDownloading !== null" @click="downloadTool(f.name)">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                        {{ tt('tool_download') }}
                      </button>
                    </li>
                  </ul>
                </section>
              </div>
              <div v-if="toolFiles.length" class="flex flex-wrap gap-2">
                <button type="button" :class="btnOutline('primary')" :disabled="toolDownloading !== null" data-testid="pohoda-tool-zip" @click="downloadTool(null)">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                  {{ tt('tool_download_all') }}
                </button>
              </div>
            </template>
          </div>
        </div>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="pohoda-file-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ tt('file_help_title') }}</h3>
          <ul class="list-disc space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in fileHelpItems" :key="i">{{ item }}</li>
          </ul>
        </div>

        <label class="block max-w-xl text-sm font-medium">
          {{ tt('choose_file') }}
          <input type="file" accept=".zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="pohoda-input" :disabled="busy" @change="onFile" />
        </label>
        <div v-if="uploadPercent !== null || processing" class="mt-4 max-w-xl space-y-2 rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3" data-testid="pohoda-progress">
          <div class="text-sm font-medium text-primary-700">
            {{ processing ? tt('processing') : tt('uploading_percent', { percent: uploadPercent ?? 0 }) }}
          </div>
          <div class="h-2 overflow-hidden rounded-full bg-primary-100">
            <div class="h-full bg-primary-500 transition-all duration-300"
              :class="processing && processingPercent === null ? 'w-1/3 animate-pulse' : ''"
              :style="processing ? (processingPercent === null ? undefined : { width: processingPercent + '%' }) : { width: (uploadPercent ?? 0) + '%' }"></div>
          </div>
          <p v-if="processing && processingProgress?.step" class="text-xs text-primary-700" data-testid="pohoda-progress-step">
            {{ processingProgress.total > 0 ? tt('processing_progress', { step: processingProgress.step, processed: processingProgress.processed, total: processingProgress.total }) : processingProgress.step }}
          </p>
          <p class="text-xs text-neutral-500">{{ processing ? tt('processing_hint') : tt('uploading_hint') }}</p>
          <div v-if="processing && processingSlow" class="flex flex-wrap items-center gap-2 border-t border-primary-200 pt-2" data-testid="pohoda-progress-slow">
            <p class="basis-full text-xs text-warning-700">{{ tt('processing_slow') }}</p>
            <button type="button" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" @click="retryUpload">
              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ tt('preview_reload') }}
            </button>
          </div>
        </div>
        <div v-if="loadError && !processing" class="mt-4 max-w-xl space-y-2 rounded-md border border-danger-500/40 bg-danger-50 px-3 py-3" role="alert" data-testid="pohoda-load-error">
          <div class="text-sm font-medium text-danger-600">{{ tt('preview_failed_title') }}</div>
          <p class="text-sm text-danger-600">{{ loadError.message }}</p>
          <div class="flex flex-wrap gap-2">
            <button type="button" :class="[btnFilled('primary'), 'whitespace-nowrap']" :disabled="busy" data-testid="pohoda-load-retry" @click="retryUpload">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ tt('preview_retry') }}
            </button>
            <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="busy" data-testid="pohoda-load-abandon" @click="abandonUpload">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
              {{ tt('preview_other_file') }}
            </button>
          </div>
        </div>
      </template>

      <template v-else-if="currentStep === 2 && upload">
        <h2 class="mb-4 text-lg font-semibold">{{ tt('step2') }}</h2>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ tt('agendas_title') }}</h3>
        <div class="mb-2 overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
              <tr>
                <th class="w-10 px-3 py-2 whitespace-nowrap">{{ tt('col_select') }}</th>
                <th class="px-3 py-2">{{ tt('col_ico') }}</th>
                <th class="px-3 py-2">{{ tt('col_company') }}</th>
                <th class="px-3 py-2">{{ tt('col_year') }}</th>
                <template v-if="!payrollWizard">
                  <th class="px-3 py-2 text-right">{{ tt('col_journal') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_opening') }}</th>
                  <th class="px-3 py-2">{{ tt('col_range') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_issued') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_purchase') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_internal') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_cash') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_bank') }}</th>
                  <th class="px-3 py-2 text-right">{{ tt('col_partners') }}</th>
                </template>
                <th class="px-3 py-2 whitespace-nowrap">{{ tt('col_payroll') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="a in agendas" :key="`${a.ico}-${a.year}`">
              <tr :class="a.ico === upload.supplier_ico && isSelected(a.year) ? 'bg-primary-50/60' : ''">
                <td class="px-3 py-2">
                  <input v-if="a.ico === upload.supplier_ico" type="checkbox" class="rounded border-neutral-300 text-primary-600" :checked="isSelected(a.year)"
                    :aria-label="tt('select_year', { year: a.year })" :data-testid="`pohoda-year-${a.year}`"
                    @change="toggleYear(a.year, ($event.target as HTMLInputElement).checked)" />
                </td>
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ a.ico || '—' }}</td>
                <td class="px-3 py-2">
                  {{ agendaCompany(a) }}
                  <span v-if="a.ico !== upload.supplier_ico" class="ml-2 rounded-full bg-neutral-100 px-2 py-0.5 text-xs whitespace-nowrap text-neutral-600">{{ tt('other_company') }}</span>
                  <span v-if="!payrollWizard && a.has_accounting === false" class="ml-2 rounded-full bg-primary-50 px-2 py-0.5 text-xs whitespace-nowrap text-primary-700" data-testid="pohoda-payroll-only">{{ tt('payroll_only') }}</span>
                </td>
                <td class="px-3 py-2 font-medium">{{ a.year }}</td>
                <template v-if="!payrollWizard">
                  <td class="px-3 py-2 text-right">{{ a.counts.journal }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.opening }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ a.counts.first_date ?? '—' }} – {{ a.counts.last_date ?? '—' }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.issued }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.purchase }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.internal }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.cash }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.bank }}</td>
                  <td class="px-3 py-2 text-right">{{ a.counts.partners }}</td>
                </template>
                <td class="px-3 py-2 whitespace-nowrap" data-testid="pohoda-payroll-cell">{{ a.payroll ? tt('payroll_cell', { employees: a.payroll.employees, months: a.payroll.months }) : '—' }}</td>
              </tr>
              <tr v-for="o in a.ico === upload.supplier_ico ? laterOptions(a.year) : []" :key="`${a.ico}-${a.year}-${o.year}`"
                :class="isSelected(o.year) ? 'bg-primary-50/60' : ''" :data-testid="`pohoda-later-year-${o.year}`">
                <td class="px-3 py-2">
                  <input type="checkbox" class="rounded border-neutral-300 text-primary-600" :checked="isSelected(o.year)"
                    :aria-label="tt('select_year', { year: o.year })" :data-testid="`pohoda-year-${o.year}`"
                    @change="toggleYear(o.year, ($event.target as HTMLInputElement).checked)" />
                </td>
                <td class="px-3 py-2"></td>
                <td class="px-3 py-2 text-neutral-600">↳ {{ tt('later_year_row', { year: o.year, agenda: a.year }) }}</td>
                <td class="px-3 py-2 font-medium">{{ o.year }}</td>
                <td :colspan="payrollWizard ? 1 : 10"></td>
              </tr>
              </template>
            </tbody>
          </table>
        </div>
        <p class="mb-5 text-sm text-neutral-500">{{ tt('supplier_ico', { ico: upload.supplier_ico || '—' }) }}</p>

        <p v-if="!years.length" class="mb-5 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="pohoda-no-agenda">
          {{ upload.supplier_ico ? tt('no_matching_agenda', { ico: upload.supplier_ico }) : tt('supplier_ico_missing') }}
        </p>
        <template v-else>
          <div class="mb-5 text-sm" data-testid="pohoda-years">
            <p class="font-medium">{{ tt('year') }}: <span data-testid="pohoda-years-selected">{{ yearsLabel || '—' }}</span></p>
            <p class="mt-1 text-neutral-500">{{ tt('year_hint') }}</p>
            <p v-if="yearOptions.some(o => !o.own)" class="mt-1 text-neutral-500" data-testid="pohoda-later-year-hint">{{ tt('later_year_hint') }}</p>
          </div>

          <p v-if="skipped > 0" class="mb-5 rounded-lg border border-primary-500/30 bg-primary-50 px-3 py-2 text-sm text-primary-700" data-testid="pohoda-skipped-agendas">{{ tt('other_wizard_hint', { n: skipped }) }}</p>

          <fieldset v-if="stockAgenda" class="mb-5 rounded-lg border border-neutral-200 p-3 text-sm" data-testid="pohoda-stock">
            <legend class="sr-only">{{ tt('stock.title') }}</legend>
            <label class="flex cursor-pointer items-start gap-3">
              <input v-model="stockEnabled" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-stock-enabled" />
              <span>
                <span class="font-medium text-neutral-800">{{ tt('stock.enable', { cards: stockAgenda.stock.cards, date: stockAgenda.stock.date ?? '—' }) }}</span>
                <span class="block text-neutral-600">{{ tt('stock.hint', { year: stockAgenda.year }) }}</span>
              </span>
            </label>
            <div v-if="stockEnabled" class="mt-3">
              <div class="overflow-x-auto rounded-md border border-neutral-200">
                <table class="min-w-full text-sm">
                  <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
                    <tr>
                      <th class="px-3 py-2">{{ tt('stock.col_warehouse') }}</th>
                      <th class="px-3 py-2 text-right whitespace-nowrap">{{ tt('stock.col_cards') }}</th>
                      <th class="px-3 py-2 text-right whitespace-nowrap">{{ tt('stock.col_stocked') }}</th>
                      <th class="px-3 py-2 text-right whitespace-nowrap">{{ tt('stock.col_without_kind') }}</th>
                      <th class="px-3 py-2">{{ tt('stock.col_choice') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-neutral-100">
                    <tr v-for="w in stockAgenda.stock.warehouses" :key="w.code" :class="stockChoices[w.code] === 'skip' ? 'text-neutral-400' : ''">
                      <td class="px-3 py-2"><span class="font-mono whitespace-nowrap">{{ w.code }}</span> <span class="text-neutral-600">{{ w.name }}</span></td>
                      <td class="px-3 py-2 text-right">{{ w.cards }}</td>
                      <td class="px-3 py-2 text-right">{{ w.stocked }}</td>
                      <td class="px-3 py-2 text-right">{{ w.without_kind }}</td>
                      <td class="px-3 py-2">
                        <select v-model="stockChoices[w.code]" class="h-9 rounded-md border border-neutral-300 px-2 text-sm" :aria-label="tt('stock.choice_label', { warehouse: w.code })" :data-testid="`pohoda-stock-choice-${w.code}`">
                          <option value="goods">{{ tt('stock.choice.goods') }}</option>
                          <option value="material">{{ tt('stock.choice.material') }}</option>
                          <option value="skip">{{ tt('stock.choice.skip') }}</option>
                        </select>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <p class="mt-2 text-neutral-500">{{ tt('stock.choice_hint') }}</p>
            </div>
          </fieldset>

          <label v-if="kind === 'payroll'" class="mb-3 flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3">
            <input v-model="confirmIdentifiers" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-confirm-identifiers" />
            <span class="text-sm text-neutral-700">{{ tt('payroll_identifiers_confirm') }}</span>
          </label>
          <label v-if="kind === 'payroll'" class="mb-5 flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3">
            <input v-model="approveTakenOver" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-approve-taken-over" />
            <span class="text-sm text-neutral-700">{{ tt('payroll_approve_taken_over') }}</span>
          </label>

          <p v-if="startWillSet" class="mb-5 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-700" data-testid="pohoda-start-will-set">{{ tt('payroll_start.will_set', startWillSet) }}</p>

          <fieldset v-if="startBehind" class="mb-5 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm" data-testid="pohoda-start-decision">
            <legend class="sr-only">{{ tt('payroll_start.title', startBehind) }}</legend>
            <p class="font-medium text-warning-700">{{ tt('payroll_start.title', startBehind) }}</p>
            <p class="mt-1 text-warning-700">{{ tt('payroll_start.hint', startBehind) }}</p>
            <label class="mt-3 flex cursor-pointer items-start gap-3">
              <input v-model="startDecision" type="radio" value="advance" class="mt-1 border-neutral-300 text-primary-600" data-testid="pohoda-start-advance" />
              <span>
                <span class="font-medium text-neutral-800">{{ tt('payroll_start.advance', { period: startBehind.to }) }}</span>
                <span class="block text-neutral-600">{{ tt('payroll_start.advance_hint', { period: startBehind.to, last: startBehind.last }) }}</span>
              </span>
            </label>
            <label class="mt-2 flex cursor-pointer items-start gap-3">
              <input v-model="startDecision" type="radio" value="keep" class="mt-1 border-neutral-300 text-primary-600" data-testid="pohoda-start-keep" />
              <span>
                <span class="font-medium text-neutral-800">{{ tt('payroll_start.keep') }}</span>
                <span class="block text-neutral-600">{{ tt('payroll_start.keep_hint', startBehind) }}</span>
              </span>
            </label>
            <label v-if="startDecision === 'keep'" class="mt-2 ml-7 flex cursor-pointer items-start gap-3">
              <input v-model="keepStartConfirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-start-keep-confirm" />
              <span class="text-neutral-700">{{ tt('payroll_start.keep_confirm', startBehind) }}</span>
            </label>
          </fieldset>

          <p v-if="!selectedYears.length" class="mb-5 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="pohoda-no-year-selected">{{ tt('choose_year_first') }}</p>
          <div v-for="g in preflightGroups" :key="g.year" class="mb-5" :data-testid="`pohoda-preflight-${g.year}`">
            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ tt('preflight_title', { year: g.year }) }}</h3>
            <ul v-if="g.messages.length" class="space-y-2">
              <li v-for="m in g.messages" :key="m.code + m.message" class="rounded-lg border px-3 py-2 text-sm"
                :class="m.level === 'error' ? 'border-danger-500/30 bg-danger-50 text-danger-600' : m.level === 'warning' ? 'border-warning-500/30 bg-warning-50 text-warning-700' : 'border-primary-500/30 bg-primary-50 text-primary-700'">
                {{ m.message }}
              </li>
            </ul>
            <p v-else class="rounded-lg border border-success-500/30 bg-success-50 px-3 py-2 text-sm text-success-600">{{ tt('preflight_ok') }}</p>
          </div>

          <details v-if="noteFiles.length" class="rounded-lg border border-neutral-200 px-4 py-3 text-sm" data-testid="pohoda-file-notes">
            <summary class="cursor-pointer font-medium text-neutral-700">{{ tt('files_title', { n: noteFiles.length }) }}</summary>
            <p class="mt-2 text-neutral-500">{{ tt('files_hint') }}</p>
            <ul class="mt-2 divide-y divide-neutral-100">
              <li v-for="f in noteFiles" :key="`${f.year}-${f.file}`" class="flex flex-wrap items-center gap-2 py-1.5">
                <span v-if="selectedAgendas.length > 1" class="font-medium">{{ f.year }}</span>
                <span class="font-mono">{{ f.file }}</span>
                <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs whitespace-nowrap text-neutral-600">{{ fileState(f.state) }}</span>
                <span v-if="f.note" class="text-neutral-500">{{ f.note }}</span>
              </li>
            </ul>
          </details>
        </template>
      </template>

      <template v-else-if="currentStep === 3">
        <h2 class="mb-1 text-lg font-semibold">{{ tt('dry_run_title') }}</h2>
        <p class="mb-2 text-sm text-neutral-500">{{ tt(kind === 'payroll' ? 'payroll_dry_run_hint' : 'dry_run_hint', { years: yearsLabel }) }}</p>
        <p v-if="runYears.length > 1" class="mb-2 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="pohoda-dry-run-years-hint">{{ tt('dry_run_years_hint') }}</p>
        <p class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ tt('dry_run_locks_hint') }}</p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="null" :cancelling="false" :show-cancel="false" :show-count="false"
          counts-key="pohoda.job_counts" background-hint-key="pohoda.dry_run_background_hint" running-key="pohoda.dry_run_running" />
        <template v-if="jobRuns.length && jobRuns[0].mode === 'dry_run'">
          <p v-if="yearsNotRun.length" class="mb-3 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="pohoda-years-not-run">{{ tt('years_not_run', { years: yearsNotRun.join(', ') }) }}</p>
          <MoneyS3Protocol v-if="jobRuns.length === 1" :run="jobRuns[0]" prefix="pohoda" />
          <div v-else class="space-y-3" data-testid="pohoda-job-runs">
            <details v-for="(r, i) in jobRuns" :key="r.id" :open="r.status === 'failed' || i === jobRuns.length - 1" class="rounded-lg border border-neutral-200" :data-testid="`pohoda-job-run-${r.agenda_year}`">
              <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-3">
                <span class="font-medium">{{ tt('job_run_year', { year: r.agenda_year ?? '', n: i + 1, total: jobRuns.length }) }}<span class="ml-2 text-xs text-neutral-500">#{{ r.id }}</span></span>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(r.status)">{{ tt(`status.${r.status}`) }}</span>
              </summary>
              <div class="border-t border-neutral-200 p-4"><MoneyS3Protocol :run="r" prefix="pohoda" /></div>
            </details>
          </div>
          <MigrationDifferences v-if="differencesAcceptable && !jobRunning" v-model="acceptDifferences" :differences="differences" prefix="pohoda" />
        </template>
      </template>

      <template v-else>
        <h2 class="mb-1 text-lg font-semibold">{{ tt('import_title') }}</h2>
        <label v-if="!importDone && !jobRunning" class="my-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
          <input v-model="confirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="pohoda-confirm" />
          <span class="text-sm text-warning-700">{{ tt(kind === 'payroll' ? 'payroll_import_confirm' : 'import_confirm', { company: (selectedAgendas[0] ?? ownAgendas[0])?.company ?? '', years: yearsLabel }) }}</span>
        </label>
        <p v-if="!importDone && !jobRunning && !dryRunPassed && acceptDifferences" class="my-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="pohoda-accepting-differences">
          {{ tt('differences.import_note', { count: differences.length }) }}
        </p>
        <p v-if="!importDone && !jobRunning && missingRights.length" class="my-4 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="pohoda-rights-missing">
          {{ rightsMessage }}
        </p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="percent" :cancelling="cancelling" :show-cancel="true"
          counts-key="pohoda.job_counts" background-hint-key="pohoda.background_hint" running-key="pohoda.import_running"
          cancel-key="pohoda.cancel" cancelling-key="pohoda.cancelling" @cancel="cancel" />
        <template v-if="jobRuns.length && jobRuns[0].mode === 'import'">
          <p v-if="yearsNotRun.length" class="mb-3 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="pohoda-years-not-run">{{ tt('years_not_run', { years: yearsNotRun.join(', ') }) }}</p>
          <MoneyS3Protocol v-if="jobRuns.length === 1" :run="jobRuns[0]" prefix="pohoda" />
          <div v-else class="space-y-3" data-testid="pohoda-job-runs">
            <details v-for="(r, i) in jobRuns" :key="r.id" :open="r.status === 'failed' || i === jobRuns.length - 1" class="rounded-lg border border-neutral-200" :data-testid="`pohoda-job-run-${r.agenda_year}`">
              <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-3">
                <span class="font-medium">{{ tt('job_run_year', { year: r.agenda_year ?? '', n: i + 1, total: jobRuns.length }) }}<span class="ml-2 text-xs text-neutral-500">#{{ r.id }}</span></span>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(r.status)">{{ tt(`status.${r.status}`) }}</span>
              </summary>
              <div class="border-t border-neutral-200 p-4"><MoneyS3Protocol :run="r" prefix="pohoda" /></div>
            </details>
          </div>
        </template>
      </template>
    </section>

    <div class="flex flex-wrap justify-end"><ActionBar :actions="actions" /></div>

    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <h2 class="border-b border-neutral-200 px-4 py-3 text-lg font-semibold">{{ tt('history_title') }}</h2>
      <p v-if="!runs.length" class="px-4 py-3 text-sm text-neutral-500">{{ tt('history_empty') }}</p>
      <div class="divide-y divide-neutral-100">
        <div v-for="item in runs" :key="item.id" class="flex items-center gap-2 pr-3 hover:bg-neutral-50">
          <button type="button" class="flex min-w-0 flex-1 cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left" @click="showRun(item)">
            <span><strong>#{{ item.id }} · {{ tt(`kind.${item.kind ?? 'accounting'}`) }} · {{ tt(`mode.${item.mode}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ [item.agenda_ico, item.agenda_year].filter(Boolean).join(' · ') }} · {{ item.created_at }}</span></span>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="item.status === 'completed' ? 'bg-success-50 text-success-600' : item.status === 'failed' ? 'bg-danger-50 text-danger-600' : item.status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">{{ tt(`status.${item.status}`) }}</span>
          </button>
          <button v-if="item.mode === 'dry_run' && item.status !== 'running' && canWrite" type="button"
            class="shrink-0 rounded p-1.5 text-neutral-400 hover:bg-danger-50 hover:text-danger-600 disabled:opacity-40"
            :title="tt('run_delete')" :aria-label="tt('run_delete')" :disabled="deletingRun === item.id"
            :data-testid="`pohoda-run-delete-${item.id}`" @click="deleteRun(item)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          </button>
        </div>
      </div>
    </section>

    <section v-if="run && !(currentStep >= 3 && jobRuns.some(r => r.id === run?.id))" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <MoneyS3Protocol :run="run" prefix="pohoda" />
    </section>

    <CompanyProfileBox variant="migration" />
  </div>
</template>

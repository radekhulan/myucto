<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import {
  accountingApi,
  type PayrollPreview, type PayrollTaxpayerType, type PayrollEmploymentType,
  type PayrollEmployee, type PayrollEmployeePayload, type ChartAccount,
} from '@/api/accounting'
import { payrollApi } from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatPeriod } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'

/**
 * Mzdová rekapitulace (Fáze F) — měsíc + typ poplatníka + hrubá mzda → rozpad → zaúčtování.
 *
 * Rozpad NEPOČÍTÁ frontend: náhled i zaúčtování volají tentýž PayrollCalculator na
 * serveru, takže se náhled a zaúčtovaný zápis nemůžou rozejít (doplatek ZP do
 * minimálního vyměřovacího základu se navíc mění s minimální mzdou každý rok).
 */

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('accounting'))

const now = new Date()
// Default = předchozí měsíc: mzda se zpravidla účtuje zpětně.
const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1)

const form = reactive({
  year: prev.getFullYear(),
  month: prev.getMonth() + 1,
  gross: null as number | null,
  taxpayer_type: 'employee' as PayrollTaxpayerType,
  // Volitelné — jen když má rekapitulace navíc vytvořit podklad pro mzdový list (§38j).
  employee_id: null as number | null,
  // Prohlášení poplatníka (§38k). Default „podepsané": platí u drtivé většiny mezd
  // a bez slevy by se na 342 účtovalo víc, než se reálně odvede (§38h odst. 4).
  // Bez zvoleného zaměstnance se sleva NEpředpokládá — dřív tu bylo `true`
  // a rekapitulace ji uplatnila i tam, kde na ni nárok nebyl.
  taxpayer_credit: false,
  child_count: 0,
})

const preview = ref<PayrollPreview | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')

const years = computed(() => {
  const y = now.getFullYear()
  return [y + 1, y, y - 1, y - 2, y - 3]
})
const months = Array.from({ length: 12 }, (_, i) => i + 1)

/**
 * Od kterého období mzdy převzal modul Mzdy (`YYYY-MM`), nebo `null`.
 *
 * Rekapitulace i modul účtují na tytéž účty a jeden o druhém neví, takže měsíc
 * spočítaný v modulu se tady zaúčtovat nesmí — seděl by v deníku dvakrát.
 * Stránka ale nezmizí ani se nezamkne celá: starší období se dál opravují tam,
 * kde vznikla. Výpadek dotazu (typicky chybějící právo na mzdy) jen skryje
 * upozornění; skutečnou pojistkou je kontrola na serveru.
 */
const payrollModuleFrom = ref<string | null>(null)

async function loadPayrollModuleState() {
  try {
    const state = await payrollApi.capabilities()
    payrollModuleFrom.value = state.state.status === 'active' ? state.state.start_period : null
  } catch {
    payrollModuleFrom.value = null
  }
}

const selectedPeriod = computed(
  () => `${form.year}-${String(form.month).padStart(2, '0')}`
)
const moduleTookOver = computed(() =>
  payrollModuleFrom.value !== null && selectedPeriod.value >= payrollModuleFrom.value
)
const canPost = computed(() =>
  preview.value !== null && !saving.value && !loading.value && !moduleTookOver.value
)

function errorMessage(e: any): string {
  return e?.response?.data?.error?.message || t('common.error')
}

/** Náhled se přepočítá při každé změně vstupu (debounce kvůli psaní do částky). */
let debounce: ReturnType<typeof setTimeout> | undefined
watch(() => [
  form.year, form.month, form.gross, form.taxpayer_type,
  form.taxpayer_credit, form.child_count,
], () => {
  preview.value = null
  error.value = ''
  clearTimeout(debounce)
  if (!(Number(form.gross) > 0)) return
  debounce = setTimeout(() => { void loadPreview() }, 300)
})

async function loadPreview() {
  if (!(Number(form.gross) > 0)) return
  loading.value = true
  error.value = ''
  try {
    preview.value = await accountingApi.previewPayroll({
      year: form.year,
      month: form.month,
      gross: Number(form.gross),
      taxpayer_type: form.taxpayer_type,
      taxpayer_credit: form.taxpayer_credit,
      child_count: form.child_count,
    })
  } catch (e: any) {
    preview.value = null
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}

async function post() {
  if (!preview.value) return
  saving.value = true
  error.value = ''
  try {
    const res = await accountingApi.postPayroll({
      year: form.year,
      month: form.month,
      gross: Number(form.gross),
      taxpayer_type: form.taxpayer_type,
      employee_id: form.employee_id,
      taxpayer_credit: form.taxpayer_credit,
      child_count: form.child_count,
    })
    toast.success(t('accounting.payroll.posted', { id: res.journal_entry_id }))
    // Routa `/accounting/journal/:id` NEEXISTUJE (jen `/accounting/journal` a `/new`) —
    // detail zápisu se otevírá drill-downem přes `?entry_id=`, jako všude jinde.
    // Cesta s ID vede na 404, i když se zaúčtování povedlo.
    router.push({ name: 'accounting-journal', query: { entry_id: String(res.journal_entry_id) } })
  } catch (e: any) {
    error.value = errorMessage(e)
  } finally {
    saving.value = false
  }
}

// ── Zaměstnanci pro mzdový list (§38j ZDP) ──────────────────────────────────
const employees = ref<PayrollEmployee[]>([])
const employeesLoading = ref(false)

async function loadEmployees() {
  employeesLoading.value = true
  try {
    employees.value = await accountingApi.listPayrollEmployees()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    employeesLoading.value = false
  }
}

const activeEmployees = computed(() => employees.value.filter(e => e.is_active))

/**
 * Karta zaměstnance je zdroj pravdy o typu poplatníka i slevách — server ji při
 * zaúčtování stejně přebije, takže se pole jen zrcadlí a zamknou, aby náhled
 * neukazoval něco jiného, než co se zaúčtuje. Deklarováno až tady kvůli závislosti
 * na `activeEmployees`.
 */
const selectedEmployee = computed(
  () => activeEmployees.value.find(e => e.id === form.employee_id) ?? null
)
const creditsLocked = computed(() => selectedEmployee.value !== null)

watch(selectedEmployee, (e, previous) => {
  if (!e) return
  // Pravidelná mzda z karty se předvyplní, ať se táž konstanta neopisuje měsíc co
  // měsíc. NEpřepisuje se ale částka, kterou uživatel zadal ručně — jednorázová
  // úprava kvůli nemoci nebo odměně by se tím tiše zahodila. Přepíše se jen
  // prázdné pole, nebo hodnota, která zjevně patřila předchozímu zaměstnanci.
  const declared = e.monthly_gross ?? null
  const untouched = form.gross === null
    || (previous != null && form.gross === previous.monthly_gross)
  if (declared !== null && declared > 0 && untouched) {
    form.gross = declared
  }
  // Typ poplatníka rozhoduje o kontaci (521/331 vs. 522/366). Formulář mohl mít
  // „zaměstnanec", zatímco karta říká „jednatel-společník" — zaúčtovalo se pak
  // na jiné účty, než co ukazoval náhled.
  form.taxpayer_type = e.taxpayer_type
  // Nárok na slevu a možnost uplatnit ji U TOHOTO PLÁTCE jsou dvě různé věci
  // (§ 38h odst. 4, § 38k odst. 4) — musí platit obě.
  // Boolean() nutně: checkbox se `v-model` porovnává s `true`, takže netknutá
  // TINYINT jednička z API by se vykreslila jako nezaškrtnutá.
  form.taxpayer_credit = Boolean(e.tax_credit_taxpayer) && Boolean(e.tax_declaration_signed)
  form.child_count = e.child_count
})

const showEmployeeForm = ref(false)
const savingEmployee = ref(false)
const editingEmployeeId = ref<number | null>(null)
/*
 * Rodné číslo ani adresa tady NEJSOU (W1/P-02). Tahle obrazovka jede po routě
 * chráněné jen právem `accounting`, takže by otevřené rodné číslo zapsal
 * i uživatel bez jediného mzdového práva — mimo šifrovanou evidenci
 * `payroll_person_identifiers` a mimo stopu o odhalení. Backend je z legacy
 * routy odstranil a vyplněnou hodnotu vrací jako 422; jediná legální cesta
 * k rodnému číslu je mzdová karta osoby v novém modulu.
 */
const employeeForm = reactive({
  full_name: '',
  birth_date: '',
  taxpayer_type: 'employee' as PayrollTaxpayerType,
  employment_type: 'hpp' as PayrollEmploymentType,
  tax_credit_taxpayer: true,
  // § 38k odst. 4 — bez podepsaného prohlášení se měsíční sleva uplatnit nesmí.
  // Výchozí NEpodepsáno: za nesraženou zálohu ručí plátce (§ 38s), kdežto přeplatek
  // se vrátí v ročním zúčtování.
  tax_declaration_signed: false,
  child_count: 0,
  // Účet pro měsíční přeúčtování čisté mzdy (1178); null = nechat ji viset jako závazek.
  net_settlement_account_code: null as string | null,
  // Pravidelná měsíční hrubá mzda a pověření cronu, ať se táž konstanta neopisuje
  // ručně měsíc co měsíc. `null` = nesjednaná, což je jiný stav než 0 Kč.
  monthly_gross: null as number | null,
  auto_post: false,
  is_active: true,
})

function resetEmployeeForm() {
  employeeForm.full_name = ''
  employeeForm.birth_date = ''
  employeeForm.taxpayer_type = 'employee'
  employeeForm.employment_type = 'hpp'
  employeeForm.tax_credit_taxpayer = true
  employeeForm.tax_declaration_signed = false
  employeeForm.child_count = 0
  employeeForm.net_settlement_account_code = null
  employeeForm.monthly_gross = null
  employeeForm.auto_post = false
  employeeForm.is_active = true
}

/**
 * Pracovněprávní vztahy na kartě. `statutory_body` = smlouva o výkonu funkce
 * (§ 59 ZOK, migrace 1302); týž klíč používá i novější mzdový modul.
 */
const EMPLOYMENT_TYPES: readonly PayrollEmploymentType[] = ['hpp', 'dpp', 'dpc', 'statutory_body']

/**
 * Typ poplatníka, který se u daného vztahu PŘEDPOKLÁDÁ. Zrcadlí
 * `PayrollEmployeeAction::TAXPAYER_TYPE_HINTS` na serveru — formulář hodnotu
 * předvyplní, server na nesoulad ještě jednou upozorní v `warnings`.
 */
const TAXPAYER_TYPE_HINTS: Partial<Record<PayrollEmploymentType, PayrollTaxpayerType>> = {
  statutory_body: 'managing_partner',
}

/**
 * Volba vztahu typ poplatníka jen PŘEDVYPLNÍ, nevynutí — kdo si vědomě zvolí
 * „zaměstnanec", tomu formulář volbu nevrátí zpátky, jen ukáže varování. Kombinace
 * může legitimně vzniknout u jednatele, který má u firmy i pracovní poměr.
 *
 * Záměrně `@change`, ne `watch` nad polem formuláře: watcher by se spustil i při
 * načtení existující karty (`openEditEmployee` plní pole programově) a otevření
 * detailu by uloženou hodnotu tiše přepsalo. Takhle se předvyplní jen to, co uživatel
 * doopravdy přepnul.
 */
function onEmploymentTypeChange(event: Event): void {
  const previous = employeeForm.employment_type
  const type = (event.target as HTMLSelectElement).value as PayrollEmploymentType
  employeeForm.employment_type = type
  if (type === previous) return

  const hint = TAXPAYER_TYPE_HINTS[type]
  const previousHint = TAXPAYER_TYPE_HINTS[previous]
  if (hint !== undefined) {
    // Přepisuje se jen výchozí hodnota — vědomá volba uživatele zůstane.
    if (employeeForm.taxpayer_type === 'employee') employeeForm.taxpayer_type = hint
  } else if (previousHint !== undefined && employeeForm.taxpayer_type === previousHint) {
    // Odchod od výkonu funkce vrátí zpátky to, co předtím předvyplnil on sám.
    employeeForm.taxpayer_type = 'employee'
  }
}

const taxpayerTypeMismatch = computed(() => {
  const hint = TAXPAYER_TYPE_HINTS[employeeForm.employment_type]
  return hint !== undefined && employeeForm.taxpayer_type !== hint
})

/**
 * Automat bez částky nemá co zaúčtovat — checkbox proto zůstane nepřístupný, dokud
 * není mzda vyplněná, a smazání částky ho shodí zpátky. Server tutéž podmínku hlídá
 * znovu; tohle je jen to, aby uživatel nedostal 422 za něco, co mu formulář dovolil.
 */
const autoPostAvailable = computed(() => Number(employeeForm.monthly_gross) > 0)

watch(autoPostAvailable, (ok) => {
  if (!ok) employeeForm.auto_post = false
})

function openNewEmployee() {
  editingEmployeeId.value = null
  resetEmployeeForm()
  showEmployeeForm.value = true
}

function openEditEmployee(e: PayrollEmployee) {
  editingEmployeeId.value = e.id
  employeeForm.full_name = e.full_name
  employeeForm.birth_date = e.birth_date ?? ''
  employeeForm.taxpayer_type = e.taxpayer_type
  employeeForm.employment_type = e.employment_type ?? 'hpp'
  employeeForm.tax_credit_taxpayer = e.tax_credit_taxpayer
  employeeForm.tax_declaration_signed = e.tax_declaration_signed ?? false
  employeeForm.child_count = e.child_count
  employeeForm.net_settlement_account_code = e.net_settlement_account_code ?? null
  employeeForm.monthly_gross = e.monthly_gross ?? null
  employeeForm.auto_post = Boolean(e.auto_post)
  employeeForm.is_active = e.is_active
  showEmployeeForm.value = true
}

async function saveEmployee() {
  if (!employeeForm.full_name.trim()) {
    toast.error(t('accounting.payroll.employees.name_required'))
    return
  }
  savingEmployee.value = true
  try {
    const payload: PayrollEmployeePayload = {
      full_name: employeeForm.full_name.trim(),
      birth_date: employeeForm.birth_date || null,
      taxpayer_type: employeeForm.taxpayer_type,
      employment_type: employeeForm.employment_type,
      tax_credit_taxpayer: employeeForm.tax_credit_taxpayer,
      tax_declaration_signed: employeeForm.tax_declaration_signed,
      child_count: Number(employeeForm.child_count),
      net_settlement_account_code: employeeForm.net_settlement_account_code || null,
      // Prázdné pole musí odejít jako null, ne 0 — „nesjednaná mzda" a „nula" jsou
      // pro cron dva různé stavy.
      monthly_gross: Number(employeeForm.monthly_gross) > 0 ? Number(employeeForm.monthly_gross) : null,
      auto_post: autoPostAvailable.value && employeeForm.auto_post,
      is_active: employeeForm.is_active,
    }
    const saved = editingEmployeeId.value === null
      ? await accountingApi.createPayrollEmployee(payload)
      : await accountingApi.updatePayrollEmployee(editingEmployeeId.value, payload)
    toast.success(t(editingEmployeeId.value === null
      ? 'accounting.payroll.employees.created'
      : 'accounting.payroll.employees.updated'))
    // Varování uložení neblokují (chyby chodí jako 422) — ale zmizet nesmí,
    // jinak by nesourodá kombinace prošla úplně tiše.
    for (const w of saved.warnings ?? []) toast.warning(w)
    showEmployeeForm.value = false
    await loadEmployees()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    savingEmployee.value = false
  }
}

async function removeEmployee(e: PayrollEmployee) {
  if (!window.confirm(t('accounting.payroll.employees.confirm_delete', { name: e.full_name }))) return
  try {
    await accountingApi.deletePayrollEmployee(e.id)
    toast.success(t('accounting.payroll.employees.deleted'))
    await loadEmployees()
  } catch (err: any) {
    toast.error(errorMessage(err))
  }
}

// ── Mzdový list (§38j ZDP) — PDF stažení za zaměstnance a rok ───────────────
const sheetEmployeeId = ref<number | null>(null)
const sheetYear = ref(now.getFullYear())
const sheetDownloading = ref(false)

async function downloadPayrollSheet() {
  if (!sheetEmployeeId.value) return
  sheetDownloading.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/payroll-sheet', {
      year: sheetYear.value,
      employee_id: sheetEmployeeId.value,
      format: 'pdf',
    })
    const blob = r.data as unknown as Blob
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `mzdovy-list-${sheetYear.value}-${sheetEmployeeId.value}.pdf`
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    sheetDownloading.value = false
  }
}

/**
 * Nabídka účtů pro přeúčtování čisté mzdy. Peněžní skupiny (21x pokladna, 22x banka,
 * 26x peníze na cestě) se vynechávají — výplatu z pokladny musí zapsat pokladní doklad
 * a bankovní výplatu párování výpisu, jinak se ty evidence rozejdou s deníkem. Backend
 * tutéž podmínku hlídá znovu, tohle je jen aby uživatel nedostal 422 za nabídnutou volbu.
 */
const settlementAccounts = ref<ChartAccount[]>([])
const settlementAccountOptions = computed(() =>
  settlementAccounts.value
    .filter(a => a.is_active && !/^(21|22|26)/.test(a.account_code))
    .sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

async function loadSettlementAccounts() {
  try {
    settlementAccounts.value = await accountingApi.listAccounts()
  } catch {
    settlementAccounts.value = []   // bez osnovy zůstane jen volba „nepřeúčtovávat"
  }
}

onMounted(() => { void loadEmployees(); void loadSettlementAccounts(); void loadPayrollModuleState() })

/** Řádky rozpadu pro tabulku „co z hrubé mzdy odchází". */
const breakdownRows = computed(() => {
  const b = preview.value?.breakdown
  if (!b) return []
  return [
    { key: 'gross', label: t('accounting.payroll.row.gross'), value: b.gross, strong: true },
    { key: 'employee_social', label: t('accounting.payroll.row.employee_social'), value: -b.employee_social },
    { key: 'employee_health', label: t('accounting.payroll.row.employee_health'), value: -b.employee_health },
    ...(b.health_min_topup > 0 ? [{
      key: 'health_min_topup',
      label: t('accounting.payroll.row.health_min_topup'),
      value: -b.health_min_topup,
      hint: t('accounting.payroll.row.health_min_topup_hint', { wage: formatMoney(b.minimum_wage) }),
    }] : []),
    {
      key: 'advance_tax',
      // Popisek nesmí tvrdit „15 %", když část základu jde 23 % (§38h odst. 2).
      label: b.tax_high_base > 0
        ? t('accounting.payroll.row.advance_tax_progressive')
        : t('accounting.payroll.row.advance_tax'),
      value: -b.advance_tax,
      hint: [
        // Základ se liší od hrubé mzdy jen u nekulatých částek — jinak by hint jen mátl.
        b.tax_base !== b.gross
          ? t('accounting.payroll.row.tax_base_hint', { base: formatMoney(b.tax_base) })
          : null,
        b.tax_high_base > 0
          ? t('accounting.payroll.row.tax_high_hint', {
              excess: formatMoney(b.tax_high_base),
              threshold: formatMoney(b.tax_high_threshold),
            })
          : null,
      ].filter(Boolean).join(' ') || undefined,
    },
    ...(b.credit_taxpayer > 0 ? [{
      key: 'credit_taxpayer',
      label: t('accounting.payroll.row.credit_taxpayer'),
      value: b.credit_taxpayer,
    }] : []),
    ...(b.credit_children > 0 ? [{
      key: 'credit_children',
      label: t('accounting.payroll.row.credit_children'),
      value: b.credit_children,
    }] : []),
    // Ořez slevy na nulu je vidět jen tady — bez tohohle řádku by rozpad nesečetl.
    ...(b.credit_total > b.advance_tax ? [{
      key: 'credit_capped',
      label: t('accounting.payroll.row.credit_capped'),
      value: -(b.credit_total - b.advance_tax),
      hint: t('accounting.payroll.row.credit_capped_hint'),
    }] : []),
    { key: 'net', label: t('accounting.payroll.row.net'), value: b.net, strong: true },
  ]
})

/**
 * Druhá varianta prohlášení (§38k) dopočítaná lokálně — jediné, co se mění, je
 * uplatnění slev, takže stačí sáhnout na už spočítaný hrubý rozpad a není kvůli
 * srovnání potřeba druhý dotaz na server.
 */
const declarationAlternative = computed(() => {
  const b = preview.value?.breakdown
  if (!b) return null
  // Bez prohlášení se sráží celá záloha; s prohlášením ta po slevách z náhledu.
  const withheld = form.taxpayer_credit ? b.advance_tax : b.advance_tax_withheld
  const net = b.gross - b.employee_deductions - withheld
  if (withheld === b.advance_tax_withheld) return null // slevy nulové → varianty se neliší
  return { signed: !form.taxpayer_credit, withheld, net, diff: net - b.net }
})

/**
 * Odvody k úhradě — tvar hromadného příkazu od účetní (ZP / OSSZ / FÚ).
 * Rozpad výš je pohled zaměstnance a `employer_*` pohled nákladu; ani jeden nedá
 * částku, která odejde z účtu, takže bez téhle tabulky nešlo rekapitulaci porovnat
 * s příkazem k úhradě.
 */
const remittanceRows = computed(() => {
  const b = preview.value?.breakdown
  if (!b) return []
  return [
    {
      key: 'health',
      label: t('accounting.payroll.remit.health'),
      hint: t('accounting.payroll.remit.health_hint', {
        employee: formatMoney(b.employee_health),
        topup: formatMoney(b.health_min_topup),
        employer: formatMoney(b.employer_health),
      }),
      value: b.health_total,
    },
    {
      key: 'social',
      label: t('accounting.payroll.remit.social'),
      hint: t('accounting.payroll.remit.social_hint', {
        employee: formatMoney(b.employee_social),
        employer: formatMoney(b.employer_social),
      }),
      value: b.social_total,
    },
    {
      key: 'tax',
      label: t('accounting.payroll.remit.tax'),
      // Na FÚ jde záloha PO slevě — hrubá by příkaz k úhradě přeplatila o celou slevu.
      hint: b.credit_total > 0
        ? t('accounting.payroll.remit.tax_hint_credited', {
            gross: formatMoney(b.advance_tax),
            credit: formatMoney(Math.min(b.credit_total, b.advance_tax)),
          })
        : t('accounting.payroll.remit.tax_hint'),
      value: b.advance_tax_withheld,
    },
    {
      key: 'total',
      label: t('accounting.payroll.remit.total'),
      value: b.remittance_total,
      strong: true,
    },
  ]
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.payroll.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.payroll.subtitle') }}</p>
      </div>
      <RouterLink to="/accounting/journal" class="text-sm text-neutral-500 hover:text-neutral-700 whitespace-nowrap">
        {{ t('common.back') }}
      </RouterLink>
    </div>

    <!--
      Firma, která přešla do modulu Mzdy, tuhle stránku pořád potřebuje kvůli
      obdobím před přechodem — ale nesmí v ní omylem zaúčtovat měsíc, který
      počítá modul. Proto upozornění místo skryté položky v menu.
    -->
    <div
      v-if="payrollModuleFrom"
      class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 p-4"
      data-test="payroll-module-notice"
    >
      <div class="flex items-start gap-3">
        <svg class="mt-0.5 h-5 w-5 shrink-0 text-warning-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.bell" /></svg>
        <div>
          <h2 class="text-sm font-semibold text-neutral-900">{{ t('accounting.payroll.module_active_title') }}</h2>
          <p class="mt-1 text-sm text-warning-800">
            {{ t('accounting.payroll.module_active_hint', { period: formatPeriod(payrollModuleFrom) }) }}
          </p>
          <RouterLink to="/payroll" class="mt-2 inline-flex text-sm font-medium text-warning-800 underline">
            {{ t('nav.section_payroll') }}
          </RouterLink>
        </div>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4">
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.payroll.year') }}</label>
          <select v-model.number="form.year" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.payroll.month') }}</label>
          <select v-model.number="form.month" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="m in months" :key="m" :value="m">{{ t(`accounting.payroll.months.${m}`) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.payroll.taxpayer_type') }}</label>
          <select v-model="form.taxpayer_type" :disabled="creditsLocked"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface disabled:opacity-50">
            <option value="employee">{{ t('accounting.payroll.type.employee') }}</option>
            <option value="managing_partner">{{ t('accounting.payroll.type.managing_partner') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.payroll.gross') }}</label>
          <input v-model.number="form.gross" type="number" min="0" step="1" inputmode="decimal"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right font-mono" />
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.payroll.employee') }}</label>
        <select v-model="form.employee_id" class="w-full sm:w-1/2 h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
          <option :value="null">{{ t('accounting.payroll.employee_none') }}</option>
          <option v-for="e in activeEmployees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
        </select>
        <p class="text-xs text-neutral-500 mt-1">{{ t('accounting.payroll.employee_hint') }}</p>
      </div>

      <!-- Slevy na dani (§35ba, §38k) — určují, kolik se reálně srazí a zaúčtuje na 342. -->
      <div class="flex flex-col sm:flex-row sm:items-start gap-3 sm:gap-6">
        <div>
          <label class="flex items-center gap-2 text-sm font-medium text-neutral-700">
            <input v-model="form.taxpayer_credit" type="checkbox" :disabled="creditsLocked"
              class="rounded border-neutral-300 disabled:opacity-50" />
            {{ t('accounting.payroll.taxpayer_credit') }}
          </label>
          <p class="text-xs text-neutral-500 mt-1">{{ t('accounting.payroll.taxpayer_credit_hint') }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.payroll.child_count') }}</label>
          <input v-model.number="form.child_count" type="number" min="0" max="20" step="1"
            :disabled="creditsLocked"
            class="w-24 h-10 px-3 border border-neutral-300 rounded-md text-sm text-right font-mono bg-surface disabled:opacity-50" />
        </div>
      </div>
      <p v-if="creditsLocked" class="text-xs text-neutral-500">
        {{ t('accounting.payroll.credits_locked', { name: selectedEmployee?.full_name }) }}
      </p>

      <p class="text-xs text-neutral-500">
        {{ t(`accounting.payroll.type_hint.${form.taxpayer_type}`) }}
      </p>

      <div v-if="error" class="text-sm text-danger-600 bg-danger-50 border border-danger-200 rounded-md px-3 py-2">
        {{ error }}
      </div>

      <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

      <template v-if="preview && !loading">
        <!-- Rozpad hrubé mzdy -->
        <div>
          <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('accounting.payroll.breakdown') }}</h2>
          <div class="border border-neutral-200 rounded-md overflow-hidden">
            <table class="w-full text-sm">
              <tbody>
                <tr v-for="row in breakdownRows" :key="row.key" class="border-b border-neutral-100 last:border-0">
                  <td class="px-3 py-2" :class="row.strong ? 'font-medium' : ''">
                    {{ row.label }}
                    <div v-if="row.hint" class="text-xs text-neutral-500">{{ row.hint }}</div>
                  </td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap"
                    :class="[row.strong ? 'font-semibold' : '', row.value < 0 ? 'text-neutral-600' : '']">
                    {{ formatMoney(row.value) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="declarationAlternative" class="text-xs text-neutral-500 mt-2">
            {{ t(declarationAlternative.signed
                ? 'accounting.payroll.alt_signed'
                : 'accounting.payroll.alt_unsigned', {
              tax: formatMoney(declarationAlternative.withheld),
              net: formatMoney(declarationAlternative.net),
            }) }}
          </p>
          <p class="text-xs text-neutral-500 mt-2">
            {{ t('accounting.payroll.employer_note', {
              social: formatMoney(preview.breakdown.employer_social),
              health: formatMoney(preview.breakdown.employer_health),
              total: formatMoney(preview.breakdown.employer_total),
            }) }}
          </p>
        </div>

        <!-- Odvody k úhradě (tvar hromadného příkazu) -->
        <div>
          <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('accounting.payroll.remit.title') }}</h2>
          <div class="border border-neutral-200 rounded-md overflow-hidden">
            <table class="w-full text-sm">
              <tbody>
                <tr v-for="row in remittanceRows" :key="row.key"
                  class="border-b border-neutral-100 last:border-0"
                  :class="row.strong ? 'bg-neutral-50' : ''">
                  <td class="px-3 py-2" :class="row.strong ? 'font-medium' : ''">
                    {{ row.label }}
                    <div v-if="row.hint" class="text-xs text-neutral-500">{{ row.hint }}</div>
                  </td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap"
                    :class="row.strong ? 'font-semibold' : ''">
                    {{ formatMoney(row.value) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-neutral-500 mt-2">{{ t('accounting.payroll.remit.note') }}</p>
        </div>

        <!-- Účetní zápis -->
        <div>
          <h2 class="text-sm font-medium text-neutral-700 mb-2">
            {{ t('accounting.payroll.entry_preview', { date: preview.entry_date }) }}
          </h2>
          <div class="border border-neutral-200 rounded-md overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accounting.manual.account') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accounting.payroll.line_description') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('accounting.manual.debit') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('accounting.manual.credit') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(l, i) in preview.lines" :key="i" class="border-t border-neutral-100">
                  <td class="px-3 py-2 font-mono">{{ l.account_code }}</td>
                  <td class="px-3 py-2 text-neutral-600">{{ l.description }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ l.side === 'debit' ? formatMoney(l.amount) : '' }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ l.side === 'credit' ? formatMoney(l.amount) : '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-neutral-500 mt-2">{{ t('accounting.payroll.idempotent_note') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-3 pt-1">
          <button type="button" :disabled="!canPost" @click="post" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
            </svg>
            <span class="whitespace-nowrap">{{ saving ? t('common.saving') : t('accounting.payroll.post') }}</span>
          </button>
          <p v-if="moduleTookOver" class="text-sm text-warning-800" data-test="payroll-module-blocked">
            {{ t('accounting.payroll.module_blocked', { period: formatPeriod(selectedPeriod) }) }}
          </p>
        </div>
      </template>

      <div v-else-if="!loading && !error" class="text-sm text-neutral-500">
        {{ t('accounting.payroll.enter_gross') }}
      </div>
    </div>

    <!-- Zaměstnanci (§38j — identifikace pro mzdový list) -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mt-4">
      <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
        <div>
          <h2 class="text-lg font-semibold">{{ t('accounting.payroll.employees.title') }}</h2>
          <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.payroll.employees.subtitle') }}</p>
        </div>
        <button v-if="canWrite" @click="openNewEmployee" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.payroll.employees.new') }}
        </button>
      </div>

      <!-- Bez prázdného stavu: panel má vlastní hlavičku s popisem i tlačítkem
           „Nový zaměstnanec" pár desítek pixelů nad sebou, takže stav jen
           potřetí opakoval totéž a tlačítko zdvojoval. -->
      <div v-if="employeesLoading" class="p-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="employees.length > 0" class="border border-neutral-200 rounded-md overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500">
            <tr>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.payroll.employees.col_name') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.payroll.employees.col_type') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.payroll.employees.col_credits') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.payroll.employees.col_monthly_gross') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.payroll.employees.col_active') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in employees" :key="e.id" class="border-t border-neutral-100" :class="{ 'opacity-50': !e.is_active }">
              <td class="px-3 py-2">
                <div class="font-medium text-neutral-700">{{ e.full_name }}</div>
                <div class="text-xs text-neutral-500">{{ e.birth_date || '—' }}</div>
              </td>
              <td class="px-3 py-2 text-xs text-neutral-600">
                <div>{{ t(`accounting.payroll.type.${e.taxpayer_type}`) }}</div>
                <div class="text-neutral-500">{{ t(`accounting.payroll.employment.${e.employment_type ?? 'hpp'}`) }}</div>
              </td>
              <td class="px-3 py-2 text-xs text-neutral-600">
                <div v-if="e.tax_credit_taxpayer">{{ t('accounting.payroll.employees.credit_taxpayer') }}</div>
                <div v-if="e.child_count > 0">{{ t('accounting.payroll.employees.credit_children', { count: e.child_count }) }}</div>
              </td>
              <td class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">
                <div v-if="e.monthly_gross !== null" class="font-mono">{{ formatMoney(e.monthly_gross) }}</div>
                <div v-else class="text-neutral-400">—</div>
                <span v-if="e.auto_post" class="inline-block mt-0.5 px-1.5 py-0.5 rounded bg-primary-50 text-primary-600 text-[10px] leading-none">
                  {{ t('accounting.payroll.employees.auto_post_badge') }}
                </span>
              </td>
              <td class="px-3 py-2 text-xs">
                <span class="px-2 py-0.5 rounded-full font-medium" :class="e.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ e.is_active ? t('accounting.expense_rules.active_yes') : t('accounting.expense_rules.active_no') }}
                </span>
              </td>
              <td class="px-3 py-2">
                <div v-if="canWrite" class="flex items-center justify-end gap-1">
                  <button @click="openEditEmployee(e)" :class="btnOutlineSm('primary')" :title="t('common.edit')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button @click="removeEmployee(e)" :class="btnOutlineSm('danger')" :title="t('common.delete')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Mzdový list (§38j ZDP) — roční PDF evidence za zaměstnance -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mt-4">
      <h2 class="text-lg font-semibold">{{ t('accounting.payroll.sheet.title') }}</h2>
      <p class="text-sm text-neutral-500 mt-0.5 mb-3">{{ t('accounting.payroll.sheet.subtitle') }}</p>
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.employee') }}</label>
          <select v-model="sheetEmployeeId" class="w-56 h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option :value="null">{{ t('accounting.payroll.employee_none') }}</option>
            <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.year') }}</label>
          <select v-model.number="sheetYear" class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <button :disabled="!sheetEmployeeId || sheetDownloading" @click="downloadPayrollSheet" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ sheetDownloading ? t('common.loading') : t('accounting.payroll.sheet.download') }}
        </button>
      </div>
    </div>

    <!-- Modal: zaměstnanec -->
    <Modal v-if="showEmployeeForm" :title="editingEmployeeId === null ? t('accounting.payroll.employees.new') : t('accounting.payroll.employees.edit')" @close="showEmployeeForm = false">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.employees.form_name') }} *</label>
          <input v-model="employeeForm.full_name" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.employees.form_birth_date') }}</label>
          <input v-model="employeeForm.birth_date" type="date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.taxpayer_type') }}</label>
          <select v-model="employeeForm.taxpayer_type" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="employee">{{ t('accounting.payroll.type.employee') }}</option>
            <option value="managing_partner">{{ t('accounting.payroll.type.managing_partner') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.employees.form_employment_type') }}</label>
          <select :value="employeeForm.employment_type" @change="onEmploymentTypeChange"
                  class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="e in EMPLOYMENT_TYPES" :key="e" :value="e">
              {{ t(`accounting.payroll.employment.${e}`) }}
            </option>
          </select>
          <p v-if="taxpayerTypeMismatch" class="text-xs text-warning-600 mt-1">
            {{ t('accounting.payroll.employees.form_statutory_body_mismatch') }}
          </p>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.employees.form_child_count') }}</label>
          <input v-model.number="employeeForm.child_count" type="number" min="0" max="20" step="1"
                 class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.employees.form_monthly_gross') }}</label>
          <input v-model.number="employeeForm.monthly_gross" type="number" min="0" step="1" inputmode="numeric"
                 class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm text-right font-mono bg-surface" />
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.payroll.employees.form_net_settlement') }}</label>
          <select v-model="employeeForm.net_settlement_account_code"
                  class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option :value="null">{{ t('accounting.payroll.employees.form_net_settlement_none') }}</option>
            <option v-for="a in settlementAccountOptions" :key="a.account_code" :value="a.account_code">
              {{ a.account_code }} — {{ a.name }}
            </option>
          </select>
          <p class="text-xs text-neutral-500">{{ t('accounting.payroll.employees.form_net_settlement_hint') }}</p>
        </div>
        <div class="sm:col-span-2">
          <label class="flex items-center gap-2 text-sm text-neutral-700 h-9"
                 :class="autoPostAvailable ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'">
            <input v-model="employeeForm.auto_post" type="checkbox" :disabled="!autoPostAvailable"
                   class="rounded border-neutral-300 disabled:opacity-50" />
            {{ t('accounting.payroll.employees.form_auto_post') }}
          </label>
          <p class="text-xs" :class="autoPostAvailable ? 'text-neutral-500' : 'text-warning-600'">
            {{ autoPostAvailable
                ? t('accounting.payroll.employees.form_auto_post_hint')
                : t('accounting.payroll.employees.form_auto_post_needs_gross') }}
          </p>
        </div>
        <div class="sm:col-span-2 flex items-center gap-4">
          <label class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer h-9">
            <input v-model="employeeForm.tax_credit_taxpayer" type="checkbox" class="rounded border-neutral-300" />
            {{ t('accounting.payroll.employees.form_credit_taxpayer') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer h-9">
            <input v-model="employeeForm.tax_declaration_signed" type="checkbox" class="rounded border-neutral-300" />
            {{ t('accounting.payroll.employees.form_declaration_signed') }}
          </label>
          <label class="flex items-center gap-2 text-sm text-neutral-700 cursor-pointer h-9">
            <input v-model="employeeForm.is_active" type="checkbox" class="rounded border-neutral-300" />
            {{ t('accounting.payroll.employees.form_active') }}
          </label>
        </div>
        <p class="sm:col-span-2 text-xs text-neutral-500 -mt-2">
          {{ t('accounting.payroll.employees.form_declaration_hint') }}
        </p>
      </div>

      <div class="flex justify-end gap-2 mt-4">
        <button @click="showEmployeeForm = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button :disabled="savingEmployee" @click="saveEmployee" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
      </div>
    </Modal>
  </div>
</template>

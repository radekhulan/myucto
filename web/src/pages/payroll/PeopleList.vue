<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollEmployment,
  type PayrollEmploymentCreatePayload,
  type PayrollOffice,
  type PayrollPeopleFilter,
  type PayrollEmploymentStatus,
  type PayrollPerson,
  type PayrollPersonCreatePayload,
  type PayrollPersonEmploymentRef,
  type PayrollPersonListItem,
  type PayrollPersonProfile,
  type PayrollPersonSetupGap,
  type PayrollPersonQuickEditResponse,
  type PayrollRelationType,
} from '@/api/payroll'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import Modal from '@/components/ui/Modal.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { loadDefaultHealthInsurerCode } from '@/composables/usePayrollDefaultInsurer'
import { loadPayrollOffices } from '@/composables/usePayrollOffices'
import { useToast } from '@/composables/useToast'
import { healthInsurerOptions } from '@/utils/healthInsurers'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useAuthStore } from '@/stores/auth'
import EmploymentCard from './EmploymentCard.vue'
import PayrollPersonQuickEdit from './PayrollPersonQuickEdit.vue'
import PayrollPersonProfilePanel from './PayrollPersonProfilePanel.vue'
import PayrollPersonDependantsPanel from './PayrollPersonDependantsPanel.vue'
import PayrollPersonStatutoryEvidencePanel from './PayrollPersonStatutoryEvidencePanel.vue'
import PayrollPersonForeignPermitPanel from './PayrollPersonForeignPermitPanel.vue'
import { todayIso } from './employmentLifecycleUi'
import {
  payrollAgendaLabelKey,
  payrollAgendas,
  type PayrollAgendaDefinition,
} from './payrollAgendaLinks'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const loading = ref(true)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
const people = ref<PayrollPersonListItem[]>([])
/*
 * Zúžení i stránkování dělá server. Kdyby zužoval prohlížeč, hledal by jen ve
 * stránce, kterou má právě načtenou, a o člověku ze třetí stránky by tvrdil,
 * že neexistuje.
 */
const pageSize = 25
const offset = ref(0)
const total = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
/*
 * „Firma nikoho nemá" a „zúžení nikoho nenašlo" jsou dvě různé zprávy, ale
 * server na obojí vrací nulu. Rozhoduje se proto zvlášť — viz `load()`.
 */
const hasAnyPeople = ref(false)
const expandedId = ref<number | null>(null)
const details = ref<Record<number, PayrollPerson>>({})
const loadingDetailId = ref<number | null>(null)
const searchQuery = ref('')
const peopleFilter = ref<PayrollPeopleFilter>('active')
const narrowed = computed(
  () => peopleFilter.value !== 'all' || searchQuery.value.trim() !== '',
)
const showEmployeeForm = ref(false)
const savingEmployee = ref(false)
const employeeError = ref('')
const createdEmployeeId = ref<number | null>(null)
const creatingForId = ref<number | null>(null)
const savingNew = ref(false)
const newEmployment = ref<PayrollEmploymentCreatePayload | null>(null)
const newEmploymentMonthlyGross = ref<number | null>(null)
const newEmploymentError = ref('')
const advancedProfileOpen = ref(false)
const deletingPerson = ref(false)
const canCreatePerson = computed(() => auth.canWrite('payroll.person.write'))
const canQuickEditPerson = computed(() =>
  auth.canWrite('payroll.person.write')
  && auth.canWrite('payroll.employment.write'),
)
const relationTypes: PayrollRelationType[] = [
  'employment',
  'small_scale_employment',
  'dpp',
  'dpc',
  'partner_dependent',
  'statutory_body',
]
const filterOptions = computed(() => [
  { value: 'active' as const, label: t('payroll.people.filters.active') },
  { value: 'all' as const, label: t('payroll.people.filters.all') },
  { value: 'needs_setup' as const, label: t('payroll.people.filters.needs_setup') },
])
const relationOptions = computed(() => relationTypes.map(type => ({
  value: type,
  label: relationLabel(type),
})))
/**
 * Mzdové účtárny pro výběr u nového vztahu.
 *
 * Nabízejí se jen firmě, která má víc než jednu aktivní účtárnu — jinak by to
 * bylo pole s jedinou možností, kterou stejně dosadí server (výchozí účtárna
 * zaměstnavatele). Firmě s víc účtárnami je naopak bez výběru nutil první vztah
 * na výchozí a opravit se to dalo až novou verzí podmínek.
 */
const offices = ref<PayrollOffice[]>([])
const officeOptions = computed(() => (offices.value.length > 1
  ? offices.value.map(office => ({
    value: office.id,
    label: office.name,
    secondary: office.code,
  }))
  : []))
function officeOption(id: number | null | undefined) {
  return officeOptions.value.find(option => option.value === id) ?? null
}
const selectedOfficeOption = computed(
  () => officeOption(newEmployment.value?.terms.office_id),
)
/**
 * Zakládání zaměstnance je JEDEN formulář, ne dva kroky.
 *
 * Nahoře je minimum, které server vyžaduje (jméno, druh vztahu, nástup);
 * ve sbalitelné části „Další údaje" je zbytek, který dřív šlo doplnit až
 * po založení na kartě: rodné číslo, datum narození, mzda, úvazek, mzdová
 * účtárna a zdravotní pojišťovna. Nic z toho uložení neblokuje.
 */
const employeeForm = reactive({
  full_name: '',
  birth_date: '',
  birth_number: '',
  relation_type: 'employment' as PayrollRelationType,
  planned_start_on: todayIso(),
  monthly_gross: null as number | null,
  weekly_hours: '40.00',
  office_id: null as number | null,
  health_insurer_code: '',
})
const insurerOptions = healthInsurerOptions()
const selectedNewEmployeeOffice = computed(() => officeOption(employeeForm.office_id))
/**
 * Editace osoby je vlastní POHLED, ne panel nad seznamem.
 *
 * Dřív zůstal seznam pod formulářem viditelný, takže vedle upravované osoby
 * svítily i ostatní a nebylo poznat, koho se editace týká. Seznam se proto během
 * editace schová a nahoře je vidět, koho upravuji.
 *
 * Zůstáváme u jedné komponenty a jen přepínáme pohled (místo vlastní routy
 * `/payroll/people/:id`): detail osoby je poskládaný ze čtyř panelů, které si
 * navzájem předávají stav (`updateQuickEdit`, `updateEmployment`, `startCreate`),
 * a jejich rozpojení do samostatné routy by znamenalo přepsat půlku komponenty.
 * Adresa přesto zůstává sdílitelná: výběr se zrcadlí do `?person=<id>`, takže
 * odkaz i tlačítko Zpět v prohlížeči fungují.
 */
const selectedDetail = computed<PayrollPerson | null>(
  () => (expandedId.value === null ? null : details.value[expandedId.value] ?? null),
)
/*
 * Řádek stránky je čerstvější, ale osoba otevřená z deep-linku na stránce být
 * nemusí — pak platí načtený detail. Bez toho by u ní chybělo rozhodnutí
 * o smazatelnosti a nabídka akcí by byla chudší jen kvůli tomu, odkud se
 * obrazovka otevřela.
 */
const selectedPerson = computed<PayrollPersonListItem | null>(
  () => people.value.find(item => item.id === expandedId.value) ?? selectedDetail.value,
)
const editing = computed(() => expandedId.value !== null)

const COLUMNS: ColumnDef[] = [
  { key: 'person', labelKey: 'payroll.people.columns.person', required: true },
  { key: 'status', labelKey: 'payroll.people.columns.status' },
  { key: 'relations', labelKey: 'payroll.people.columns.relations' },
  { key: 'count', labelKey: 'payroll.people.columns.count' },
  { key: 'detail', labelKey: 'payroll.people.columns.detail', required: true },
]
const tbl = useTablePrefs('payroll-people', COLUMNS)

/**
 * Hlavička bere přednostně načtený detail — je čerstvější než řádek seznamu.
 * Jméno je ZOBRAZOVANÉ (`full_name`), ne strukturované: osoba může mít vyplněné
 * jen celé jméno a pole Jméno/Příjmení prázdná, a hlavička by pak byla anonymní.
 */
const selectedSummary = computed<PayrollPersonListItem | null>(
  () => selectedDetail.value ?? selectedPerson.value,
)
const selectedName = computed(() => selectedSummary.value?.full_name ?? '')
const selectedEmploymentCount = computed(
  () => selectedDetail.value?.employments.length
    ?? selectedPerson.value?.employment_count
    ?? 0,
)

function backToList() {
  expandedId.value = null
  advancedProfileOpen.value = false
  creatingForId.value = null
  newEmployment.value = null
}

/*
 * Výběr se zrcadlí do adresy, aby šel poslat a uložit do záložek. Hledání ani
 * filtr se přitom nikde neresetují — návrat zpět je proto zachová.
 *
 * Otevření detailu MUSÍ být `push`, zavření `replace`. Dřív se obojí dělalo
 * `replace`em a otevření tím přepsalo položku historie se seznamem: v historii
 * po seznamu nezůstalo `/payroll/people`, ale rovnou `/payroll/people?person=1`,
 * takže tlačítko Zpět skočilo o krok dál — z detailu rovnou na `/payroll`.
 *
 * Zavření naopak `replace` zůstává: kdyby i ono pushovalo, vrátilo by Zpět ze
 * seznamu zpátky do detailu, který uživatel právě zavřel. Přepnutí mezi dvěma
 * osobami je skutečná navigace mezi dvěma obrazovkami, proto taky `push`;
 * řetěz to nenafoukne, protože při otevřeném detailu není seznam vidět a další
 * osoba se dá vybrat jen cíleným odkazem.
 *
 * Deep-link `?person=1` se do historie nepřidává podruhé — adresa už tu hodnotu
 * nese, takže se hlídač vrátí hned na prvním řádku.
 */
watch(expandedId, (value) => {
  const person = value === null ? undefined : String(value)
  if ((route.query.person ?? undefined) === person) return
  const target = { query: { ...route.query, person } }
  void (value === null ? router.replace(target) : router.push(target))
})

const personDeleteCascade = computed<string>(() => {
  const cascade = selectedPerson.value?.delete_cascade ?? {}
  return Object.entries(cascade)
    .filter(([, count]) => count > 0)
    .map(([key, count]) => t(`payroll.people.delete.person_cascade.${key}`, { count }, count))
    .join(', ')
})

const personDeleteBlocker = computed(() => selectedPerson.value?.delete_blocker ?? null)

/**
 * Smazání osoby se potvrzuje dál — vratné to není. Odchází s ní i všechno
 * navázané (proto `person_cascade`) a znovu založená osoba by měla nové id,
 * takže by se na ni nenapojily ani doklady, ani mzdové snímky. Dialog už
 * pojmenovává osobu i to, co s ní odejde, takže tady zůstává beze změny.
 */
async function removePerson() {
  const person = selectedPerson.value
  if (!person || deletingPerson.value || !person.can_delete) return
  const summary = personDeleteCascade.value
  const question = summary === ''
    ? t('payroll.people.delete.person_confirm_empty', { name: selectedName.value })
    : t('payroll.people.delete.person_confirm', { name: selectedName.value, summary })
  if (!window.confirm(question)) return

  deletingPerson.value = true
  try {
    await payrollApi.deletePerson(person.id)
    backToList()
    delete details.value[person.id]
    people.value = people.value.filter(item => item.id !== person.id)
    // Bez tohohle by pager dál počítal se smazanou osobou a nabídl stránku navíc.
    total.value = Math.max(0, total.value - 1)
    // Prázdno pod zúžením ještě neznamená, že firma nikoho nemá.
    if (!narrowed.value) hasAnyPeople.value = total.value > 0
    toast.success(t('payroll.people.delete.person_done'))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.mutation_failed')))
  } finally {
    deletingPerson.value = false
  }
}

const personActions = computed<ActionItem[]>(() => [
  {
    key: 'add-employment',
    label: t('payroll.people.add_employment'),
    icon: 'plus',
    tier: 'primary',
    variant: 'primary',
    show: auth.canWrite('payroll.employment.write') && expandedId.value !== null,
    run: () => { if (expandedId.value !== null) startCreate(expandedId.value) },
  },
  {
    // Nevratná akce patří do „…", ne mezi hlavní tlačítka.
    //
    // Když smazat nejde, akce se NESKRÝVÁ a ani nezešedne — důvod se řekne až
    // při pokusu. Trvalý banner nad kartou zabíral nejlepší místo stránky
    // vysvětlením něčeho, co uživatel zrovna nedělá.
    key: 'delete-person',
    label: t('payroll.people.delete.person_action'),
    icon: 'trash',
    tier: 'advanced',
    variant: 'danger',
    disabled: deletingPerson.value,
    show: canCreatePerson.value && selectedPerson.value !== null,
    run: () => {
      if (selectedPerson.value?.can_delete !== true) {
        toast.error(personDeleteBlocker.value?.message ?? t('payroll.people.delete.blocked_title'))
        return
      }
      void removePerson()
    },
  },
])

/**
 * Rychlé akce v řádku seznamu.
 *
 * Why: účetní, která chce zapsat nepřítomnost, musela otevřít kartu, najít v ní
 * rozcestník agend a teprve odtud kliknout dál — tři kliky na jeden zápis, a to
 * u agendy, kterou dělá denně. Katalog agend
 * ({@link file://./payrollAgendaLinks.ts}) je přitom hotový, takže se jen
 * znovupoužije: nejčastější trojice (docházka, nepřítomnosti, mzdové vstupy)
 * je v řádku jako ikona, zbytek pod „Další agendy". Katalog zůstává JEDINÝM
 * zdrojem popisků, ikon i práv — seznam ani karta si nic nedrží stranou.
 *
 * Seznam kvůli tomu NEDĚLÁ žádný dotaz navíc: cíle vztahových agend nese
 * `employment_refs`, který jede v témže poddotazu jako `employment_count`.
 */
const quickAgendas = computed<PayrollAgendaDefinition[]>(
  // Stabilní řazení: nejčastější dopředu, jinak pořadí katalogu. Bez toho by
  // trojice v řádku byla náhodná podle toho, kam kdo agendu do katalogu vložil.
  () => [...payrollAgendas].sort(
    (left, right) => Number(right.quick ?? false) - Number(left.quick ?? false),
  ),
)

/**
 * Vztahy, do kterých má smysl něco zadávat. Archivovaný ani nenastoupivší vztah
 * není cíl — nabízet ho by znamenalo poslat účetní zadat docházku někam, kde ji
 * agenda stejně odmítne. Když osobě zbydou jen takové, nabídnou se přesto: lepší
 * je otevřít agendu, která řekne proč, než tvrdit, že vztah není žádný.
 */
const AGENDA_TARGET_STATUSES: readonly PayrollEmploymentStatus[] = [
  'planned',
  'preregistered',
  'active',
  'suspended',
  'ended',
]

function agendaEmployments(person: PayrollPersonListItem): PayrollPersonEmploymentRef[] {
  const refs = person.employment_refs ?? []
  const usable = refs.filter(item => AGENDA_TARGET_STATUSES.includes(item.status))
  return usable.length > 0 ? usable : refs
}

/*
 * Osoba s víc pracovními vztahy se musí zeptat, do kterého zápis patří — ale
 * jen ona. U jednoho vztahu (drtivá většina lidí) zůstává akce OBYČEJNÝM
 * odkazem, takže nestojí klik navíc a jde otevřít i na nové kartě prohlížeče.
 * Dialog se proto vrací jen tam, kde `to` chybí.
 */
function agendaTarget(person: PayrollPersonListItem, agenda: PayrollAgendaDefinition) {
  if (agenda.scope === 'person') return agenda.to(0, person.id)
  const targets = agendaEmployments(person)
  return targets.length === 1 ? agenda.to(targets[0]!.id, person.id) : null
}

const agendaPicker = ref<{ person: PayrollPersonListItem; agenda: PayrollAgendaDefinition } | null>(null)
const agendaPickerEmployments = computed(
  () => (agendaPicker.value === null ? [] : agendaEmployments(agendaPicker.value.person)),
)

function employmentStatusLabel(status: PayrollEmploymentStatus): string {
  return t(`payroll.people.employment_status.${status}`)
}

function employmentPickerLabel(employment: PayrollPersonEmploymentRef): string {
  const parts = [relationLabel(employment.relation_type)]
  if (employment.code !== '') parts.push(employment.code)
  parts.push(employmentStatusLabel(employment.status))
  return parts.join(' · ')
}

function chooseAgendaEmployment(employment: PayrollPersonEmploymentRef) {
  const picked = agendaPicker.value
  if (!picked) return
  agendaPicker.value = null
  void router.push(picked.agenda.to(employment.id, picked.person.id))
}

function quickActions(person: PayrollPersonListItem): RowAction[] {
  return quickAgendas.value.map((agenda) => {
    const allowed = auth.canRead(agenda.permission)
    const targets = agendaEmployments(person)
    const missingEmployment = agenda.scope === 'employment' && targets.length === 0
    const blocked = !allowed || missingEmployment
    const to = blocked ? undefined : agendaTarget(person, agenda) ?? undefined
    return {
      key: `agenda-${agenda.key}`,
      label: t(payrollAgendaLabelKey(agenda.key)),
      icon: agenda.icon,
      variant: agenda.variant,
      /*
       * Blokovaná akce se NESKRÝVÁ — mizející tlačítko nejde odlišit od tlačítka,
       * které tam nikdy nebylo, a uživatel pak hledá funkci, o které ví, že
       * existuje. Zůstane zašedlá i s větou proč; `RowActionsMenu` ji vypíše
       * viditelně, protože `title` na dotykovém displeji nic neřekne.
       */
      disabled: blocked,
      disabledReason: !allowed
        ? t('payroll.people.quick_actions.no_permission')
        : (missingEmployment ? t('payroll.people.quick_actions.no_employment') : undefined),
      to,
      run: blocked || to !== undefined
        ? undefined
        : () => { agendaPicker.value = { person, agenda } },
    } satisfies RowAction
  })
}

function removeEmploymentFromDetail(personId: number, employmentId: number) {
  const detail = details.value[personId]
  if (detail) {
    detail.employments = detail.employments.filter(item => item.id !== employmentId)
  }
  const listItem = people.value.find(item => item.id === personId)
  if (listItem && listItem.employment_count > 0) listItem.employment_count -= 1
}

function resetEmployeeForm() {
  employeeForm.full_name = ''
  employeeForm.birth_date = ''
  employeeForm.birth_number = ''
  employeeForm.relation_type = 'employment'
  employeeForm.planned_start_on = todayIso()
  employeeForm.monthly_gross = null
  employeeForm.weekly_hours = '40.00'
  employeeForm.office_id = null
  employeeForm.health_insurer_code = ''
  employeeError.value = ''
}

function openEmployeeForm() {
  if (!canCreatePerson.value) return
  resetEmployeeForm()
  createdEmployeeId.value = null
  showEmployeeForm.value = true
  // Účtárna i výchozí pojišťovna se drží v paměti aplikace — otevření formuláře
  // proto nestojí požadavek navíc a předvyplní se tím, co firma opravdu používá.
  void loadPayrollOffices().then((value) => {
    offices.value = value
    employeeForm.office_id = value.length === 1 ? value[0].id : null
  })
  void loadDefaultHealthInsurerCode().then((code) => {
    if (employeeForm.health_insurer_code === '') {
      employeeForm.health_insurer_code = code ?? ''
    }
  })
}

function closeEmployeeForm() {
  showEmployeeForm.value = false
  employeeError.value = ''
}

function relationLabel(type: PayrollRelationType): string {
  return t(`payroll.people.relations.${type}`)
}

function statusLabel(isActive: boolean): string {
  return t(isActive ? 'payroll.people.status.active' : 'payroll.people.status.inactive')
}

/**
 * Dva lidé se stejným jménem se v seznamu nedali rozlišit. Rozlišovač je osobní
 * číslo pracovního vztahu (`code`) — je jednoznačné, není citlivé a seznam ho
 * už vrací, takže nestojí ani řádek navíc v dotazu.
 *
 * Rodné číslo tu SCHVÁLNĚ není: seznam ho nevrací, doplnit by ho šlo jen
 * maskované (`value_masked`) a maska je po zúžení na dvě číslice (`••••••••89`)
 * jako rozlišovač stejně slabá — za cenu citlivého údaje na první obrazovce.
 *
 * U víc vztahů se vypíšou dva kódy a zbytek se shrne číslem, aby řádek
 * nenarostl; `+2` je bez gramatiky, proto nepotřebuje překlad.
 */
function personCodeLabel(person: PayrollPersonListItem): string {
  const refs = person.employment_refs
  if (refs.length === 0) return ''
  const ordered = [...refs].sort((a, b) => Number(b.is_primary) - Number(a.is_primary))
  const shown = ordered.slice(0, 2).map(item => item.code).join(', ')
  const rest = ordered.length - 2
  return rest > 0 ? `${shown} +${rest}` : shown
}

/**
 * Karta osoby má adresu (`?person=<id>`), takže řádek seznamu na ni míří
 * OPRAVDOVÝM odkazem — ne divem s posluchačem. Jen tak funguje prostřední klik,
 * Ctrl+klik i klávesnice.
 */
function personLink(person: PayrollPersonListItem) {
  return { query: { ...route.query, person: String(person.id) } }
}

/**
 * Jeden další krok místo obecného „Chybí". Pořadí kopíruje skutečný pracovní
 * postup: bez vztahu není co zpracovat, potom se doplní zákonná identita,
 * bydliště a nakonec kontakt. Server zůstává autoritou pro samotné mezery.
 */
const setupGapPriority: PayrollPersonSetupGap[] = [
  'employment',
  'name',
  'identifier',
  'residence',
  'contact',
]

function personNextStep(person: PayrollPersonListItem): PayrollPersonSetupGap | 'ready' {
  return setupGapPriority.find(gap => person.setup_gaps.includes(gap)) ?? 'ready'
}

function nextStepLabel(person: PayrollPersonListItem): string {
  return t(`payroll.people.next_step.${personNextStep(person)}`)
}

function nextStepActionLabel(person: PayrollPersonListItem): string {
  return t(`payroll.people.next_step.action.${personNextStep(person)}`)
}

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    const page = await payrollApi.peoplePage({
      limit: pageSize,
      offset: offset.value,
      filter: peopleFilter.value,
      q: searchQuery.value.trim(),
    })
    people.value = page.items
    total.value = page.total
    if (page.total > 0 || !narrowed.value) {
      hasAnyPeople.value = page.total > 0
    } else {
      // Prázdno po zúžení znamená obojí. Rozhodne JEDEN doplňkový dotaz na
      // nezúžený počet — a jen v tomhle vzácném případě.
      hasAnyPeople.value = (await payrollApi.peoplePage({
        limit: 1,
        offset: 0,
        filter: 'all',
      })).total > 0
    }
    pruneDetails()
  } catch {
    // `people` se schválně nevynuluje — poslední známý seznam je pořád lepší
    // informace než prázdno, které by vypadalo jako „firma nemá zaměstnance".
    loadFailed.value = true
    toast.error(t('payroll.people.load_failed'))
  } finally {
    loading.value = false
  }
}

/*
 * Detail se drží jen k řádkům, které jsou na stránce, plus k právě otevřené
 * osobě. Jinak by po přestránkování zůstal v paměti někdo, koho seznam už
 * nezobrazuje, a rozbalený řádek by ukazoval mimo obrazovku.
 */
function pruneDetails() {
  const visible = new Set(people.value.map(item => item.id))
  for (const key of Object.keys(details.value)) {
    const id = Number(key)
    if (!visible.has(id) && id !== expandedId.value) delete details.value[id]
  }
}

/*
 * Jiné zúžení = jiná množina osob; zůstat na třetí stránce by ukázalo prázdno.
 * Rozbalený řádek se přitom zavírá — patřil předchozímu výběru a nad novým
 * seznamem by visel jako řádek, který v něm nejde najít.
 */
function reloadFromFirstPage() {
  offset.value = 0
  backToList()
  void load()
}

// Stránkuje sdílená `PaginationBar` (číslo stránky od jedné); server zná offset.
function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  backToList()
  void load()
}

/*
 * Filtr a hledání teď stojí požadavek, takže se hledání nesmí posílat na každé
 * písmeno. Vlastní `setTimeout` proto, že sdílený pomocník v projektu není —
 * stejný vzor používá i seznam bankovních výpisů.
 */
const SEARCH_DEBOUNCE_MS = 300
let searchTimer: ReturnType<typeof setTimeout> | undefined
// Když zúžení přestaví kód (po založení osoby), načte si stránku sám; hlídač se
// odmlčí, aby nenačítal podruhé a nezavřel právě otevřenou osobu.
let suppressNarrowingReload = false

watch(peopleFilter, () => {
  if (suppressNarrowingReload) return
  reloadFromFirstPage()
})

watch(searchQuery, () => {
  if (searchTimer !== undefined) clearTimeout(searchTimer)
  if (suppressNarrowingReload) return
  searchTimer = setTimeout(reloadFromFirstPage, SEARCH_DEBOUNCE_MS)
})

async function toggleDetail(person: PayrollPersonListItem) {
  if (expandedId.value === person.id) {
    expandedId.value = null
    advancedProfileOpen.value = false
    return
  }

  expandedId.value = person.id
  advancedProfileOpen.value = false
  if (details.value[person.id]) return

  loadingDetailId.value = person.id
  try {
    details.value[person.id] = await payrollApi.person(person.id)
  } catch {
    expandedId.value = null
    toast.error(t('payroll.people.detail_load_failed'))
  } finally {
    loadingDetailId.value = null
  }
}

function startCreate(personId: number) {
  const start = todayIso()
  creatingForId.value = personId
  // Nabídka se drží v paměti aplikace, takže tohle nestojí požadavek navíc.
  void loadPayrollOffices().then((value) => { offices.value = value })
  newEmploymentMonthlyGross.value = null
  newEmploymentError.value = ''
  newEmployment.value = employmentDraft(
    '',
    'employment',
    start,
    null,
    details.value[personId]?.employments.every(item => !item.is_primary) ?? true,
  )
}

function employmentDraft(
  code: string,
  relationType: PayrollRelationType,
  start: string,
  monthlyGrossMinor: number | null,
  isPrimary: boolean,
): PayrollEmploymentCreatePayload {
  return {
    code,
    relation_type: relationType,
    meal_entitlement_basis: 'shift',
    monthly_gross_minor: monthlyGrossMinor,
    terms: {
      office_id: null,
      effective_from: start,
      contract_signed_on: null,
      planned_start_on: start,
      actual_start_on: null,
      fixed_term_end_on: null,
      weekly_hours: '40.00',
      workload_basis_points: 10000,
      work_place: null,
      regular_workplace: null,
      jmhz_workplace_municipality_code: null,
      jmhz_workplace_country_code: null,
      jmhz_apz_contribution_status: 'unverified',
      jmhz_apz_instrument_code: null,
      jmhz_functional_benefits_status: 'unverified',
      jmhz_temporary_assignment_status: 'unverified',
      cz_isco_code: null,
      activity_code: null,
      jmhz_relationship_detail_code: null,
      social_insurance_participation: 'automatic',
      health_insurance_participation: 'automatic',
      tax_regime: 'advance',
      foreign_legislation_country_code: null,
      a1_certificate_until: null,
      risky_work: false,
      tax_declaration_signed: false,
      is_primary: isPrimary,
      change_reason: t('payroll.people.initial_terms'),
    },
  }
}

async function saveNew(personId: number) {
  if (!newEmployment.value || savingNew.value) return
  savingNew.value = true
  newEmploymentError.value = ''
  try {
    const employment = await payrollApi.createEmployment(personId, {
      ...newEmployment.value,
      monthly_gross_minor: Number(newEmploymentMonthlyGross.value) > 0
        ? Number(newEmploymentMonthlyGross.value) * 100
        : null,
    })
    const person = details.value[personId]
    if (person) person.employments.push(employment)
    const listItem = people.value.find(item => item.id === personId)
    if (listItem) {
      listItem.employment_count += 1
      if (!listItem.relation_types.includes(employment.relation_type)) {
        listItem.relation_types.push(employment.relation_type)
      }
      // Vztah zaplnil právě jednu mezeru; ostatní čtyři zná jen server.
      listItem.setup_gaps = listItem.setup_gaps.filter(gap => gap !== 'employment')
      listItem.needs_setup = listItem.setup_gaps.length > 0
    }
    creatingForId.value = null
    newEmployment.value = null
    toast.success(t('payroll.people.employment_created'))
  } catch (error) {
    newEmploymentError.value = apiErrorMessage(error, t('payroll.people.mutation_failed'))
    toast.error(newEmploymentError.value)
  } finally {
    savingNew.value = false
  }
}

function updateEmployment(personId: number, updated: PayrollEmployment) {
  const employments = details.value[personId]?.employments
  if (!employments) return
  const index = employments.findIndex(item => item.id === updated.id)
  if (index >= 0) employments[index] = updated
}

async function createEmployee() {
  if (savingEmployee.value) return
  const fullName = employeeForm.full_name.trim()
  if (!fullName) {
    employeeError.value = t('payroll.people.create.name_required')
    toast.error(employeeError.value)
    return
  }
  savingEmployee.value = true
  employeeError.value = ''
  const payload: PayrollPersonCreatePayload = {
    full_name: fullName,
    birth_date: employeeForm.birth_date || null,
    birth_number: employeeForm.birth_number.trim() || null,
    relation_type: employeeForm.relation_type,
    planned_start_on: employeeForm.planned_start_on,
    monthly_gross: Number(employeeForm.monthly_gross) > 0
      ? Number(employeeForm.monthly_gross)
      : null,
    office_id: employeeForm.office_id,
    weekly_hours: employeeForm.weekly_hours.trim() || null,
    /*
     * Pojišťovna je zákonná evidence osoby, ne sloupec karty — zapisuje ji ale
     * TENTÝŽ požadavek, a to v jedné transakci se zaměstnancem. Druhé volání by
     * po svém selhání nechalo osobu bez zákonem vyžadované evidence.
     */
    health_insurer_code: employeeForm.health_insurer_code.trim() || null,
  }
  try {
    const created = await payrollApi.createPerson(payload)
    showEmployeeForm.value = false
    // Nová osoba musí být vidět bez ohledu na to, co bylo v hledání a filtru —
    // zúžení se proto srovná JEŠTĚ před načtením, ne po něm.
    suppressNarrowingReload = true
    peopleFilter.value = 'all'
    searchQuery.value = ''
    offset.value = 0
    await nextTick()
    suppressNarrowingReload = false
    await load()
    createdEmployeeId.value = created.id
    details.value[created.id] = created
    expandedId.value = created.id
    toast.success(t('payroll.people.create.created'))
  } catch (error) {
    employeeError.value = apiErrorMessage(
      error,
      t('payroll.people.create.failed'),
    )
    toast.error(employeeError.value)
  } finally {
    savingEmployee.value = false
  }
}

/**
 * Optimistické přepočítání mezer po uložení profilu. Autoritou zůstává server
 * (`setup_gaps` v seznamu) — tohle jen srovná štítek hned po uložení, aby po
 * doplnění adresy nesvítil dál až do dalšího načtení seznamu.
 *
 * Mezera `employment` se tu nepočítá: profil o pracovních vztazích nic neví,
 * takže se přebírá z dosavadní hodnoty.
 */
function profileSetupGaps(
  updated: PayrollPersonProfile,
  previous: PayrollPersonSetupGap[],
): PayrollPersonSetupGap[] {
  const today = todayIso()
  const effective = (from: string, to: string | null): boolean =>
    from <= today && (to === null || to >= today)

  const gaps: PayrollPersonSetupGap[] = []
  if (!updated.identity_history.some(row =>
    (row.first_name ?? '') !== '' && (row.last_name ?? '') !== ''
    && effective(row.effective_from, row.effective_to),
  )) gaps.push('name')
  if (!updated.addresses.some(row =>
    row.address_type === 'residence' && effective(row.effective_from, row.effective_to),
  )) gaps.push('residence')
  if (!updated.contacts.some(row => row.is_active && row.is_primary)) gaps.push('contact')
  if (!updated.identifiers.some(row =>
    ['birth_number', 'ecp', 'vcp'].includes(row.identifier_type),
  )) gaps.push('identifier')
  if (previous.includes('employment')) gaps.push('employment')

  return gaps
}

function updatePersonProfile(updated: PayrollPersonProfile) {
  const person = people.value.find(item => item.id === updated.employee_id)
  const detail = details.value[updated.employee_id]
  const gaps = profileSetupGaps(updated, person?.setup_gaps ?? detail?.setup_gaps ?? [])
  if (person) {
    person.full_name = updated.full_name
    person.profile_status = updated.profile_status
    person.setup_gaps = gaps
    person.needs_setup = gaps.length > 0
  }
  if (detail) {
    detail.full_name = updated.full_name
    detail.profile_status = updated.profile_status
    detail.setup_gaps = gaps
    detail.needs_setup = gaps.length > 0
  }
}

function updateQuickEdit(result: PayrollPersonQuickEditResponse) {
  updatePersonProfile(result.profile)
  if (result.employment) {
    updateEmployment(result.profile.employee_id, result.employment)
  }
}

function toggleAdvancedProfile(event: Event) {
  advancedProfileOpen.value = (event.currentTarget as HTMLDetailsElement).open
}

/**
 * Deep-link na člověka (`/payroll/people?person=12`) — z karty zaměstnance
 * na přehledu mezd. Bez toho vede „karta zaměstnance" jen na seznam a uživatel
 * v něm musí jméno znovu najít.
 *
 * Osoba nemusí být na načtené stránce — může být neaktivní, nebo až na páté.
 * Detail se proto dotahuje PŘÍMO podle id; prolistovat kvůli jednomu odkazu
 * celý seznam by stálo tolik požadavků, kolik má firma stránek. Neznámé ani
 * cizí id nic neotevře.
 */
async function openFromQuery() {
  const employmentRaw = Array.isArray(route.query.employment)
    ? route.query.employment[0]
    : route.query.employment
  let raw = Array.isArray(route.query.person) ? route.query.person[0] : route.query.person
  if ((typeof raw !== 'string' || raw === '')
    && typeof employmentRaw === 'string' && employmentRaw !== ''
  ) {
    const employmentId = Number(employmentRaw)
    if (!Number.isInteger(employmentId) || employmentId <= 0) return
    try {
      const summary = await payrollApi.employmentAgendaSummary(employmentId)
      raw = String(summary.employee_id)
    } catch {
      return
    }
  }
  if (typeof raw !== 'string' || raw === '') return
  const id = Number(raw)
  if (!Number.isInteger(id) || id <= 0) return
  const person = people.value.find(item => item.id === id)
  if (person) {
    await toggleDetail(person)
    return
  }

  loadingDetailId.value = id
  try {
    details.value[id] = await payrollApi.person(id)
    advancedProfileOpen.value = false
    expandedId.value = id
  } catch {
    // Odkaz na neznámou osobu je slepý, ne rozbitý — seznam zůstane, jak byl.
  } finally {
    loadingDetailId.value = null
  }
}

/**
 * Zákonná evidence a vyživované osoby nejsou stránka, ale panel karty osoby —
 * ze seznamu k nim proto vede `?person=<id>&panel=<klíč>`. Bez tohohle by
 * rychlá akce doručila člověka na kartu a on by panel hledal očima; u
 * vyživovaných osob dokonce pod sbaleným „Další údaje", takže by ho nenašel.
 *
 * Parametr se po použití z adresy odstraní: je to jednorázový povel, ne stav
 * obrazovky, a při návratu Zpět by panel bez varování odscrolloval znovu.
 *
 * Úklid adresy musí proběhnout PŘED odscrollováním. `router.replace` je
 * plnohodnotná navigace, takže na ni sáhne `scrollBehavior` routeru — a ten by
 * scroll na panel vzápětí zase smázl skokem na začátek stránky. Proto ho
 * `scrollBehavior` u odebrání povelu `?panel=` vynechává (viz router).
 */
async function focusPanel(panel: string) {
  if (panel !== 'statutory_evidence' && panel !== 'dependants') return
  if (panel === 'dependants') advancedProfileOpen.value = true
  const query = { ...route.query }
  delete query.panel
  await router.replace({ query })
  await nextTick()
  const target = document.querySelector(`[data-panel-anchor="${panel}"]`)
  if (target === null) return
  // Odrolovat na sbalený panel je totéž jako neudělat nic — kdo si zákonnou
  // evidenci předtím zavřel, dostal by prázdnou hlavičku a hledal by dál.
  target.querySelector('details')?.setAttribute('open', '')
  const before = window.scrollY
  target.scrollIntoView({ behavior: 'smooth', block: 'start' })
  /*
   * Plynulý scroll umí prohlížeč vypnout (Chrome „Smooth Scrolling") a pak
   * `scrollIntoView` s `behavior: 'smooth'` NEUDĚLÁ NIC — ověřeno: `smooth`
   * skončil na 0 px, `auto` na cíli. Povel by tak u části uživatelů tiše
   * vyzněl naprázdno: panel by se rozbalil, ale stránka by zůstala nahoře.
   * Když se tedy nic nepohnulo, doskočí se natvrdo. (Cíl už nahoře = scroll
   * nemá kam jít; druhé volání je pak neškodné nic.)
   */
  window.setTimeout(() => {
    if (window.scrollY === before) target.scrollIntoView({ block: 'start' })
  }, 300)
}

/**
 * Zakládání zaměstnance nemá vlastní routu — formulář otevírá tlačítko na téhle
 * stránce. Aby na něj šlo odkázat zvenčí (globální „+" v hlavičce, klávesová
 * zkratka), přijímá stránka povel `?new=1`.
 *
 * Povel se z adresy hned uklidí: je jednorázový, ne stav obrazovky. Bez úklidu
 * by tlačítko Zpět nebo obnovení stránky formulář otevřely znovu a přepsaly
 * rozepsaná data prázdným.
 *
 * Úklid musí proběhnout PŘED otevřením formuláře — `router.replace` je
 * plnohodnotná navigace, takže na ni sáhne `scrollBehavior` routeru; ten má pro
 * odebrání jednorázového povelu výjimku (viz router), aby stránkou necuknul.
 */
async function openFromCreateCommand() {
  const raw = Array.isArray(route.query.new) ? route.query.new[0] : route.query.new
  if (raw !== '1') return
  const query = { ...route.query }
  delete query.new
  await router.replace({ query })
  openEmployeeForm()
}

/*
 * Rychlá akce ze seznamu míří na TUTÉŽ routu, jen s jinými parametry — Vue
 * Router proto komponentu nepřemontuje a `onMounted` už znovu neproběhne. Bez
 * tohohle hlídače by kliknutí na „Zákonná evidence" jen tiše přepsalo adresu
 * a na obrazovce by se nestalo nic. Totéž platí pro „+ → Nový zaměstnanec",
 * když už na seznamu stojím.
 */
watch(() => [route.query.person, route.query.panel, route.query.new] as const, async ([person, panel, isNew]) => {
  const raw = Array.isArray(person) ? person[0] : person
  const id = typeof raw === 'string' && raw !== '' ? Number(raw) : null
  if (id !== null && Number.isInteger(id) && id > 0 && id !== expandedId.value) {
    await openFromQuery()
  }
  const requested = Array.isArray(panel) ? panel[0] : panel
  if (typeof requested === 'string' && requested !== '') await focusPanel(requested)
  if (isNew !== undefined) await openFromCreateCommand()
})

/**
 * Od kdy firma vede mzdy v MyÚčtu. Karta vztahu z toho pozná zaměstnance, který
 * nastoupil dřív — takový potřebuje počáteční stavy, jinak jeho mzda nespočítá.
 * Výpadek nesmí shodit seznam, proto tichý fallback.
 */
const payrollStartPeriod = ref<string | null>(null)

onMounted(async () => {
  await load()
  await openFromQuery()
  const panel = Array.isArray(route.query.panel) ? route.query.panel[0] : route.query.panel
  if (typeof panel === 'string' && panel !== '') await focusPanel(panel)
  await openFromCreateCommand()
  payrollStartPeriod.value = await payrollApi.capabilities()
    .then(data => data.state.start_period)
    .catch(() => null)
})
</script>

<template>
  <div class="space-y-6">
    <!--
      Zakládání zaměstnance schová seznam stejně jako editace osoby.
      Formulář se sice kreslil nad seznamem, ale „Přidat zaměstnance" je
      i uvnitř prázdného stavu dole — kdo klikl tam, zůstal odscrollovaný
      u seznamu a formulář nahoře vůbec neviděl.
    -->
    <header v-if="!editing && !showEmployeeForm" class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.people.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.people.subtitle') }}</p>
      </div>
      <button
        type="button"
        :class="btnFilled('primary')"
        :disabled="!canCreatePerson"
        :title="canCreatePerson ? undefined : t('payroll.people.create.permission_required')"
        data-test="add-employee"
        @click="openEmployeeForm"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
        {{ t('payroll.people.create.action') }}
      </button>
    </header>

    <form
      v-if="showEmployeeForm && !editing"
      class="rounded-xl border border-payroll-500/30 bg-surface p-4 shadow-sm sm:p-5"
      data-test="new-employee-form"
      @submit.prevent="createEmployee"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.create.title') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.create.subtitle') }}</p>
          <!--
            Server při zakládání vyžaduje jen jméno, druh vztahu a datum
            nástupu (`PayrollPersonCreateValidator`). Rodné číslo, datum
            narození ani mzda povinné nejsou — a formulář to teď říká rovnou,
            místo aby to uživatel zkoušel.
          -->
          <p class="mt-1 text-xs text-neutral-500" data-test="new-employee-required-hint">{{ t('payroll.people.create.required_hint') }}</p>
        </div>
        <button type="button" :class="btnOutline('neutral')" @click="closeEmployeeForm">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
      <div class="mt-4 grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label class="min-w-0 text-xs text-neutral-600 sm:col-span-2">
          {{ t('payroll.people.create.full_name') }} <RequiredMark />
          <input v-model="employeeForm.full_name" required class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="new-employee-name">
        </label>
        <label class="min-w-0 text-xs text-neutral-600">
          {{ t('payroll.people.create.relation_type') }} <RequiredMark />
          <SearchableSelect
            v-model="employeeForm.relation_type"
            class="mt-1"
            :options="relationOptions"
            :clearable="false"
            accent="payroll"
            data-test="new-employee-relation"
          />
        </label>
        <label class="min-w-0 text-xs text-neutral-600">
          {{ t('payroll.people.create.planned_start') }} <RequiredMark />
          <input v-model="employeeForm.planned_start_on" required type="date" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="new-employee-planned-start">
        </label>
      </div>

      <!--
        Detaily, které se dřív doplňovaly až po založení na kartě zaměstnance:
        rodné číslo, datum narození, mzda, úvazek, účtárna a pojišťovna.
        Sekce je otevřená — kdo zakládá zaměstnance, obvykle je po ruce má —
        ale sbalitelná, aby formulář nevypadal jako dotazník.
      -->
      <details class="group mt-4 rounded-lg border border-neutral-200" open data-test="new-employee-advanced">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
          <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
          <span class="min-w-0">
            <span class="block text-xs font-semibold text-neutral-900">{{ t('payroll.people.create.advanced_title') }}</span>
            <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.people.create.advanced_hint') }}</span>
          </span>
        </summary>
        <div class="grid min-w-0 grid-cols-1 gap-3 border-t border-neutral-200 p-3 sm:grid-cols-2 lg:grid-cols-3">
          <label class="min-w-0 text-xs text-neutral-600">
            {{ t('payroll.people.create.birth_number') }}
            <input v-model="employeeForm.birth_number" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" inputmode="numeric" autocomplete="off" data-test="new-employee-birth-number">
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('payroll.people.quick_edit.birth_number_optional_hint') }}
            </span>
          </label>
          <label class="min-w-0 text-xs text-neutral-600">
            {{ t('payroll.people.create.birth_date') }}
            <input v-model="employeeForm.birth_date" type="date" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          </label>
          <label class="min-w-0 text-xs text-neutral-600">
            {{ t('payroll.people.create.monthly_gross') }}
            <input v-model.number="employeeForm.monthly_gross" type="number" min="0" step="1" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          </label>
          <!--
            Úvazek se dosud dosazoval natvrdo na 40 hodin, takže poloviční
            úvazek se musel hned po založení přepsat novou verzí podmínek —
            a do historie vztahu tím spadl interval, který nikdy neplatil.
          -->
          <label class="min-w-0 text-xs text-neutral-600">
            {{ t('payroll.people.weekly_hours') }}
            <input v-model="employeeForm.weekly_hours" inputmode="decimal" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="new-employee-weekly-hours">
          </label>
          <label v-if="officeOptions.length > 0" class="min-w-0 text-xs text-neutral-600">
            {{ t('payroll.people.office_label') }}
            <SearchableSelect
              :model-value="employeeForm.office_id"
              :options="officeOptions"
              :selected-option="selectedNewEmployeeOffice"
              :clearable="false"
              :placeholder="t('payroll.people.office_select')"
              accent="payroll"
              class="mt-1"
              data-test="new-employee-office"
              @update:model-value="employeeForm.office_id = $event === null ? null : Number($event)"
            />
          </label>
          <!--
            Pojišťovna se vede jako zákonná evidence osoby, ne jako sloupec
            karty — zapisuje se proto samostatným požadavkem hned po založení.
            Doplnit ji jde kdykoli později v panelu Zákonná evidence.
          -->
          <label class="min-w-0 text-xs text-neutral-600">
            {{ t('payroll.people.create.health_insurer') }}
            <select v-model="employeeForm.health_insurer_code" class="mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="new-employee-insurer">
              <option value="">{{ t('payroll.people.statutory_evidence.insurer_unset') }}</option>
              <option v-for="insurer in insurerOptions" :key="insurer.value" :value="insurer.value">{{ insurer.label }}</option>
            </select>
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.people.create.health_insurer_hint') }}</span>
          </label>
        </div>
      </details>
      <p v-if="employeeError" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-test="new-employee-error">
        {{ employeeError }}
      </p>
      <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="closeEmployeeForm">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="submit" :class="btnFilled('primary')" :disabled="savingEmployee">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t(savingEmployee ? 'common.saving' : 'common.save') }}
        </button>
      </div>
    </form>

    <p
      v-if="employeeError && !showEmployeeForm"
      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="new-employee-error"
    >
      {{ employeeError }}
    </p>

    <section
      v-if="createdEmployeeId !== null"
      class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-800"
      data-test="employee-created-next"
    >
      <p class="font-medium">{{ t('payroll.people.create.next_steps') }}</p>
      <p class="mt-1 text-xs">{{ t('payroll.people.create.next_steps_hint') }}</p>
    </section>

    <section v-if="!editing && !showEmployeeForm" class="rounded-xl border border-neutral-200 bg-surface p-3 shadow-sm sm:p-4">
      <div class="flex min-w-0 flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div class="grid min-w-0 flex-1 grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
          <label class="min-w-0 text-xs font-medium text-neutral-600">
            {{ t('payroll.people.search') }}
            <div class="relative mt-1">
              <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
              <input v-model="searchQuery" type="search" class="h-9 w-full min-w-0 rounded-md border border-neutral-300 bg-surface pl-9 pr-3 text-sm" :placeholder="t('payroll.people.search_placeholder')" data-test="people-search">
            </div>
          </label>
          <label class="min-w-0 text-xs font-medium text-neutral-600">
            {{ t('payroll.people.filter') }}
            <SearchableSelect
              v-model="peopleFilter"
              class="mt-1"
              :options="filterOptions"
              :clearable="false"
              accent="payroll"
              data-test="people-filter"
            />
          </label>
        </div>
        <RouterLink
          :to="{ name: 'payroll-quick-inputs' }"
          :class="btnOutline('primary')"
          data-test="quick-inputs-link"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.coin" /></svg>
          {{ t('payroll.people.quick_inputs') }}
        </RouterLink>
      </div>
    </section>

    <div v-if="editing && loadingDetailId !== null" class="h-24 animate-pulse rounded-lg bg-neutral-100" />

    <div
      v-else-if="expandedId !== null && details[expandedId]"
      class="space-y-4"
      data-test="selected-person-editor"
    >
      <!--
        Nahoře musí být vidět, KOHO upravuji. Jméno bere zobrazovanou podobu —
        u osoby, která má vyplněné jen celé jméno, jsou pole Jméno a Příjmení
        prázdná a formulář by jinak vypadal anonymně.
      -->
      <div class="sticky top-0 z-10 -mx-3 border-b border-neutral-200 bg-surface/95 px-3 py-3 backdrop-blur sm:-mx-4 sm:px-4">
        <nav class="text-xs text-neutral-500" aria-label="breadcrumb" data-test="person-breadcrumbs">
          <ol class="flex flex-wrap items-center gap-1">
            <li>
              <RouterLink :to="{ name: 'payroll-dashboard' }" class="hover:text-neutral-700 hover:underline">
                {{ t('payroll.people.breadcrumbs.payroll') }}
              </RouterLink>
            </li>
            <li aria-hidden="true">›</li>
            <li>
              <button type="button" class="hover:text-neutral-700 hover:underline" data-test="breadcrumb-people" @click="backToList">
                {{ t('payroll.people.breadcrumbs.people') }}
              </button>
            </li>
            <li aria-hidden="true">›</li>
            <li class="font-medium text-neutral-800" aria-current="page">{{ selectedName }}</li>
          </ol>
        </nav>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h1 class="truncate text-xl font-semibold text-neutral-900" data-test="person-header-name">
              {{ selectedName }}
            </h1>
            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
              <span
                v-if="selectedSummary"
                class="rounded-full px-2 py-1 font-medium"
                :class="selectedSummary.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'"
              >{{ statusLabel(selectedSummary.is_active) }}</span>
              <!--
                Štítek JMENUJE, co chybí. Dřív svítil prázdný „Vyžaduje doplnění"
                a uživatel to hledal po celé kartě — často u profilu, kterému
                nechybělo nic a jen měl neaktualizovaný ruční stav.
              -->
              <span
                v-if="selectedSummary?.needs_setup"
                class="rounded-full bg-warning-50 px-2 py-1 font-medium text-warning-700"
                data-test="person-setup-gaps"
              >
                {{ t('payroll.people.needs_setup') }}:
                {{ (selectedSummary.setup_gaps ?? []).map(gap => t(`payroll.people.setup_gap.${gap}`)).join(', ') }}
              </span>
              <span class="text-neutral-500" data-test="person-header-employments">
                {{ t('payroll.people.header_employments', { count: selectedEmploymentCount }, selectedEmploymentCount) }}
              </span>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" :class="btnOutline('neutral')" data-test="back-to-people" @click="backToList">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
              {{ t('payroll.people.back_to_list') }}
            </button>
            <ActionBar v-if="personActions.some(action => action.show)" :actions="personActions" />
          </div>
        </div>
      </div>

      <PayrollPersonQuickEdit
        :person-id="expandedId"
        :can-write="canQuickEditPerson"
        :can-read-sensitive="auth.canRead('payroll.person.read_sensitive')"
        @saved="updateQuickEdit"
      />

      <div data-panel-anchor="statutory_evidence" class="scroll-mt-24">
        <PayrollPersonStatutoryEvidencePanel
          :person-id="expandedId"
          :can-write="auth.canWrite('payroll.person.write')"
        />
      </div>

      <details
        class="group overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
        data-test="advanced-person-profile"
        :open="advancedProfileOpen"
        @toggle="toggleAdvancedProfile"
      >
        <summary class="cursor-pointer list-none px-4 py-4 sm:px-6">
          <span class="flex min-w-0 items-start justify-between gap-3">
            <span class="min-w-0">
              <span class="block text-sm font-semibold text-neutral-900">
                {{ t('payroll.people.quick_edit.advanced_title') }}
              </span>
              <span class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll.people.quick_edit.advanced_hint') }}
              </span>
            </span>
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="m6 9 6 6 6-6" />
            </svg>
          </span>
        </summary>
        <div v-if="advancedProfileOpen" class="space-y-4 border-t border-neutral-200 p-3 sm:p-4">
          <PayrollPersonProfilePanel
            :person-id="expandedId"
            :can-write="auth.canWrite('payroll.person.write')"
            :relation-types="details[expandedId].relation_types"
            @saved="updatePersonProfile"
          />
          <div data-panel-anchor="dependants" class="scroll-mt-24">
            <PayrollPersonDependantsPanel
              :person-id="expandedId"
              :can-write="auth.canWrite('payroll.person.write')"
            />
          </div>
          <PayrollPersonForeignPermitPanel
            :person-id="expandedId"
            :can-write="auth.canWrite('payroll.person.write') && auth.canRead('documents')"
            :can-read-documents="auth.canRead('documents')"
          />
        </div>
      </details>

      <section class="space-y-3 rounded-xl border border-neutral-200 bg-surface p-3 shadow-sm sm:p-4" data-test="person-employments">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.employments_title') }}</h2>
          <p class="text-xs text-neutral-500">{{ t('payroll.people.detail_hint') }}</p>
        </div>
        <form v-if="creatingForId === expandedId && newEmployment" class="grid grid-cols-1 gap-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-4 sm:grid-cols-2 lg:grid-cols-4" data-test="new-employment-form" @submit.prevent="saveNew(expandedId)">
          <!--
            Kód vztahu tu není. Server ho vygeneruje jako pořadové číslo u osoby
            a nepotřebuje ho žádný zákonný výstup; kdo importuje docházku, změní
            si označení přes „…" na kartě vztahu.
          -->
          <label class="text-xs text-neutral-600">
            {{ t('payroll.people.relation_type') }} <RequiredMark />
            <SearchableSelect v-model="newEmployment.relation_type" class="mt-1" :options="relationOptions" :clearable="false" accent="payroll" />
          </label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.planned_start') }} <RequiredMark /><input v-model="newEmployment.terms.planned_start_on" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
          <label v-if="officeOptions.length > 0" class="text-xs text-neutral-600">
            {{ t('payroll.people.office_label') }} <RequiredMark />
            <SearchableSelect
              :model-value="newEmployment.terms.office_id"
              :options="officeOptions"
              :selected-option="selectedOfficeOption"
              :clearable="false"
              required
              :placeholder="t('payroll.people.office_select')"
              accent="payroll"
              class="mt-1"
              data-test="new-employment-office"
              @update:model-value="newEmployment.terms.office_id = $event === null ? null : Number($event)"
            />
          </label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.weekly_hours') }}<input v-model="newEmployment.terms.weekly_hours" inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.create.monthly_gross') }}<input v-model.number="newEmploymentMonthlyGross" type="number" min="0" step="1" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
          <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="newEmployment.terms.is_primary" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.primary') }}</label>
          <p v-if="newEmploymentError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700 sm:col-span-2 lg:col-span-4" role="alert">{{ newEmploymentError }}</p>
          <div class="flex flex-wrap items-end justify-end gap-2 sm:col-span-2 lg:col-span-4">
            <button type="button" :class="btnOutline('neutral')" @click="creatingForId = null"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
            <button type="submit" :class="btnFilled('primary')" :disabled="savingNew"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
          </div>
        </form>
        <EmploymentCard
          v-for="employment in details[expandedId].employments"
          :key="employment.id"
          :employment="employment"
          :can-write="auth.canWrite('payroll.employment.write')"
          :can-write-person="auth.canWrite('payroll.person.write')"
          :can-read-documents="auth.canRead('payroll.documents')"
          :can-write-documents="auth.canWrite('payroll.documents')"
          :payroll-start-period="payrollStartPeriod"
          @updated="updateEmployment(expandedId, $event)"
          @deleted="removeEmploymentFromDetail(expandedId, $event)"
          @focus-statutory-evidence="focusPanel('statutory_evidence')"
        />
      </section>
    </div>

    <!-- Během editace se seznam schová — jinak by u upravované osoby svítily i ostatní. -->
    <section v-if="!editing && !showEmployeeForm" class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-test="people-list">
      <div v-if="loading" class="space-y-3 p-4 sm:p-6">
        <div v-for="index in 5" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <EmptyState
        v-else-if="loadFailed"
        variant="failed"
        dense
        data-test="load-failed"
        :message="t('payroll.people.load_failed_hint')"
        @action="load"
      />

      <div v-else-if="!hasAnyPeople" class="p-8 text-center">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.empty_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.empty_description') }}</p>
        <button
          type="button"
          :class="[btnFilled('primary'), 'mt-4']"
          :disabled="!canCreatePerson"
          :title="canCreatePerson ? undefined : t('payroll.people.create.permission_required')"
          data-test="empty-add-employee"
          @click="openEmployeeForm"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
          {{ t('payroll.people.create.action') }}
        </button>
      </div>

      <div v-else-if="people.length === 0" class="p-8 text-center">
        <h2 class="text-base font-semibold text-neutral-900">{{ t('payroll.people.no_results_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.people.no_results_description') }}</p>
      </div>

      <template v-else>
        <div class="hidden md:block">
          <div class="flex flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2">
            <ColumnPicker class="hidden md:block" :ctrl="tbl" />
            <DensityToggle class="hidden md:block" :ctrl="tbl" />
          </div>
          <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="tbl.isVisible('person')" class="px-4 py-3">{{ t('payroll.people.columns.person') }}</th>
                <th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.people.columns.status') }}</th>
                <th v-if="tbl.isVisible('relations')" class="px-4 py-3">{{ t('payroll.people.columns.relations') }}</th>
                <th v-if="tbl.isVisible('count')" class="px-4 py-3 text-right">{{ t('payroll.people.columns.count') }}</th>
                <th v-if="tbl.isVisible('detail')" class="px-4 py-3"><span class="sr-only">{{ t('payroll.people.columns.detail') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="person in people" :key="person.id">
                <!--
                  Celý řádek otevírá kartu. Je to skutečný <a> roztažený přes
                  řádek (`absolute inset-0`), ne posluchač na <tr> — prostřední
                  klik i Ctrl+klik tak otevřou kartu na novém panelu.

                  Overlay leží POD obsahem (z-0 vs. `relative z-10` na jménu a
                  na sloupci akcí): kdyby ležel nad ním, nešel by v řádku označit
                  text a rychlé akce by přestaly reagovat. Klikací tak zůstává
                  celá plocha řádku kromě samotného jména (které je odkaz samo)
                  a tlačítek.
                -->
                <tr class="relative align-top transition-colors hover:bg-neutral-50">
                  <td v-if="tbl.isVisible('person')" class="px-4 py-3">
                    <RouterLink
                      :to="personLink(person)"
                      class="absolute inset-0 z-0"
                      tabindex="-1"
                      aria-hidden="true"
                      :data-test="`person-row-link-${person.id}`"
                    />
                    <span class="relative z-10 inline-block">
                      <RouterLink
                        :to="personLink(person)"
                        class="font-medium text-neutral-900 hover:underline"
                        :data-test="`person-name-link-${person.id}`"
                      >{{ person.full_name }}</RouterLink>
                      <span
                        v-if="personCodeLabel(person) !== ''"
                        class="mt-0.5 block text-xs text-neutral-500"
                        :title="t('payroll.people.person_code')"
                        :data-test="`person-code-${person.id}`"
                      >
                        <span class="sr-only">{{ t('payroll.people.person_code') }}: </span>{{ personCodeLabel(person) }}
                      </span>
                    </span>
                    <p
                      v-if="person.needs_setup"
                      class="relative z-10 mt-1 max-w-sm text-xs leading-snug text-warning-700"
                      :data-test="`person-next-step-${person.id}`"
                    >{{ nextStepLabel(person) }}</p>
                  </td>
                  <td v-if="tbl.isVisible('status')" class="px-4 py-3">
                    <div class="flex flex-wrap gap-1.5">
                      <span class="rounded-full px-2 py-1 text-xs font-medium" :class="person.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ statusLabel(person.is_active) }}</span>
                      <span v-if="person.needs_setup" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">{{ t('payroll.people.needs_setup') }}</span>
                    </div>
                  </td>
                  <td v-if="tbl.isVisible('relations')" class="px-4 py-3 text-neutral-600">{{ person.relation_types.map(relationLabel).join(', ') }}</td>
                  <td v-if="tbl.isVisible('count')" class="px-4 py-3 text-right text-neutral-700">{{ person.employment_count }}</td>
                  <!--
                    Karta zůstává hlavní akcí; vedle ní jsou tři nejčastější
                    agendy jako ikona a zbytek pod „…". Ikony schválně: popisky
                    („Docházka a směny", „Nepřítomnosti", …) by sloupec roztáhly
                    tak, že by tabulka na notebooku začala scrollovat do stran.
                    Popisek nese `aria-label` i tooltip, takže o něj nepřijde ani
                    čtečka, ani myš.
                  -->
                  <td v-if="tbl.isVisible('detail')" class="relative z-10 px-4 py-3">
                    <div class="flex flex-wrap items-center justify-end gap-2">
                      <button :class="btnOutline(person.needs_setup ? 'warning' : 'neutral')" :aria-expanded="expandedId === person.id" :data-test="`edit-employee-${person.id}`" @click="toggleDetail(person)">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                          <path :d="person.needs_setup ? ICONS.edit : ICONS.user" />
                        </svg>
                        {{ nextStepActionLabel(person) }}
                      </button>
                      <RowActionsMenu
                        :actions="quickActions(person)"
                        :inline-count="3"
                        icon-only
                        :menu-label="t('payroll.people.quick_actions.more')"
                        :data-test="`person-quick-actions-${person.id}`"
                      />
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
          </div>
        </div>

        <div class="space-y-3 p-4 md:hidden">
          <article v-for="person in people" :key="person.id" class="min-w-0 overflow-hidden rounded-lg border border-neutral-200 p-4">
            <!--
              Na mobilu se karta NEROZTAHUJE do klikacího cíle: prst by na malé
              ploše trefil odkaz místo rychlé akce. Odkaz je jen na jméně, kde
              ho uživatel čeká, a pod ním osobní číslo pro rozlišení jmenovců.
            -->
            <div class="flex flex-wrap items-start justify-between gap-2">
              <h2 class="min-w-0 font-semibold text-neutral-900">
                <RouterLink :to="personLink(person)" class="hover:underline" :data-test="`person-name-link-mobile-${person.id}`">{{ person.full_name }}</RouterLink>
                <span
                  v-if="personCodeLabel(person) !== ''"
                  class="mt-0.5 block text-xs font-normal text-neutral-500"
                  :data-test="`person-code-mobile-${person.id}`"
                >
                  <span class="sr-only">{{ t('payroll.people.person_code') }}: </span>{{ personCodeLabel(person) }}
                </span>
              </h2>
              <div class="flex flex-wrap gap-1.5">
                <span class="rounded-full px-2 py-1 text-xs font-medium" :class="person.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ statusLabel(person.is_active) }}</span>
                <span v-if="person.needs_setup" class="rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700">{{ t('payroll.people.needs_setup') }}</span>
              </div>
            </div>
            <dl class="mt-3 space-y-2 text-sm">
              <div><dt class="text-xs text-neutral-500">{{ t('payroll.people.columns.relations') }}</dt><dd class="mt-0.5 text-neutral-800">{{ person.relation_types.map(relationLabel).join(', ') }}</dd></div>
              <div><dt class="text-xs text-neutral-500">{{ t('payroll.people.columns.count') }}</dt><dd class="mt-0.5 text-neutral-800">{{ person.employment_count }}</dd></div>
            </dl>
            <p
              v-if="person.needs_setup"
              class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs leading-snug text-warning-700"
              :data-test="`person-next-step-${person.id}`"
            >{{ nextStepLabel(person) }}</p>
            <!--
              Na mobilu se akce nesmí uříznout ani schovat za vodorovný scroll:
              řádek se proto zalamuje (`flex-wrap`) a v „…" zůstává celý zbytek
              katalogu. Tři ikonová tlačítka + „…" se vejdou i na nejužší
              obrazovku vedle „Otevřít kartu".
            -->
            <div class="mt-4 flex flex-wrap items-center gap-2">
              <button :class="btnOutline(person.needs_setup ? 'warning' : 'neutral')" :aria-expanded="expandedId === person.id" :data-test="`edit-employee-${person.id}`" @click="toggleDetail(person)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="person.needs_setup ? ICONS.edit : ICONS.user" />
                </svg>
                {{ nextStepActionLabel(person) }}
              </button>
              <RowActionsMenu
                :actions="quickActions(person)"
                :inline-count="3"
                icon-only
                :menu-label="t('payroll.people.quick_actions.more')"
                :data-test="`person-quick-actions-mobile-${person.id}`"
              />
            </div>
          </article>
        </div>

        <PaginationBar
          data-testid="payroll-people-pagination"
          embedded
          :page="currentPage"
          :per-page="pageSize"
          :total="total"
          @update:page="goToPage"
        />
      </template>
    </section>

    <!--
      Dialog „do kterého vztahu to patří" se otevírá JEN u osoby, která jich má
      víc. U jednoho vztahu je akce obyčejný odkaz, takže běžný případ nestojí
      klik navíc — a tady je ptaní se nutné, ne obtěžování: docházka zapsaná pod
      špatný vztah skončí ve špatné mzdě.
    -->
    <Modal
      v-if="agendaPicker"
      :title="t('payroll.people.quick_actions.pick_title', { agenda: t(payrollAgendaLabelKey(agendaPicker.agenda.key)) })"
      width-class="max-w-md"
      @close="agendaPicker = null"
    >
      <p class="text-sm text-neutral-600" data-test="agenda-picker-hint">
        {{ t('payroll.people.quick_actions.pick_hint', { name: agendaPicker.person.full_name }) }}
      </p>
      <ul class="mt-3 space-y-2" data-test="agenda-picker">
        <li v-for="employment in agendaPickerEmployments" :key="employment.id">
          <button
            type="button"
            class="flex w-full cursor-pointer items-center justify-between gap-3 rounded-lg border border-neutral-200 px-3 py-2 text-left text-sm hover:border-payroll-500/60 hover:bg-payroll-50"
            :data-test="`agenda-picker-${employment.id}`"
            @click="chooseAgendaEmployment(employment)"
          >
            <span class="min-w-0 text-neutral-800">{{ employmentPickerLabel(employment) }}</span>
            <span v-if="employment.is_primary" class="shrink-0 rounded-full bg-success-50 px-2 py-1 text-xs font-medium text-success-700">
              {{ t('payroll.people.primary') }}
            </span>
          </button>
        </li>
      </ul>
    </Modal>

  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { formInput as field, formLabel } from '@/components/ui/formStyles'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { accountingApi, type AccountingPeriod } from '@/api/accounting'
import { membershipApi, type MemberRow, type MembershipListFilters } from '@/api/nonprofitMembership'
import type { Client } from '@/api/clients'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { errorBox, loudBadge, quietBadge, membershipError, memberStatusBadge, membershipMemberLabel } from './membershipFormat'
import { clearListHandoff, MEMBERSHIP_LIST_PATH, memberDetailPath, readListHandoff, saveListHandoff } from './membershipPage'
import { useRowLink } from '@/composables/useRowLink'
import { useListKeyboard } from '@/composables/useListKeyboard'
import { usePaneId } from '@/workspace/paneActivity'
import MembershipReconciliation from './MembershipReconciliation.vue'
import MembershipRates from './MembershipRates.vue'
import MembershipBatchFlow from './MembershipBatchFlow.vue'
import MembershipImportFlow from './MembershipImportFlow.vue'
import MembershipErasure from './MembershipErasure.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import Modal from '@/components/ui/Modal.vue'
import BulkActionBar from '@/components/ui/BulkActionBar.vue'
import { useToast } from '@/composables/useToast'
import { useModalDraft } from './useModalDraft'
import EmptyState from '@/components/ui/EmptyState.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const props = defineProps<{ supplier: number; canManage: boolean; canManageRates?: boolean; canReadErasure?: boolean; canWriteErasure?: boolean }>()
const { t, te, tm, rt } = useI18n()
const toast = useToast()
/** Rozepsaný formulář v okně (založení, dávka, import): zavření se nejdřív zeptá (vzor datové schránky). */
const draft = useModalDraft()

const members = ref<MemberRow[]>([])
const nextAfter = ref<number | null>(null)
const periods = ref<AccountingPeriod[]>([])
const periodsLoaded = ref(false)
const loading = ref(false)
const error = ref('')
// Chyba exportu: vlastní hláška, aby nemazala chybu načtení seznamu (ta blokuje hromadný předpis).
const exportError = ref('')
/** Pořadí spuštěných exportů: stažení i odemčení akcí patří jen poslednímu (změna firmy ho také zneplatní). */
let exportRun = 0
const clientId = ref<number | null>(null)
const clientLabel = ref('')
const memberNumber = ref('')
const creating = ref(false)
const createOpen = ref(false), createError = ref('')
const root = ref<HTMLElement | null>(null)
const dataVersion = ref(0)
const route = useRoute(), router = useRouter()

/**
 * Stránka má záložky jako Uživatelé: seznam členů a vedle něj agendy, které se seznamem souvisí, ale nejsou jeho
 * součástí (sazby, kontrola s účetnictvím, výmaz údajů). Záložka je v adrese (?tab=), aby šla otevřít odkazem.
 */
type WorkspaceTab = 'members' | 'rates' | 'reconciliation' | 'erasure'
const tab = ref<WorkspaceTab>('members')
const tabs = computed(() => [
  { key: 'members' as const, label: t('nonprofitMembership.tabs.members'), icon: ICONS.user },
  { key: 'rates' as const, label: t('nonprofitMembership.tabs.rates'), icon: ICONS.coin },
  ...(periods.value.length ? [{ key: 'reconciliation' as const, label: t('nonprofitMembership.tabs.reconciliation'), icon: ICONS.badgeCheck }] : []),
  ...(props.canReadErasure ? [{ key: 'erasure' as const, label: t('nonprofitMembership.tabs.erasure'), icon: ICONS.trash }] : []),
])
function tabFromQuery(): WorkspaceTab {
  const value = route?.query.tab
  return value === 'rates' || value === 'reconciliation' || value === 'erasure' ? value : 'members'
}
function setTab(next: WorkspaceTab) {
  if (tab.value === next) return
  tab.value = next
  if (router) void router.replace({ query: { ...route?.query, tab: next === 'members' ? undefined : next } })
}
function onTabKey(event: KeyboardEvent) {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return
  event.preventDefault()
  const list = tabs.value, index = list.findIndex(item => item.key === tab.value)
  const next = list[(index + (event.key === 'ArrowRight' ? 1 : list.length - 1)) % list.length]!
  setTab(next.key)
  const host = (event.currentTarget as HTMLElement | null)?.parentElement
  void nextTick(() => host?.querySelector<HTMLElement>(`[data-tab="${next.key}"]`)?.focus())
}
const titles = ref<string[]>([]), titlesError = ref(false)
let titlesTicket = 0, hydrating = false, titlesYear: number | null | undefined
let searchTimeout: ReturnType<typeof setTimeout> | null = null
let pendingRouteFilters = false, pendingFormFilters = false
function cancelSearchTimer() {
  if (searchTimeout !== null) clearTimeout(searchTimeout)
  searchTimeout = null
}
const search = ref(''), filterYear = ref(appIsoDate().slice(0,4)), titleKey = ref('')
const paymentStatus = ref<NonNullable<MembershipListFilters['payment_status']>>('all')
const appliedFilters = ref<MembershipListFilters>({search: '', year: Number(filterYear.value), title_key: '', payment_status: 'all'})
/** Filtry z formuláře, nebo null s chybou, když rok a titul neurčují období. */
function formFilters(): MembershipListFilters | null {
  if (!String(filterYear.value).trim() && paymentStatus.value !== 'all') {
    hydrating = true; filterYear.value = appIsoDate().slice(0,4); hydrating = false
  }
  const year = String(filterYear.value).trim(), title = titleKey.value.trim()
  if ((!year && !title) || (year && (!/^[0-9]{4}$/.test(year) || Number(year) < 1900 || Number(year) > 9998))) {
    error.value = t('nonprofitMembership.listNeedPeriod')
    return null
  }
  return {search: search.value.trim(), year: year ? Number(year) : null, title_key: title, payment_status: paymentStatus.value}
}
function applyFilters(syncUrl = true, forceTitles = false) {
  cancelSearchTimer()
  if (batchLocked.value) {pendingFormFilters = true; return}
  const next = formFilters()
  if (!next) return
  const year = next.year === null ? '' : String(next.year), title = next.title_key
  if (JSON.stringify(next) === JSON.stringify(appliedFilters.value)) {
    // Opakování načtení stejných filtrů musí znovu ověřit výběr, nikoli jej zahodit.
    if (error.value && !loading.value) void load()
    void loadTitles(forceTitles)
    return
  }
  appliedFilters.value = next
  // Tituly závisí na použitém roku; bez změny roku je volání díky deduplikaci v loadTitles levné.
  void loadTitles(forceTitles)
  if (syncUrl && router) void router.replace({query: {...route?.query, search: next.search || undefined, year: year || '', title_key: title || undefined, payment_status: next.payment_status === 'all' ? undefined : next.payment_status}})
  if (checkedMembers.value.length) toast.warning(t('nonprofitMembership.listSelectionCleared'))
  checkedMembers.value = []; members.value = []; nextAfter.value = null
  void load()
}
const checkedMembers = ref<number[]>([]), batchOpen = ref(false), batchLocked = ref(false)
// Uložená dávka může založit nový titul, proto se obnoví i výběr titulů.
function batchChanged() { draft.reset(); dataVersion.value++; void load(); void loadTitles(true) }
/** Výmaz mění čísla a jména v seznamu. */
async function membersErased() { dataVersion.value++; await load() }

function checkMember(id: number, event: Event) {
  if (!props.canManage || batchLocked.value || loading.value) return
  const checked = (event.target as HTMLInputElement).checked
  if (checked && !checkedMembers.value.includes(id) && checkedMembers.value.length < 500) checkedMembers.value.push(id)
  else if (!checked) checkedMembers.value = checkedMembers.value.filter(x => x !== id)
}
let alive = true
let generation = 0
let createTicket = 0

const today = computed(() => appIsoDate())
const numberValid = computed(() => memberNumber.value.trim().length > 0 && memberNumber.value.trim().length <= 80)
const labelOf = (row: MemberRow) => row.erased
  ? t('nonprofitMembership.erasure.erasedMemberLabel', { id: row.id })
  : membershipMemberLabel(row) || t('nonprofitMembership.clientFallback', { id: row.client_id })

const navigateRow = useRowLink()
const paneId = usePaneId()
const paneKey = () => (paneId.value === null ? null : String(paneId.value))
/**
 * Detail člena je samostatná stránka jako detail kontaktu (Ctrl+klik a prostřední tlačítko ho otevřou v novém panelu).
 * Rozpracovaný hromadný předpis, import nebo běžící export by odchodem ze seznamu zmizel, proto se tehdy detail
 * otevře v novém panelu. Při odchodu ve stejném panelu seznam předá adresu s filtry, výběr a otevřeného člena.
 */
function goToMember(id: number, event?: MouseEvent) {
  const keepList = batchOpen.value || importOpen.value || exporting.value
  const newPanel = keepList || !!event && (event.ctrlKey || event.metaKey || event.button === 1)
  if (!newPanel) saveListHandoff({ supplier: props.supplier, pane: paneKey(), returning: false, path: route?.fullPath || MEMBERSHIP_LIST_PATH, filters: appliedFilters.value, ids: [...checkedMembers.value], openedId: id })
  navigateRow(memberDetailPath(id), keepList ? new MouseEvent('click', { ctrlKey: true }) : event)
}
function openMember(row: MemberRow, event?: MouseEvent) { if (event?.button === 2) return; goToMember(row.id, event) }

/** Výběr řádku do dávky klávesou (x) se stejnými podmínkami jako zaškrtávátko. */
function toggleMember(id: number) {
  if (!props.canManage || batchLocked.value || loading.value) return
  if (checkedMembers.value.includes(id)) checkedMembers.value = checkedMembers.value.filter(x => x !== id)
  else if (checkedMembers.value.length < 500) checkedMembers.value.push(id)
}
/** Klávesnice jako v seznamu faktur: j/k pohyb po řádcích, Enter otevře člena, x vybere do dávky, Esc zruší výběr. */
const { activeIndex } = useListKeyboard({
  count: () => (tab.value === 'members' ? members.value.length : 0),
  open: index => { const row = members.value[index]; if (row) openMember(row) },
  toggle: index => { const row = members.value[index]; if (row) toggleMember(row.id) },
  clear: () => { if (!batchLocked.value) checkedMembers.value = [] },
})

/** Po návratu z detailu fokus na odkaz člena; tabulka i karty jsou v DOM, bere se viditelný. */
function focusMember(id: number) {
  const links = Array.from(root.value?.querySelectorAll<HTMLElement>(`[data-member="${id}"] [data-field="member-name"], [data-member-card="${id}"] [data-field="member-name"]`) ?? [])
  ;(links.find(el => el.getClientRects().length > 0) ?? links[0])?.focus()
}

// Řazení zůstává podle id: seznam jde keyset stránkováním po id, jiné pořadí by potřebovalo nový index.
const COLUMNS: ColumnDef[] = [
  { key: 'member', labelKey: 'nonprofitMembership.listColumns.name', required: true },
  { key: 'membership', labelKey: 'nonprofitMembership.listColumns.membership' },
  { key: 'payment', labelKey: 'nonprofitMembership.listColumns.payment' },
  { key: 'prescribed', labelKey: 'nonprofitMembership.listColumns.prescribed' },
  { key: 'paid', labelKey: 'nonprofitMembership.listColumns.paid' },
  { key: 'open', labelKey: 'nonprofitMembership.listColumns.open' },
]
const tbl = useTablePrefs('nonprofit-members', COLUMNS)
const hasListFilters = computed(() => !!appliedFilters.value.search || !!appliedFilters.value.title_key || appliedFilters.value.payment_status !== 'all')
const money = (cents: string | null) => formatMoneyMinor(cents === null ? null : Number(cents))
/** Uhrazeno a bez předpisu jsou běžné stavy; neuhrazeno a neověřeno chtějí pozornost, proto mají výplň. */
const contributionBadge = (status: NonNullable<MemberRow['contribution']>['status']) =>
  status === 'paid' ? quietBadge('success') : status === 'unpaid' ? loudBadge('danger') : status === 'unproven' ? loudBadge('warning') : quietBadge('neutral')
type RevisionState = { kind: 'current'; status: NonNullable<MemberRow['status']>; date: string } | { kind: 'future' | 'none' | 'voided'; full: string; short: string }
function revisionState(row: MemberRow): RevisionState {
  if (row.revision_no === null || row.effective_from === null || row.status === null) {
    return row.all_revisions_voided
      ? { kind: 'voided', full: t('nonprofitMembership.allRevisionsVoided'), short: t('nonprofitMembership.listColumns.voided') }
      : { kind: 'none', full: t('nonprofitMembership.noRevision'), short: t('nonprofitMembership.listColumns.noRevision') }
  }
  const params = { status: t(`nonprofitMembership.status_${row.status}`), date: formatDate(row.effective_from) }
  if (row.effective_from > today.value) return { kind: 'future', full: t('nonprofitMembership.futureRevision', params), short: t('nonprofitMembership.listColumns.future', params) }
  return { kind: 'current', status: row.status, date: formatDate(row.effective_from) }
}
const atSelectionLimit = (id: number) => checkedMembers.value.length >= 500 && !checkedMembers.value.includes(id)
const checkDisabled = (id: number) => loading.value || batchLocked.value || atSelectionLimit(id)


async function load(append = false) {
  const ticket = ++generation, supplier = props.supplier
  // Nové načtení seznamu (jiný filtr, obnova) ruší starou hlášku exportu; „Načíst další“ výběr nemění.
  if (!append) exportError.value = ''
  const selectedIds = [...checkedMembers.value]
  // Obnova ponechá zobrazený seznam, dokud nepřijdou nová data; vyprázdní ho jen změna filtrů nebo firmy.
  loading.value = true; error.value = ''
  try {
    const page = await membershipApi.members(supplier, append ? nextAfter.value ?? 0 : 0, 50, appliedFilters.value)
    if (!alive || ticket !== generation || supplier !== props.supplier) return
    if (!append && selectedIds.length) {
      // Ověřujeme všechna vybraná ID, nikoli jen právě načtenou první stránku.
      const checked = await membershipApi.selectionCheck(supplier, selectedIds, appliedFilters.value)
      if (!alive || ticket !== generation || supplier !== props.supplier) return
      const valid = new Set(checked.member_ids)
      const remaining = selectedIds.filter(id => valid.has(id))
      if (remaining.length !== selectedIds.length) toast.warning(t('nonprofitMembership.listSelectionRefreshed'))
      checkedMembers.value = remaining
    }
    if (!alive || ticket !== generation || supplier !== props.supplier) return
    members.value = append ? [...members.value, ...page.items] : page.items
    nextAfter.value = page.next_after_id
  } catch (e) {
    if (alive && ticket === generation) error.value = membershipError({t, te}, e)
  } finally {
    if (alive && ticket === generation) loading.value = false
  }
}

async function loadPeriods() {
  const ticket = generation, supplier = props.supplier
  try {
    const rows = await accountingApi.listPeriods()
    if (!alive || ticket !== generation || supplier !== props.supplier) return
    periods.value = rows; periodsLoaded.value = true
  } catch {
    if (alive && ticket === generation) { periods.value = []; periodsLoaded.value = true }
  }
}

async function reloadAll() {
  await Promise.all([load(), loadPeriods(), loadTitles(true)])
}

/** Obnova nejdřív ověří formulář: neplatný rok nesmí tiše načíst data podle starých filtrů. */
function reloadMembers() {
  // Během dávky jsou filtry zmrazené (výběr dávky na nich závisí): obnovujeme data podle použitých filtrů
  // a rozepsaný formulář ověříme a použijeme až po odemčení dávky.
  if (batchLocked.value) { void reloadAll(); return }
  const next = formFilters()
  if (!next) return
  if (JSON.stringify(next) !== JSON.stringify(appliedFilters.value)) {
    applyFilters(true, true); void loadPeriods(); return
  }
  void reloadAll()
}

function chooseClient(client: Client | null) {
  clientLabel.value = client ? membershipMemberLabel(client) : ''
}

const clientModalOpen = ref(false)

function onClientCreated(client: Client) {
  clientId.value = client.id
  chooseClient(client)
  clientModalOpen.value = false
}

function openCreate() { draft.reset(); createError.value = ''; createOpen.value = true }
/** Okno se během ukládání nezavře: odpověď by jinak neměla kam ohlásit chybu. */
function closeCreate() {
  if (creating.value || clientModalOpen.value || !draft.confirmClose()) return
  createOpen.value = false; clientModalOpen.value = false
  clientId.value = null; clientLabel.value = ''; memberNumber.value = ''; createError.value = ''
}
async function create() {
  if (!props.canManage || creating.value || !clientId.value || !numberValid.value) return
  const ticket = ++createTicket, supplier = props.supplier, body = { client_id: clientId.value, member_number: memberNumber.value.trim() }
  creating.value = true; createError.value = ''
  try {
    const created = await membershipApi.createMember(supplier, body)
    if (!alive || ticket !== createTicket || supplier !== props.supplier) return
    creating.value = false; draft.reset()
    closeCreate()
    // Jako u kontaktu rovnou detail nového člena (tam se zapisuje jeho první revize); oznámení přežije přechod.
    toast.success(t('nonprofitMembership.memberCreated'))
    goToMember(created.id)
  } catch (e) {
    if (alive && ticket === createTicket) createError.value = membershipError({t, te}, e)
  } finally {
    if (alive && ticket === createTicket) creating.value = false
  }
}

watch(() => props.supplier, () => {
  draft.reset(); cancelSearchTimer(); pendingRouteFilters = false; pendingFormFilters = false
  generation++; createTicket++; titlesTicket++; hydrating = true
  checkedMembers.value = []; batchOpen.value = false; batchLocked.value = false; importOpen.value = false; importLocked.value = false; exportError.value = ''; exporting.value = false; exportRun++
  search.value = ''; filterYear.value = appIsoDate().slice(0, 4); titleKey.value = ''; paymentStatus.value = 'all'
  appliedFilters.value = {search: '', year: Number(filterYear.value), title_key: '', payment_status: 'all'}
  hydrating = false; titles.value = []
  members.value = []; nextAfter.value = null; periods.value = []
  clientId.value = null; clientLabel.value = ''; memberNumber.value = ''
  creating.value = false; createOpen.value = false; clientModalOpen.value = false; error.value = ''; createError.value = ''
  periodsLoaded.value = false
  void reloadAll()
})
onBeforeUnmount(() => { cancelSearchTimer(); alive = false; titlesTicket++; generation++; createTicket++ })
onMounted(async () => {
  // Návrat z detailu ve stejném panelu: výběr do dávky platí jen pro stejné filtry; load() ho ověří na serveru.
  // Jen návrat odkazem Zpět z detailu: stejný panel, stejná adresa seznamu i filtry (menu ani jiný panel ne).
  const handoff = readListHandoff(props.supplier, paneKey())
  let restored = false, focusId: number | null = null
  if (handoff?.returning && handoff.path === (route?.fullPath || MEMBERSHIP_LIST_PATH) && JSON.stringify(handoff.filters) === JSON.stringify(appliedFilters.value)) {
    clearListHandoff()
    checkedMembers.value = props.canManage ? handoff.ids : []
    restored = true; focusId = handoff.openedId
  }
  await reloadAll()
  if (!restored || !alive) return
  await nextTick()
  // Otevřený člen mezitím zmizel (smazání): fokus na nadpis seznamu, aby klávesnice nespadla na začátek stránky.
  if (focusId !== null) focusMember(focusId)
  else root.value?.querySelector<HTMLElement>('[data-focus="members-heading"]')?.focus()
})
let firstActivation = true
onActivated(() => {
  if (firstActivation) { firstActivation = false; return }
  void reloadAll()
})

const importOpen = ref(false), importLocked = ref(false), exporting = ref(false)
/**
 * Export použije filtry, podle kterých je seznam právě načtený, ne rozepsaný formulář.
 * Chyba exportu má vlastní hlášku: nesmí smazat chybu načtení seznamu (ta blokuje hromadný předpis) ani ji zastoupit.
 */
async function exportMembers(format: 'xlsx' | 'csv') {
  // Generace seznamu: odpověď exportu, která dorazí až po novém načtení (jiný filtr), už nic neohlásí.
  // Běh exportu: o stažení a odemčení akcí rozhoduje jen poslední spuštěný export této firmy (i po A → B → A).
  const supplier = props.supplier, ticket = generation, run = ++exportRun
  exporting.value = true; exportError.value = ''
  try {
    const { blob, filename } = await membershipApi.exportMembers(supplier, format, appliedFilters.value)
    if (!alive || run !== exportRun) return
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url; link.download = filename; link.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    if (alive && run === exportRun && ticket === generation) exportError.value = membershipError({ t, te }, e)
  } finally {
    if (alive && run === exportRun) exporting.value = false
  }
}
function importChanged() {
  draft.reset()
  dataVersion.value++
  void load()
}
/** Hlavička jako u seznamu kontaktů: plná primární akce, ostatní obrysové, export a obnova v nabídce „…“. */
const actions = computed<ActionItem[]>(() => [
  { key: 'create-open', label: t('nonprofitMembership.createTitle'), icon: 'plus', tier: 'primary', variant: 'primary', show: props.canManage, disabled: batchLocked.value, run: openCreate },
  { key: 'batch-open', label: t('nonprofitMembership.batchTitle'), icon: 'doc', variant: 'primary', show: props.canManage, disabled: batchLocked.value || loading.value || !!error.value, run: openBatch },
  { key: 'import-open', label: t('nonprofitMembership.import.open'), icon: 'upload', show: props.canManage, disabled: importOpen.value, run: () => { draft.reset(); importOpen.value = true } },
  { key: 'export-xlsx', label: t('nonprofitMembership.exportXlsx'), icon: 'download', tier: 'overflow', disabled: exporting.value || loading.value, run: () => { void exportMembers('xlsx') } },
  { key: 'export-csv', label: t('nonprofitMembership.exportCsv'), icon: 'download', tier: 'overflow', disabled: exporting.value || loading.value, run: () => { void exportMembers('csv') } },
  { key: 'reload-members', label: t('nonprofitMembership.reload'), icon: 'cycle', tier: 'overflow', disabled: loading.value, run: reloadMembers },
])
/** Hromadný předpis patří k seznamu: otevře se na záložce členů nad aktuálním výběrem. */
function openBatch() { draft.reset(); setTab('members'); batchOpen.value = true }
/** Rozpracovanou dávku (zamčený výběr a filtry) okno nezavře, jinak by běžící kroky ztratily kontext. */
function closeBatch() { if (!batchLocked.value && draft.confirmClose()) batchOpen.value = false }
function closeImport() { if (!importLocked.value && draft.confirmClose()) { importOpen.value = false; importLocked.value = false } }

/** Tituly závisí jen na firmě a roce; bez vynucení je pro rok, který se už načítá nebo načetl, nečteme znovu. */
async function loadTitles(force = false) {
  // Rozepsaný nebo neplatný rok ve formuláři tituly nemění; platí jen použitý filtr.
  const year = appliedFilters.value.year ?? undefined
  if (!force && !titlesError.value && titlesYear === (year ?? null)) return
  titlesYear = year ?? null
  const supplier = props.supplier, ticket = ++titlesTicket
  titlesError.value = false
  try {
    const items: string[] = []
    let after: string | undefined
    do {
      const page = await membershipApi.titles(supplier, year, after)
      if (!alive || ticket !== titlesTicket || supplier !== props.supplier) return
      items.push(...page.items)
      if (page.next_after_title_key === null) break
      if (!page.next_after_title_key || page.next_after_title_key === after) throw new Error('invalid_cursor')
      after = page.next_after_title_key
    } while (true)
    titles.value = items
  } catch { if (alive && ticket === titlesTicket) {titlesError.value = true; titles.value = []} }
}
/** Roky do výběru jako v ostatních seznamech: roky účetních období, aktuální rok a rok z adresy. */
const yearOptions = computed(() => {
  const years = new Set<number>(periods.value.map(period => period.fiscal_year))
  years.add(Number(appIsoDate().slice(0, 4)))
  if (/^\d{4}$/.test(String(filterYear.value))) years.add(Number(filterYear.value))
  return [...years].sort((a, b) => b - a)
})
const titleOptions = computed(() => [...new Set([...titles.value, ...(titleKey.value ? [titleKey.value] : [])])].map(value => ({value, label: value})))
// Aktuální rok je výchozí filtr: štítek jen tehdy, když ho odebrání skutečně zruší (titul bez stavu úhrady,
// který rok vyžaduje), nebo když jde o jiný rok, který se odebráním vrátí na aktuální.
const yearChipRemovable = computed(() => !!appliedFilters.value.year
  && (String(appliedFilters.value.year) !== appIsoDate().slice(0,4)
    || (!!appliedFilters.value.title_key && appliedFilters.value.payment_status === 'all')))
const filterChips = computed<FilterChip[]>(() => [
  ...(appliedFilters.value.search ? [{key: 'search', label: t('nonprofitMembership.listSearch'), value: appliedFilters.value.search}] : []),
  ...(yearChipRemovable.value ? [{key: 'year', label: t('nonprofitMembership.listYear'), value: String(appliedFilters.value.year)}] : []),
  ...(appliedFilters.value.title_key ? [{key: 'title', label: t('nonprofitMembership.titleKey'), value: appliedFilters.value.title_key}] : []),
  ...(appliedFilters.value.payment_status !== 'all' ? [{key: 'status', label: t('nonprofitMembership.listPaymentStatus'), value: t(`nonprofitMembership.listStatus_${appliedFilters.value.payment_status}`)}] : []),
])
// Během dávky změní jen formulář; seznam se podle něj načte po odemčení (applyFilters ho odloží).
function clearFilter(key?: string) {
  hydrating = true
  if (!key || key === 'search') search.value = ''
  if (!key || key === 'title') {titleKey.value = ''; if (!filterYear.value) filterYear.value = appIsoDate().slice(0,4)}
  if (!key || key === 'status') paymentStatus.value = 'all'
  if (!key || key === 'year') filterYear.value = titleKey.value ? '' : appIsoDate().slice(0,4)
  hydrating = false
  applyFilters()
}
function hydrateFilters() {
  if (!route) return
  const q = route.query, text = (key: string) => typeof q[key] === 'string' ? q[key] as string : ''
  hydrating = true
  search.value = text('search'); titleKey.value = text('title_key')
  filterYear.value = q.year === undefined ? appIsoDate().slice(0,4) : text('year')
  const status = text('payment_status')
  paymentStatus.value = ['paid','unpaid','unproven','none'].includes(status) ? status as typeof paymentStatus.value : 'all'
  if (!filterYear.value && paymentStatus.value !== 'all') filterYear.value = appIsoDate().slice(0,4)
  hydrating = false
}
hydrateFilters()
if ((!/^\d{4}$/.test(String(filterYear.value)) || Number(filterYear.value) < 1900 || Number(filterYear.value) > 9998) && !(filterYear.value === '' && titleKey.value)) filterYear.value = appIsoDate().slice(0,4)
appliedFilters.value = {search: search.value, year: filterYear.value ? Number(filterYear.value) : null, title_key: titleKey.value, payment_status: paymentStatus.value}
watch(search, () => {
  if (hydrating) return
  cancelSearchTimer()
  searchTimeout = setTimeout(() => {searchTimeout = null; if (alive) applyFilters()}, 300)
}, {flush: 'sync'})
// Běží-li prodleva rozepsaného hledání, výběr roku, titulu či stavu ji jen znovu naplánuje.
watch([filterYear, titleKey, paymentStatus], () => {
  if (hydrating) return
  if (searchTimeout === null) {applyFilters(); return}
  cancelSearchTimer()
  searchTimeout = setTimeout(() => {searchTimeout = null; if (alive) applyFilters()}, 300)
}, {flush: 'sync'})
tab.value = tabFromQuery()
watch(() => route?.query.tab, () => { tab.value = tabFromQuery() })
// Záložka, kterou stránka nenabízí (bez účetních období, bez práva na výmaz), vrací na seznam.
watch(tabs, list => {
  if (list.some(item => item.key === tab.value) || (tab.value === 'reconciliation' && !periodsLoaded.value)) return
  tab.value = 'members'
  if (router && route?.query.tab) void router.replace({ query: { ...route.query, tab: undefined } })
})
watch(() => route?.query, (now, before) => {
  // Přepnutí záložky filtry nemění.
  if (now && before && JSON.stringify({ ...now, tab: undefined }) === JSON.stringify({ ...before, tab: undefined })) return
  cancelSearchTimer()
  // Navigace má přednost před rozepsaným hledáním; během dávky ji použijeme až po odemčení.
  if (batchLocked.value) {pendingRouteFilters = true; return}
  hydrateFilters(); applyFilters(false)
})
watch(batchLocked, locked => {
  if (locked) {
    if (searchTimeout !== null) {pendingFormFilters = true; cancelSearchTimer()}
    return
  }
  if (pendingRouteFilters) {
    pendingRouteFilters = false; pendingFormFilters = false
    hydrateFilters(); applyFilters(false)
  } else if (pendingFormFilters) {
    pendingFormFilters = false; applyFilters()
  }
})

</script>

<template>
  <div ref="root">
    <!-- Hlavička jako u seznamu kontaktů: nadpis s popisem vlevo, akce vpravo. -->
    <header class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold">{{ t('nonprofitMembership.title') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('nonprofitMembership.help') }}</p>
      </div>
      <ActionBar :actions="actions" />
    </header>
    <div class="space-y-3">
      <p v-if="!canManage" role="status" class="text-sm text-neutral-500" data-section="readonly">{{ t('nonprofitMembership.readonly') }}</p>
      <p v-if="error" role="alert" :class="errorBox" data-section="workspace-error">{{ error }}</p>
      <p v-if="exportError" role="alert" :class="errorBox" data-section="export-error">{{ exportError }}</p>
    </div>

    <!-- Přepínač agend je záložka, ne tlačítko: tlačítka na stránce jsou akce (vzor Uživatelé). -->
    <div role="tablist" class="mb-4 mt-4 flex gap-1 overflow-x-auto overflow-y-hidden border-b border-neutral-200" :aria-label="t('nonprofitMembership.title')" data-section="membership-tabs">
      <button v-for="item in tabs" :id="`membership-tab-${item.key}`" :key="item.key" type="button" role="tab" :data-tab="item.key" :aria-controls="`membership-panel-${item.key}`"
        :aria-selected="tab === item.key" :tabindex="tab === item.key ? 0 : -1"
        class="-mb-px inline-flex cursor-pointer items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm transition-colors"
        :class="tab === item.key ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        @click="setTab(item.key)" @keydown="onTabKey">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" /></svg>{{ item.label }}
      </button>
    </div>

    <section v-show="tab === 'members'" id="membership-panel-members" class="min-w-0 space-y-3" data-section="members" role="tabpanel" aria-labelledby="membership-tab-members" :aria-busy="loading ? 'true' : 'false'">
      <h2 tabindex="-1" class="sr-only" data-focus="members-heading">{{ t('nonprofitMembership.members') }}</h2>
      <!-- Lišta filtrů jako v seznamu faktur: hledání s lupou, ostatní filtry kompaktně v jednom řádku
           (na telefonu za tlačítkem Filtry), sloupce a hustota vpravo. -->
      <FilterBar :active-count="filterChips.length" :chips="filterChips" data-section="member-filters" @clear="clearFilter" @clear-all="clearFilter()">
        <template #primary>
          <div class="relative min-w-56 flex-1 max-md:min-w-0 max-md:basis-full">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
            </svg>
            <input v-model="search" type="search" :placeholder="t('nonprofitMembership.listSearch')" :aria-label="t('nonprofitMembership.listSearch')" :disabled="batchLocked" maxlength="160" data-field="member-search"
              class="h-9 w-full rounded-md border border-neutral-300 pl-9 pr-3 text-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 disabled:opacity-50 max-md:h-10 max-md:text-base" />
          </div>
        </template>
        <select v-model="filterYear" :aria-label="t('nonprofitMembership.listYear')" :title="t('nonprofitMembership.listYear')" :disabled="batchLocked" data-field="member-year"
          class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm tabular-nums disabled:opacity-50">
          <option value="">{{ t('nonprofitMembership.listYearAll') }}</option>
          <option v-for="year in yearOptions" :key="year" :value="String(year)">{{ year }}</option>
        </select>
        <div class="min-w-48 max-w-xs flex-1">
          <SearchableSelect :model-value="titleKey || null" :options="titleOptions" :disabled="batchLocked" :placeholder="t('nonprofitMembership.listTitleAll')" :aria-label="t('nonprofitMembership.titleKey')" :no-results-label="t('nonprofitMembership.rateNoResults')" :clear-label="t('nonprofitMembership.rateClear')" data-field="member-title" @update:model-value="titleKey = typeof $event === 'string' ? $event : ''" />
        </div>
        <select v-model="paymentStatus" :aria-label="t('nonprofitMembership.listPaymentStatus')" :title="t('nonprofitMembership.listPaymentStatus')" :disabled="batchLocked" data-field="member-payment-status"
          class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm disabled:opacity-50">
          <option v-for="status in ['all','paid','unpaid','unproven','none'] as const" :key="status" :value="status">{{ t(`nonprofitMembership.listStatus_${status}`) }}</option>
        </select>
        <template #actions>
          <ColumnPicker class="hidden md:block" :ctrl="tbl" />
          <DensityToggle class="hidden md:block" :ctrl="tbl" />
        </template>
      </FilterBar>
      <p v-if="paymentStatus !== 'all'" class="text-xs text-neutral-500" data-section="status-year-help">{{ t('nonprofitMembership.listStatusNeedsYear') }}</p>
      <div v-if="titlesError" role="alert" :class="[errorBox, 'flex flex-wrap items-center gap-2']" data-section="titles-error">
        <span>{{ t('nonprofitMembership.titlesError') }}</span>
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" data-action="reload-titles" @click="loadTitles(true)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>{{ t('nonprofitMembership.reload') }}
        </button>
      </div>

      <!-- Obnova nechává zobrazené řádky; kostra tabulky jen do příchodu první stránky. -->
      <p v-if="loading && members.length" role="status" class="text-sm text-neutral-500" data-section="members-loading">{{ t('common.loading') }}</p>
      <div v-if="loading || members.length || !error" class="rounded-lg border border-neutral-200 bg-surface shadow-sm">
        <div v-if="loading && !members.length" data-section="members-loading"><span class="sr-only">{{ t('common.loading') }}</span><TableSkeleton :rows="5" :cols="7" /></div>
        <div v-else-if="!members.length && !error" data-section="members-empty">
          <EmptyState v-if="hasListFilters" variant="filtered" :title="t('nonprofitMembership.listNoResults')" :cta="batchLocked ? undefined : t('common.empty_state.clear_filters')" dense @action="clearFilter()" />
          <template v-else>
            <EmptyState icon="user" :title="t('nonprofitMembership.membersEmpty')" :cta="canManage ? t('nonprofitMembership.createTitle') : undefined" dense @action="openCreate" />
            <ol class="mx-auto max-w-md list-decimal space-y-1 px-4 pb-6 pl-9 text-sm text-neutral-600" data-section="members-steps">
              <li v-for="(step, index) in tm('nonprofitMembership.firstSteps')" :key="index">{{ rt(step) }}</li>
            </ol>
          </template>
        </div>

        <!-- Desktop: tabulka -->
        <div v-if="members.length" class="hidden md:block overflow-x-auto rounded-lg">
          <table v-column-labels="tbl" class="w-full text-sm table-sticky-first" :class="tbl.densityClass.value">
            <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th scope="col" class="px-3 py-2.5 text-left font-medium">{{ t('nonprofitMembership.listColumns.name') }}</th>
                <th v-if="tbl.isVisible('membership')" scope="col" class="px-3 py-2.5 text-left font-medium">{{ t('nonprofitMembership.listColumns.membership') }}</th>
                <th v-if="tbl.isVisible('payment')" scope="col" class="px-3 py-2.5 text-left font-medium">{{ t('nonprofitMembership.listColumns.payment') }}</th>
                <th v-if="tbl.isVisible('prescribed')" scope="col" class="px-3 py-2.5 text-right font-medium">{{ t('nonprofitMembership.listColumns.prescribed') }}</th>
                <th v-if="tbl.isVisible('paid')" scope="col" class="px-3 py-2.5 text-right font-medium">{{ t('nonprofitMembership.listColumns.paid') }}</th>
                <th v-if="tbl.isVisible('open')" scope="col" class="px-3 py-2.5 text-right font-medium">{{ t('nonprofitMembership.listColumns.open') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, index) in members" :key="row.id" class="cursor-pointer hover:bg-neutral-50" :data-row-active="index === activeIndex"
                data-section="member-row" :data-member="row.id"
                @click="openMember(row, $event)" @auxclick.prevent="openMember(row, $event)">
                <td class="px-3 py-2.5">
                  <div class="flex items-center gap-3">
                    <label v-if="canManage" class="-my-2 flex shrink-0 cursor-pointer py-2" @click.stop>
                      <input type="checkbox" class="h-4 w-4 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                        :checked="checkedMembers.includes(row.id)" :disabled="checkDisabled(row.id)" :title="atSelectionLimit(row.id) ? t('nonprofitMembership.batchSelectionLimit') : undefined" :aria-label="atSelectionLimit(row.id) ? `${t('nonprofitMembership.batchSelectMember', {name: labelOf(row)})}. ${t('nonprofitMembership.batchSelectionLimit')}` : t('nonprofitMembership.batchSelectMember', {name: labelOf(row)})"
                        data-action="batch-check-member" @change="checkMember(row.id, $event)" />
                    </label>
                    <span class="shrink-0 whitespace-nowrap font-mono text-xs text-neutral-500" data-field="member-number">{{ row.member_number }}</span>
                    <RouterLink class="row-link max-w-[14rem] truncate font-medium text-neutral-900" :to="memberDetailPath(row.id)" :title="labelOf(row)" data-field="member-name"
                      @click.stop.prevent="openMember(row, $event)" @auxclick.stop>{{ labelOf(row) }}</RouterLink>
                  </div>
                </td>
                <td v-if="tbl.isVisible('membership')" class="whitespace-nowrap px-3 py-2.5 text-xs">
                  <template v-for="rev in [revisionState(row)]" :key="rev.kind">
                    <span v-if="rev.kind === 'current'" class="flex items-center gap-2" data-field="member-revision"><span :class="memberStatusBadge(rev.status)" data-field="member-status">{{ t(`nonprofitMembership.status_${rev.status}`) }}</span><span class="text-neutral-500">{{ t('nonprofitMembership.sinceDate', { date: rev.date }) }}</span></span>
                    <span v-else class="text-warning-700" :title="rev.full" data-field="member-revision">{{ rev.short }}<span class="sr-only">. {{ rev.full }}</span></span>
                  </template>
                </td>
                <td v-if="tbl.isVisible('payment')" class="px-3 py-2.5 text-xs">
                  <template v-if="row.contribution">
                    <span :class="contributionBadge(row.contribution.status)" :title="t(`nonprofitMembership.listStatus_${row.contribution.status}`)" data-field="contribution-status">{{ row.contribution.status === 'none' ? t('nonprofitMembership.listColumns.statusNone') : t(`nonprofitMembership.listStatus_${row.contribution.status}`) }}</span>
                    <div v-if="row.contribution.unpaired_count" class="mt-1 whitespace-nowrap text-neutral-500" data-field="contribution-unpaired">{{ t('nonprofitMembership.listUnpaired', {count: row.contribution.unpaired_count}) }}</div>
                  </template>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td v-if="tbl.isVisible('prescribed')" class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums" data-field="contribution-prescribed">{{ row.contribution?.claim_count ? money(row.contribution.prescribed_cents) : '—' }}</td>
                <td v-if="tbl.isVisible('paid')" class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums" data-field="contribution-paid">{{ row.contribution?.claim_count ? money(row.contribution.paid_cents) : '—' }}</td>
                <td v-if="tbl.isVisible('open')" class="whitespace-nowrap px-3 py-2.5 text-right font-mono tabular-nums" data-field="contribution-open">{{ row.contribution?.claim_count ? money(row.contribution.open_cents) : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobil: karty -->
        <div v-if="members.length" class="divide-y divide-neutral-100 md:hidden">
          <div v-for="row in members" :key="`m-${row.id}`" class="cursor-pointer px-4 py-3 hover:bg-neutral-50"
            data-section="member-card" :data-member-card="row.id" @click="openMember(row, $event)" @auxclick.prevent="openMember(row, $event)">
            <div class="flex items-start gap-3">
              <label v-if="canManage" class="-m-3 flex shrink-0 cursor-pointer p-3" @click.stop>
                <input type="checkbox" class="mt-0.5 h-5 w-5 cursor-pointer rounded border-neutral-300 text-primary-600"
                  :checked="checkedMembers.includes(row.id)" :disabled="checkDisabled(row.id)" :title="atSelectionLimit(row.id) ? t('nonprofitMembership.batchSelectionLimit') : undefined" :aria-label="atSelectionLimit(row.id) ? `${t('nonprofitMembership.batchSelectMember', {name: labelOf(row)})}. ${t('nonprofitMembership.batchSelectionLimit')}` : t('nonprofitMembership.batchSelectMember', {name: labelOf(row)})"
                  data-action="batch-check-member" @change="checkMember(row.id, $event)" />
              </label>
              <div class="min-w-0 flex-1 space-y-1 text-sm">
                <p class="break-words font-medium text-neutral-900"><span class="font-mono text-xs text-neutral-500">{{ row.member_number }}</span> · <RouterLink class="row-link text-neutral-900" :to="memberDetailPath(row.id)" data-field="member-name" @click.stop.prevent="openMember(row, $event)" @auxclick.stop>{{ labelOf(row) }}</RouterLink></p>
                <template v-for="rev in [revisionState(row)]" :key="rev.kind">
                  <p v-if="rev.kind === 'current'" class="flex flex-wrap items-center gap-2 text-xs" data-field="member-revision"><span :class="memberStatusBadge(rev.status)" data-field="member-status">{{ t(`nonprofitMembership.status_${rev.status}`) }}</span><span class="text-neutral-500">{{ t('nonprofitMembership.sinceDate', { date: rev.date }) }}</span></p>
                  <p v-else class="text-xs text-warning-700" data-field="member-revision">{{ rev.full }}</p>
                </template>
                <template v-if="row.contribution">
                  <p class="flex flex-wrap items-center gap-2 text-xs">
                    <span :class="contributionBadge(row.contribution.status)" data-field="contribution-status">{{ t(`nonprofitMembership.listStatus_${row.contribution.status}`) }}</span>
                    <span v-if="row.contribution.unpaired_count" class="text-neutral-500" data-field="contribution-unpaired">{{ t('nonprofitMembership.listUnpaired', {count: row.contribution.unpaired_count}) }}</span>
                  </p>
                  <p v-if="row.contribution.claim_count" class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-neutral-500" data-field="contribution-amounts">
                    <span class="whitespace-nowrap">{{ t('nonprofitMembership.listColumns.prescribed') }}: <span class="font-mono tabular-nums text-neutral-700">{{ money(row.contribution.prescribed_cents) }}</span></span>
                    <span class="whitespace-nowrap">{{ t('nonprofitMembership.listColumns.paid') }}: <span class="font-mono tabular-nums text-neutral-700">{{ money(row.contribution.paid_cents) }}</span></span>
                    <span class="whitespace-nowrap">{{ t('nonprofitMembership.listColumns.open') }}: <span class="font-mono tabular-nums text-neutral-700">{{ money(row.contribution.open_cents) }}</span></span>
                  </p>
                </template>
              </div>
            </div>
          </div>
        </div>

        <div v-if="nextAfter !== null" class="flex flex-wrap items-center justify-center gap-2 border-t border-neutral-200 px-4 py-3">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" data-action="more-members" :disabled="loading || exporting" @click="load(true)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('nonprofitMembership.moreMembers') }}
          </button>
        </div>
      </div>
    </section>

    <section v-if="tab === 'rates'" id="membership-panel-rates" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" role="tabpanel" aria-labelledby="membership-tab-rates" data-section="rates-management">
      <MembershipRates :key="supplier" :supplier="supplier" :can-manage="!!canManageRates" />
    </section>

    <!-- v-show: zvolené období a výsledek kontroly přežijí přepnutí záložky. -->
    <div v-if="periods.length" v-show="tab === 'reconciliation'" id="membership-panel-reconciliation" role="tabpanel" aria-labelledby="membership-tab-reconciliation">
      <MembershipReconciliation :supplier="supplier" :periods="periods" :data-version="dataVersion" />
    </div>

    <section v-if="tab === 'erasure' && canReadErasure" id="membership-panel-erasure" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" role="tabpanel" aria-labelledby="membership-tab-erasure" data-section="erasure-management">
      <MembershipErasure :key="supplier" :supplier="supplier" :can-write="!!canWriteErasure" :open="tab === 'erasure'" @erased="membersErased" />
    </section>

    <!-- Výběr do hromadného předpisu: lišta u spodní hrany jako ve výpisu banky a automatizaci. -->
    <BulkActionBar v-if="canManage && tab === 'members' && !batchOpen" :count="checkedMembers.length" @clear="checkedMembers = []">
      <button type="button" :class="[btnFilled('primary'), 'whitespace-nowrap']" data-action="bulk-batch-open" :disabled="batchLocked || loading || !!error" @click="openBatch">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>{{ t('nonprofitMembership.batchTitle') }}
      </button>
    </BulkActionBar>

    <Modal v-if="createOpen && canManage" :title="t('nonprofitMembership.createTitle')" width-class="max-w-xl" @close="closeCreate">
      <form id="membership-create-form" class="space-y-4" data-section="create-member" @submit.prevent="create()" @input="draft.mark" @change="draft.mark">
        <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.createHelp') }}</p>
        <p v-if="createError" role="alert" :class="errorBox" data-section="create-error">{{ createError }}</p>
        <fieldset :disabled="creating" class="grid gap-3">
          <div :class="formLabel">
            <span id="membership-client-label">{{ t('nonprofitMembership.client') }}</span>
            <div class="mt-1 flex items-start gap-2">
              <div class="min-w-0 flex-1" role="group" aria-labelledby="membership-client-label">
                <ClientSearchSelect v-model="clientId" :selected-label="clientLabel || null" data-field="member-client" @selected="chooseClient" />
              </div>
              <button type="button" :class="[btnOutline('primary'), 'shrink-0 whitespace-nowrap']" :title="t('client.new_title')" :aria-label="t('client.new_title')" data-action="member-new-client" @click="clientModalOpen = true">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                <span class="hidden sm:inline">{{ t('client.new_title') }}</span>
              </button>
            </div>
          </div>
          <label :class="formLabel">{{ t('nonprofitMembership.memberNumber') }}<input v-model="memberNumber" :class="field" maxlength="80" data-field="member-number" /></label>
        </fieldset>
        <p v-if="!creating && (!clientId || !numberValid)" class="text-xs text-neutral-500" data-section="create-hint">{{ t('nonprofitMembership.createNeedsInput') }}</p>
      </form>
      <ClientFormModal v-if="clientModalOpen" @created="onClientCreated" @close="clientModalOpen = false" />
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="creating" data-action="create-cancel" @click="closeCreate">{{ t('common.cancel') }}</button>
          <button type="submit" form="membership-create-form" :class="[btnFilled('primary'), 'whitespace-nowrap']" :disabled="creating || !clientId || !numberValid" data-action="create-member" @click.prevent="create()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>{{ creating ? t('common.saving') : t('nonprofitMembership.createMember') }}
          </button>
        </div>
      </template>
    </Modal>

    <Modal v-if="batchOpen" :title="t('nonprofitMembership.batchTitle')" width-class="max-w-4xl" @close="closeBatch">
      <MembershipBatchFlow :key="supplier" :supplier="supplier" :can-manage="canManage" :periods="periods" :selected-member-ids="checkedMembers"
        @changed="batchChanged" @lock="batchLocked = $event" @close="closeBatch" @input="draft.mark" @change="draft.mark" />
    </Modal>
    <Modal v-if="importOpen && canManage" :title="t('nonprofitMembership.import.title')" width-class="max-w-3xl" @close="closeImport">
      <MembershipImportFlow :key="`import-${supplier}`" :supplier="supplier" :can-manage="canManage" @changed="importChanged" @lock="importLocked = $event" @close="closeImport" @input="draft.mark" @change="draft.mark" />
    </Modal>
  </div>
</template>

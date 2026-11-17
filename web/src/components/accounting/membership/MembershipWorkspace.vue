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
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { accountingApi, type AccountingPeriod } from '@/api/accounting'
import { membershipApi, type MemberRow, type MembershipListFilters } from '@/api/nonprofitMembership'
import type { Client } from '@/api/clients'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { badgeBase, errorBox, membershipError, memberStatusBadge, membershipMemberLabel } from './membershipFormat'
import { clearListHandoff, MEMBERSHIP_LIST_PATH, memberDetailPath, membershipDetailNotice, membershipListNotice, readListHandoff, saveListHandoff } from './membershipPage'
import { useRowLink } from '@/composables/useRowLink'
import MembershipReconciliation from './MembershipReconciliation.vue'
import MembershipRates from './MembershipRates.vue'
import MembershipBatchFlow from './MembershipBatchFlow.vue'
import MembershipImportFlow from './MembershipImportFlow.vue'
import MembershipErasure from './MembershipErasure.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const props = defineProps<{ supplier: number; canManage: boolean; canManageRates?: boolean; canReadErasure?: boolean; canWriteErasure?: boolean }>()
const erasureOpen = ref(false)
const { t, te, tm, rt } = useI18n()

const members = ref<MemberRow[]>([])
const nextAfter = ref<number | null>(null)
const periods = ref<AccountingPeriod[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
// Chyba exportu: vlastní hláška, aby nemazala chybu načtení seznamu (ta blokuje hromadný předpis).
const exportError = ref('')
/** Pořadí spuštěných exportů: stažení i odemčení akcí patří jen poslednímu (změna firmy ho také zneplatní). */
let exportRun = 0
const selectionNotice = ref('')
const clientId = ref<number | null>(null)
const clientLabel = ref('')
const memberNumber = ref('')
const creating = ref(false)
const root = ref<HTMLElement | null>(null)
const dataVersion = ref(0)
const route = useRoute(), router = useRouter()
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
  if (checkedMembers.value.length) selectionNotice.value = t('nonprofitMembership.listSelectionCleared')
  checkedMembers.value = []; members.value = []; nextAfter.value = null
  void load()
}
const checkedMembers = ref<number[]>([]), batchOpen = ref(false), batchLocked = ref(false)
// Uložená dávka může založit nový titul, proto se obnoví i výběr titulů.
function batchChanged() { dataVersion.value++; void load(); void loadTitles(true) }
/** Výmaz mění čísla a jména v seznamu. */
async function membersErased() { dataVersion.value++; await load() }

function checkMember(id: number, event: Event) {
  if (!props.canManage || batchLocked.value || loading.value) return
  selectionNotice.value = ''
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
/**
 * Detail člena je samostatná stránka jako detail kontaktu (Ctrl+klik a prostřední tlačítko ho otevřou v novém panelu).
 * Rozpracovaný hromadný předpis, import nebo běžící export by odchodem ze seznamu zmizel, proto se tehdy detail
 * otevře v novém panelu. Při odchodu ve stejném panelu seznam předá adresu s filtry, výběr a otevřeného člena.
 */
function goToMember(id: number, event?: MouseEvent) {
  const keepList = batchOpen.value || importOpen.value || exporting.value
  const newPanel = keepList || !!event && (event.ctrlKey || event.metaKey || event.button === 1)
  if (!newPanel) saveListHandoff({ supplier: props.supplier, path: route?.fullPath || MEMBERSHIP_LIST_PATH, filters: appliedFilters.value, ids: [...checkedMembers.value], openedId: id })
  navigateRow(memberDetailPath(id), keepList ? new MouseEvent('click', { ctrlKey: true }) : event)
}
function openMember(row: MemberRow, event?: MouseEvent) { goToMember(row.id, event) }

/** Po návratu z detailu fokus na tlačítko řádku člena; tabulka i karty jsou v DOM, bere se viditelné. */
function focusMember(id: number) {
  const buttons = Array.from(root.value?.querySelectorAll<HTMLElement>(`[data-member="${id}"] [data-action="open-member"], [data-member-card="${id}"] [data-action="open-member"]`) ?? [])
  ;(buttons.find(el => el.getClientRects().length > 0) ?? buttons[0])?.focus()
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
const contributionBadge = (status: NonNullable<MemberRow['contribution']>['status']) => [badgeBase,
  status === 'paid' ? 'bg-success-50 text-success-700' : status === 'unpaid' ? 'bg-danger-50 text-danger-600' : status === 'unproven' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600']
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
const checkDisabled = (id: number) => loading.value || batchLocked.value || (checkedMembers.value.length >= 500 && !checkedMembers.value.includes(id))


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
      if (remaining.length !== selectedIds.length) selectionNotice.value = t('nonprofitMembership.listSelectionRefreshed')
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
    periods.value = rows
  } catch {
    if (alive && ticket === generation) periods.value = []
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

async function create() {
  if (!props.canManage || creating.value || !clientId.value || !numberValid.value) return
  const ticket = ++createTicket, supplier = props.supplier, body = { client_id: clientId.value, member_number: memberNumber.value.trim() }
  creating.value = true; error.value = ''; notice.value = ''
  try {
    const created = await membershipApi.createMember(supplier, body)
    if (!alive || ticket !== createTicket || supplier !== props.supplier) return
    notice.value = t('nonprofitMembership.memberCreated')
    clientId.value = null; clientLabel.value = ''; memberNumber.value = ''
    // Jako u kontaktu rovnou detail nového člena (tam se zapisuje jeho první revize); potvrzení ukáže detail.
    membershipDetailNotice.value = t('nonprofitMembership.memberCreated')
    goToMember(created.id)
  } catch (e) {
    if (alive && ticket === createTicket) error.value = membershipError({t, te}, e)
  } finally {
    if (alive && ticket === createTicket) creating.value = false
  }
}

watch(() => props.supplier, () => {
  cancelSearchTimer(); pendingRouteFilters = false; pendingFormFilters = false
  generation++; createTicket++; titlesTicket++; hydrating = true
  checkedMembers.value = []; batchOpen.value = false; batchLocked.value = false; importOpen.value = false; exportError.value = ''; exporting.value = false; exportRun++
  search.value = ''; filterYear.value = appIsoDate().slice(0, 4); titleKey.value = ''; paymentStatus.value = 'all'
  selectionNotice.value = ''
  appliedFilters.value = {search: '', year: Number(filterYear.value), title_key: '', payment_status: 'all'}
  hydrating = false; titles.value = []
  members.value = []; nextAfter.value = null; periods.value = []
  clientId.value = null; clientLabel.value = ''; memberNumber.value = ''
  creating.value = false; error.value = ''; notice.value = ''
  void reloadAll()
})
onBeforeUnmount(() => { cancelSearchTimer(); alive = false; titlesTicket++; generation++; createTicket++ })
onMounted(async () => {
  // Hláška akce z detailu, který se zavřel (smazání člena).
  if (membershipListNotice.value) { notice.value = membershipListNotice.value; membershipListNotice.value = '' }
  // Návrat z detailu ve stejném panelu: výběr do dávky platí jen pro stejné filtry; load() ho ověří na serveru.
  const handoff = readListHandoff(props.supplier)
  let focusId: number | null = null
  if (handoff && JSON.stringify(handoff.filters) === JSON.stringify(appliedFilters.value)) {
    clearListHandoff()
    checkedMembers.value = props.canManage ? handoff.ids : []
    focusId = handoff.openedId
  }
  await reloadAll()
  if (focusId !== null && alive) { await nextTick(); focusMember(focusId) }
})
let firstActivation = true
onActivated(() => {
  if (firstActivation) { firstActivation = false; return }
  void reloadAll()
})

const importOpen = ref(false), exporting = ref(false)
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
  dataVersion.value++
  void load()
}
const actions = computed<ActionItem[]>(() => [
  { key: 'batch-open', label: t('nonprofitMembership.batchTitle'), icon: 'plus', variant: 'primary', show: props.canManage, disabled: batchLocked.value || loading.value || !!error.value, run: () => { batchOpen.value = true } },
  { key: 'import-open', label: t('nonprofitMembership.import.open'), icon: 'upload', show: props.canManage, disabled: importOpen.value, run: () => { importOpen.value = true } },
  { key: 'export-xlsx', label: t('nonprofitMembership.exportXlsx'), icon: 'download', disabled: exporting.value || loading.value, run: () => { void exportMembers('xlsx') } },
  { key: 'export-csv', label: t('nonprofitMembership.exportCsv'), icon: 'download', disabled: exporting.value || loading.value, run: () => { void exportMembers('csv') } },
  { key: 'reload-members', label: t('nonprofitMembership.reload'), icon: 'cycle', disabled: loading.value, run: reloadMembers },
])
const createActions = computed<ActionItem[]>(() => [
  { key: 'create-member', label: t('nonprofitMembership.createMember'), icon: 'plus', tier: 'primary', variant: 'primary', show: props.canManage, disabled: creating.value || !clientId.value || !numberValid.value, disabledReason: t('nonprofitMembership.createNeedsInput'), run: () => { void create() } },
])

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
// Rozepsaný rok (např. „202“) ještě není filtr: seznam i tituly načteme až po stejné prodlevě jako u hledání.
watch(filterYear, () => {
  if (hydrating) return
  cancelSearchTimer()
  searchTimeout = setTimeout(() => {searchTimeout = null; if (alive) applyFilters()}, 300)
}, {flush: 'sync'})
// Běží-li prodleva rozepsaného roku nebo hledání, výběr titulu či stavu ji jen znovu naplánuje.
watch([titleKey, paymentStatus], () => {
  if (hydrating) return
  if (searchTimeout === null) {applyFilters(); return}
  cancelSearchTimer()
  searchTimeout = setTimeout(() => {searchTimeout = null; if (alive) applyFilters()}, 300)
}, {flush: 'sync'})
watch(() => route?.query, () => {
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
  <div ref="root" class="space-y-5">
    <h1 class="text-xl font-semibold">{{ t('nonprofitMembership.title') }}</h1>
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.help') }}</p>
    <p v-if="!canManage" role="status" class="text-sm text-neutral-500" data-section="readonly">{{ t('nonprofitMembership.readonly') }}</p>
    <p v-if="error" role="alert" :class="errorBox" data-section="workspace-error">{{ error }}</p>
    <p v-if="notice" role="status" class="text-sm text-success-600" data-section="workspace-notice">{{ notice }}</p>
    <p v-if="selectionNotice" role="status" class="text-sm text-warning-700">{{ selectionNotice }}</p>
    <ActionBar :actions="actions" />
    <p v-if="exportError" role="alert" :class="errorBox" data-section="export-error">{{ exportError }}</p>
    <MembershipBatchFlow v-if="batchOpen" :key="supplier" :supplier="supplier" :can-manage="canManage" :periods="periods" :selected-member-ids="checkedMembers"
      @changed="batchChanged" @lock="batchLocked = $event" @close="batchOpen = false" />
    <MembershipImportFlow v-if="importOpen && canManage" :key="`import-${supplier}`" :supplier="supplier" :can-manage="canManage" @changed="importChanged" @close="importOpen = false" />

    <details class="rounded-lg border border-neutral-200 bg-surface p-4" data-section="rates-management">
      <summary class="cursor-pointer font-semibold">{{ t('nonprofitMembership.ratesTitle') }}</summary>
      <MembershipRates :key="supplier" class="mt-3" :supplier="supplier" :can-manage="!!canManageRates" />
    </details>

    <details v-if="canReadErasure" class="rounded-lg border border-neutral-200 bg-surface p-4" data-section="erasure-management" @toggle="erasureOpen = ($event.target as HTMLDetailsElement).open">
      <summary class="cursor-pointer font-semibold">{{ t('nonprofitMembership.erasure.title') }}</summary>
      <MembershipErasure :key="supplier" class="mt-3" :supplier="supplier" :can-write="!!canWriteErasure" :open="erasureOpen" @erased="membersErased" />
    </details>

    <section v-if="canManage" class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-4" data-section="create-member">
      <h2 class="font-semibold">{{ t('nonprofitMembership.createTitle') }}</h2>
      <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.createHelp') }}</p>
      <fieldset :disabled="creating" class="grid gap-3 sm:grid-cols-2">
        <div :class="formLabel">
          <span id="membership-client-label">{{ t('nonprofitMembership.client') }}</span>
          <div class="mt-1 flex items-start gap-2">
            <div class="min-w-0 flex-1" role="group" aria-labelledby="membership-client-label">
              <ClientSearchSelect v-model="clientId" :selected-label="clientLabel || null" data-field="member-client" @selected="chooseClient" />
            </div>
            <button type="button" class="cursor-pointer shrink-0 h-9 px-3 inline-flex items-center gap-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md text-sm font-medium" :title="t('client.new_title')" :aria-label="t('client.new_title')" data-action="member-new-client" @click="clientModalOpen = true">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
              <span class="hidden sm:inline">{{ t('client.new_title') }}</span>
            </button>
          </div>
        </div>
        <label :class="formLabel">{{ t('nonprofitMembership.memberNumber') }}<input v-model="memberNumber" :class="field" maxlength="80" data-field="member-number" /></label>
      </fieldset>
      <ActionBar :actions="createActions" />
      <ClientFormModal v-if="clientModalOpen" @created="onClientCreated" @close="clientModalOpen = false" />
    </section>

    <section class="min-w-0 space-y-3" data-section="members" :aria-busy="loading ? 'true' : 'false'">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 tabindex="-1" class="font-semibold focus:outline-none" data-focus="members-heading">{{ t('nonprofitMembership.members') }}</h2>
        <p v-if="canManage && members.length" class="min-w-0 flex-1 text-sm text-neutral-500" :title="t('nonprofitMembership.batchSelectedCount', {count: checkedMembers.length})" data-section="batch-selected-count">{{ t('nonprofitMembership.listColumns.selectedCount', {count: checkedMembers.length}) }}<span class="sr-only">. {{ t('nonprofitMembership.batchSelectedCount', {count: checkedMembers.length}) }}</span></p>
        <div class="flex flex-wrap items-center gap-2">
          <ColumnPicker class="hidden md:block" :ctrl="tbl" />
          <DensityToggle class="hidden md:block" :ctrl="tbl" />
        </div>
      </div>
      <FilterBar :active-count="filterChips.length" collapsible :chips="filterChips" data-section="member-filters" @clear="clearFilter" @clear-all="clearFilter()">
        <template #primary><label :class="formLabel">{{ t('nonprofitMembership.listSearch') }}<input v-model="search" :class="field" :disabled="batchLocked" maxlength="160" data-field="member-search" /></label></template>
        <label :class="formLabel">{{ t('nonprofitMembership.listYear') }}<input v-model="filterYear" :class="field" :disabled="batchLocked" type="number" min="1900" max="9998" step="1" data-field="member-year" /></label>
        <label :class="formLabel">{{ t('nonprofitMembership.titleKey') }}<SearchableSelect :model-value="titleKey || null" :options="titleOptions" :disabled="batchLocked" :no-results-label="t('nonprofitMembership.rateNoResults')" :clear-label="t('nonprofitMembership.rateClear')" data-field="member-title" @update:model-value="titleKey = typeof $event === 'string' ? $event : ''" /></label>
        <label :class="formLabel">{{ t('nonprofitMembership.listPaymentStatus') }}<select v-model="paymentStatus" :class="field" :disabled="batchLocked" data-field="member-payment-status"><option v-for="status in ['all','paid','unpaid','unproven','none'] as const" :key="status" :value="status">{{ t(`nonprofitMembership.listStatus_${status}`) }}</option></select></label>
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
            <EmptyState icon="user" :title="t('nonprofitMembership.membersEmpty')" dense />
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
                <th scope="col" class="px-3 py-2.5"><span class="sr-only">{{ t('nonprofitMembership.listColumns.actions') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <!-- Lepivá první buňka dědí pozadí řádku. Globální zebra a hover jsou poloprůhledné a obsah posunutých
                   buněk by pod ní prosvítal, proto má řádek tady vždy neprůhledné pozadí (sudý řádek, hover). -->
              <tr v-for="row in members" :key="row.id" class="cursor-pointer even:bg-neutral-50 hover:bg-neutral-100"
                data-section="member-row" :data-member="row.id"
                @click="openMember(row, $event)" @auxclick.prevent="openMember(row, $event)">
                <td class="px-3 py-2.5">
                  <div class="flex items-center gap-3">
                    <label v-if="canManage" class="-my-2 flex shrink-0 cursor-pointer py-2" @click.stop>
                      <input type="checkbox" class="h-4 w-4 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                        :checked="checkedMembers.includes(row.id)" :disabled="checkDisabled(row.id)" :aria-label="t('nonprofitMembership.batchSelectMember', {name: labelOf(row)})"
                        data-action="batch-check-member" @change="checkMember(row.id, $event)" />
                    </label>
                    <span class="shrink-0 whitespace-nowrap font-mono text-xs text-neutral-500" data-field="member-number">{{ row.member_number }}</span>
                    <RouterLink class="row-link max-w-[14rem] truncate font-medium text-neutral-900" :to="memberDetailPath(row.id)" :title="labelOf(row)" data-field="member-name"
                      @click.stop.prevent="openMember(row, $event)" @auxclick.stop>{{ labelOf(row) }}</RouterLink>
                  </div>
                </td>
                <td v-if="tbl.isVisible('membership')" class="whitespace-nowrap px-3 py-2.5 text-xs">
                  <template v-for="rev in [revisionState(row)]" :key="rev.kind">
                    <span v-if="rev.kind === 'current'" class="flex items-center gap-2" data-field="member-revision"><span :class="memberStatusBadge(rev.status)" data-field="member-status">{{ t(`nonprofitMembership.status_${rev.status}`) }}</span><span class="text-neutral-600">{{ t('nonprofitMembership.sinceDate', { date: rev.date }) }}</span></span>
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
                <td class="px-3 py-1.5 text-right">
                  <!-- Pod šířkou xl jen ikona, aby se tabulka vešla; popisek nese aria-label a title. -->
                  <button type="button" :class="[btnOutline('primary'), 'whitespace-nowrap']" :aria-label="t('nonprofitMembership.openMember')" :title="t('nonprofitMembership.openMember')" data-action="open-member" @click.stop="openMember(row, $event)" @auxclick.stop.prevent="openMember(row, $event)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg><span class="hidden xl:inline">{{ t('nonprofitMembership.openMember') }}</span>
                  </button>
                </td>
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
                  :checked="checkedMembers.includes(row.id)" :disabled="checkDisabled(row.id)" :aria-label="t('nonprofitMembership.batchSelectMember', {name: labelOf(row)})"
                  data-action="batch-check-member" @change="checkMember(row.id, $event)" />
              </label>
              <div class="min-w-0 flex-1 space-y-1 text-sm">
                <p class="break-words font-medium text-neutral-900"><span class="font-mono text-xs text-neutral-500">{{ row.member_number }}</span> · {{ labelOf(row) }}</p>
                <template v-for="rev in [revisionState(row)]" :key="rev.kind">
                  <p v-if="rev.kind === 'current'" class="flex flex-wrap items-center gap-2 text-xs" data-field="member-revision"><span :class="memberStatusBadge(rev.status)" data-field="member-status">{{ t(`nonprofitMembership.status_${rev.status}`) }}</span><span class="text-neutral-600">{{ t('nonprofitMembership.sinceDate', { date: rev.date }) }}</span></p>
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
              <button type="button" :class="[btnOutline('primary'), 'shrink-0 whitespace-nowrap']" data-action="open-member" @click.stop="openMember(row, $event)" @auxclick.stop.prevent="openMember(row, $event)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>{{ t('nonprofitMembership.openMember') }}
              </button>
            </div>
          </div>
        </div>

        <div v-if="nextAfter !== null" class="flex flex-wrap items-center justify-end gap-2 border-t border-neutral-200 px-4 py-3">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" data-action="more-members" :disabled="loading || exporting" @click="load(true)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('nonprofitMembership.moreMembers') }}
          </button>
        </div>
      </div>
    </section>

    <MembershipReconciliation v-if="periods.length" :supplier="supplier" :periods="periods" :data-version="dataVersion" />
  </div>
</template>

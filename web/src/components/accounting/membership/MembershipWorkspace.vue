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
import MembershipMemberDetail from './MembershipMemberDetail.vue'
import MembershipReconciliation from './MembershipReconciliation.vue'
import MembershipRates from './MembershipRates.vue'
import MembershipBatchFlow from './MembershipBatchFlow.vue'

const props = defineProps<{ supplier: number; canManage: boolean; canManageRates?: boolean }>()
const { t, te, tm, rt } = useI18n()

const members = ref<MemberRow[]>([])
const nextAfter = ref<number | null>(null)
const periods = ref<AccountingPeriod[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
const selectionNotice = ref('')
const selected = ref<{ id: number; label: string } | null>(null)
const clientId = ref<number | null>(null)
const clientLabel = ref('')
const memberNumber = ref('')
const creating = ref(false)
const root = ref<HTMLElement | null>(null)
const detailPane = ref<HTMLElement | null>(null)
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
const appliedFilters = ref<MembershipListFilters>({search:'',year:Number(filterYear.value),title_key:'',payment_status:'all'})
/** Filtry z formuláře, nebo null s chybou, když rok a titul neurčují období. */
function formFilters(): MembershipListFilters | null {
  if (!String(filterYear.value).trim() && paymentStatus.value !== 'all') {
    hydrating = true; filterYear.value = appIsoDate().slice(0,4); hydrating = false
  }
  const year = String(filterYear.value).trim(), title = titleKey.value.trim()
  if ((!year && !title) || (year && (!/^[0-9]{4}$/.test(year) || Number(year)<1900 || Number(year)>9998))) {error.value=t('nonprofitMembership.listNeedPeriod');return null}
  return {search:search.value.trim(),year:year ? Number(year) : null,title_key:title,payment_status:paymentStatus.value}
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
  if (checkedMembers.value.length) selectionNotice.value=t('nonprofitMembership.listSelectionCleared')
  checkedMembers.value=[]; members.value=[]; nextAfter.value=null
  void load()
}
const checkedMembers = ref<number[]>([]), batchOpen = ref(false), batchLocked = ref(false)
const detailVersion = ref(0)
// Uložená dávka nebo předpis v detailu mohou založit nový titul, proto se obnoví i výběr titulů.
function batchChanged() { dataVersion.value++; detailVersion.value++; void load(); void loadTitles(true) }
function memberChanged() { dataVersion.value++; void load(); void loadTitles(true) }
function checkMember(id: number, event: Event) {
  if (!props.canManage || batchLocked.value || loading.value) return
  selectionNotice.value=''
  const checked = (event.target as HTMLInputElement).checked
  if (checked && !checkedMembers.value.includes(id) && checkedMembers.value.length < 500) checkedMembers.value.push(id)
  else if (!checked) checkedMembers.value = checkedMembers.value.filter(x => x !== id)
}
let alive = true
let generation = 0
let createTicket = 0

const today = computed(() => appIsoDate())
const numberValid = computed(() => memberNumber.value.trim().length > 0 && memberNumber.value.trim().length <= 80)
const labelOf = (row: MemberRow) => membershipMemberLabel(row) || t('nonprofitMembership.clientFallback', { id: row.client_id })

function openMember(row: MemberRow) {
  selected.value = { id: row.id, label: labelOf(row) }
}

/** Po zavření detailu se fokus vrací na tlačítko řádku, ze kterého se detail otevřel. */
async function closeMember() {
  const id = selected.value?.id
  selected.value = null
  await nextTick()
  if (id === undefined) return
  const target = root.value?.querySelector<HTMLElement>(`[data-member="${id}"] [data-action="open-member"]`)
    ?? root.value?.querySelector<HTMLElement>('[data-focus="members-heading"]')
  target?.focus()
}

watch(() => selected.value?.id, async id => {
  if (id === undefined) return
  await nextTick()
  detailPane.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
  detailPane.value?.querySelector<HTMLElement>('[data-focus="member-heading"]')?.focus({ preventScroll: true })
})

async function load(append = false) {
  const ticket = ++generation, supplier = props.supplier
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
  const ticket = ++createTicket, supplier = props.supplier, body = { client_id: clientId.value, member_number: memberNumber.value.trim() }, label = clientLabel.value
  creating.value = true; error.value = ''; notice.value = ''
  try {
    const created = await membershipApi.createMember(supplier, body)
    if (!alive || ticket !== createTicket || supplier !== props.supplier) return
    notice.value = t('nonprofitMembership.memberCreated')
    selected.value = { id: created.id, label: label || t('nonprofitMembership.clientFallback', { id: created.client_id }) }
    clientId.value = null; clientLabel.value = ''; memberNumber.value = ''
    await load()
  } catch (e) {
    if (alive && ticket === createTicket) error.value = membershipError({t, te}, e)
  } finally {
    if (alive && ticket === createTicket) creating.value = false
  }
}

watch(() => props.supplier, () => {
  cancelSearchTimer(); pendingRouteFilters = false; pendingFormFilters = false
  generation++; createTicket++; titlesTicket++; hydrating = true
  checkedMembers.value = []; batchOpen.value = false; batchLocked.value = false
  search.value=''; filterYear.value=appIsoDate().slice(0,4); titleKey.value=''; paymentStatus.value='all'
  selectionNotice.value=''
  appliedFilters.value={search:'',year:Number(filterYear.value),title_key:'',payment_status:'all'}
  hydrating = false; titles.value = []
  members.value = []; nextAfter.value = null; periods.value = []; selected.value = null
  clientId.value = null; clientLabel.value = ''; memberNumber.value = ''
  creating.value = false; error.value = ''; notice.value = ''
  void reloadAll()
})
onBeforeUnmount(() => { cancelSearchTimer(); alive = false; titlesTicket++; generation++; createTicket++ })
onMounted(() => { void reloadAll() })
let firstActivation = true
onActivated(() => {
  if (firstActivation) { firstActivation = false; return }
  void reloadAll()
})

const actions = computed<ActionItem[]>(() => [
  { key: 'batch-open', label: t('nonprofitMembership.batchTitle'), icon: 'plus', variant: 'primary', show: props.canManage, disabled: batchLocked.value || loading.value || !!error.value, run: () => { batchOpen.value = true } },
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
const filterChips = computed<FilterChip[]>(() => [
  ...(appliedFilters.value.search ? [{key: 'search', label: t('nonprofitMembership.listSearch'), value: appliedFilters.value.search}] : []),
  ...(appliedFilters.value.year ? [{key: 'year', label: t('nonprofitMembership.listYear'), value: String(appliedFilters.value.year)}] : []),
  ...(appliedFilters.value.title_key ? [{key: 'title', label: t('nonprofitMembership.titleKey'), value: appliedFilters.value.title_key}] : []),
  ...(appliedFilters.value.payment_status !== 'all' ? [{key: 'status', label: t('nonprofitMembership.listPaymentStatus'), value: t(`nonprofitMembership.listStatus_${appliedFilters.value.payment_status}`)}] : []),
])
function clearFilter(key?: string) {
  if (batchLocked.value) return
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
    <p v-if="canManage && members.length" class="text-sm text-neutral-500">{{ t('nonprofitMembership.batchSelectedCount', {count: checkedMembers.length}) }}</p>
    <MembershipBatchFlow v-if="batchOpen" :key="supplier" :supplier="supplier" :can-manage="canManage" :periods="periods" :selected-member-ids="checkedMembers"
      @changed="batchChanged" @lock="batchLocked = $event" @close="batchOpen = false" />

    <details class="rounded-lg border border-neutral-200 bg-surface p-4" data-section="rates-management">
      <summary class="cursor-pointer font-semibold">{{ t('nonprofitMembership.ratesTitle') }}</summary>
      <MembershipRates :key="supplier" class="mt-3" :supplier="supplier" :can-manage="!!canManageRates" />
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

    <div :class="selected ? 'grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]' : ''" data-section="members-layout">
    <div v-if="selected" ref="detailPane" class="min-w-0 scroll-mt-4 self-start lg:sticky lg:top-4 lg:order-last" data-section="member-detail-pane">
      <MembershipMemberDetail
        :key="selected.id"
        :supplier="supplier" :can-manage="canManage" :member-id="selected.id" :label="selected.label" :periods="periods" :refresh-version="detailVersion"
        @changed="memberChanged" @close="closeMember()"
      />
    </div>
    <section class="min-w-0 space-y-3" data-section="members" :aria-busy="loading ? 'true' : 'false'">
      <h2 tabindex="-1" class="font-semibold focus:outline-none" data-focus="members-heading">{{ t('nonprofitMembership.members') }}</h2>
      <FilterBar :active-count="filterChips.length" :chips="filterChips" data-section="member-filters" @clear="clearFilter" @clear-all="clearFilter()">
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

      <p v-if="loading" role="status" class="text-sm text-neutral-500" data-section="members-loading">{{ t('common.loading') }}</p>
      <div v-else-if="!members.length && !loading && !error" class="space-y-2 text-sm text-neutral-500" data-section="members-empty">
        <p>{{ t(appliedFilters.search || appliedFilters.title_key || appliedFilters.payment_status !== 'all' ? 'nonprofitMembership.listNoResults' : 'nonprofitMembership.membersEmpty') }}</p>
        <ol v-if="!appliedFilters.search && !appliedFilters.title_key && appliedFilters.payment_status === 'all'" class="list-decimal space-y-1 pl-5" data-section="members-steps">
          <li v-for="(step, index) in tm('nonprofitMembership.firstSteps')" :key="index">{{ rt(step) }}</li>
        </ol>
      </div>
      <div v-for="row in members" :key="row.id" :class="['flex flex-wrap items-center justify-between gap-3 rounded border bg-surface p-3', selected?.id === row.id ? 'border-primary-400 ring-1 ring-primary-300' : 'border-neutral-200']" data-section="member-row" :data-member="row.id" :aria-current="selected?.id === row.id ? 'true' : undefined">
        <input v-if="canManage" type="checkbox" :checked="checkedMembers.includes(row.id)" :disabled="loading || batchLocked || (checkedMembers.length >= 500 && !checkedMembers.includes(row.id))" :aria-label="t('nonprofitMembership.batchSelectMember', {name: labelOf(row)})" data-action="batch-check-member" @change="checkMember(row.id, $event)" />
        <div class="min-w-0 flex-1 space-y-1 text-sm">
          <p class="break-words font-medium">{{ row.member_number }} · {{ labelOf(row) }}</p>
          <p v-if="row.revision_no === null || row.effective_from === null || row.status === null" class="text-warning-700" data-field="member-revision">{{ t('nonprofitMembership.noRevision') }}</p>
          <p v-else-if="row.effective_from > today" class="text-warning-700" data-field="member-revision">{{ t('nonprofitMembership.futureRevision', { status: t(`nonprofitMembership.status_${row.status}`), date: formatDate(row.effective_from) }) }}</p>
          <p v-else class="flex flex-wrap items-center gap-2" data-field="member-revision"><span :class="memberStatusBadge(row.status)" data-field="member-status">{{ t(`nonprofitMembership.status_${row.status}`) }}</span><span>{{ t('nonprofitMembership.sinceDate', { date: formatDate(row.effective_from) }) }}</span></p>
          <template v-if="row.contribution">
            <span :class="[badgeBase,row.contribution.status === 'paid' ? 'bg-success-50 text-success-700' : row.contribution.status === 'unpaid' ? 'bg-danger-50 text-danger-600' : row.contribution.status === 'unproven' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600']" data-field="contribution-status">{{ t(`nonprofitMembership.listStatus_${row.contribution.status}`) }}</span>
            <p v-if="row.contribution.unpaired_count" class="text-neutral-500" data-field="contribution-unpaired">{{ t('nonprofitMembership.listUnpaired', {count: row.contribution.unpaired_count}) }}</p>
            <p v-if="row.contribution.claim_count" class="flex flex-wrap gap-x-3 gap-y-1 text-neutral-500" data-field="contribution-amounts">
              <span class="whitespace-nowrap">{{ t('nonprofitMembership.listPrescribed') }}: {{ formatMoneyMinor(Number(row.contribution.prescribed_cents)) }}</span>
              <span class="whitespace-nowrap">{{ t('nonprofitMembership.listPaid') }}: {{ formatMoneyMinor(row.contribution.paid_cents === null ? null : Number(row.contribution.paid_cents)) }}</span>
              <span class="whitespace-nowrap">{{ t('nonprofitMembership.sumOpen') }}: {{ formatMoneyMinor(row.contribution.open_cents === null ? null : Number(row.contribution.open_cents)) }}</span>
            </p>
          </template>
        </div>
        <button type="button" :class="btnOutline('primary')" data-action="open-member" @click="openMember(row)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>{{ t('nonprofitMembership.openMember') }}
        </button>
      </div>
      <button v-if="nextAfter !== null" type="button" :class="btnOutline('neutral')" data-action="more-members" :disabled="loading" @click="load(true)">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('nonprofitMembership.moreMembers') }}
      </button>
    </section>
    </div>

    <MembershipReconciliation v-if="periods.length" :supplier="supplier" :periods="periods" :data-version="dataVersion" />
  </div>
</template>

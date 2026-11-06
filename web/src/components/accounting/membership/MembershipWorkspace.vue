<script setup lang="ts">
import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { formInput as field, formLabel } from '@/components/ui/formStyles'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { accountingApi, type AccountingPeriod } from '@/api/accounting'
import { membershipApi, type MemberRow } from '@/api/nonprofitMembership'
import type { Client } from '@/api/clients'
import { formatDate } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { errorBox, errorMessage, memberStatusBadge } from './membershipFormat'
import MembershipMemberDetail from './MembershipMemberDetail.vue'
import MembershipReconciliation from './MembershipReconciliation.vue'
import MembershipRates from './MembershipRates.vue'

const props = defineProps<{ supplier: number; canManage: boolean }>()
const { t, tm, rt } = useI18n()

const members = ref<MemberRow[]>([])
const nextAfter = ref<number | null>(null)
const periods = ref<AccountingPeriod[]>([])
const loading = ref(false)
const error = ref('')
const notice = ref('')
const selected = ref<{ id: number; label: string } | null>(null)
const clientId = ref<number | null>(null)
const clientLabel = ref('')
const memberNumber = ref('')
const creating = ref(false)
const root = ref<HTMLElement | null>(null)
const detailPane = ref<HTMLElement | null>(null)
const dataVersion = ref(0)
let alive = true
let generation = 0
let createTicket = 0

const today = computed(() => appIsoDate())
const numberValid = computed(() => memberNumber.value.trim().length > 0 && memberNumber.value.trim().length <= 80)
const labelOf = (row: MemberRow) => row.company_name || t('nonprofitMembership.clientFallback', { id: row.client_id })

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
  loading.value = true; error.value = ''
  try {
    const page = await membershipApi.members(supplier, append ? nextAfter.value ?? 0 : 0, 50)
    if (!alive || ticket !== generation || supplier !== props.supplier) return
    members.value = append ? [...members.value, ...page.items] : page.items
    nextAfter.value = page.next_after_id
  } catch (e) {
    if (alive && ticket === generation) error.value = errorMessage(e) || t('nonprofitMembership.error')
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
  await Promise.all([load(), loadPeriods()])
}

function chooseClient(client: Client | null) {
  clientLabel.value = client?.company_name ?? ''
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
    if (alive && ticket === createTicket) error.value = errorMessage(e) || t('nonprofitMembership.error')
  } finally {
    if (alive && ticket === createTicket) creating.value = false
  }
}

watch(() => props.supplier, () => {
  generation++; createTicket++
  members.value = []; nextAfter.value = null; periods.value = []; selected.value = null
  clientId.value = null; clientLabel.value = ''; memberNumber.value = ''
  creating.value = false; error.value = ''; notice.value = ''
  void reloadAll()
})
onBeforeUnmount(() => { alive = false; generation++; createTicket++ })
onMounted(() => { void reloadAll() })
let firstActivation = true
onActivated(() => {
  if (firstActivation) { firstActivation = false; return }
  void reloadAll()
})

const actions = computed<ActionItem[]>(() => [
  { key: 'reload-members', label: t('nonprofitMembership.reload'), icon: 'cycle', disabled: loading.value, run: () => { void reloadAll() } },
])
const createActions = computed<ActionItem[]>(() => [
  { key: 'create-member', label: t('nonprofitMembership.createMember'), icon: 'plus', tier: 'primary', variant: 'primary', show: props.canManage, disabled: creating.value || !clientId.value || !numberValid.value, disabledReason: t('nonprofitMembership.createNeedsInput'), run: () => { void create() } },
])
</script>

<template>
  <div ref="root" class="space-y-5">
    <h1 class="text-xl font-semibold">{{ t('nonprofitMembership.title') }}</h1>
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.help') }}</p>
    <p v-if="!canManage" role="status" class="text-sm text-neutral-500" data-section="readonly">{{ t('nonprofitMembership.readonly') }}</p>
    <p v-if="error" role="alert" :class="errorBox" data-section="workspace-error">{{ error }}</p>
    <p v-if="notice" role="status" class="text-sm text-success-600" data-section="workspace-notice">{{ notice }}</p>
    <ActionBar :actions="actions" />

    <details class="rounded-lg border bg-surface p-4" data-section="rates-management">
      <summary class="cursor-pointer font-semibold">{{ t('nonprofitMembership.ratesTitle') }}</summary>
      <MembershipRates :key="supplier" :supplier="supplier" :can-manage="canManage" />
    </details>

    <section v-if="canManage" class="space-y-3 rounded-lg border bg-surface p-4" data-section="create-member">
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
        :supplier="supplier" :can-manage="canManage" :member-id="selected.id" :label="selected.label" :periods="periods"
        @changed="dataVersion++; load()" @close="closeMember()"
      />
    </div>
    <section class="min-w-0 space-y-3" data-section="members">
      <h2 tabindex="-1" class="font-semibold focus:outline-none" data-focus="members-heading">{{ t('nonprofitMembership.members') }}</h2>
      <p v-if="loading && !members.length" role="status" class="text-sm text-neutral-500" data-section="members-loading">{{ t('common.loading') }}</p>
      <div v-else-if="!members.length && !loading" class="space-y-2 text-sm text-neutral-500" data-section="members-empty">
        <p>{{ t('nonprofitMembership.membersEmpty') }}</p>
        <ol class="list-decimal space-y-1 pl-5" data-section="members-steps">
          <li v-for="(step, index) in tm('nonprofitMembership.firstSteps')" :key="index">{{ rt(step) }}</li>
        </ol>
      </div>
      <div v-for="row in members" :key="row.id" :class="['flex flex-wrap items-center justify-between gap-3 rounded border bg-surface p-3', selected?.id === row.id ? 'border-primary-400 ring-1 ring-primary-300' : '']" data-section="member-row" :data-member="row.id" :aria-current="selected?.id === row.id ? 'true' : undefined">
        <div class="min-w-0 space-y-1 text-sm">
          <p class="break-words font-medium">{{ row.member_number }} · {{ labelOf(row) }}</p>
          <p v-if="row.revision_no === null || row.effective_from === null || row.status === null" class="text-warning-700" data-field="member-revision">{{ t('nonprofitMembership.noRevision') }}</p>
          <p v-else-if="row.effective_from > today" class="text-warning-700" data-field="member-revision">{{ t('nonprofitMembership.futureRevision', { status: t(`nonprofitMembership.status_${row.status}`), date: formatDate(row.effective_from) }) }}</p>
          <p v-else class="flex flex-wrap items-center gap-2" data-field="member-revision"><span :class="memberStatusBadge(row.status)" data-field="member-status">{{ t(`nonprofitMembership.status_${row.status}`) }}</span><span>{{ t('nonprofitMembership.sinceDate', { date: formatDate(row.effective_from) }) }}</span></p>
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

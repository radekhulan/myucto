<script setup lang="ts">
import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import { useToast } from '@/composables/useToast'
import { useModalDraft } from './useModalDraft'
import type { AccountingPeriod } from '@/api/accounting'
import { membershipApi, type ClaimRow, type MemberCorrections, type MemberIdentity, type MemberRevision } from '@/api/nonprofitMembership'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { errorBox, loudBadge, quietBadge, errorCode, membershipError, membershipMemberLabel, formatDateRange, memberStatusBadge, revisionAt, validRevisions, voidedRevisions, warningBox } from './membershipFormat'
import MembershipCorrectionFlow from './MembershipCorrectionFlow.vue'
import MembershipIssueFlow from './MembershipIssueFlow.vue'
import MembershipReverseFlow from './MembershipReverseFlow.vue'
import MembershipRevisionForm from './MembershipRevisionForm.vue'
import MembershipSettlement from './MembershipSettlement.vue'

const props = defineProps<{ supplier: number; canManage: boolean; memberId: number; label?: string; periods: AccountingPeriod[]; refreshVersion?: number }>()
const emit = defineEmits<{ changed: []; deleted: []; relabel: [label: string] }>()
const { t, te } = useI18n()
const toast = useToast()
/** Rozepsaný formulář v okně: zavření se nejdřív zeptá (vzor datové schránky). */
const draft = useModalDraft()

const identity = ref<MemberIdentity | null>(null)
const history = ref<MemberRevision[]>([])
const corrections = ref<MemberCorrections | null>(null)
/** Osobní údaje člena byly vymazány (krok 10); členství ani předpisy už měnit nejde. */
const erased = ref(false)
const manage = computed(() => props.canManage && !erased.value)
/** Otevřená oprava (kontakt a číslo, smazání, zrušení revize); jen jedna najednou. */
const correction = ref<'identity' | 'delete' | 'void' | null>(null)
const heading = ref<HTMLElement | null>(null)
const claims = ref<ClaimRow[]>([])
const nextAfter = ref<number | null>(null)
const loading = ref(false)
const error = ref('')
// Zavřená zastaralá oprava není úspěch: vlastní varovná hláška, zmizí s dalším otevřením opravy nebo s chybou.
const staleNotice = ref(false)
watch(error, failure => { if (failure) staleNotice.value = false })
/** Potvrzení akce jako jinde v aplikaci (toast); varování o zastaralé opravě se jím ruší. */
function confirmDone(message: string) { staleNotice.value = false; toast.success(message) }
const issueOpen = ref(false)
/** Formulář revize je u člena s historií sbalený za akcí „Změnit stav členství“. */
const revisionOpen = ref(false)
const reverseClaim = ref<ClaimRow | null>(null)
const locks = ref({ revision: false, issue: false, reverse: false, correction: false })
let alive = true
let generation = 0
let refreshPending = false

const today = computed(() => appIsoDate())
const current = computed(() => revisionAt(history.value, today.value))
const valid = computed(() => validRevisions(history.value))
const voided = computed(() => voidedRevisions(history.value))
const upcoming = computed(() => valid.value.filter(r => r.effective_from > today.value))
const voidable = computed(() => history.value.find(r => r.id === corrections.value?.voidable_revision_id) ?? null)
const revisionDirty = ref(false)
const anyLocked = computed(() => locks.value.revision || locks.value.issue || locks.value.reverse || locks.value.correction)
/** Jméno kontaktu ze serveru (aktuální i po opravě kontaktu); popisek ze seznamu jen do prvního načtení. */
// Vymazaný člen nemá jméno ani v detailu, stejně jako v seznamu.
const displayLabel = computed(() => erased.value ? t('nonprofitMembership.erasure.erasedMemberLabel', { id: props.memberId }) : (identity.value && membershipMemberLabel(identity.value)) || props.label || (identity.value ? t('nonprofitMembership.clientFallback', { id: identity.value.client_id }) : ''))
const member = computed(() => ({ id: props.memberId, member_number: identity.value?.member_number ?? '', label: displayLabel.value }))

const issued = computed(() => claims.value.filter(c => c.reversal_claim_id === null).reduce((sum, c) => sum + c.amount_cents, 0))
const caseSymbols = computed(() => Object.fromEntries(claims.value.filter(c => c.variable_symbol)
  .map(c => [`${c.title_key}|${c.period_from}|${c.period_to}`, c.variable_symbol as string])))
const needsReview = computed(() => claims.value.filter(c => c.settlement.status === 'ambiguous').length)
// Stejně jako řádek seznamu: s předpisem k prověření je zůstatek neurčený.
const openTotal = computed(() => needsReview.value ? null : claims.value.reduce((sum, c) => sum + (c.settlement.open_cents ?? 0), 0))

async function load(append = false) {
  const ticket = ++generation, supplier = props.supplier, id = props.memberId
  loading.value = true; error.value = ''
  try {
    const data = await membershipApi.member(supplier, id, append ? nextAfter.value ?? 0 : 0, 50)
    if (!alive || ticket !== generation || supplier !== props.supplier || id !== props.memberId) return
    identity.value = data.member
    history.value = data.history
    corrections.value = data.corrections ?? null
    erased.value = data.erased === true
    claims.value = append ? [...claims.value, ...data.balance.items] : data.balance.items
    nextAfter.value = data.balance.next_after_claim_id
  } catch (e) {
    // Už načtený člen smazaný v jiné záložce: detail se zavře stejně jako po smazání zde.
    if (alive && ticket === generation && identity.value && errorCode(e) === 'nonprofit_membership_member_not_found') { emit('deleted'); return }
    if (alive && ticket === generation) error.value = membershipError({t, te}, e)
  } finally {
    if (alive && ticket === generation) loading.value = false
  }
}

async function refreshClaim(row: ClaimRow) {
  const ticket = generation, supplier = props.supplier, id = props.memberId
  try {
    const fresh = await membershipApi.claim(supplier, row.claim_id)
    if (!alive || ticket !== generation || supplier !== props.supplier || id !== props.memberId) return
    claims.value = claims.value.map(c => (c.claim_id === fresh.claim_id ? fresh : c))
  } catch (e) {
    if (alive && ticket === generation) error.value = membershipError({t, te}, e)
  }
}

async function finished(message: string) {
  draft.reset()
  // Okno se zavře dřív, než formulář stihne ohlásit uvolnění zámku; jinak by akce detailu zůstaly zašedlé.
  issueOpen.value = false; locks.value.issue = false
  reverseClaim.value = null; locks.value.reverse = false
  confirmDone(message)
  await load()
  emit('changed')
}

async function revisionFinished() {
  draft.reset()
  // Stejně jako u předpisu: zámek uvolní detail, jinak by se pod přehledem objevil formulář první revize.
  revisionOpen.value = false; locks.value.revision = false; revisionDirty.value = false
  confirmDone(t('nonprofitMembership.revisionDone'))
  await load()
  emit('changed')
}

// Zastaralý podklad opravy: oprava se zavře, detail i seznam se načtou znovu a uživatel ji otevře nad aktuálními údaji.
async function correctionStale() {
  draft.reset()
  correction.value = null
  locks.value.correction = false
  refreshPending = false
  // Varování platí hned; chyba kteréhokoli dalšího načtení ho smaže (hlášky by si odporovaly), novější úspěšné nechá.
  staleNotice.value = true
  await load()
  emit('changed')
}

async function correctionFinished(mode: 'identity' | 'delete' | 'void', label: string) {
  draft.reset()
  // Formulář se odpojí dřív, než stihne ohlásit uvolnění zámku; jinak by akce detailu zůstaly zašedlé.
  correction.value = null
  locks.value.correction = false
  // Nejdřív odebrat smazaného člena z výběru, teprve pak obnovit seznam (hláška o smazání se nepřepíše).
  if (mode === 'delete') { emit('deleted'); emit('changed'); return }
  if (mode === 'identity' && label) emit('relabel', label)
  confirmDone(t(`nonprofitMembership.correction.done_${mode}`))
  await load()
  emit('changed')
  // Spouštěcí akce je po dokončení skrytá, fokus jde na nadpis detailu.
  await nextTick()
  heading.value?.focus()
}

/** Důvod blokace ze serveru jako přeložená věta (kód chyby je stejný jako odpověď zakázané akce). */
function blockReason(code: string | null | undefined): string | undefined {
  return code ? membershipError({ t, te }, code) : undefined
}

function startCorrection(mode: 'identity' | 'delete' | 'void') {
  if (anyLocked.value) return
  issueOpen.value = false; reverseClaim.value = null; revisionOpen.value = false
  correction.value = mode
  staleNotice.value = false
}

function startReverse(row: ClaimRow) {
  if (anyLocked.value) return
  issueOpen.value = false
  reverseClaim.value = row
}

// Rozepsaná první revize se obnovou neodpojí: kdyby mezitím vznikla platná revize, formulář by i s textem zmizel.
const refreshBlocked = computed(() => anyLocked.value || revisionDirty.value)
watch(() => props.refreshVersion, () => {
  if (refreshBlocked.value) refreshPending = true
  else void load()
})
watch(refreshBlocked, blocked => {
  if (!blocked && refreshPending) { refreshPending = false; void load() }
})
watch(() => props.memberId, () => { draft.reset(); generation++; refreshPending = false; identity.value = null; history.value = []; corrections.value = null; erased.value = false; correction.value = null; claims.value = []; nextAfter.value = null; issueOpen.value = false; reverseClaim.value = null; staleNotice.value = false; void load() })
watch(() => props.supplier, () => { draft.reset(); generation++; refreshPending = false; loading.value = false; identity.value = null; history.value = []; corrections.value = null; erased.value = false; correction.value = null; claims.value = []; nextAfter.value = null; issueOpen.value = false; reverseClaim.value = null; error.value = ''; staleNotice.value = false })
onBeforeUnmount(() => { alive = false; generation++ })
onMounted(() => { void load() })
let firstActivation = true
onActivated(() => {
  if (firstActivation) { firstActivation = false; return }
  if (refreshBlocked.value) refreshPending = true
  else void load()
})

// Oprava zavírá formulář revize, předpisu i storna. Rozepsaný text se nesmí ztratit tiše, proto jsou akce
// oprav zašedlé s důvodem, dokud je jiný formulář otevřený; obdobně „Změnit stav“ při rozepsané opravě.
const formOpen = computed(() => issueOpen.value || reverseClaim.value !== null || revisionOpen.value || revisionDirty.value)
const formReason = (block?: string | null) => (formOpen.value ? t('nonprofitMembership.correction.closeFormFirst') : blockReason(block))

const actions = computed<ActionItem[]>(() => [
  // Bez platné revize je hlavní akcí zápis první revize (předpis ještě nejde), jinak nový předpis.
  { key: 'new-charge', label: t('nonprofitMembership.newCharge'), icon: 'plus', tier: valid.value.length ? 'primary' : 'secondary', variant: 'primary', show: manage.value, disabled: anyLocked.value || loading.value || !valid.value.length, disabledReason: t('nonprofitMembership.newChargeNeedsRevision'), run: () => { reverseClaim.value = null; issueOpen.value = true } },
  { key: 'change-status', label: valid.value.length ? t('nonprofitMembership.changeStatus') : t('nonprofitMembership.firstRevision'), icon: valid.value.length ? 'edit' : 'plus',
    tier: valid.value.length ? 'secondary' : 'primary', variant: valid.value.length ? 'neutral' : 'primary', show: manage.value && !revisionOpen.value, disabled: anyLocked.value || loading.value || correction.value !== null,
    disabledReason: t('nonprofitMembership.correction.closeCorrectionFirst'), run: () => { correction.value = null; revisionOpen.value = true } },
  // Zablokovaná oprava zůstává v „…“ zašedlá s důvodem ze serveru, aby bylo vidět, proč nejde.
  { key: 'fix-identity', label: t('nonprofitMembership.correction.title_identity'), icon: 'edit', tier: 'overflow',
    show: manage.value && !!corrections.value && correction.value === null, disabled: anyLocked.value || loading.value || formOpen.value || !corrections.value?.can_edit_identity,
    disabledReason: formReason(corrections.value?.edit_identity_block), run: () => startCorrection('identity') },
  { key: 'reload', label: t('nonprofitMembership.reload'), icon: 'cycle', tier: 'overflow', disabled: loading.value || anyLocked.value || revisionDirty.value, disabledReason: revisionDirty.value ? t('nonprofitMembership.correction.closeFormFirst') : undefined, run: () => { staleNotice.value = false; void load() } },
  { key: 'void-revision', label: t('nonprofitMembership.correction.title_void'), icon: 'uturn', tier: 'overflow', variant: 'danger',
    show: manage.value && (!!voidable.value || !!corrections.value?.void_block) && correction.value === null, disabled: anyLocked.value || loading.value || formOpen.value || !voidable.value,
    disabledReason: formReason(corrections.value?.void_block), run: () => startCorrection('void') },
  { key: 'delete-member', label: t('nonprofitMembership.correction.title_delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
    show: manage.value && !!corrections.value && correction.value === null, disabled: anyLocked.value || loading.value || formOpen.value || !corrections.value?.can_delete,
    disabledReason: formReason(corrections.value?.delete_block), run: () => startCorrection('delete') },
])

/**
 * Formuláře detailu jsou v okně (vzor detail majetku). Okno se zavře jen tehdy, když ho formulář nedrží:
 * rozběhnutý zápis by jinak ztratil, kam ohlásit výsledek.
 */
const memberTitle = computed(() => (identity.value ? `${identity.value.member_number} · ${displayLabel.value}` : displayLabel.value))
function closeIssue() { if (!locks.value.issue && draft.confirmClose()) issueOpen.value = false }
function closeReverse() { if (!locks.value.reverse && draft.confirmClose()) reverseClaim.value = null }
function closeCorrection() { if (!locks.value.correction && draft.confirmClose()) correction.value = null }
function closeRevision() {
  if (locks.value.revision) return
  // Formulář revize hlásí rozepsanost sám (i bez psaní, např. změnou stavu ve výběru).
  if (revisionDirty.value) draft.mark()
  if (draft.confirmClose()) revisionOpen.value = false
}

/** Akce řádku předpisu jako v ostatních hustých tabulkách: ikona obnovy a storno v jednom místě. */
function claimActions(claim: ClaimRow): RowAction[] {
  return [
    { key: 'refresh-claim', label: t('nonprofitMembership.refreshClaim'), icon: 'cycle', disabled: loading.value || anyLocked.value, run: () => { void refreshClaim(claim) } },
    { key: 'reverse-claim', label: t('nonprofitMembership.reverse'), icon: 'uturn', variant: 'danger', show: manage.value && claim.reversal_claim_id === null,
      disabled: anyLocked.value || claim.settlement.pairing_id !== null, disabledReason: claim.settlement.pairing_id !== null ? t('nonprofitMembership.reversePaired') : undefined, run: () => startReverse(claim) },
  ]
}
</script>

<template>
  <div class="space-y-6" data-section="member-detail">
    <!-- Hlavička jako detail kontaktu a majetku: odkaz zpět, jméno se stavem, akce vpravo. -->
    <header class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div class="min-w-0">
        <slot name="back" />
        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
          <h1 ref="heading" tabindex="-1" class="min-w-0 break-words text-2xl font-semibold focus:outline-none" data-focus="member-heading">{{ displayLabel || t('common.loading') }}</h1>
          <span v-if="current" :class="memberStatusBadge(current.status)" data-field="status-badge">{{ t(`nonprofitMembership.status_${current.status}`) }}</span>
        </div>
        <p v-if="identity" class="mt-0.5 text-sm text-neutral-500" data-field="member-identity">{{ t('nonprofitMembership.memberNumber') }} <span class="font-mono text-neutral-700">{{ identity.member_number }}</span></p>
      </div>
      <ActionBar :actions="actions" />
    </header>

    <p v-if="error" role="alert" :class="errorBox" data-section="detail-error">{{ error }}</p>
    <p v-if="loading && !identity" role="status" class="text-sm text-neutral-500" data-section="detail-loading">{{ t('common.loading') }}</p>
    <p v-if="staleNotice" role="alert" :class="warningBox" data-section="detail-stale">{{ t('nonprofitMembership.correction.staleClosed') }}</p>
    <p v-if="erased" role="status" :class="warningBox" data-section="detail-erased">{{ t('nonprofitMembership.erasure.detailErased') }}</p>

    <section v-if="identity" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-section="member-status">
      <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('nonprofitMembership.detailColumns.summary') }}</h2>
      <dl class="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4" data-section="claims-summary">
        <div>
          <dt class="text-neutral-500">{{ t('nonprofitMembership.statusToday', { date: formatDate(today) }) }}</dt>
          <dd v-if="current" class="mt-1 flex flex-wrap items-center gap-2" data-field="status-today">
            <span :class="memberStatusBadge(current.status)">{{ t(`nonprofitMembership.status_${current.status}`) }}</span>
            <span class="text-neutral-500">{{ t('nonprofitMembership.sinceDate', { date: formatDate(current.effective_from) }) }}</span>
          </dd>
          <dd v-else class="mt-1 text-warning-700" data-field="status-today">{{ t('nonprofitMembership.statusNoneToday', { date: formatDate(today) }) }}</dd>
          <dd v-if="manage && !valid.length" class="mt-1 text-xs text-neutral-500" data-field="first-revision-hint">{{ t('nonprofitMembership.firstRevisionHint') }}</dd>
          <dd v-for="revision in upcoming" :key="revision.id" class="mt-1 text-warning-700" data-field="status-upcoming">
            {{ t('nonprofitMembership.statusUpcoming', { status: t(`nonprofitMembership.status_${revision.status}`), date: formatDate(revision.effective_from) }) }}
          </dd>
        </div>
        <div><dt class="text-neutral-500">{{ t('nonprofitMembership.sumIssued') }}</dt><dd class="mt-1 font-mono text-base tabular-nums" data-field="sum-issued">{{ formatMoneyMinor(issued) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('nonprofitMembership.sumOpen') }}</dt><dd class="mt-1 font-mono text-base tabular-nums" data-field="sum-open">{{ formatMoneyMinor(openTotal) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('nonprofitMembership.sumNeedsReview') }}</dt><dd class="mt-1 text-base tabular-nums" :class="needsReview ? 'text-warning-700' : ''" data-field="sum-needs-review">{{ needsReview }}</dd></div>
      </dl>
      <p class="mt-3 text-xs text-neutral-500" data-field="client-ref">{{ t('nonprofitMembership.clientRef', { client: displayLabel }) }}</p>
    </section>


    <section class="rounded-lg border border-neutral-200 bg-surface shadow-sm" data-section="history">
      <h2 class="px-5 pb-2 pt-4 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('nonprofitMembership.history') }}</h2>
      <p v-if="identity && !history.length" class="px-5 pb-4 text-sm text-neutral-500">{{ t('nonprofitMembership.historyEmpty') }}</p>
      <div v-if="history.length" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
            <tr>
              <th scope="col" class="px-4 py-2 text-left font-medium">{{ t('nonprofitMembership.detailColumns.revision') }}</th>
              <th scope="col" class="px-4 py-2 text-left font-medium">{{ t('nonprofitMembership.detailColumns.status') }}</th>
              <th scope="col" class="px-4 py-2 text-left font-medium">{{ t('nonprofitMembership.detailColumns.from') }}</th>
              <th scope="col" class="px-4 py-2 text-left font-medium">{{ t('nonprofitMembership.detailColumns.basis') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="revision in history" :key="revision.id">
              <tr v-if="revision.voids_revision_id != null" class="align-top" data-section="history-void-row">
                <td class="whitespace-nowrap px-4 py-2.5 font-medium tabular-nums">#{{ revision.revision_no }}</td>
                <td class="px-4 py-2.5" colspan="2">
                  <span class="flex flex-wrap items-center gap-2">
                    <span :class="loudBadge('danger')">{{ t('nonprofitMembership.correction.voidingBadge') }}</span>
                    <span>{{ t('nonprofitMembership.correction.voidingOf', { no: history.find(r => r.id === revision.voids_revision_id)?.revision_no ?? '?' }) }}</span>
                  </span>
                </td>
                <td class="px-4 py-2.5"><span v-if="revision.request?.reason" class="break-words" data-field="void-reason">{{ revision.request.reason }}</span></td>
              </tr>
              <tr v-else class="align-top" data-section="history-row" :data-voided="voided.has(revision.id) ? 'true' : undefined">
                <td class="whitespace-nowrap px-4 py-2.5 font-medium tabular-nums" :class="{ 'line-through text-neutral-500': voided.has(revision.id) }">#{{ revision.revision_no }}</td>
                <td class="px-4 py-2.5">
                  <span class="flex flex-wrap items-center gap-2">
                    <span :class="memberStatusBadge(revision.status)">{{ t(`nonprofitMembership.status_${revision.status}`) }}</span>
                    <span v-if="voided.has(revision.id)" :class="quietBadge('neutral')" data-field="voided-badge">{{ t('nonprofitMembership.correction.voidedBy', { no: voided.get(revision.id)?.revision_no }) }}</span>
                    <span v-else-if="current?.id === revision.id" :class="quietBadge('success')">{{ t('nonprofitMembership.validToday') }}</span>
                    <span v-else-if="revision.effective_from > today" :class="quietBadge('warning')">{{ t('nonprofitMembership.notYetValid') }}</span>
                  </span>
                </td>
                <td class="whitespace-nowrap px-4 py-2.5 tabular-nums" :class="{ 'line-through text-neutral-500': voided.has(revision.id) }">{{ formatDate(revision.effective_from) }}</td>
                <td class="min-w-[16rem] px-4 py-2.5">
                  <p class="break-words">{{ revision.legal_basis.reference }}</p>
                  <p class="break-words text-xs text-neutral-500">{{ revision.legal_basis.evidence }}</p>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <section class="rounded-lg border border-neutral-200 bg-surface shadow-sm" data-section="claims">
      <div class="px-5 pb-2 pt-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('nonprofitMembership.claims') }}</h2>
        <p class="mt-1 text-xs text-neutral-500">{{ t('nonprofitMembership.claimsHelp') }}</p>
        <p v-if="needsReview" class="mt-2 text-sm text-warning-700" data-section="unproven-note">{{ t('nonprofitMembership.unprovenNote') }}</p>
        <p v-if="nextAfter !== null" class="mt-2 text-sm text-neutral-500" data-section="partial-note">{{ t('nonprofitMembership.partialSummary') }}</p>
      </div>
      <p v-if="identity && !claims.length && !loading" class="px-5 pb-4 text-sm text-neutral-500">{{ t('nonprofitMembership.claimsEmpty') }}</p>
      <div v-if="claims.length" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
            <tr>
              <th scope="col" class="px-4 py-2 text-left font-medium">{{ t('nonprofitMembership.detailColumns.title') }}</th>
              <th scope="col" class="px-4 py-2 text-right font-medium">{{ t('nonprofitMembership.detailColumns.amount') }}</th>
              <th scope="col" class="px-4 py-2 text-left font-medium">{{ t('nonprofitMembership.detailColumns.dates') }}</th>
              <th scope="col" class="px-4 py-2 text-left font-medium">{{ t('nonprofitMembership.detailColumns.settlement') }}</th>
              <th scope="col" class="px-4 py-2"><span class="sr-only">{{ t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="claim in claims" :key="claim.claim_id" class="align-top" data-section="claim-row" :data-claim="claim.claim_id">
              <td class="min-w-[12rem] px-4 py-2.5">
                <p class="break-words font-medium">{{ claim.title_key }}</p>
                <p class="text-xs text-neutral-500" data-field="claim-period">{{ formatDateRange(claim.period_from, claim.period_to) }}</p>
                <p v-if="claim.variable_symbol" class="text-xs text-neutral-500" data-field="claim-variable-symbol">{{ t('nonprofitMembership.variableSymbol') }}: <span class="font-mono">{{ claim.variable_symbol }}</span></p>
              </td>
              <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono tabular-nums" data-field="claim-amount">{{ formatMoneyMinor(claim.amount_cents) }}</td>
              <td class="whitespace-nowrap px-4 py-2.5 text-xs" data-field="claim-amounts">
                <p>{{ t('nonprofitMembership.accountingDate') }} {{ formatDate(claim.accounting_date) }}</p>
                <p class="text-neutral-500">{{ t('nonprofitMembership.dueDate') }} {{ formatDate(claim.due_date) }}</p>
              </td>
              <td class="min-w-[14rem] px-4 py-2.5"><MembershipSettlement :settlement="claim.settlement" /></td>
              <td class="px-4 py-2 text-right"><RowActionsMenu :actions="claimActions(claim)" :inline-count="2" icon-only /></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="nextAfter !== null" class="flex justify-center border-t border-neutral-200 px-4 py-3">
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" data-action="more-claims" :disabled="loading" @click="load(true)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('nonprofitMembership.moreClaims') }}
        </button>
      </div>
    </section>

    <Modal v-if="manage && identity && correction" :title="`${t(`nonprofitMembership.correction.title_${correction}`)} · ${memberTitle}`" width-class="max-w-2xl" @close="closeCorrection">
      <MembershipCorrectionFlow
        :key="`${correction}-${identity.id}`"
        :supplier="supplier" :can-manage="canManage" :mode="correction" :member="identity" :label="displayLabel"
        :revision="correction === 'void' ? voidable : null" :head-revision-no="corrections?.head_revision_no ?? 0"
        @done="(mode, label) => correctionFinished(mode, label)" @close="closeCorrection" @lock="locks.correction = $event" @stale="correctionStale()"
        @input="draft.mark" @change="draft.mark"
      />
    </Modal>
    <Modal v-if="manage && identity && !correction && revisionOpen" :title="`${t('nonprofitMembership.revisionTitle')} · ${memberTitle}`" width-class="max-w-2xl" @close="closeRevision">
      <MembershipRevisionForm
        :supplier="supplier" :can-manage="canManage" :member="identity" :history="history"
        @done="revisionFinished()" @close="closeRevision" @lock="locks.revision = $event" @dirty="revisionDirty = $event"
        @input="draft.mark" @change="draft.mark"
      />
    </Modal>
    <Modal v-if="manage && issueOpen" :title="`${t('nonprofitMembership.issueTitle')} · ${memberTitle}`" width-class="max-w-4xl" @close="closeIssue">
      <MembershipIssueFlow
        :supplier="supplier" :can-manage="canManage" :member="member" :history="history" :periods="periods" :case-symbols="caseSymbols"
        @done="finished(t('nonprofitMembership.issueDone'))" @close="closeIssue" @lock="locks.issue = $event"
        @input="draft.mark" @change="draft.mark"
      />
    </Modal>
    <Modal v-if="manage && reverseClaim" :title="`${t('nonprofitMembership.reverseTitle')} · ${memberTitle}`" width-class="max-w-2xl" @close="closeReverse">
      <MembershipReverseFlow
        :key="reverseClaim.claim_id"
        :supplier="supplier" :can-manage="canManage" :member="member" :claim="reverseClaim" :periods="periods"
        @done="finished(t('nonprofitMembership.reverseDone'))" @close="closeReverse" @lock="locks.reverse = $event"
        @input="draft.mark" @change="draft.mark"
      />
    </Modal>
  </div>
</template>

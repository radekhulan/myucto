<script setup lang="ts">
import { computed, nextTick, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnIconSm, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import type { AccountingPeriod } from '@/api/accounting'
import { membershipApi, type ClaimRow, type MemberCorrections, type MemberIdentity, type MemberRevision } from '@/api/nonprofitMembership'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { badgeBase, errorBox, errorCode, membershipError, formatDateRange, memberStatusBadge, revisionAt, validRevisions, voidedRevisions } from './membershipFormat'
import MembershipCorrectionFlow from './MembershipCorrectionFlow.vue'
import MembershipIssueFlow from './MembershipIssueFlow.vue'
import MembershipReverseFlow from './MembershipReverseFlow.vue'
import MembershipRevisionForm from './MembershipRevisionForm.vue'
import MembershipSettlement from './MembershipSettlement.vue'

const props = defineProps<{ supplier: number; canManage: boolean; memberId: number; label: string; periods: AccountingPeriod[]; refreshVersion?: number }>()
const emit = defineEmits<{ changed: []; close: []; deleted: []; relabel: [label: string] }>()
const { t, te } = useI18n()

const identity = ref<MemberIdentity | null>(null)
const history = ref<MemberRevision[]>([])
const corrections = ref<MemberCorrections | null>(null)
/** Otevřená oprava (kontakt a číslo, smazání, zrušení revize); jen jedna najednou. */
const correction = ref<'identity' | 'delete' | 'void' | null>(null)
const heading = ref<HTMLElement | null>(null)
const correctionPane = ref<HTMLElement | null>(null)
const claims = ref<ClaimRow[]>([])
const nextAfter = ref<number | null>(null)
const loading = ref(false)
const error = ref('')
const notice = ref('')
// Zavřená zastaralá oprava není úspěch: vlastní varovná hláška, zmizí s dalším otevřením opravy.
const staleNotice = ref(false)
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
const member = computed(() => ({ id: props.memberId, member_number: identity.value?.member_number ?? '', label: props.label }))

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
  issueOpen.value = false
  reverseClaim.value = null
  notice.value = message
  await load()
  emit('changed')
}

/** Formulář revize se po zápisu sbalí, proto hlášku o úspěchu drží detail stejně jako u předpisu a storna. */
async function revisionFinished() {
  revisionOpen.value = false
  notice.value = t('nonprofitMembership.revisionDone')
  await load()
  emit('changed')
}

// Zastaralý podklad opravy: oprava se zavře, detail i seznam se načtou znovu a uživatel ji otevře nad aktuálními údaji.
async function correctionStale() {
  correction.value = null
  locks.value.correction = false
  refreshPending = false
  notice.value = ''
  await load()
  staleNotice.value = !error.value
  emit('changed')
}

async function correctionFinished(mode: 'identity' | 'delete' | 'void', label: string) {
  // Formulář se odpojí dřív, než stihne ohlásit uvolnění zámku; jinak by akce detailu zůstaly zašedlé.
  correction.value = null
  locks.value.correction = false
  // Nejdřív odebrat smazaného člena z výběru, teprve pak obnovit seznam (hláška o smazání se nepřepíše).
  if (mode === 'delete') { emit('deleted'); emit('changed'); return }
  if (mode === 'identity' && label) emit('relabel', label)
  notice.value = t(`nonprofitMembership.correction.done_${mode}`)
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

async function startCorrection(mode: 'identity' | 'delete' | 'void') {
  if (anyLocked.value) return
  issueOpen.value = false; reverseClaim.value = null; revisionOpen.value = false
  correction.value = mode
  staleNotice.value = false
  // Formulář je pod historií a předpisy; bez posunu by otevření zůstalo mimo obrazovku.
  await nextTick()
  correctionPane.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
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
watch(() => props.memberId, () => { generation++; refreshPending = false; identity.value = null; history.value = []; corrections.value = null; correction.value = null; claims.value = []; nextAfter.value = null; issueOpen.value = false; reverseClaim.value = null; notice.value = ''; staleNotice.value = false; void load() })
watch(() => props.supplier, () => { generation++; refreshPending = false; loading.value = false; identity.value = null; history.value = []; corrections.value = null; correction.value = null; claims.value = []; nextAfter.value = null; issueOpen.value = false; reverseClaim.value = null; error.value = ''; notice.value = ''; staleNotice.value = false })
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
  { key: 'new-charge', label: t('nonprofitMembership.newCharge'), icon: 'plus', tier: 'primary', variant: 'primary', show: props.canManage, disabled: anyLocked.value || loading.value || !valid.value.length, disabledReason: t('nonprofitMembership.newChargeNeedsRevision'), run: () => { reverseClaim.value = null; issueOpen.value = true } },
  { key: 'change-status', label: t('nonprofitMembership.changeStatus'), icon: 'edit', show: props.canManage && valid.value.length > 0 && !revisionOpen.value, disabled: anyLocked.value || loading.value || correction.value !== null,
    disabledReason: t('nonprofitMembership.correction.closeCorrectionFirst'), run: () => { correction.value = null; revisionOpen.value = true } },
  // Zablokovaná oprava zůstává v „…“ zašedlá s důvodem ze serveru, aby bylo vidět, proč nejde.
  { key: 'fix-identity', label: t('nonprofitMembership.correction.title_identity'), icon: 'edit', tier: 'overflow',
    show: props.canManage && !!corrections.value && correction.value === null, disabled: anyLocked.value || loading.value || formOpen.value || !corrections.value?.can_edit_identity,
    disabledReason: formReason(corrections.value?.edit_identity_block), run: () => startCorrection('identity') },
  { key: 'reload', label: t('nonprofitMembership.reload'), icon: 'cycle', disabled: loading.value || anyLocked.value || revisionDirty.value, disabledReason: revisionDirty.value ? t('nonprofitMembership.correction.closeFormFirst') : undefined, run: () => { void load() } },
  { key: 'close-detail', label: t('nonprofitMembership.close'), icon: 'x', disabled: anyLocked.value, run: () => emit('close') },
  { key: 'void-revision', label: t('nonprofitMembership.correction.title_void'), icon: 'uturn', tier: 'overflow', variant: 'danger',
    show: props.canManage && (!!voidable.value || !!corrections.value?.void_block) && correction.value === null, disabled: anyLocked.value || loading.value || formOpen.value || !voidable.value,
    disabledReason: formReason(corrections.value?.void_block), run: () => startCorrection('void') },
  { key: 'delete-member', label: t('nonprofitMembership.correction.title_delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
    show: props.canManage && !!corrections.value && correction.value === null, disabled: anyLocked.value || loading.value || formOpen.value || !corrections.value?.can_delete,
    disabledReason: formReason(corrections.value?.delete_block), run: () => startCorrection('delete') },
])
</script>

<template>
  <section class="space-y-5 rounded-lg border border-neutral-200 bg-surface p-4" data-section="member-detail">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 ref="heading" tabindex="-1" class="min-w-0 break-words text-lg font-semibold focus:outline-none" data-focus="member-heading">{{ identity ? `${identity.member_number} · ${label}` : label }}</h2>
      <ActionBar :actions="actions" />
    </div>
    <p v-if="error" role="alert" :class="errorBox" data-section="detail-error">{{ error }}</p>
    <p v-if="loading && !identity" role="status" class="text-sm text-neutral-500" data-section="detail-loading">{{ t('common.loading') }}</p>
    <p v-if="notice" role="status" class="text-sm text-success-600" data-section="detail-notice">{{ notice }}</p>
    <p v-if="staleNotice" role="alert" class="text-sm text-warning-700" data-section="detail-stale">{{ t('nonprofitMembership.correction.staleClosed') }}</p>

    <div v-if="identity" class="space-y-2" data-section="member-status">
      <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.clientRef', { client: label }) }}</p>
      <p v-if="current" data-field="status-today">
        {{ t('nonprofitMembership.statusToday', { date: formatDate(today) }) }}:
        <span :class="memberStatusBadge(current.status)">{{ t(`nonprofitMembership.status_${current.status}`) }}</span>
        ({{ t('nonprofitMembership.sinceDate', { date: formatDate(current.effective_from) }) }})
      </p>
      <p v-else class="text-warning-700" data-field="status-today">{{ t('nonprofitMembership.statusNoneToday', { date: formatDate(today) }) }}</p>
      <p v-for="revision in upcoming" :key="revision.id" class="text-sm text-warning-700" data-field="status-upcoming">
        {{ t('nonprofitMembership.statusUpcoming', { status: t(`nonprofitMembership.status_${revision.status}`), date: formatDate(revision.effective_from) }) }}
      </p>
    </div>

    <div class="space-y-2" data-section="history">
      <h3 class="font-semibold">{{ t('nonprofitMembership.history') }}</h3>
      <p v-if="identity && !history.length" class="text-sm text-neutral-500">{{ t('nonprofitMembership.historyEmpty') }}</p>
      <template v-for="revision in history" :key="revision.id">
        <div v-if="revision.voids_revision_id != null" class="space-y-1 rounded border border-neutral-200 p-3 text-sm" data-section="history-void-row">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium">#{{ revision.revision_no }}</span>
            <span :class="[badgeBase, 'bg-danger-50 text-danger-700']">{{ t('nonprofitMembership.correction.voidingBadge') }}</span>
            <span>{{ t('nonprofitMembership.correction.voidingOf', { no: history.find(r => r.id === revision.voids_revision_id)?.revision_no ?? '?' }) }}</span>
          </div>
          <p v-if="revision.request?.reason" data-field="void-reason">{{ revision.request.reason }}</p>
        </div>
        <div v-else class="space-y-1 rounded border border-neutral-200 p-3 text-sm" data-section="history-row" :data-voided="voided.has(revision.id) ? 'true' : undefined">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium" :class="{ 'line-through text-neutral-500': voided.has(revision.id) }">#{{ revision.revision_no }}</span>
            <span :class="memberStatusBadge(revision.status)">{{ t(`nonprofitMembership.status_${revision.status}`) }}</span>
            <span :class="{ 'line-through text-neutral-500': voided.has(revision.id) }">{{ t('nonprofitMembership.sinceDate', { date: formatDate(revision.effective_from) }) }}</span>
            <span v-if="voided.has(revision.id)" :class="memberStatusBadge('ended')" data-field="voided-badge">{{ t('nonprofitMembership.correction.voidedBy', { no: voided.get(revision.id)?.revision_no }) }}</span>
            <span v-else-if="current?.id === revision.id" :class="[badgeBase, 'bg-success-50 text-success-600']">{{ t('nonprofitMembership.validToday') }}</span>
            <span v-else-if="revision.effective_from > today" :class="[badgeBase, 'bg-warning-50 text-warning-700']">{{ t('nonprofitMembership.notYetValid') }}</span>
          </div>
          <p>{{ revision.legal_basis.reference }}</p>
          <p class="text-neutral-500">{{ revision.legal_basis.evidence }}</p>
        </div>
      </template>
    </div>

    <div v-if="canManage && identity && correction" ref="correctionPane" class="scroll-mt-4">
      <MembershipCorrectionFlow
        :key="`${correction}-${identity.id}`"
        :supplier="supplier" :can-manage="canManage" :mode="correction" :member="identity" :label="label"
        :revision="correction === 'void' ? voidable : null" :head-revision-no="corrections?.head_revision_no ?? 0"
        @done="(mode, label) => correctionFinished(mode, label)" @close="correction = null" @lock="locks.correction = $event" @stale="correctionStale()"
      />
    </div>

    <MembershipRevisionForm
      v-if="canManage && identity && !correction && (!valid.length || revisionOpen || locks.revision)"
      :supplier="supplier" :can-manage="canManage" :member="identity" :history="history"
      @done="revisionFinished()" @close="revisionOpen = false" @lock="locks.revision = $event" @dirty="revisionDirty = $event"
    />

    <MembershipIssueFlow
      v-if="canManage && issueOpen"
      :supplier="supplier" :can-manage="canManage" :member="member" :history="history" :periods="periods" :case-symbols="caseSymbols"
      @done="finished(t('nonprofitMembership.issueDone'))" @close="issueOpen = false" @lock="locks.issue = $event"
    />
    <MembershipReverseFlow
      v-if="canManage && reverseClaim"
      :key="reverseClaim.claim_id"
      :supplier="supplier" :can-manage="canManage" :member="member" :claim="reverseClaim" :periods="periods"
      @done="finished(t('nonprofitMembership.reverseDone'))" @close="reverseClaim = null" @lock="locks.reverse = $event"
    />

    <div class="space-y-3" data-section="claims">
      <h3 class="font-semibold">{{ t('nonprofitMembership.claims') }}</h3>
      <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.claimsHelp') }}</p>
      <dl class="grid gap-3 sm:grid-cols-3" data-section="claims-summary">
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.sumIssued') }}</dt><dd class="tabular-nums" data-field="sum-issued">{{ formatMoneyMinor(issued) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.sumOpen') }}</dt><dd class="tabular-nums" data-field="sum-open">{{ formatMoneyMinor(openTotal) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.sumNeedsReview') }}</dt><dd data-field="sum-needs-review">{{ needsReview }}</dd></div>
      </dl>
      <p v-if="needsReview" class="text-sm text-warning-700" data-section="unproven-note">{{ t('nonprofitMembership.unprovenNote') }}</p>
      <p v-if="nextAfter !== null" class="text-sm text-neutral-500" data-section="partial-note">{{ t('nonprofitMembership.partialSummary') }}</p>
      <p v-if="identity && !claims.length && !loading" class="text-sm text-neutral-500">{{ t('nonprofitMembership.claimsEmpty') }}</p>
      <div v-for="claim in claims" :key="claim.claim_id" class="grid gap-3 rounded border border-neutral-200 p-3 sm:grid-cols-[1fr_1fr_auto]" data-section="claim-row" :data-claim="claim.claim_id">
        <div class="min-w-0 space-y-1 text-sm">
          <p class="break-words font-medium">{{ claim.title_key }}</p>
          <p v-if="claim.variable_symbol" class="text-neutral-500" data-field="claim-variable-symbol">{{ t('nonprofitMembership.variableSymbol') }}: {{ claim.variable_symbol }}</p>
          <p class="text-neutral-500" data-field="claim-period">{{ formatDateRange(claim.period_from, claim.period_to) }}</p>
          <p data-field="claim-amounts"><span class="whitespace-nowrap font-medium tabular-nums">{{ formatMoneyMinor(claim.amount_cents) }}</span> · {{ t('nonprofitMembership.accountingDate') }} {{ formatDate(claim.accounting_date) }} · {{ t('nonprofitMembership.dueDate') }} {{ formatDate(claim.due_date) }}</p>
        </div>
        <MembershipSettlement :settlement="claim.settlement" />
        <div class="flex flex-wrap items-start gap-2">
          <button type="button" :class="btnIconSm('neutral')" :title="t('nonprofitMembership.refreshClaim')" :aria-label="t('nonprofitMembership.refreshClaim')" data-action="refresh-claim" :disabled="loading || anyLocked" @click="refreshClaim(claim)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          </button>
          <button v-if="canManage && claim.reversal_claim_id === null" type="button" :class="btnOutlineSm('danger')" data-action="reverse-claim" :disabled="anyLocked || claim.settlement.pairing_id !== null" :title="claim.settlement.pairing_id !== null ? t('nonprofitMembership.reversePaired') : undefined" @click="startReverse(claim)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>{{ t('nonprofitMembership.reverse') }}
          </button>
        </div>
      </div>
      <button v-if="nextAfter !== null" type="button" :class="btnOutline('neutral')" data-action="more-claims" :disabled="loading" @click="load(true)">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('nonprofitMembership.moreClaims') }}
      </button>
    </div>
  </section>
</template>

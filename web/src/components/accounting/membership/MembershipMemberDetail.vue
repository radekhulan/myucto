<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnIconSm, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import type { AccountingPeriod } from '@/api/accounting'
import { membershipApi, type ClaimRow, type MemberIdentity, type MemberRevision } from '@/api/nonprofitMembership'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { badgeBase, errorBox, membershipError, formatDateRange, memberStatusBadge, revisionAt } from './membershipFormat'
import MembershipIssueFlow from './MembershipIssueFlow.vue'
import MembershipReverseFlow from './MembershipReverseFlow.vue'
import MembershipRevisionForm from './MembershipRevisionForm.vue'
import MembershipSettlement from './MembershipSettlement.vue'

const props = defineProps<{ supplier: number; canManage: boolean; memberId: number; label: string; periods: AccountingPeriod[] }>()
const emit = defineEmits<{ changed: []; close: [] }>()
const { t, te } = useI18n()

const identity = ref<MemberIdentity | null>(null)
const history = ref<MemberRevision[]>([])
const claims = ref<ClaimRow[]>([])
const nextAfter = ref<number | null>(null)
const loading = ref(false)
const error = ref('')
const notice = ref('')
const issueOpen = ref(false)
/** Formulář revize je u člena s historií sbalený za akcí „Změnit stav členství“. */
const revisionOpen = ref(false)
const reverseClaim = ref<ClaimRow | null>(null)
const locks = ref({ revision: false, issue: false, reverse: false })
let alive = true
let generation = 0

const today = computed(() => appIsoDate())
const current = computed(() => revisionAt(history.value, today.value))
const upcoming = computed(() => history.value.filter(r => r.effective_from > today.value))
const anyLocked = computed(() => locks.value.revision || locks.value.issue || locks.value.reverse)
const member = computed(() => ({ id: props.memberId, member_number: identity.value?.member_number ?? '', label: props.label }))

const issued = computed(() => claims.value.filter(c => c.reversal_claim_id === null).reduce((sum, c) => sum + c.amount_cents, 0))
const provenOpen = computed(() => claims.value.reduce((sum, c) => sum + (c.settlement.open_cents ?? 0), 0))
const unproven = computed(() => claims.value.filter(c => c.settlement.status === 'unreconciled' || c.settlement.status === 'ambiguous').length)

async function load(append = false) {
  const ticket = ++generation, supplier = props.supplier, id = props.memberId
  loading.value = true; error.value = ''
  try {
    const data = await membershipApi.member(supplier, id, append ? nextAfter.value ?? 0 : 0, 50)
    if (!alive || ticket !== generation || supplier !== props.supplier || id !== props.memberId) return
    identity.value = data.member
    history.value = data.history
    claims.value = append ? [...claims.value, ...data.balance.items] : data.balance.items
    nextAfter.value = data.balance.next_after_claim_id
  } catch (e) {
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

function startReverse(row: ClaimRow) {
  if (anyLocked.value) return
  issueOpen.value = false
  reverseClaim.value = row
}

watch(() => props.memberId, () => { generation++; identity.value = null; history.value = []; claims.value = []; nextAfter.value = null; issueOpen.value = false; reverseClaim.value = null; notice.value = ''; void load() })
watch(() => props.supplier, () => { generation++; loading.value = false; identity.value = null; history.value = []; claims.value = []; nextAfter.value = null; issueOpen.value = false; reverseClaim.value = null; error.value = ''; notice.value = '' })
onBeforeUnmount(() => { alive = false; generation++ })
onMounted(() => { void load() })
let firstActivation = true
onActivated(() => {
  if (firstActivation) { firstActivation = false; return }
  void load()
})

const actions = computed<ActionItem[]>(() => [
  { key: 'new-charge', label: t('nonprofitMembership.newCharge'), icon: 'plus', tier: 'primary', variant: 'primary', show: props.canManage, disabled: anyLocked.value || loading.value || !history.value.length, disabledReason: t('nonprofitMembership.newChargeNeedsRevision'), run: () => { reverseClaim.value = null; issueOpen.value = true } },
  { key: 'change-status', label: t('nonprofitMembership.changeStatus'), icon: 'edit', show: props.canManage && history.value.length > 0 && !revisionOpen.value, disabled: anyLocked.value || loading.value, run: () => { revisionOpen.value = true } },
  { key: 'reload', label: t('nonprofitMembership.reload'), icon: 'cycle', disabled: loading.value || anyLocked.value, run: () => { void load() } },
  { key: 'close-detail', label: t('nonprofitMembership.close'), icon: 'x', disabled: anyLocked.value, run: () => emit('close') },
])
</script>

<template>
  <section class="space-y-5 rounded-lg border bg-surface p-4" data-section="member-detail">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 tabindex="-1" class="min-w-0 break-words text-lg font-semibold focus:outline-none" data-focus="member-heading">{{ identity ? `${identity.member_number} · ${label}` : label }}</h2>
      <ActionBar :actions="actions" />
    </div>
    <p v-if="error" role="alert" :class="errorBox" data-section="detail-error">{{ error }}</p>
    <p v-if="loading && !identity" role="status" class="text-sm text-neutral-500" data-section="detail-loading">{{ t('common.loading') }}</p>
    <p v-if="notice" role="status" class="text-sm text-success-600" data-section="detail-notice">{{ notice }}</p>

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
      <div v-for="revision in history" :key="revision.id" class="space-y-1 rounded border p-3 text-sm" data-section="history-row">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-medium">#{{ revision.revision_no }}</span>
          <span :class="memberStatusBadge(revision.status)">{{ t(`nonprofitMembership.status_${revision.status}`) }}</span>
          <span>{{ t('nonprofitMembership.sinceDate', { date: formatDate(revision.effective_from) }) }}</span>
          <span v-if="current?.id === revision.id" :class="[badgeBase, 'bg-success-50 text-success-600']">{{ t('nonprofitMembership.validToday') }}</span>
          <span v-else-if="revision.effective_from > today" :class="[badgeBase, 'bg-warning-50 text-warning-700']">{{ t('nonprofitMembership.notYetValid') }}</span>
        </div>
        <p>{{ revision.legal_basis.reference }}</p>
        <p class="text-neutral-500">{{ revision.legal_basis.evidence }}</p>
      </div>
    </div>

    <MembershipRevisionForm
      v-if="canManage && identity && (!history.length || revisionOpen || locks.revision)"
      :supplier="supplier" :can-manage="canManage" :member="identity" :history="history"
      @done="revisionFinished()" @close="revisionOpen = false" @lock="locks.revision = $event"
    />

    <MembershipIssueFlow
      v-if="canManage && issueOpen"
      :supplier="supplier" :can-manage="canManage" :member="member" :history="history" :periods="periods"
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
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.sumProvenOpen') }}</dt><dd class="tabular-nums" data-field="sum-proven-open">{{ formatMoneyMinor(provenOpen) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.sumUnproven') }}</dt><dd data-field="sum-unproven">{{ unproven }}</dd></div>
      </dl>
      <p v-if="unproven" class="text-sm text-warning-700" data-section="unproven-note">{{ t('nonprofitMembership.unprovenNote') }}</p>
      <p v-if="nextAfter !== null" class="text-sm text-neutral-500" data-section="partial-note">{{ t('nonprofitMembership.partialSummary') }}</p>
      <p v-if="identity && !claims.length && !loading" class="text-sm text-neutral-500">{{ t('nonprofitMembership.claimsEmpty') }}</p>
      <div v-for="claim in claims" :key="claim.claim_id" class="grid gap-3 rounded border p-3 sm:grid-cols-[1fr_1fr_auto]" data-section="claim-row" :data-claim="claim.claim_id">
        <div class="min-w-0 space-y-1 text-sm">
          <p class="break-words font-medium">{{ claim.title_key }}</p>
          <p class="text-neutral-500" data-field="claim-period">{{ formatDateRange(claim.period_from, claim.period_to) }}</p>
          <p data-field="claim-amounts"><span class="whitespace-nowrap font-medium tabular-nums">{{ formatMoneyMinor(claim.amount_cents) }}</span> · {{ t('nonprofitMembership.accountingDate') }} {{ formatDate(claim.accounting_date) }} · {{ t('nonprofitMembership.dueDate') }} {{ formatDate(claim.due_date) }}</p>
        </div>
        <MembershipSettlement :settlement="claim.settlement" />
        <div class="flex flex-wrap items-start gap-2">
          <button type="button" :class="btnIconSm('neutral')" :title="t('nonprofitMembership.refreshClaim')" :aria-label="t('nonprofitMembership.refreshClaim')" data-action="refresh-claim" :disabled="loading || anyLocked" @click="refreshClaim(claim)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          </button>
          <button v-if="canManage && claim.reversal_claim_id === null" type="button" :class="btnOutlineSm('danger')" data-action="reverse-claim" :disabled="anyLocked" @click="startReverse(claim)">
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

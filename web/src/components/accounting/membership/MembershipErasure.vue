<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { formInput as field, formLabel } from '@/components/ui/formStyles'
import { btnFilled, btnIconSm, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import {
  membershipErasureApi, type MembershipErasureAssessment, type MembershipErasureDetail, type MembershipErasureItem,
  type MembershipErasureProposal, type MembershipErasureStatus,
} from '@/api/nonprofitMembership'
import { errorBox, loudBadge, quietBadge, membershipError, membershipMemberLabel, warningBox } from './membershipFormat'

/**
 * Výmaz osobních údajů bývalých členů (#321, krok 10): posouzení lhůt, návrh, schválení a provedení.
 * Vzor tokem i texty je výmaz ve mzdách; data se načítají až při otevření sekce.
 */
const props = defineProps<{ supplier: number; canWrite: boolean; open: boolean }>()
const emit = defineEmits<{ erased: [] }>()
const { t, te } = useI18n()
const toast = useToast()

const proposals = ref<MembershipErasureProposal[]>([])
const assessment = ref<MembershipErasureAssessment | null>(null)
const detail = ref<MembershipErasureDetail | null>(null)
const loading = ref(false), busy = ref(false), loaded = ref(false)
const error = ref('')
const confirmation = ref('')
let alive = true, generation = 0

// Návrh čekající na schválení chce rozhodnutí, ostatní stavy jsou tiché.
const statusBadges: Record<MembershipErasureStatus, string> = {
  pending: loudBadge('warning'),
  approved: quietBadge('primary'),
  rejected: quietBadge('neutral'),
  executed: quietBadge('success'),
}
const statusBadge = (status: MembershipErasureStatus) => statusBadges[status]
const outcomeTone = (outcome: MembershipErasureItem['outcome']) => outcome === 'done' ? 'text-success-700' : outcome === 'pending' ? 'text-neutral-500' : 'text-warning-700'

const openProposal = computed(() => proposals.value.find(p => p.status === 'pending' || p.status === 'approved') ?? null)
const summary = computed(() => {
  const all = [...(assessment.value?.items ?? []), ...(assessment.value?.deleted_members ?? [])]
  const blocked = new Map<string, number>()
  for (const item of all) if (item.blocked_by) blocked.set(item.blocked_by, (blocked.get(item.blocked_by) ?? 0) + 1)
  return { erasable: all.filter(i => i.erasable).length, blocked: [...blocked.entries()] }
})
const current = computed(() => detail.value?.proposal ?? null)
const coolingOff = computed(() => {
  const from = current.value?.executable_from
  return current.value?.status === 'approved' && !!from && new Date(`${from.replace(' ', 'T')}Z`).getTime() > Date.now()
})
const started = computed(() => (detail.value?.items ?? []).some(i => i.outcome !== 'pending'))
const confirmed = computed(() => confirmation.value.trim() === detail.value?.confirmation)

function reason(code: string | null): string {
  if (!code) return ''
  const key = `nonprofitMembership.erasure.reason.${code}`
  return te(key) ? t(key) : code
}
function itemLabel(item: MembershipErasureItem): string {
  if (item.subject === 'deleted_member') return t('nonprofitMembership.erasure.deletedMemberLabel', { id: item.member_id })
  if (item.member_number === null) return t(item.outcome === 'done' ? 'nonprofitMembership.erasure.erasedMemberLabel' : 'nonprofitMembership.erasure.memberFallback', { id: item.member_id })
  return `${item.member_number} · ${membershipMemberLabel(item) || t('nonprofitMembership.erasure.memberFallback', { id: item.member_id })}`
}

async function load() {
  const ticket = ++generation, supplier = props.supplier
  loading.value = true; error.value = ''
  try {
    const [list, assessed] = await Promise.all([membershipErasureApi.proposals(supplier), membershipErasureApi.assessment(supplier)])
    if (!alive || ticket !== generation || supplier !== props.supplier) return
    proposals.value = list.items
    assessment.value = assessed
    loaded.value = true
    const shown = detail.value?.proposal.id ?? openProposal.value?.id ?? null
    if (shown !== null) await show(shown, ticket)
  } catch (e) { if (alive && ticket === generation) error.value = membershipError({ t, te }, e) }
  finally { if (alive && ticket === generation) loading.value = false }
}

async function show(id: number, ticket = ++generation) {
  const supplier = props.supplier
  try {
    const data = await membershipErasureApi.proposal(supplier, id)
    if (!alive || ticket !== generation || supplier !== props.supplier) return
    detail.value = data
  } catch (e) { if (alive && ticket === generation) error.value = membershipError({ t, te }, e) }
}

async function more() {
  const page = detail.value
  if (!page || page.next_after_member_id === null) return
  const ticket = generation, supplier = props.supplier
  try {
    const data = await membershipErasureApi.proposal(supplier, page.proposal.id, page.next_after_member_id)
    if (!alive || ticket !== generation || supplier !== props.supplier || detail.value?.proposal.id !== page.proposal.id) return
    detail.value = { ...data, items: [...page.items, ...data.items] }
  } catch (e) { if (alive && ticket === generation) error.value = membershipError({ t, te }, e) }
}

async function run(work: () => Promise<string>) {
  if (busy.value) return
  busy.value = true; error.value = ''
  const supplier = props.supplier
  try {
    const message = await work()
    if (!alive || supplier !== props.supplier) return
    // Potvrzení jako jinde v aplikaci (toast).
    toast.success(message)
    await load()
  } catch (e) { if (alive && supplier === props.supplier) error.value = membershipError({ t, te }, e) }
  finally { if (alive) busy.value = false }
}

const create = () => run(async () => {
  const { proposal } = await membershipErasureApi.create(props.supplier, null)
  detail.value = null
  await show(proposal.id)
  return t('nonprofitMembership.erasure.created')
})
const approve = (id: number) => run(async () => { await membershipErasureApi.approve(props.supplier, id); return t('nonprofitMembership.erasure.approved') })
const revoke = (id: number) => run(async () => { await membershipErasureApi.revoke(props.supplier, id); return t('nonprofitMembership.erasure.revoked') })
const reject = (id: number) => run(async () => { await membershipErasureApi.reject(props.supplier, id); return t('nonprofitMembership.erasure.rejected') })
const execute = (id: number) => run(async () => {
  const result = await membershipErasureApi.execute(props.supplier, id, confirmation.value.trim())
  confirmation.value = ''
  emit('erased')
  return result.remaining > 0
    ? t('nonprofitMembership.erasure.executedPartly', { done: result.processed, remaining: result.remaining })
    : t('nonprofitMembership.erasure.executed', result.summary)
})

const actions = computed<ActionItem[]>(() => [
  { key: 'erasure-create', label: t('nonprofitMembership.erasure.create'), icon: 'plus', tier: 'primary', variant: 'primary',
    // Souhrn je z první stránky posouzení; s dalšími stránkami rozhodne server (může navrhnout i člena dál v pořadí).
    show: props.canWrite, disabled: busy.value || loading.value || !!openProposal.value || (summary.value.erasable === 0 && assessment.value?.next_after == null),
    disabledReason: openProposal.value ? t('nonprofitMembership.erasure.openFirst') : t('nonprofitMembership.erasure.nothing'), run: () => { void create() } },
  { key: 'erasure-reload', label: t('nonprofitMembership.reload'), icon: 'cycle', disabled: busy.value || loading.value, run: () => { void load() } },
])
const detailActions = computed<ActionItem[]>(() => {
  const p = current.value
  if (!p || !props.canWrite) return []
  return [
    { key: 'erasure-approve', label: t('nonprofitMembership.erasure.approve'), icon: 'check', tier: 'primary', variant: 'success', show: p.status === 'pending', disabled: busy.value, run: () => { void approve(p.id) } },
    { key: 'erasure-revoke', label: t('nonprofitMembership.erasure.revoke'), icon: 'uturn', variant: 'warning', show: p.status === 'approved' && !started.value, disabled: busy.value, run: () => { void revoke(p.id) } },
    { key: 'erasure-reject', label: t(p.status === 'approved' ? 'nonprofitMembership.erasure.close' : 'nonprofitMembership.erasure.reject'), icon: 'x', tier: 'overflow', variant: 'danger',
      show: p.status === 'pending' || p.status === 'approved', disabled: busy.value, run: () => { void reject(p.id) } },
  ]
})

watch(() => props.open, open => { if (open && !loaded.value) void load() }, { immediate: true })
watch(() => props.supplier, () => { generation++; proposals.value = []; assessment.value = null; detail.value = null; loaded.value = false; error.value = ''; confirmation.value = ''; if (props.open) void load() })
onActivated(() => { if (props.open && loaded.value && !busy.value) void load() })
onBeforeUnmount(() => { alive = false })
</script>

<template>
  <div class="space-y-4" data-section="erasure">
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.erasure.help') }}</p>
    <p v-if="error" role="alert" :class="errorBox" data-section="erasure-error">{{ error }}</p>
    <ActionBar :actions="actions" />
    <p v-if="loading && !loaded" role="status" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>

    <div v-if="assessment" class="space-y-1 text-sm" data-section="erasure-assessment">
      <p>{{ t('nonprofitMembership.erasure.assessment', { date: formatDate(assessment.as_of), count: summary.erasable }) }}</p>
      <ul v-if="summary.blocked.length" class="list-disc pl-5 text-neutral-500">
        <li v-for="[code, count] in summary.blocked" :key="code" data-field="erasure-blocked">{{ reason(code) }}: {{ count }}</li>
      </ul>
      <p v-if="assessment.next_after !== null" class="text-xs text-neutral-500">{{ t('nonprofitMembership.erasure.assessmentPartial') }}</p>
    </div>

    <div v-if="proposals.length" class="space-y-2" data-section="erasure-proposals">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('nonprofitMembership.erasure.proposals') }}</h3>
      <!-- Návrhy jako tabulka se zebrou jako ostatní seznamy; otevřený návrh je zvýrazněný. -->
      <div class="overflow-x-auto rounded-lg border border-neutral-200">
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="p in proposals" :key="p.id" :class="detail?.proposal.id === p.id ? 'bg-primary-50/40' : ''" data-field="erasure-proposal">
              <td class="px-4 py-2.5 font-medium">{{ t('nonprofitMembership.erasure.proposalTitle', { id: p.id, date: formatDate(p.as_of) }) }}</td>
              <td class="whitespace-nowrap px-4 py-2.5"><span :class="statusBadge(p.status)">{{ t(`nonprofitMembership.erasure.status_${p.status}`) }}</span></td>
              <td class="px-4 py-2.5 text-neutral-500">{{ t('nonprofitMembership.erasure.proposalCounts', { total: p.item_count ?? 0, done: p.done_count ?? 0, skipped: p.skipped_count ?? 0 }) }}</td>
              <td class="px-4 py-2 text-right">
                <button type="button" :class="btnIconSm('primary')" :title="t('nonprofitMembership.erasure.show')" :aria-label="t('nonprofitMembership.erasure.show')" data-action="erasure-show" :disabled="busy" @click="show(p.id)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg></button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <section v-if="detail && current" class="space-y-3 border-t border-neutral-200 pt-4" data-section="erasure-detail">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h3 class="flex flex-wrap items-center gap-2 text-base font-semibold">{{ t('nonprofitMembership.erasure.proposalTitle', { id: current.id, date: formatDate(current.as_of) }) }}
          <span :class="statusBadge(current.status)">{{ t(`nonprofitMembership.erasure.status_${current.status}`) }}</span></h3>
        <ActionBar :actions="detailActions" />
      </div>
      <p v-if="current.status === 'pending'" class="text-sm text-neutral-500">{{ t('nonprofitMembership.erasure.pendingHelp', { hours: detail.cooling_off_hours }) }}</p>
      <p v-if="coolingOff && current.executable_from" :class="warningBox" data-section="erasure-cooling">{{ t('nonprofitMembership.erasure.coolingOff', { date: formatDateTime(`${current.executable_from.replace(' ', 'T')}Z`) }) }}</p>
      <form v-if="canWrite && current.status === 'approved' && !coolingOff" class="space-y-2" data-section="erasure-execute" @submit.prevent="confirmed && execute(current.id)">
        <p :class="warningBox">{{ t('nonprofitMembership.erasure.executeWarning') }}</p>
        <label :class="formLabel">{{ t('nonprofitMembership.erasure.confirmLabel', { phrase: detail.confirmation }) }}
          <input v-model="confirmation" :class="field" autocomplete="off" maxlength="40" data-field="erasure-confirmation" :disabled="busy" />
        </label>
        <button type="submit" :class="btnFilled('danger')" class="whitespace-nowrap" data-action="erasure-execute" :disabled="busy || !confirmed"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>{{ started ? t('nonprofitMembership.erasure.continue') : t('nonprofitMembership.erasure.execute') }}</button>
      </form>
      <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 text-sm" data-section="erasure-items">
        <li v-for="item in detail.items" :key="item.id" class="space-y-1 px-4 py-2.5 even:bg-neutral-50" data-field="erasure-item">
          <p class="break-words font-medium">{{ itemLabel(item) }}</p>
          <p class="text-neutral-500">{{ t(`nonprofitMembership.erasure.rule_${item.governing_rule}`, { until: formatDate(item.retained_until), ended: formatDate(item.ended_on) }) }}</p>
          <p :class="outcomeTone(item.outcome)">{{ t(`nonprofitMembership.erasure.outcome_${item.outcome}`) }}<template v-if="item.skip_reason"> · {{ reason(item.skip_reason) }}</template>
            <template v-if="item.outcome === 'done' && item.counts?.contact"> · {{ t(`nonprofitMembership.erasure.contact_${item.counts.contact}`) }}</template></p>
        </li>
      </ul>
      <button v-if="detail.next_after_member_id !== null" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" data-action="erasure-more" :disabled="busy" @click="more"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('nonprofitMembership.erasure.more') }}</button>
    </section>
  </div>
</template>

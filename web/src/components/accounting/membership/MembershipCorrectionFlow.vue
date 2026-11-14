<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import { formInput as field, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { membershipApi, type MemberIdentity, type MemberIdentityInput, type MemberRevision, type MemberRevisionVoidInput } from '@/api/nonprofitMembership'
import { formatDate } from '@/composables/useFormat'
import type { Client } from '@/api/clients'
import { errorBox, errorStatus, memberStatusBadge, membershipMemberLabel } from './membershipFormat'
import MembershipConfirmBar from './MembershipConfirmBar.vue'
import { useGuardedCommit } from './useGuardedCommit'

/**
 * Opravy členské evidence do prvního účetního zápisu: kontakt a číslo, smazání člena, zrušení poslední revize.
 * Server rozhoduje, co je dovolené; potvrzuje se zmrazený požadavek stejně jako zápis revize.
 */
type Mode = 'identity' | 'delete' | 'void'
type Command =
  | { mode: 'identity'; member: number; body: MemberIdentityInput }
  | { mode: 'delete'; member: number }
  | { mode: 'void'; member: number; revision: number; body: MemberRevisionVoidInput }

const props = defineProps<{ supplier: number; canManage: boolean; mode: Mode; member: MemberIdentity; label: string; revision?: MemberRevision | null; headRevisionNo: number }>()
const emit = defineEmits<{ done: [mode: Mode, label: string]; close: []; lock: [locked: boolean] }>()
const { t } = useI18n()

const form = reactive({ clientId: props.member.client_id as number | null, clientLabel: props.label, number: props.member.member_number, reason: '' })

const commit = useGuardedCommit<Command, Command>({
  supplier: () => props.supplier,
  canManage: () => props.canManage,
  send: (supplier, command) => {
    if (command.mode === 'identity') return membershipApi.updateMember(supplier, command.member, command.body)
    // Opakování po nejisté odpovědi: člen už neexistuje, smazání tedy proběhlo.
    if (command.mode === 'delete') return membershipApi.deleteMember(supplier, command.member).catch(e => (errorStatus(e) === 404 ? { deleted: true, member_id: command.member } : Promise.reject(e)))
    return membershipApi.voidRevision(supplier, command.member, command.revision, command.body)
  },
  onDone: () => emit('done', props.mode, form.clientLabel),
})

const invalid = computed(() => {
  if (props.mode === 'identity') {
    const number = form.number.trim()
    if (!form.clientId || !number || number.length > 80) return t('nonprofitMembership.correction.identityRequired')
    if (form.clientId === props.member.client_id && number === props.member.member_number) return t('nonprofitMembership.correction.identityUnchanged')
  }
  if (props.mode === 'void') {
    if (!props.revision) return t('nonprofitMembership.correction.voidUnavailable')
    const reason = form.reason.trim()
    if (!reason || reason.length > 500) return t('nonprofitMembership.correction.reasonRequired')
  }
  return ''
})

function chooseClient(client: Client | null) {
  form.clientId = client?.id ?? null
  form.clientLabel = client ? membershipMemberLabel(client) : ''
}

function review() {
  if (!props.canManage || invalid.value || commit.busy.value || commit.locked.value) return
  let command: Command
  if (props.mode === 'identity') {
    command = { mode: 'identity', member: props.member.id, body: { client_id: form.clientId as number, member_number: form.number.trim(),
      expected_client_id: props.member.client_id, expected_member_number: props.member.member_number } }
  } else if (props.mode === 'delete') {
    command = { mode: 'delete', member: props.member.id }
  } else {
    command = { mode: 'void', member: props.member.id, revision: (props.revision as MemberRevision).id,
      body: { expected_revision: props.headRevisionNo, reason: form.reason.trim(), idempotency_key: crypto.randomUUID() } }
  }
  commit.arm(command, command, props.supplier)
}

watch([form, () => props.headRevisionNo, () => props.member.id, () => props.member.member_number, () => props.member.client_id], () => commit.touch(), { deep: true, flush: 'sync' })
watch(() => props.supplier, () => commit.reset())
watch(commit.locked, locked => emit('lock', locked))

const title = computed(() => t(`nonprofitMembership.correction.title_${props.mode}`))
const tone = computed(() => (props.mode === 'identity' ? 'success' : 'danger'))
const actions = computed<ActionItem[]>(() => [
  { key: 'corr-close', label: t('nonprofitMembership.close'), icon: 'x', disabled: commit.locked.value, run: () => emit('close') },
  { key: 'corr-review', label: t('nonprofitMembership.review'), icon: 'eye', tier: 'primary', variant: props.mode === 'identity' ? 'primary' : 'danger',
    disabled: !props.canManage || !!invalid.value || commit.busy.value || commit.locked.value, disabledReason: invalid.value || undefined, run: review },
])
const pending = computed(() => commit.review.value)
</script>

<template>
  <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4" :data-section="`correction-${mode}`">
    <h3 class="font-semibold">{{ title }}</h3>
    <p class="text-sm text-neutral-500">{{ t(`nonprofitMembership.correction.help_${mode}`) }}</p>
    <p v-if="commit.error.value" role="alert" :class="errorBox" data-section="corr-error">{{ commit.error.value }}</p>

    <fieldset v-if="mode === 'identity'" :disabled="commit.locked.value || !!pending" class="grid gap-3 sm:grid-cols-2">
      <div :class="formLabel">{{ t('nonprofitMembership.correction.client') }}
        <ClientSearchSelect v-model="form.clientId" :selected-label="form.clientLabel || null" data-field="corr-client" @selected="chooseClient" />
      </div>
      <label :class="formLabel">{{ t('nonprofitMembership.correction.memberNumber') }}<input v-model="form.number" :class="field" maxlength="80" data-field="corr-number" /></label>
    </fieldset>

    <div v-if="mode === 'void' && revision" class="space-y-3">
      <div class="flex flex-wrap items-center gap-2 text-sm" data-section="corr-revision">
        <span class="font-medium">#{{ revision.revision_no }}</span>
        <span :class="memberStatusBadge(revision.status)">{{ t(`nonprofitMembership.status_${revision.status}`) }}</span>
        <span>{{ t('nonprofitMembership.sinceDate', { date: formatDate(revision.effective_from) }) }}</span>
      </div>
      <label :class="formLabel">{{ t('nonprofitMembership.correction.reason') }}
        <textarea v-model="form.reason" :class="textarea" maxlength="500" :disabled="commit.locked.value || !!pending" data-field="corr-reason" />
      </label>
    </div>

    <p v-if="mode === 'delete'" class="text-sm" data-section="corr-delete-target">{{ t('nonprofitMembership.correction.deleteTarget', { member: `${member.member_number} · ${label}` }) }}</p>
    <p v-if="invalid && mode !== 'delete' && (mode !== 'void' || form.reason)" class="text-sm text-warning-700" data-section="corr-invalid">{{ invalid }}</p>

    <ActionBar v-if="canManage && !pending" :actions="actions" />
    <div v-if="pending" class="space-y-3 rounded border border-neutral-200 p-3" data-section="corr-review">
      <dl v-if="pending.mode === 'identity'" class="grid gap-3 sm:grid-cols-2">
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.correction.client') }}</dt><dd>{{ label }} → {{ form.clientLabel }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.correction.memberNumber') }}</dt><dd>{{ pending.body.expected_member_number }} → {{ pending.body.member_number }}</dd></div>
      </dl>
      <p v-if="pending.mode === 'void'" class="text-sm" data-field="corr-review-reason">{{ pending.body.reason }}</p>
      <MembershipConfirmBar
        v-model:acknowledged="commit.acknowledged.value"
        :busy="commit.busy.value" :uncertain="commit.uncertain.value" :can-manage="canManage" :tone="tone"
        :ack-label="t(`nonprofitMembership.correction.ack_${mode}`)"
        :confirm-label="commit.uncertain.value ? undefined : t(`nonprofitMembership.correction.confirm_${mode}`)"
        @confirm="commit.send()" @cancel="commit.touch()" @discard="commit.discard()"
      />
    </div>
  </section>
</template>

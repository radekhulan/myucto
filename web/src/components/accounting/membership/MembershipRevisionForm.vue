<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { formDateInput as dateInput, formInput as field, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { membershipApi, type MemberIdentity, type MemberRevision, type MemberRevisionInput, type MemberStatus } from '@/api/nonprofitMembership'
import { formatDate } from '@/composables/useFormat'
import { errorBox, isIsoDate, lastRevision } from './membershipFormat'
import MembershipConfirmBar from './MembershipConfirmBar.vue'
import { useGuardedCommit } from './useGuardedCommit'

interface RevisionCommand { member: number; body: MemberRevisionInput }

const props = defineProps<{ supplier: number; canManage: boolean; member: MemberIdentity; history: MemberRevision[] }>()
const emit = defineEmits<{ done: []; close: []; lock: [locked: boolean] }>()
const { t } = useI18n()

const inputForm = ref<HTMLFormElement | null>(null)

const form = reactive({ status: 'active' as MemberStatus, effectiveFrom: '', reference: '', evidence: '' })
const notice = ref('')
const last = computed(() => lastRevision(props.history))
const statuses = computed<MemberStatus[]>(() => (last.value ? ['active', 'suspended', 'ended'] : ['active']))

const commit = useGuardedCommit<RevisionCommand, RevisionCommand>({
  supplier: () => props.supplier,
  canManage: () => props.canManage,
  send: (supplier, command) => membershipApi.appendRevision(supplier, command.member, command.body),
  onDone: () => {
    form.status = 'active'; form.effectiveFrom = ''; form.reference = ''; form.evidence = ''
    notice.value = t('nonprofitMembership.revisionDone')
    emit('done')
  },
})

const invalid = computed(() => {
  const date = form.effectiveFrom
  if (!statuses.value.includes(form.status)) return t('nonprofitMembership.revisionStatusInvalid')
  if (!isIsoDate(date)) return t('nonprofitMembership.dateInvalid')
  if (last.value && date <= last.value.effective_from) return t('nonprofitMembership.revisionAfterLast', { date: formatDate(last.value.effective_from) })
  const reference = form.reference.trim(), evidence = form.evidence.trim()
  if (!reference || reference.length > 1000 || !evidence || evidence.length > 2000) return t('nonprofitMembership.revisionEvidenceRequired')
  return ''
})

function review() {
  if (!props.canManage || invalid.value || commit.busy.value || commit.locked.value || !inputForm.value?.reportValidity()) return
  const command: RevisionCommand = {
    member: props.member.id,
    body: {
      status: form.status,
      effective_from: form.effectiveFrom,
      legal_basis: { organisation_form: 'association', relationship: 'nonreciprocal_membership', reference: form.reference.trim(), evidence: form.evidence.trim() },
      expected_revision: last.value?.revision_no ?? 0,
      idempotency_key: crypto.randomUUID(),
    },
  }
  notice.value = ''
  commit.arm(command, command, props.supplier)
}

watch([form, () => last.value?.revision_no ?? 0, () => props.member.id], () => commit.touch(), { deep: true, flush: 'sync' })
watch(() => props.supplier, () => { commit.reset(); notice.value = '' })
watch(commit.locked, locked => emit('lock', locked))

const actions = computed<ActionItem[]>(() => [
  { key: 'rev-close', label: t('nonprofitMembership.close'), icon: 'x', show: !!last.value, disabled: commit.locked.value, run: () => emit('close') },
  { key: 'rev-review', label: t('nonprofitMembership.review'), icon: 'eye', tier: 'primary', variant: 'primary', disabled: !props.canManage || !!invalid.value || commit.busy.value || commit.locked.value, disabledReason: invalid.value || undefined, run: review },
])
</script>

<template>
  <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4" data-section="revision-form">
    <h3 class="font-semibold">{{ t('nonprofitMembership.revisionTitle') }}</h3>
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.revisionHelp') }}</p>
    <p v-if="notice" role="status" class="text-sm text-success-600">{{ notice }}</p>
    <p v-if="commit.error.value" role="alert" :class="errorBox" data-section="rev-error">{{ commit.error.value }}</p>
    <form ref="inputForm" @submit.prevent="review" @input="commit.touch">
    <fieldset :disabled="commit.locked.value" class="grid gap-3 sm:grid-cols-2">
      <label :class="formLabel">{{ t('nonprofitMembership.revisionStatus') }}
        <select v-model="form.status" :class="field" data-field="rev-status">
          <option v-for="status in statuses" :key="status" :value="status">{{ t(`nonprofitMembership.status_${status}`) }}</option>
        </select>
      </label>
      <label :class="formLabel">{{ t('nonprofitMembership.effectiveFrom') }}<DateInput :class="dateInput" v-model="form.effectiveFrom" required data-field="rev-from" /></label>
      <p class="text-sm sm:col-span-2">{{ t('nonprofitMembership.legalBasisFixed') }}</p>
      <label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitMembership.reference') }}<input v-model="form.reference" :class="field" maxlength="1000" data-field="rev-reference" /></label>
      <label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitMembership.evidence') }}<textarea v-model="form.evidence" :class="textarea" maxlength="2000" data-field="rev-evidence" /></label>
    </fieldset>
    </form>
    <p v-if="invalid && (form.effectiveFrom || form.reference || form.evidence)" class="text-sm text-warning-700" data-section="rev-invalid">{{ invalid }}</p>
    <ActionBar v-if="canManage && !commit.review.value" :actions="actions" />
    <div v-if="commit.review.value" class="space-y-3 rounded border border-neutral-200 p-3" data-section="rev-preview">
      <h4 class="font-semibold">{{ t('nonprofitMembership.revisionReview') }}</h4>
      <dl class="grid gap-3 sm:grid-cols-3">
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.revisionStatus') }}</dt><dd>{{ t(`nonprofitMembership.status_${commit.review.value.body.status}`) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.effectiveFrom') }}</dt><dd>{{ formatDate(commit.review.value.body.effective_from) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.expectedRevision') }}</dt><dd>{{ commit.review.value.body.expected_revision }}</dd></div>
      </dl>
      <p class="text-sm">{{ commit.review.value.body.legal_basis.reference }}</p>
      <p class="text-sm text-neutral-500">{{ commit.review.value.body.legal_basis.evidence }}</p>
      <MembershipConfirmBar
        v-model:acknowledged="commit.acknowledged.value"
        :busy="commit.busy.value" :uncertain="commit.uncertain.value" :can-manage="canManage"
        :ack-label="t('nonprofitMembership.revisionAck')"
        @confirm="commit.send()" @cancel="commit.touch()" @discard="commit.discard()"
      />
    </div>
  </section>
</template>

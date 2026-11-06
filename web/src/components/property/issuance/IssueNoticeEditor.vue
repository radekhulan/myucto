<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { IssueContext, IssueUnit } from '@/api/propertyChargeIssuance'
import type { UnitState } from '@/api/propertyRegistry'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import { propertyInput as input, propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
const props = defineProps<{ entry: IssueUnit['notices'][number]; context: IssueContext; unitId: number; supplierId: number }>()
const { t } = useI18n()
const component = computed(() => props.context.version.preview.calculation.rates.find(r => r.component.id === props.entry.component_id)?.component)
const recipients = computed(() => [...new Set(((props.context.units.find(u => u.id === props.unitId)?.revision?.state as UnitState | undefined)?.ownerships ?? []).flatMap(g => g.members))])
const monthly = computed(() => props.context.version.request.frequency === 'monthly')
function person(id: number) { const p = props.context.parties.find(v => v.id === id); return p?.company_name || [p?.first_name, p?.last_name].filter(Boolean).join(' ') || t('propertyIssuance.unknownPerson') }
function changeMode() { if (props.entry.notice.mode === 'change' && !monthly.value) props.entry.notice.agreement ??= { effective_at: '', recipient_ids: [], evidence_ref: '', legal_basis: '' }; else props.entry.notice.agreement = null }
</script>
<template>
  <fieldset class="rounded border border-neutral-200 p-3 space-y-3" data-testid="service-notice">
    <legend class="font-medium">{{ component?.name }} · {{ t('propertyIssuance.serviceNotice') }}</legend>
    <p class="text-sm">{{ t(monthly ? 'propertyIssuance.monthlyNoticeHelp' : 'propertyIssuance.nonMonthlyNoticeHelp') }}</p>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.noticeMode') }}<select v-model="entry.notice.mode" :class="input" @change="changeMode"><option value="">{{ t('propertyIssuance.choose') }}</option><option v-for="mode in ['initial', 'unchanged', 'change']" :key="mode" :value="mode">{{ t(`propertyIssuance.noticeModes.${mode}`) }}</option></select></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.noticeTitle') }}<input v-model="entry.notice.title_ref" :class="input"></label>
    <template v-if="entry.notice.mode === 'change'">
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.writtenNotice') }}<input v-model="entry.notice.written_notice_ref" :class="input"></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.reason') }}<textarea v-model="entry.notice.reason" :class="textarea" /></label>
      <div v-for="(proof, index) in entry.notice.deliveries" :key="index" class="space-y-2 rounded bg-neutral-50 p-3">
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.deliveryRecipient') }}<PropertyPartyPicker :model-value="proof.recipient_id || null" :supplier-id="supplierId" :selected-label="person(proof.recipient_id)" @update:model-value="proof.recipient_id = $event ?? 0" /></label>
        <fieldset><legend>{{ t('propertyIssuance.represented') }}</legend><div class="flex flex-wrap gap-3"><label v-for="id in recipients" :key="id" class="inline-flex items-center gap-2"><input v-model="proof.represented_ids" type="checkbox" :value="id">{{ person(id) }}</label></div></fieldset>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.authority') }}<input v-model="proof.authority_ref" :class="input"></label>
        <PropertyInstantInput v-model="proof.delivered_at" :label="t('propertyIssuance.deliveredAt')" />
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.deliveryEvidence') }}<input v-model="proof.evidence_ref" :class="input"></label>
        <div class="flex flex-wrap gap-2"><PropertyButton icon="trash" variant="danger" @click="entry.notice.deliveries?.splice(index, 1)">{{ t('propertyIssuance.remove') }}</PropertyButton></div>
      </div>
      <div class="flex flex-wrap gap-2"><PropertyButton icon="plus" @click="(entry.notice.deliveries ??= []).push({ recipient_id: 0, represented_ids: [], authority_ref: '', delivered_at: '', evidence_ref: '' })">{{ t('propertyIssuance.addDelivery') }}</PropertyButton></div>
      <fieldset v-if="!monthly && entry.notice.agreement" class="space-y-2">
        <legend>{{ t('propertyIssuance.nonMonthlyTitle') }}</legend>
        <PropertyInstantInput v-model="entry.notice.agreement.effective_at" :label="t('propertyIssuance.agreementEffectiveAt')" />
        <div class="flex flex-wrap gap-3"><label v-for="id in recipients" :key="id" class="inline-flex items-center gap-2"><input v-model="entry.notice.agreement.recipient_ids" type="checkbox" :value="id">{{ person(id) }}</label></div>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.evidence') }}<input v-model="entry.notice.agreement.evidence_ref" :class="input"></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.legalReason') }}<textarea v-model="entry.notice.agreement.legal_basis" :class="textarea" /></label>
      </fieldset>
    </template>
  </fieldset>
</template>

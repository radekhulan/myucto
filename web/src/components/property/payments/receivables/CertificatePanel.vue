<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyReceivablesApi as api, type Certificate, type CertificateRequest, type LegalContext } from '@/api/propertyReceivables'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import CertificateView from './CertificateView.vue'
import { usePreviewFlow } from '../operations/flows'
import { failureMessage } from '../operations/format'
import { inputClass } from '../operations/ui'
const props = defineProps<{ supplier: number; context: LegalContext }>(); const emit = defineEmits<{ stale: []; issued: [] }>()
const { t } = useI18n(); const form = ref<HTMLFormElement | null>(null); const requester = ref<number | null>(null)
const facts = reactive({ authority_evidence: '', purpose: '', coverage_acknowledgement: '' }); const issued = ref<Certificate | null>(null)
const loading = ref(false); const error = ref(''); let epoch = 0
onBeforeUnmount(() => epoch++)
const flow = usePreviewFlow({ supplier: () => props.supplier, validate: () => !!form.value?.reportValidity() && !!requester.value, canPreview: () => props.context.capabilities.certificates, canConfirm: () => props.context.capabilities.certificates,
  build: (): CertificateRequest => ({ unit_id: props.context.unit.id, as_of: props.context.as_of, known_at: props.context.known_at, requester_id: requester.value!, ...facts }), preview: api.certificatePreview,
  confirm: async (supplier, request, hash, token) => { try { return await api.certificateIssue(supplier, request, hash, token) } catch (e) { if ((e as { response?: { status: number } }).response?.status === 409) { flow.invalidate(); emit('stale') } throw e } }, onDone: result => { issued.value = result; emit('issued') } })
watch([requester, facts], flow.invalidate, { deep: true, flush: 'sync' })
watch(() => [props.supplier, props.context], () => { epoch++; issued.value = null; loading.value = false; error.value = ''; flow.reset() })
async function open(id: number) { if (loading.value || flow.busy.value) return; const ticket = ++epoch; loading.value = true; error.value = ''; issued.value = null; try { const result = await api.certificate(props.supplier, id); if (ticket === epoch) issued.value = result } catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) } finally { if (ticket === epoch) loading.value = false } }
const actions = computed<ActionItem[]>(() => [{ key: 'certificate-preview', label: t('propertySaldo.preview'), icon: 'search', variant: 'primary', tier: 'primary', disabled: !props.context.capabilities.certificates || flow.busy.value, run: flow.showPreview }, { key: 'certificate-issue', label: t('propertySaldo.issueCertificate'), icon: 'check', variant: 'success', tier: 'secondary', disabled: !props.context.capabilities.certificates || !flow.preview.value || flow.busy.value, run: flow.confirm }])
</script>
<template>
  <section class="space-y-3" data-section="certificates">
    <h3 class="font-semibold">{{ t('propertySaldo.certificates') }}</h3><p>{{ t('propertySaldo.certificateScope') }}</p>
    <div class="flex flex-wrap gap-2"><PropertyButton v-for="c in context.certificates" :key="c.id" icon="search" :disabled="loading" :data-certificate="c.id" @click="open(c.id)">{{ t('propertySaldo.issuedCertificate', { id: c.id }) }} · {{ c.as_of }}</PropertyButton></div>
    <p v-if="error" role="alert">{{ error }}</p><p v-if="loading">{{ t('propertyPayments.loading') }}</p>
    <CertificateView v-if="issued" :snapshot="issued.snapshot" :hash="issued.snapshot_hash" :id="issued.id" :recorded-at="issued.recorded_at" />
    <form v-if="context.capabilities.certificates" ref="form" class="space-y-3" @submit.prevent="flow.showPreview">
      <fieldset :disabled="flow.busy.value" class="space-y-3"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.requester') }}<PropertyPartyPicker :supplier-id="supplier" v-model="requester" /></label><label v-for="key in (['authority_evidence', 'purpose', 'coverage_acknowledgement'] as const)" :key="key" class="flex min-w-0 flex-col gap-1 text-sm">{{ t(`propertySaldo.certificateFields.${key}`) }}<input v-model="facts[key]" required maxlength="4000" :class="inputClass" :data-field="key" /></label></fieldset>
      <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
      <CertificateView v-if="flow.preview.value" :snapshot="flow.preview.value.snapshot" :hash="flow.preview.value.preview_hash" />
      <ActionBar :actions="actions" />
    </form>
  </section>
</template>

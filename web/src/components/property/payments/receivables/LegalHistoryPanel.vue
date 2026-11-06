<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyReceivablesApi as api, type LegalContext } from '@/api/propertyReceivables'
import type { UnitState } from '@/api/propertyRegistry'
import PropertyButton from '@/components/property/PropertyButton.vue'
import LegalGroups from './LegalGroups.vue'
import TransferForm from './TransferForm.vue'
import CertificatePanel from './CertificatePanel.vue'
import { failureMessage, moneyText } from '../operations/format'
import { useBusyScope } from '../operations/busy'
import { formatPropertyInstant } from '@/components/property/propertyInstant'
const props = defineProps<{ supplier: number; unitId: number; asOf: string; knownAt: string }>()
const { t, locale } = useI18n(); const at = (value: string | null | undefined) => formatPropertyInstant(value, locale.value); const context = ref<LegalContext | null>(null); const error = ref(''); const loading = ref(false); const stale = ref(false)
const scope = useBusyScope(); let epoch = 0
function clear() { epoch++; context.value = null; stale.value = false; error.value = ''; if (loading.value) { loading.value = false; scope.end() } }
onBeforeUnmount(clear); watch(() => [props.supplier, props.unitId, props.asOf, props.knownAt], clear)
async function load() { if (loading.value || scope.busy.value) return; const ticket = ++epoch; loading.value = true; scope.begin(); context.value = null; stale.value = false; error.value = ''; try { const result = await api.context(props.supplier, { unit_id: props.unitId, as_of: props.asOf, known_at: props.knownAt }); if (ticket === epoch) context.value = result } catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) } finally { if (ticket === epoch) { loading.value = false; scope.end() } } }
onMounted(load)
function changed() { stale.value = true }
function names(ids: number[]) { return ids.map(id => { const p = context.value?.parties.find(p => p.id === id); return p?.company_name || [p?.first_name, p?.last_name].filter(Boolean).join(' ') || t('propertySaldo.personId', { id }) }).join(', ') }
</script>
<template>
  <section class="space-y-4 rounded-lg border border-neutral-200 p-4" data-section="legal-history">
    <h3 class="font-semibold">{{ t('propertySaldo.legalHistory') }}</h3>
    <PropertyButton icon="search" :disabled="loading || scope.busy.value" data-action="legal-refresh" @click="load">{{ t('propertySaldo.reload') }}</PropertyButton>
    <p v-if="loading">{{ t('propertyPayments.loading') }}</p><p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <p v-if="stale" role="status" class="text-warning-700">{{ t('propertySaldo.stale') }}</p>
    <template v-if="context">
      <p>{{ t('propertySaldo.asOf') }}: {{ at(context.as_of) }} · {{ t('propertyPayments.card.knownAt') }}: {{ at(context.known_at) }}</p>
      <p class="text-warning-700">{{ t('propertySaldo.partial') }}</p><p class="text-sm">{{ t('propertySaldo.oneDebt') }}</p>
      <p class="text-xs text-neutral-500">{{ t('propertySaldo.currentNames') }}</p>
      <div class="space-y-2"><h4>{{ t('propertySaldo.owners') }}</h4><article v-for="r in context.unit.history" :key="r.id" class="rounded border p-2" data-owner-revision><p>{{ at(r.effective_from) }} <template v-if="r.effective_to"> - {{ at(r.effective_to) }}</template> · {{ t('propertySaldo.recordedAt') }}: {{ at(r.recorded_at) }} · {{ t(`propertyRegistry.statuses.${r.status}`) }}</p><p v-for="g in (r.state as UnitState).ownerships" :key="g.group_key">{{ names(g.members) }} · {{ g.share.num }}/{{ g.share.den }} · {{ g.title_ref }}</p></article></div>
      <article v-for="c in context.projection.claims" :key="c.claim_key" class="space-y-2 rounded border p-3" data-legal-claim><p>{{ c.title_ref }} · {{ moneyText(c.amount_cents, t('propertyPayments.unknown')) }}</p><p v-if="c.precision === 'day' || !c.complete || !c.liability.complete" class="text-warning-700">{{ t('propertySaldo.review') }}</p><LegalGroups :groups="c.groups" :parties="context.parties" /></article>
      <article v-for="e in context.events" :key="e.id" class="space-y-2 rounded border p-3" data-party-event><h4>{{ t(`propertySaldo.decisions.${e.snapshot.request.decision}`) }}</h4><p>{{ t('propertySaldo.effectiveAt') }}: {{ at(e.effective_at) }} · {{ t('propertySaldo.recordedAt') }}: {{ at(e.recorded_at) }}</p><p>{{ e.snapshot.request.reason }}</p><p>{{ e.snapshot.request.acquisition.legal_basis }} · {{ e.snapshot.request.acquisition.evidence }}</p><div v-for="d in e.snapshot.debts" :key="d.debt_id"><p>{{ t('propertySaldo.previousGroups') }}</p><LegalGroups :groups="d.previous_liability.groups" :parties="context.parties" /><p>{{ t('propertySaldo.proposedGroups') }}</p><LegalGroups :groups="d.groups" :parties="e.snapshot.parties" /></div></article>
      <TransferForm v-if="!stale && (context.capabilities.manage || context.capabilities.confirm)" :supplier="supplier" :context="context" @saved="changed" @stale="changed" />
      <CertificatePanel :supplier="supplier" :context="stale ? { ...context, capabilities: { ...context.capabilities, certificates: false } } : context" @stale="changed" />
    </template>
  </section>
</template>

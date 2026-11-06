<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { LiabilityGroup, DirectoryParty } from '@/api/propertyReceivables'
import { moneyText } from '../operations/format'
defineProps<{ groups: LiabilityGroup[]; parties: DirectoryParty[] }>()
const { t } = useI18n()
function name(id: number, parties: DirectoryParty[]) { const p = parties.find(p => p.id === id); return p?.company_name || [p?.first_name, p?.last_name].filter(Boolean).join(' ') || t('propertySaldo.personId', { id }) }
</script>
<template>
  <ul class="space-y-2 text-sm">
    <li v-for="g in groups" :key="g.group_key" data-legal-group class="rounded border border-neutral-200 p-2">
      <strong>{{ t(`propertyPayments.role.${g.role}`) }}: {{ g.members.map(id => name(id, parties)).join(', ') }}</strong>
      <p>{{ t(`propertySaldo.modes.${g.mode}`) }} · {{ t(`propertySaldo.bases.${g.basis}`) }} · {{ g.extent.num }}/{{ g.extent.den }}</p>
      <p v-if="g.remaining_cap_cents !== undefined">{{ t('propertyPayments.debt.cap') }}: {{ moneyText(g.remaining_cap_cents, t('propertyPayments.unknown')) }}</p>
      <p>{{ g.valid_from }} <template v-if="g.valid_to"> - {{ g.valid_to }}</template></p>
      <p>{{ g.legal_reason }} · {{ g.evidence_ref }}</p>
    </li>
  </ul>
</template>

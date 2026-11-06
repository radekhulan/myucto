<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CertificateSnapshot } from '@/api/propertyReceivables'
import LegalGroups from './LegalGroups.vue'
import { moneyText } from '../operations/format'
defineProps<{ snapshot: CertificateSnapshot; hash: string; id?: number; recordedAt?: string }>()
const { t } = useI18n()
</script>
<template>
  <section class="space-y-3 rounded border border-neutral-200 p-3" data-section="certificate-snapshot">
    <h4>{{ id ? t('propertySaldo.issuedCertificate', { id }) : t('propertySaldo.certificatePreview') }}</h4>
    <p v-if="recordedAt">{{ t('propertySaldo.recordedAt') }}: {{ recordedAt }}</p>
    <p>{{ t('propertySaldo.asOf') }}: {{ snapshot.request.as_of }} · {{ t('propertyPayments.card.knownAt') }}: {{ snapshot.request.known_at }}</p>
    <p>{{ snapshot.request.purpose }} · {{ snapshot.request.authority_evidence }}</p><p>{{ snapshot.requester.company_name }} {{ snapshot.requester.first_name }} {{ snapshot.requester.last_name }}</p>
    <p class="text-warning-700">{{ t('propertySaldo.certificateScope') }} {{ snapshot.request.coverage_acknowledgement }}</p>
    <dl class="grid gap-2 sm:grid-cols-2"><template v-for="(value, key) in snapshot.projection.totals" :key="key"><dt>{{ t(`propertyPayments.card.totals.${key}`) }}</dt><dd :data-total="key">{{ moneyText(value, t('propertyPayments.unknown')) }}</dd></template></dl>
    <article v-for="c in snapshot.projection.claims" :key="c.claim_key" class="space-y-2 border-t pt-2" data-certificate-claim><p>{{ c.title_ref }} · {{ c.due_date }} · {{ moneyText(c.amount_cents, t('propertyPayments.unknown')) }}</p><LegalGroups :groups="c.groups" :parties="[]" /></article>
    <p class="break-all text-xs">{{ t('propertySaldo.immutableHash') }}: {{ hash }}</p>
  </section>
</template>

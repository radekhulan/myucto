<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import type { CashDocument } from '@/api/cash'

/**
 * Rozbalený detail pokladního dokladu.
 *
 * Vytažené ze stránky proto, že týž obsah potřebuje desktopový rozbalovací
 * řádek i mobilní karta — a duplikovat třicet řádků markupu znamená, že se
 * jedna z kopií dřív nebo později rozejde.
 */
defineProps<{ doc: CashDocument; purposeLabel: (purpose: string) => string }>()

const { t } = useI18n()
const auth = useAuthStore()
</script>

<template>
  <div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
      <div class="space-y-1">
        <div><span class="text-neutral-500">{{ t('cash.col.link') }}:</span> {{ purposeLabel(doc.purpose) }}</div>
        <div v-if="doc.partner_ic"><span class="text-neutral-500">{{ t('common.ic') }}:</span> {{ doc.partner_ic }}</div>
        <div v-if="doc.partner_dic"><span class="text-neutral-500">{{ t('cash.form.partner_dic') }}:</span> {{ doc.partner_dic }}</div>
        <div v-if="doc.tax_date"><span class="text-neutral-500">{{ t('cash.col.tax_date') }}:</span> {{ formatDate(doc.tax_date) }}</div>
        <div v-if="doc.register"><span class="text-neutral-500">{{ t('cash.register') }}:</span> {{ doc.register.name }} ({{ doc.register.account_code }})</div>
      </div>
      <div v-if="doc.vat_mode === 'vat' && doc.vat_lines.length" class="space-y-1">
        <div class="text-neutral-500 text-xs uppercase tracking-wide">{{ t('cash.form.vat_mode_vat') }}</div>
        <table class="table-plain w-full text-xs">
          <thead class="text-neutral-500">
            <tr>
              <th class="text-left font-medium py-0.5">{{ t('cash.form.vat_rate') }}</th>
              <th class="text-right font-medium py-0.5">{{ t('cash.form.vat_base') }}</th>
              <th class="text-right font-medium py-0.5">{{ t('cash.form.vat_amount') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, i) in doc.vat_lines" :key="i">
              <td class="py-0.5">{{ l.vat_rate }} %</td>
              <td class="py-0.5 text-right font-mono">{{ formatMoney(l.base_amount) }}</td>
              <td class="py-0.5 text-right font-mono">{{ formatMoney(l.vat_amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <!-- Proklik do deníku na zápis tohoto pokladního dokladu (§4). -->
    <div v-if="doc.journal_entry_id" class="mt-3 pt-3 border-t border-neutral-200">
      <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(doc.journal_entry_id) } }"
        class="inline-flex items-center gap-1.5 text-sm text-primary-600 hover:text-primary-700 hover:underline">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
        {{ t('common.view_in_journal') }}
      </RouterLink>
    </div>
    <!-- Sken účtenky / pokladního dokladu. @click.stop: na mobilu leží detail
         v kartě, jejíž klik rozbalení zavírá. -->
    <div v-if="auth.canRead('documents')" class="mt-3" data-testid="cash-attachments" @click.stop>
      <LinkedDocumentsPanel entity-type="cash_document" :entity-id="doc.id" uploadable
        :title="t('linked_documents.title')" />
    </div>
  </div>
</template>

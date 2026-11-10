<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { StockDocument } from '@/api/stock'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

/**
 * Karta Sklad v detailu faktury: výdejky a vratky z faktury i příjemky, ze kterých
 * faktura vznikla. Chyba jednoho seznamu se ukáže vedle druhého, ne místo něj.
 */
defineProps<{ documents: StockDocument[]; loading: boolean; error: string }>()
const emit = defineEmits<{ (e: 'retry'): void }>()

const { t } = useI18n()
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mt-4">
    <div class="px-5 py-3 border-b border-neutral-200">
      <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.invoice_card.title') }}</h3>
    </div>
    <div v-if="loading" class="px-5 py-4 text-sm text-neutral-500">
      {{ t('common.loading') }}
    </div>
    <template v-else>
      <div v-if="error" data-test="stock-card-error" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
        :class="{ 'border-b border-neutral-100': documents.length > 0 }">
        <span class="text-sm text-danger-600">{{ error }}</span>
        <button type="button" :class="btnOutline('neutral')" class="!h-8 !px-2.5 !text-xs"
          @click="emit('retry')">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
          </svg>
          {{ t('stock.invoice_card.retry') }}
        </button>
      </div>
      <div v-if="documents.length > 0" class="divide-y divide-neutral-100">
        <RouterLink v-for="d in documents" :key="d.id" :to="`/stock/documents/${d.id}`"
          class="flex items-center justify-between gap-2 px-5 py-2.5 text-sm hover:bg-neutral-50">
          <span class="flex items-center gap-2">
            <span class="font-mono text-xs text-neutral-500">{{ d.doc_number || '—' }}</span>
            <span>{{ t(`stock.doc_type.${d.doc_type}`) }}</span>
          </span>
          <span class="text-xs px-2 py-0.5 rounded font-medium"
            :class="d.status === 'posted' ? 'bg-success-50 text-success-600' : d.status === 'reversed' ? 'bg-danger-50 text-danger-500' : 'bg-neutral-100 text-neutral-600'">
            {{ t(`stock.doc_status.${d.status}`) }}
          </span>
        </RouterLink>
      </div>
      <div v-else-if="!error" class="px-5 py-4 text-sm text-neutral-500">
        {{ t('stock.invoice_card.empty') }}
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentCompletenessApi, type DocumentCompletenessResult, type Direction } from '@/api/documentCompleteness'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()

const data = ref<DocumentCompletenessResult | null>(null)
const loading = ref(false)
const days = ref(30)
const direction = ref<Direction>('all')

const DIRECTIONS: Direction[] = ['all', 'outgoing', 'incoming']

let requestSeq = 0
async function load() {
  loading.value = true
  const seq = ++requestSeq
  try {
    const result = await documentCompletenessApi.get({ days: days.value, direction: direction.value })
    if (seq !== requestSeq) return
    data.value = result
  } catch (e: any) {
    if (seq !== requestSeq) return
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}
onMounted(load)

function bucketLabel(bucket: string): string {
  return t(`documentCompleteness.bucket.${bucket}`)
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('documentCompleteness.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('documentCompleteness.subtitle') }}</p>
      </div>
    </div>

    <!-- Sekce 1: bankovní pohyby bez dokladu -->
    <section class="mb-8">
      <div class="flex flex-wrap items-end gap-3 mb-3">
        <h2 class="text-lg font-medium">{{ t('documentCompleteness.bank_title') }}</h2>
        <label class="flex items-center gap-1 text-xs text-neutral-600">
          {{ t('documentCompleteness.threshold_label') }}
          <input v-model.number="days" type="number" min="0" max="3650"
            class="h-8 w-20 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface"
            @change="load" />
        </label>
        <select v-model="direction" class="h-8 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface" @change="load">
          <option v-for="d in DIRECTIONS" :key="d" :value="d">{{ t(`documentCompleteness.direction.${d}`) }}</option>
        </select>
      </div>
      <p class="text-xs text-neutral-400 mb-3">{{ t('documentCompleteness.bank_note') }}</p>

      <div v-if="loading && !data" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
      <template v-else-if="data">
        <div v-if="data.bank_without_document.summary.by_bucket.length" class="flex flex-wrap gap-2 mb-3">
          <span v-for="b in data.bank_without_document.summary.by_bucket" :key="b.bucket"
            class="text-xs px-2 py-1 rounded bg-warning-50 text-warning-700 font-medium">
            {{ bucketLabel(b.bucket) }}: {{ b.count }} ({{ formatMoney(b.total_czk, 'CZK') }})
          </span>
        </div>

        <EmptyState v-if="data.bank_without_document.items.length === 0" boxed accent="success" icon="checkCircle" :title="t('documentCompleteness.bank_empty')" />
        <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('documentCompleteness.col_date') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('documentCompleteness.col_days') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('documentCompleteness.col_counterparty') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('documentCompleteness.col_amount') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('documentCompleteness.col_status') }}</th>
                <th class="px-3 py-2 text-right font-medium"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in data.bank_without_document.items" :key="item.bank_transaction_id" class="hover:bg-neutral-50">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(item.date) }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">{{ item.days }}</td>
                <td class="px-3 py-2">
                  <div>{{ item.counterparty || '—' }}</div>
                  <div v-if="item.description" class="text-xs text-neutral-400">{{ item.description }}</div>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(item.amount, item.currency) }}</td>
                <td class="px-3 py-2">
                  <span v-if="item.document_requested" class="text-xs px-2 py-0.5 rounded bg-primary-50 text-primary-700">
                    {{ t('documentCompleteness.status_requested') }}
                  </span>
                  <span v-else class="text-xs px-2 py-0.5 rounded bg-danger-50 text-danger-700">
                    {{ t('documentCompleteness.status_missing') }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right">
                  <RouterLink :to="{ name: 'bank-detail', params: { id: item.statement_id } }"
                    class="text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
                    {{ t('documentCompleteness.open_statement') }} →
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <!-- Sekce 2: doklady bez úhrady po splatnosti -->
    <section v-if="data">
      <h2 class="text-lg font-medium mb-1">{{ t('documentCompleteness.overdue_title') }}</h2>
      <p class="text-xs text-neutral-400 mb-3">{{ t('documentCompleteness.overdue_note') }}</p>

      <p
        v-if="data.documents_overdue_unpaid.summary.truncated"
        class="text-xs px-3 py-2 mb-3 rounded bg-warning-50 text-warning-700 font-medium"
      >
        {{ t('documentCompleteness.overdue_truncated') }}
      </p>

      <EmptyState v-if="data.documents_overdue_unpaid.items.length === 0" boxed accent="success" icon="checkCircle" :title="t('documentCompleteness.overdue_empty')" />
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('documentCompleteness.col_doc') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('documentCompleteness.col_counterparty') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('documentCompleteness.col_due') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('documentCompleteness.col_overdue_days') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('documentCompleteness.col_remaining') }}</th>
              <th class="px-3 py-2 text-right font-medium"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in data.documents_overdue_unpaid.items" :key="item.doc_type + item.doc_id" class="hover:bg-neutral-50">
              <td class="px-3 py-2 whitespace-nowrap">
                <span class="text-xs text-neutral-400">{{ t(`documentCompleteness.doc_type.${item.doc_type}`) }}</span>
                <div>{{ item.doc_no }}</div>
              </td>
              <td class="px-3 py-2">{{ item.partner_name }}</td>
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(item.due_date) }}</td>
              <td class="px-3 py-2 text-right text-danger-600 whitespace-nowrap">{{ item.days_overdue }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(item.remaining_czk, 'CZK') }}</td>
              <td class="px-3 py-2 text-right">
                <RouterLink :to="{ name: item.doc_type === 'purchase_invoice' ? 'purchase-invoice-detail' : 'invoice-detail', params: { id: item.doc_id } }"
                  class="text-xs font-medium text-primary-600 hover:underline whitespace-nowrap">
                  {{ t('documentCompleteness.open_doc') }} →
                </RouterLink>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup lang="ts">
/** Seznam rozporů dokladů s přílohami (přehled dávky skenů i celé firmy). */
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import type { AttachmentCheckRow, AttachmentEntityType } from '@/api/attachmentChecks'

defineProps<{ rows: AttachmentCheckRow[] }>()
const emit = defineEmits<{ (e: 'review', row: AttachmentCheckRow): void }>()

const { t, locale } = useI18n()

function docLink(type: AttachmentEntityType, id: number): string | null {
  if (type === 'purchase_invoice') return `/purchase-invoices/${id}`
  if (type === 'invoice') return `/invoices/${id}`
  return null
}

function fmt(v: unknown, field: string): string {
  if (v === null || v === undefined || v === '') return '—'
  const loc = locale.value === 'en' ? 'en-GB' : 'cs-CZ'
  if (field === 'amount' && typeof v === 'number') {
    return new Intl.NumberFormat(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)
  }
  if (typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v)) {
    const d = new Date(v + 'T00:00:00')
    return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString(loc)
  }
  return String(v)
}
</script>

<template>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
          <th class="py-2 pr-3">{{ t('attachment_check.col_document') }}</th>
          <th class="py-2 pr-3">{{ t('attachment_check.col_counterparty') }}</th>
          <th class="py-2 pr-3">{{ t('attachment_check.col_differences') }}</th>
          <th class="py-2 pr-3">{{ t('attachment_check.col_state') }}</th>
          <th class="py-2 text-right">{{ t('attachment_check.col_actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="r.entity_type + r.entity_id + r.sha256" class="border-b border-neutral-50 last:border-0 align-top">
          <td class="py-2 pr-3">
            <span class="block text-xs text-neutral-500">{{ t('attachment_check.doc_type.' + r.entity_type) }}</span>
            <RouterLink v-if="docLink(r.entity_type, r.entity_id)" :to="docLink(r.entity_type, r.entity_id)!" class="text-primary-600 hover:underline">{{ r.doc_no }}</RouterLink>
            <span v-else>{{ r.doc_no }}</span>
          </td>
          <td class="py-2 pr-3">{{ r.partner_name || '—' }}</td>
          <td class="py-2 pr-3 text-xs">
            <div v-for="f in r.findings" :key="f.field" :class="f.severity === 'warning' ? 'text-warning-700' : 'text-neutral-700'">
              {{ t('attachment_check.field.' + f.field) }}: {{ fmt(f.doc, f.field) }} × {{ fmt(f.attachment, f.field) }}
            </div>
          </td>
          <td class="py-2 pr-3">
            <span class="text-xs px-2 py-0.5 rounded whitespace-nowrap"
              :class="r.acknowledged ? 'bg-neutral-100 text-neutral-600' : r.severity === 'warning' ? 'bg-warning-50 text-warning-700' : 'bg-primary-50 text-primary-700'">
              {{ r.acknowledged ? t('attachment_check.badge_acknowledged') : t('attachment_check.severity.' + (r.severity ?? 'info')) }}
            </span>
          </td>
          <td class="py-2">
            <div class="flex flex-wrap justify-end gap-2">
              <button type="button" :class="btnOutlineSm('primary')" class="whitespace-nowrap" @click="emit('review', r)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                {{ t('attachment_check.review') }}
              </button>
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

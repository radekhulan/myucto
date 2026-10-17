<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { NoticeConfirmationSummary } from '@/api/bank'
defineProps<{ summary: NoticeConfirmationSummary }>()
const { t } = useI18n()
</script>
<template>
  <div class="flex flex-wrap items-center gap-2 text-xs" data-testid="notice-summary">
    <span class="rounded bg-warning-50 px-2 py-1 text-warning-700">◷ {{ t('bank.notice_confirmation.count_pending', { count: summary.pending }) }}</span>
    <span class="rounded bg-danger-50 px-2 py-1 text-danger-600">! {{ t('bank.notice_confirmation.count_review', { count: summary.review }) }}</span>
    <span class="rounded bg-success-50 px-2 py-1 text-success-700">✓ {{ t('bank.notice_confirmation.count_confirmed', { count: summary.confirmed }) }}</span>
  </div>
</template>

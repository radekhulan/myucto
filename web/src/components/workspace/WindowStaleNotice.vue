<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { btnOutlineSm } from '@/components/ui/buttonStyles'

const emit = defineEmits<{ reload: [] }>()
const { t } = useI18n()

function reload(): void {
  if (window.confirm(t('common.window_data_changed_confirm'))) emit('reload')
}
</script>

<template>
  <div role="status" aria-live="polite"
    class="fixed top-2 right-2 z-40 flex flex-wrap items-center gap-2 rounded-md border border-warning-500/40 bg-warning-50 px-3 py-1.5 text-xs text-neutral-700 shadow-sm print:hidden">
    <span>{{ t('common.window_data_changed') }}</span>
    <button type="button" :class="btnOutlineSm('warning')" class="whitespace-nowrap" @click="reload">
      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6M20 20v-6h-6M5 19A9 9 0 0 0 20 14M19 5A9 9 0 0 0 4 10" />
      </svg>
      {{ t('common.window_data_changed_reload') }}
    </button>
  </div>
</template>

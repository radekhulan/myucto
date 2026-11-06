<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { EetRecordStatus } from '@/api/eet'

/** Stav EET záznamu jako odznak; stav peněz a dokladu se ukazuje vždy zvlášť. */
const props = defineProps<{
  status: EetRecordStatus | null | undefined
  /** Bez záznamu: platba EET nepodléhá nebo ještě neexistuje. */
  emptyKey?: string
}>()

const { t } = useI18n()

const BADGE = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap'
const CLASS = {
  ok: 'bg-success-50 text-success-700 ring-success-600/20',
  warn: 'bg-warning-50 text-warning-700 ring-warning-600/20',
  bad: 'bg-danger-50 text-danger-700 ring-danger-600/20',
  off: 'bg-neutral-100 text-neutral-600 ring-neutral-500/20',
}

const view = computed(() => {
  switch (props.status) {
    case 'confirmed': return { cls: CLASS.ok, label: t('eet.state.confirmed') }
    case 'verification_ok': return { cls: CLASS.ok, label: t('eet.state.verification_ok') }
    case 'confirmed_warning': return { cls: CLASS.warn, label: t('eet.state.confirmed_warning') }
    case 'pending': return { cls: CLASS.warn, label: t('eet.state.pending') }
    case 'sending': return { cls: CLASS.warn, label: t('eet.state.sending') }
    case 'retry_wait': return { cls: CLASS.warn, label: t('eet.state.retry_wait') }
    case 'action_required': return { cls: CLASS.bad, label: t('eet.state.action_required') }
    // Tržbu evidoval jiný systém (e-shop), MyÚčto ji neodesílá.
    case 'external': return { cls: CLASS.off, label: t('eet.state.external') }
    default: return { cls: CLASS.off, label: t(props.emptyKey ?? 'eet.state.none') }
  }
})
</script>

<template>
  <span :class="[BADGE, view.cls]" data-test="eet-record-badge">{{ view.label }}</span>
</template>

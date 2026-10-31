<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import DashboardLayoutEditor from './DashboardLayoutEditor.vue'
import type { DashboardLayoutState } from '@/dashboard/useDashboardLayout'
const props = defineProps<{ state: DashboardLayoutState }>()
const { t } = useI18n()
const dialog = ref(false)
function open() { if (!props.state.editing.value) props.state.edit(); dialog.value = props.state.editing.value }
async function reload() { props.state.cancel(); await props.state.load(); open() }
watch(() => props.state.editing.value, editing => { if (!editing) dialog.value = false })
</script>
<template>
  <div class="flex flex-wrap items-center gap-2">
    <p v-if="state.recovered.value" role="status" class="text-xs text-warning-600">{{ t('dashboardLayout.recovered') }}</p>
    <button type="button" :disabled="state.loading.value || !state.catalog.value || state.saving.value" :class="btnOutline('neutral')" @click="open"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.edit" /></svg>{{ t(state.editing.value ? 'dashboardLayout.continue' : 'dashboardBoard.edit') }}</button>
    <template v-if="state.editing.value">
      <button type="button" :disabled="state.saving.value || !state.writable.value" :title="!state.writable.value ? t('dashboardLayout.demo') : undefined" :class="btnFilled('success')" @click="state.persist()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('dashboardBoard.save') }}</button>
      <button type="button" :disabled="state.saving.value" :class="btnOutline('neutral')" @click="state.cancel()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('dashboardBoard.cancel') }}</button>
    </template>
    <div v-if="state.error.value && !dialog" role="alert" class="basis-full text-sm text-danger-600">{{ t(`dashboardLayout.${state.error.value}`) }} <button type="button" :class="btnOutline('warning')" @click="reload"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.cycle" /></svg>{{ t('dashboardBoard.retry') }}</button></div>
    <DashboardLayoutEditor v-if="dialog" :state="state" @close="dialog = false" @reload="reload" />
  </div>
</template>

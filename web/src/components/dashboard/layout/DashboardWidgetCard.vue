<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import WidgetContent from './WidgetContent.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import type { DashboardLayoutState } from '@/dashboard/useDashboardLayout'
import type { LayoutItem } from '@/dashboard/types'
const props = defineProps<{ item: LayoutItem; state: DashboardLayoutState }>()
const { t } = useI18n()
</script>
<template>
  <article class="dashboard-added-widget min-w-0" :class="item.type === 'actions.todo' ? '' : 'bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm'"
    :style="{ '--widget-columns': item.span }" :data-layout-widget="item.id">
    <h2 v-if="item.type !== 'actions.todo'" class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t(`dashboardWidgets.widgets.${item.type.replaceAll('.', '_')}.title`) }}</h2>
    <div v-if="state.results.value[item.id]?.status === 'error'" role="alert" class="space-y-2">
      <p class="text-sm text-danger-600">{{ t('dashboardBoard.widgetError') }}</p>
      <button type="button" :class="btnOutline('warning')" @click="state.query()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path :d="ICONS.cycle" /></svg>{{ t('dashboardBoard.retry') }}</button>
    </div>
    <p v-else-if="!state.results.value[item.id]" role="status" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="state.results.value[item.id]?.status === 'unavailable'" class="text-sm text-neutral-500">{{ t('dashboardBoard.unavailable') }}</p>
    <p v-else-if="state.results.value[item.id]?.status === 'empty' && item.type !== 'actions.todo'" class="text-sm text-neutral-500">{{ t('dashboardBoard.noData') }}</p>
    <WidgetContent v-else :instance="item" :data="state.results.value[item.id]?.data ?? {}" :reload="() => props.state.reloadWidget(item.id)" />
  </article>
</template>

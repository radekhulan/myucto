<script setup lang="ts">
import { Comment, Fragment, cloneVNode, computed, h, isVNode, useSlots, type VNode } from 'vue'
import DashboardWidgetCard from './DashboardWidgetCard.vue'
import type { DashboardLayoutState } from '@/dashboard/useDashboardLayout'
const props = defineProps<{ state: DashboardLayoutState }>()
const slots = useSlots()
const grid = computed(() => props.state.visible.value.some(item => item.kind !== 'section'))

/** Sloty ponechávají původní DOM, komponenty a jejich self-gating; bez prázdných obalů. */
function roots(nodes: unknown[]): VNode[] {
  return nodes.flatMap(node => {
    if (!isVNode(node) || node.type === Comment) return []
    if (node.type === Fragment && Array.isArray(node.children)) return roots(node.children)
    return [node]
  })
}
const Content = () => props.state.visible.value.flatMap(item => {
  if (item.kind === 'section') return roots(slots[item.type]?.() ?? []).map(node => cloneVNode(node, {
    key: item.id, ...(grid.value ? { class: 'dashboard-original-section' } : {}),
  }))
  if (item.kind === 'divider') return [h('hr', { key: item.id, class: 'col-span-full border-neutral-200', 'data-layout-divider': item.id })]
  return [h(DashboardWidgetCard, { key: item.id, item, state: props.state })]
})
</script>
<template>
  <div :class="grid ? 'dashboard-layout-grid grid grid-cols-1 gap-4 items-start' : state.board === 'home' ? 'space-y-6' : 'space-y-5'" data-dashboard-layout>
    <Content />
  </div>
</template>
<style scoped>
@media (min-width: 1024px) {
  .dashboard-layout-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); }
  .dashboard-layout-grid :deep(.dashboard-original-section) { grid-column: 1 / -1; }
  .dashboard-layout-grid :deep(.dashboard-added-widget) { grid-column: span var(--widget-columns); }
}
</style>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WidgetDefinition, WidgetInstance } from '@/dashboard/types'
const props = withDefaults(defineProps<{ instance: WidgetInstance; definition: WidgetDefinition; cellWidth: number; cellHeight: number; disabled?: boolean; axis?: 'width' | 'height' | 'both' }>(), { axis: 'both' })
const emit = defineEmits<{ resize: [span: number, height: WidgetInstance['height']] }>()
const { t } = useI18n()
const active = ref(false)
let origin: { x: number; y: number; span: number; rows: number; height: WidgetInstance['height'] } = { x: 0, y: 0, span: 0, rows: 0, height: undefined }
let capture: { element: HTMLElement; pointerId: number } | undefined
const heightRows: Record<string, number> = { auto: 6, compact: 4, normal: 6, tall: 9 }
const canWidth = computed(() => props.axis !== 'height')
const canHeight = computed(() => props.axis !== 'width')
const label = computed(() => props.axis === 'width' ? 'dashboardBoard.resizeWidth' : props.axis === 'height' ? 'dashboardBoard.resizeHeight' : 'dashboardBoard.resize')
const position = computed(() => props.axis === 'width' ? 'right-0 top-1/2 cursor-ew-resize' : props.axis === 'height' ? 'bottom-0 left-1/2 cursor-ns-resize' : 'bottom-0 right-0 cursor-nwse-resize')
function nearest<T>(values: T[], target: number, number: (v: T) => number): T | undefined {
  return values.reduce<T | undefined>((best, value) => best === undefined || Math.abs(number(value) - target) < Math.abs(number(best) - target) ? value : best, undefined)
}
function stop() {
  active.value = false
  const captured = capture
  capture = undefined
  if (captured?.element.hasPointerCapture?.(captured.pointerId)) captured.element.releasePointerCapture?.(captured.pointerId)
}
function start(event: PointerEvent) {
  if (props.disabled) return
  stop()
  active.value = true
  origin = { x: event.clientX, y: event.clientY, span: props.instance.span, rows: heightRows[props.instance.height ?? 'auto'] ?? 6, height: props.instance.height }
  capture = { element: event.currentTarget as HTMLElement, pointerId: event.pointerId }
  capture.element.setPointerCapture?.(event.pointerId)
}
function move(event: PointerEvent) {
  if (!active.value || props.disabled) return
  const span = canWidth.value ? nearest(props.definition.spans, origin.span + (event.clientX - origin.x) / (props.cellWidth + 16), Number) ?? origin.span : origin.span
  const heights = (props.definition.heights ?? ['auto']).filter(h => h !== 'auto')
  const height = !canHeight.value || Math.abs(event.clientY - origin.y) < 12 || !heights.length ? origin.height : nearest(heights, origin.rows + (event.clientY - origin.y) / (props.cellHeight + 16), h => heightRows[h] ?? 6) as WidgetInstance['height']
  emit('resize', span, height)
}
function keyboard(event: KeyboardEvent) {
  if (props.disabled) return
  const horizontal = event.key === 'ArrowRight' || event.key === 'ArrowLeft'
  const vertical = event.key === 'ArrowDown' || event.key === 'ArrowUp'
  if ((!horizontal && !vertical) || (horizontal && !canWidth.value) || (vertical && !canHeight.value)) return
  const spans = [...props.definition.spans].sort((a,b) => a-b)
  const heights = (props.definition.heights ?? ['auto']).filter(h => h !== 'auto')
  let span = props.instance.span; let height = props.instance.height
  if (event.key === 'ArrowRight') span = spans.find(s => s > span) ?? span
  else if (event.key === 'ArrowLeft') span = [...spans].reverse().find(s => s < span) ?? span
  else if (event.key === 'ArrowDown') height = heights.find(h => (heightRows[h] ?? 0) > (heightRows[height ?? 'auto'] ?? 6)) as WidgetInstance['height'] ?? height
  else if (event.key === 'ArrowUp') height = [...heights].reverse().find(h => (heightRows[h] ?? 0) < (heightRows[height ?? 'auto'] ?? 6)) as WidgetInstance['height'] ?? height
  event.preventDefault(); emit('resize', span, height)
}
watch(() => props.disabled, value => { if (value) stop() })
watch(() => props.axis, stop)
onBeforeUnmount(stop)
</script>
<template>
  <button type="button" :disabled="disabled" :data-resize-axis="axis" class="widget-resize-handle absolute text-primary-600 touch-none disabled:opacity-50" :class="position" :aria-label="t(label)" :title="t(label)" @pointerdown.stop.prevent="start" @pointermove.stop.prevent="move" @pointerup.stop.prevent="stop" @pointercancel.stop.prevent="stop" @lostpointercapture="stop" @keydown="keyboard" @dragstart.stop.prevent>
    <span v-if="axis !== 'both'" aria-hidden="true" class="resize-edge-mark" />
    <svg v-else aria-hidden="true" class="resize-corner-mark w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m9 20 11-11M15 20l5-5M4 20 20 4" /></svg>
  </button>
</template>

<style scoped>
.widget-resize-handle {
  z-index: 10;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border: 0;
  background: transparent;
  border-radius: .375rem;
}
.widget-resize-handle[data-resize-axis="width"] { width: 32px; height: 44px; transform: translate(50%, -50%); }
.widget-resize-handle[data-resize-axis="height"] { width: 44px; height: 32px; transform: translate(-50%, 50%); }
.widget-resize-handle[data-resize-axis="both"] { width: 28px; height: 28px; }
.resize-edge-mark { display: block; border-radius: 999px; background: currentColor; opacity: .45; transition: opacity .15s, box-shadow .15s; }
[data-resize-axis="width"] .resize-edge-mark { width: 4px; height: 28px; }
[data-resize-axis="height"] .resize-edge-mark { width: 28px; height: 4px; }
.resize-corner-mark { opacity: .45; transition: opacity .15s; }
.widget-resize-handle:hover:not(:disabled) .resize-edge-mark,
.widget-resize-handle:focus-visible .resize-edge-mark,
.widget-resize-handle:active:not(:disabled) .resize-edge-mark { opacity: 1; box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 12%, transparent); }
.widget-resize-handle:hover:not(:disabled) .resize-corner-mark,
.widget-resize-handle:focus-visible .resize-corner-mark,
.widget-resize-handle:active:not(:disabled) .resize-corner-mark { opacity: 1; }
.widget-resize-handle:focus-visible { outline: 2px solid currentColor; outline-offset: 1px; }
@media (pointer: coarse) {
  .widget-resize-handle[data-resize-axis] { width: 44px; height: 44px; }
}
</style>

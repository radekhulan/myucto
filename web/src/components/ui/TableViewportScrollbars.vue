<script setup lang="ts">
import { onBeforeUnmount, onMounted, shallowRef } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  installTableViewportScroll,
  TABLE_VIEWPORT_SCROLLBAR_HEIGHT,
  type TableViewportScrollBar,
} from '@/utils/tableViewportScroll'

const { t } = useI18n()
const bars = shallowRef<TableViewportScrollBar[]>([])
let controller: ReturnType<typeof installTableViewportScroll> | undefined
let drag: { id: string; pointerId: number; startX: number; startScroll: number; scale: number; element: HTMLElement } | undefined

function thumbWidth(bar: TableViewportScrollBar): number {
  return Math.min(bar.width, Math.max(24, bar.width * bar.viewportWidth / bar.contentWidth))
}

function thumbLeft(bar: TableViewportScrollBar): number {
  return bar.maxScrollLeft > 0 ? (bar.width - thumbWidth(bar)) * bar.scrollLeft / bar.maxScrollLeft : 0
}

function scrollTo(bar: TableViewportScrollBar, value: number) {
  controller?.scrollTo(bar.id, Math.max(0, Math.min(bar.maxScrollLeft, value)))
}

function pointerDown(event: PointerEvent, bar: TableViewportScrollBar) {
  if (event.button !== 0 || !event.isPrimary) return
  const element = event.currentTarget as HTMLElement
  element.focus({ preventScroll: true })
  const rect = element.getBoundingClientRect()
  const scale = rect.width > 0 ? rect.width / bar.width : 1
  const x = (event.clientX - rect.left) / scale
  const left = thumbLeft(bar)
  const width = thumbWidth(bar)
  const travel = bar.width - width
  const next = x >= left && x <= left + width
    ? bar.scrollLeft
    : travel > 0 ? (x - width / 2) / travel * bar.maxScrollLeft : 0
  const startScroll = Math.max(0, Math.min(bar.maxScrollLeft, next))
  scrollTo(bar, startScroll)
  drag = { id: bar.id, pointerId: event.pointerId, startX: event.clientX, startScroll, scale, element }
  element.setPointerCapture(event.pointerId)
  event.preventDefault()
}

function pointerMove(event: PointerEvent, bar: TableViewportScrollBar) {
  if (!drag || drag.id !== bar.id || drag.pointerId !== event.pointerId) return
  const travel = bar.width - thumbWidth(bar)
  if (travel > 0) scrollTo(bar, drag.startScroll + (event.clientX - drag.startX) / drag.scale / travel * bar.maxScrollLeft)
}

function endDrag() {
  const previous = drag
  drag = undefined
  if (previous?.element.hasPointerCapture(previous.pointerId)) previous.element.releasePointerCapture(previous.pointerId)
}

function keyDown(event: KeyboardEvent, bar: TableViewportScrollBar) {
  let next: number
  switch (event.key) {
    case 'ArrowLeft': next = bar.scrollLeft - 40; break
    case 'ArrowRight': next = bar.scrollLeft + 40; break
    case 'PageUp': next = bar.scrollLeft - bar.viewportWidth; break
    case 'PageDown': next = bar.scrollLeft + bar.viewportWidth; break
    case 'Home': next = 0; break
    case 'End': next = bar.maxScrollLeft; break
    default: return
  }
  event.preventDefault()
  scrollTo(bar, next)
}

onMounted(() => {
  controller = installTableViewportScroll({ onChange: value => {
    if (drag && !value.some(bar => bar.id === drag?.id)) endDrag()
    bars.value = value
  } })
})

onBeforeUnmount(() => {
  endDrag()
  controller?.destroy()
})
</script>

<template>
  <Teleport v-for="bar in bars" :key="bar.id" :to="bar.portalTarget ?? 'body'">
    <div
         data-table-viewport-scrollbar
         class="table-viewport-scrollbar"
         role="scrollbar" tabindex="0" aria-orientation="horizontal"
         :aria-label="t('common.tableHorizontalScroll')"
         :aria-controls="bar.sourceId"
         :aria-valuemin="0" :aria-valuemax="bar.maxScrollLeft" :aria-valuenow="Math.round(bar.scrollLeft)"
         :style="{ left: `${bar.left}px`, top: `${bar.top}px`, width: `${bar.width}px`, height: `${TABLE_VIEWPORT_SCROLLBAR_HEIGHT}px`, zIndex: bar.zIndex }"
         @keydown="keyDown($event, bar)"
         @pointerdown="pointerDown($event, bar)"
         @pointermove="pointerMove($event, bar)"
         @pointerup="endDrag" @pointercancel="endDrag" @lostpointercapture="endDrag">
      <span class="table-viewport-scrollbar-thumb" aria-hidden="true"
            :style="{ width: `${thumbWidth(bar)}px`, transform: `translateX(${thumbLeft(bar)}px)` }" />
    </div>
  </Teleport>
</template>

<style scoped>
.table-viewport-scrollbar {
  position: fixed;
  box-sizing: border-box;
  border-block: 1px solid var(--color-neutral-300);
  border-radius: 4px;
  background: var(--color-neutral-100);
  box-shadow: 0 -1px 4px rgb(0 0 0 / 8%);
  cursor: pointer;
  touch-action: none;
  user-select: none;
}

.table-viewport-scrollbar:focus-visible {
  outline: 2px solid var(--color-primary-500);
  outline-offset: -2px;
}

.table-viewport-scrollbar-thumb {
  position: absolute;
  top: 2px;
  bottom: 2px;
  left: 0;
  border: 1px solid var(--color-neutral-500);
  border-radius: 999px;
  background: var(--color-neutral-400);
  cursor: grab;
  pointer-events: none;
}

.table-viewport-scrollbar:hover .table-viewport-scrollbar-thumb,
.table-viewport-scrollbar:focus-visible .table-viewport-scrollbar-thumb {
  background: var(--color-neutral-500);
}

@media print {
  .table-viewport-scrollbar { display: none !important; }
}
</style>

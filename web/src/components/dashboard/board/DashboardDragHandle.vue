<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
const props = defineProps<{ disabled?: boolean }>()
const emit = defineEmits<{ start: [x: number, y: number]; move: [x: number, y: number]; finish: []; cancel: [] }>()
const { t } = useI18n()
const active = ref(false)
function start(event: PointerEvent) { if (props.disabled) return; active.value = true; (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId); emit('start', event.clientX, event.clientY) }
function move(event: PointerEvent) { if (active.value && !props.disabled) emit('move', event.clientX, event.clientY) }
function finish(event: PointerEvent) { if (!active.value) return; active.value = false; (event.currentTarget as HTMLElement).releasePointerCapture?.(event.pointerId); if (props.disabled) emit('cancel'); else emit('finish') }
function cancel() { if (!active.value) return; active.value = false; emit('cancel') }
watch(() => props.disabled, value => { if (value) cancel() })
onBeforeUnmount(cancel)
</script>
<template>
  <button type="button" :disabled="disabled" :aria-label="t('dashboardBoard.move')" :title="t('dashboardBoard.move')" :aria-pressed="active" class="p-1.5 rounded text-neutral-600 hover:bg-neutral-100 cursor-grab active:cursor-grabbing touch-none" @pointerdown.stop.prevent="start" @pointermove.stop.prevent="move" @pointerup.stop.prevent="finish" @pointercancel.stop.prevent="cancel" @lostpointercapture="cancel" @dragstart.stop.prevent>
    <svg aria-hidden="true" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="5" r="1.5"/><circle cx="16" cy="5" r="1.5"/><circle cx="8" cy="12" r="1.5"/><circle cx="16" cy="12" r="1.5"/><circle cx="8" cy="19" r="1.5"/><circle cx="16" cy="19" r="1.5"/></svg>
  </button>
</template>

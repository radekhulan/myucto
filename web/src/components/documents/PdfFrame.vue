<script setup lang="ts">
import { computed } from 'vue'
import PdfCanvasViewer from './PdfCanvasViewer.vue'
import { usesNativePdfViewer } from '@/utils/pdfPreview'

const props = defineProps<{ src: string; title?: string | null }>()

const native = usesNativePdfViewer()
const isBlob = computed(() => props.src.startsWith('blob:'))
const framedSrc = computed(() => props.src.includes('#') || isBlob.value ? props.src : `${props.src}#view=FitH`)
const plainSrc = computed(() => props.src.split('#')[0]!)
</script>

<template>
  <embed
    v-if="native && isBlob"
    :key="framedSrc"
    :src="framedSrc"
    type="application/pdf"
    :title="title ?? undefined"
  />
  <iframe
    v-else-if="native"
    :key="framedSrc"
    :src="framedSrc"
    :title="title ?? undefined"
  ></iframe>
  <PdfCanvasViewer v-else :key="plainSrc" :src="plainSrc" :title="title" />
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PDFDocumentProxy, RenderTask } from 'pdfjs-dist'
import { openPdfDocument, type PdfDocumentLoad } from '@/utils/pdfPreview'

const props = defineProps<{ src: string; title?: string | null }>()
const { t } = useI18n()

const MAX_PAGES = 50
const MAX_PIXEL_RATIO = 2

const host = ref<HTMLElement | null>(null)
const pagesEl = ref<HTMLElement | null>(null)
const state = ref<'loading' | 'ready' | 'error'>('loading')
const pageCount = ref(0)
const pageRatios = ref<number[]>([])
const canvases = ref<HTMLCanvasElement[]>([])
const doc = shallowRef<PDFDocumentProxy | null>(null)

let load: PdfDocumentLoad | null = null
let generation = 0
let renderedWidth = 0
let observer: IntersectionObserver | null = null
let resizeObserver: ResizeObserver | null = null
let resizeTimer: ReturnType<typeof setTimeout> | null = null
const renderTasks = new Map<number, RenderTask>()
const renderedAt = new Map<number, number>()
const visible = new Set<number>()

function pageWidth(): number {
  return pagesEl.value?.clientWidth ?? host.value?.clientWidth ?? 0
}

function cancelRenders() {
  for (const task of renderTasks.values()) task.cancel()
  renderTasks.clear()
}

function teardown() {
  generation++
  cancelRenders()
  renderedAt.clear()
  visible.clear()
  observer?.disconnect()
  observer = null
  load?.destroy()
  load = null
  doc.value = null
}

async function renderPage(index: number) {
  const pdf = doc.value, canvas = canvases.value[index], width = pageWidth()
  if (!pdf || !canvas || width <= 0 || renderedAt.get(index) === width || renderTasks.has(index)) return
  const ticket = generation
  const page = await pdf.getPage(index + 1)
  if (ticket !== generation) return
  const base = page.getViewport({ scale: 1 })
  const ratio = Math.min(window.devicePixelRatio || 1, MAX_PIXEL_RATIO)
  const viewport = page.getViewport({ scale: (width / base.width) * ratio })
  canvas.width = Math.floor(viewport.width)
  canvas.height = Math.floor(viewport.height)
  const task = page.render({ canvas, viewport })
  renderTasks.set(index, task)
  try {
    await task.promise
    if (ticket === generation) renderedAt.set(index, width)
  } catch (error) {
    if ((error as { name?: string })?.name !== 'RenderingCancelledException') throw error
  } finally {
    if (renderTasks.get(index) === task) renderTasks.delete(index)
  }
}

function renderVisible() {
  for (const index of visible) void renderPage(index).catch(() => { state.value = 'error' })
}

async function open() {
  teardown()
  const ticket = generation
  state.value = 'loading'
  pageCount.value = 0
  pageRatios.value = []
  canvases.value = []
  load = openPdfDocument(props.src)
  try {
    const pdf = await load.promise
    if (ticket !== generation) return
    const count = Math.min(pdf.numPages, MAX_PAGES)
    const ratios: number[] = []
    for (let n = 1; n <= count; n++) {
      const viewport = (await pdf.getPage(n)).getViewport({ scale: 1 })
      if (ticket !== generation) return
      ratios.push(viewport.height / viewport.width)
    }
    doc.value = pdf
    pageCount.value = pdf.numPages
    pageRatios.value = ratios
    state.value = 'ready'
    await nextTick()
    renderedWidth = pageWidth()
    observer = new IntersectionObserver((entries) => {
      for (const entry of entries) {
        const index = Number((entry.target as HTMLElement).dataset.page)
        if (entry.isIntersecting) visible.add(index)
        else visible.delete(index)
      }
      renderVisible()
    }, { root: host.value, rootMargin: '600px 0px' })
    canvases.value.forEach((canvas) => observer?.observe(canvas))
  } catch {
    if (ticket === generation) state.value = 'error'
  }
}

onMounted(() => {
  void open()
  resizeObserver = new ResizeObserver(() => {
    if (resizeTimer) clearTimeout(resizeTimer)
    resizeTimer = setTimeout(() => {
      const width = pageWidth()
      if (state.value !== 'ready' || Math.abs(width - renderedWidth) < 8) return
      renderedWidth = width
      cancelRenders()
      renderedAt.clear()
      renderVisible()
    }, 200)
  })
  if (host.value) resizeObserver.observe(host.value)
})

watch(() => props.src, () => { void open() })

onBeforeUnmount(() => {
  if (resizeTimer) clearTimeout(resizeTimer)
  resizeObserver?.disconnect()
  teardown()
})
</script>

<template>
  <div
    ref="host"
    data-test="pdf-canvas-viewer"
    class="overflow-auto bg-neutral-100"
    role="document"
    :aria-label="title || t('common.preview_side_title')"
    :aria-busy="state === 'loading'"
  >
    <div v-if="state === 'loading'" class="flex h-full min-h-40 items-center justify-center p-6 text-sm text-neutral-500" role="status">
      {{ t('common.pdf_preview_loading') }}
    </div>
    <div v-else-if="state === 'error'" class="flex h-full min-h-40 flex-col items-center justify-center gap-3 p-6 text-center text-sm text-neutral-500" role="alert">
      <span>{{ t('common.pdf_preview_failed') }}</span>
      <a :href="src" target="_blank" rel="noopener" class="font-medium text-primary-600 underline">{{ t('common.pdf_preview_open') }}</a>
    </div>
    <div v-else ref="pagesEl" class="space-y-1">
      <canvas
        v-for="(ratio, index) in pageRatios"
        :key="index"
        :ref="(el) => { if (el) canvases[index] = el as HTMLCanvasElement }"
        :data-page="index"
        class="block w-full bg-white shadow-sm"
        :style="{ aspectRatio: `1 / ${ratio}` }"
      />
      <p v-if="pageCount > pageRatios.length" class="p-3 text-center text-sm text-neutral-500">
        {{ t('common.pdf_preview_truncated', { shown: pageRatios.length, total: pageCount }) }}
        <a :href="src" target="_blank" rel="noopener" class="font-medium text-primary-600 underline">{{ t('common.pdf_preview_open') }}</a>
      </p>
    </div>
  </div>
</template>

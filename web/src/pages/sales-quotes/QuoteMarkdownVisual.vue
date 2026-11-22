<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { renderMarkdown } from '@/utils/miniMarkdown'
import { ICONS } from '@/components/ui/buttonStyles'

interface ImageOption { id: number; name: string; url: string }
const props = defineProps<{
  modelValue: string
  rows: number
  disabled: boolean
  placeholder?: string
  images: ImageOption[]
}>()

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const { t } = useI18n()
const body = ref<HTMLDivElement | null>(null)
const imageUrls = computed(() => new Map(props.images.map(image => [image.id, image.url])))
const link = ref('')
const linkOpen = ref(false)
let lastRange: Range | null = null
let lastEmitted: string | null = null

watch([body, () => props.modelValue, imageUrls], ([editor, value], previous) => {
  if (!editor) return
  if (lastEmitted === value && previous?.[1] !== undefined && previous[2] === imageUrls.value) return
  editor.innerHTML = renderMarkdown(value, id => imageUrls.value.get(id)).replaceAll('>\n<', '><')
}, { immediate: true })

function captureSelection() {
  const selection = window.getSelection()
  if (selection?.rangeCount && body.value?.contains(selection.anchorNode) && body.value.contains(selection.focusNode)) {
    lastRange = selection.getRangeAt(0).cloneRange()
  }
}

function focusSelection() {
  if (!body.value) return
  body.value.focus()
  const selection = window.getSelection()
  if (selection && lastRange && body.value.contains(lastRange.commonAncestorContainer)) {
    selection.removeAllRanges()
    selection.addRange(lastRange)
  }
}

function marked(text: string, marker: string): string {
  const clean = text.trim()
  if (clean === '') return text
  return text.replace(clean, marker + clean + marker)
}

function children(node: Node): string {
  return Array.from(node.childNodes, markdown).join('')
}

function markdown(node: Node): string {
  if (node.nodeType === Node.TEXT_NODE) return node.textContent?.replaceAll('\u00a0', ' ') ?? ''
  if (!(node instanceof HTMLElement)) return ''
  const content = children(node)
  const tag = node.tagName.toLowerCase()
  if (/^h[1-6]$/.test(tag)) return `${'#'.repeat(Number(tag[1]))} ${content.trim()}\n\n`
  switch (tag) {
    case 'p':
    case 'div':
      return content.trim() + '\n\n'
    case 'br':
      return '  \n'
    case 'b':
    case 'strong':
      return marked(content, '**')
    case 'i':
    case 'em':
      return marked(content, '*')
    case 's':
    case 'strike':
    case 'del':
      return marked(content, '~~')
    case 'code':
      return node.parentElement?.tagName === 'PRE' ? content : marked(content, '`')
    case 'pre':
      return '```\n' + content.trimEnd() + '\n```\n\n'
    case 'blockquote':
      return content.trim().split('\n').map(line => '> ' + line).join('\n') + '\n\n'
    case 'hr':
      return '\n---\n\n'
    case 'ul':
    case 'ol': {
      const start = Number(node.getAttribute('start')) || 1
      return Array.from(node.children).filter(item => item.tagName === 'LI').map((item, index) => {
        const text = children(item).trim().replaceAll('\n', '\n  ')
        return (tag === 'ol' ? `${start + index}. ` : '- ') + text
      }).join('\n') + (node.parentElement?.tagName === 'LI' ? '\n' : '\n\n')
    }
    case 'a': {
      const href = node.getAttribute('href') ?? ''
      return safeLink(href) ? `[${content}](${href})` : content
    }
    case 'img': {
      const id = Number(node.getAttribute('data-quote-file'))
      const alt = (node.getAttribute('alt') ?? '').replace(/[\[\]\r\n]/g, ' ')
      return imageUrls.value.has(id) ? `![${alt}](quote-file:${id})` : alt
    }
    case 'table': {
      const rows = Array.from(node.querySelectorAll('tr')).map(row =>
        Array.from(row.cells).map(cell => children(cell).trim().replaceAll('|', '&#124;').replaceAll('\n', ' ')),
      )
      const header = rows[0]
      if (!header?.length) return ''
      const format = (cells: string[]) => '| ' + cells.join(' | ') + ' |'
      return [format(header), format(header.map(() => '---')), ...rows.slice(1).map(format)].join('\n') + '\n\n'
    }
    default:
      return content
  }
}

function update() {
  if (!body.value || props.disabled) return
  lastEmitted = children(body.value).trim()
  emit('update:modelValue', lastEmitted)
  captureSelection()
}

function command(name: string, value?: string) {
  if (props.disabled) return
  focusSelection()
  document.execCommand(name, false, value)
  update()
}

function safeLink(url: string): boolean {
  return /^(https?:\/\/|mailto:|\/(?!\/)|#)[^\s)]+$/i.test(url)
}

function openLink() {
  link.value = ''
  captureSelection()
  linkOpen.value = true
  void nextTick(() => body.value?.parentElement?.querySelector<HTMLInputElement>('[data-link-entry]')?.focus())
}

function insertLink() {
  if (!safeLink(link.value.trim())) return
  command('createLink', link.value.trim())
  linkOpen.value = false
}

function insertImage(event: Event) {
  const select = event.target as HTMLSelectElement
  const image = props.images.find(file => file.id === Number(select.value))
  select.value = ''
  if (!image || !body.value) return
  focusSelection()
  const element = document.createElement('img')
  element.src = image.url
  element.alt = image.name
  element.dataset.quoteFile = String(image.id)
  command('insertHTML', element.outerHTML)
}

function paste(event: ClipboardEvent) {
  event.preventDefault()
  const content = event.clipboardData?.getData('text/plain')
  if (content) command('insertText', content)
}
</script>

<template>
  <div class="rounded-md border border-neutral-300 bg-surface">
    <div v-if="!disabled" class="flex flex-wrap items-center gap-1 border-b border-neutral-200 px-2 py-1.5">
      <button type="button" class="h-7 rounded border border-neutral-300 px-2 text-xs font-bold" :title="t('markdown_editor.bold')" @mousedown.prevent @click="command('bold')">B</button>
      <button type="button" class="h-7 rounded border border-neutral-300 px-2 text-xs italic" :title="t('markdown_editor.italic')" @mousedown.prevent @click="command('italic')">I</button>
      <select class="h-7 rounded border border-neutral-300 bg-surface px-1 text-xs" :aria-label="t('markdown_editor.heading')" @change="command('formatBlock', ($event.target as HTMLSelectElement).value)">
        <option value="p">{{ t('markdown_editor.paragraph') }}</option>
        <option value="h2">H2</option>
        <option value="h3">H3</option>
        <option value="h4">H4</option>
      </select>
      <button type="button" class="h-7 rounded border border-neutral-300 px-2 text-xs" :title="t('markdown_editor.bullet')" @mousedown.prevent @click="command('insertUnorderedList')">•</button>
      <button type="button" class="h-7 rounded border border-neutral-300 px-2 text-xs" :title="t('markdown_editor.numbered')" @mousedown.prevent @click="command('insertOrderedList')">1.</button>
      <button type="button" class="h-7 rounded border border-neutral-300 px-2 text-xs" :title="t('markdown_editor.link')" @mousedown.prevent @click="openLink">
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
      </button>
      <select v-if="images.length" class="h-7 max-w-48 rounded border border-neutral-300 bg-surface px-1 text-xs" :aria-label="t('sales_quotes.insert_image')" @change="insertImage">
        <option value="">{{ t('sales_quotes.insert_image') }}</option>
        <option v-for="image in images" :key="image.id" :value="image.id">{{ image.name }}</option>
      </select>
      <slot />
    </div>
    <div v-if="linkOpen" class="flex flex-wrap gap-2 border-b border-neutral-200 px-2 py-1.5">
      <input v-model="link" data-link-entry type="url" class="min-w-56 flex-1 rounded border border-neutral-300 px-2 text-sm" :aria-label="t('markdown_editor.link')" placeholder="https://" @keydown.enter.prevent="insertLink" />
      <button type="button" class="px-2 py-1 text-sm font-medium text-primary-700" :disabled="!safeLink(link.trim())" @click="insertLink">{{ t('sales_quotes.insert_link') }}</button>
      <button type="button" class="px-2 py-1 text-sm text-neutral-700" @click="linkOpen = false">{{ t('common.cancel') }}</button>
    </div>
    <div ref="body" class="visual-body px-2 py-1.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-primary-500" :style="{ minHeight: rows * 1.5 + 'rem' }"
      :contenteditable="disabled ? undefined : true" :tabindex="disabled ? undefined : 0" :role="disabled ? undefined : 'textbox'"
      :aria-label="disabled ? undefined : placeholder || t('sales_quotes.section_text')" :aria-multiline="disabled ? undefined : true"
      @input="update" @focusout="captureSelection" @keyup="captureSelection" @pointerup="captureSelection" @paste="paste" @drop.prevent></div>
  </div>
</template>

<style scoped>
.visual-body :deep(h1), .visual-body :deep(h2), .visual-body :deep(h3), .visual-body :deep(h4), .visual-body :deep(h5), .visual-body :deep(h6) { margin: .3em 0; font-weight: 600; }
.visual-body :deep(h1), .visual-body :deep(h2) { font-size: 1.2em; }
.visual-body :deep(p) { margin: .4em 0; }
.visual-body :deep(ul) { list-style: disc; padding-inline-start: 1.6em; }
.visual-body :deep(ol) { list-style: decimal; padding-inline-start: 1.6em; }
.visual-body :deep(a) { color: var(--color-primary-700); text-decoration: underline; }
.visual-body :deep(img) { max-width: 100%; max-height: 22rem; object-fit: contain; }
.visual-body :deep(td), .visual-body :deep(th) { border: 1px solid var(--color-neutral-300); padding: .3em; }
.visual-body :deep(blockquote) { border-inline-start: 2px solid var(--color-neutral-300); padding-inline-start: .6em; }
</style>

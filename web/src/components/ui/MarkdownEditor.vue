<script setup lang="ts">
/**
 * Velmi jednoduchý Markdown editor — textarea, pár tlačítek a náhled.
 *
 * Why: popis skladové karty jde do e-shopu jako formátovaný text, ale holá
 * textarea uživateli neřekne, že Markdown umí, ani jak vypadá výsledek.
 * Plnohodnotný WYSIWYG by sem přinesl závislost i vlastní sanitizaci; tady
 * stačí obalit značky kolem výběru a ukázat náhled.
 *
 * Vykresluje se přes {@link ../../utils/miniMarkdown.ts miniMarkdown}, který
 * vstup nejdřív escapuje — do náhledu se proto nedostane HTML z textu.
 *
 * `v-model` je čistý Markdown, takže je to náhrada 1:1 za `<textarea>`
 * a v datech ani v API se nic nemění.
 */
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { renderMarkdown } from '@/utils/miniMarkdown'
import { ICONS } from './buttonStyles'

const props = withDefaults(defineProps<{
  modelValue: string | null | undefined
  rows?: number
  disabled?: boolean
  placeholder?: string
  imageLabel?: string
  images?: Array<{ id: number; name: string; url: string }>
}>(), {
  rows: 6,
  disabled: false,
  placeholder: undefined,
  imageLabel: undefined,
  images: () => [],
})

const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const { t } = useI18n()
const area = ref<HTMLTextAreaElement | null>(null)
const preview = ref(false)
const text = computed(() => props.modelValue ?? '')
const rendered = computed(() => renderMarkdown(text.value, props.images.length ? id => props.images.find(image => image.id === id)?.url : undefined))

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)

/** Popis zkratky tak, jak ho uživatel zná ze svého systému (Ctrl+B, na Macu ⌘B). */
function shortcut(key: string, shift = false): string {
  return isMac ? `⌘${shift ? '⇧' : ''}${key}` : `Ctrl+${shift ? 'Shift+' : ''}${key}`
}

function titled(label: string, key: string, shift = false): string {
  return `${t(label)} (${shortcut(key, shift)})`
}

/**
 * Nahradí úsek textu. Přes `execCommand('insertText')`, aby změna z tlačítka
 * nebo zkratky šla vrátit přes Ctrl+Z (přepsání hodnoty historii úprav smaže).
 * Kde prohlížeč příkaz nemá, hodnota se jen přepíše.
 */
function replaceRange(el: HTMLTextAreaElement, from: number, to: number, insert: string): void {
  el.focus()
  el.setSelectionRange(from, to)
  const native = typeof document.execCommand === 'function'
    && document.execCommand(insert === '' ? 'delete' : 'insertText', false, insert)
  if (!native) {
    emit('update:modelValue', text.value.slice(0, from) + insert + text.value.slice(to))
  }
}

function runBefore(value: string, pos: number, ch: string): number {
  let n = 0
  while (pos - n - 1 >= 0 && value[pos - n - 1] === ch) n++
  return n
}

function runAfter(value: string, pos: number, ch: string): number {
  let n = 0
  while (value[pos + n] === ch) n++
  return n
}

/** Kurzíva a tučné sdílí znak `*`: kurzíva je běh 1 nebo 3, tučné 2 nebo 3 (`***` = obojí). */
function starMatches(run: number, marker: string): boolean {
  if (marker === '*') return run === 1 || run === 3
  if (marker === '**') return run === 2 || run === 3
  return true
}

function isStarMarker(before: string, after: string): boolean {
  return before === after && /^\*+$/.test(before)
}

/** Značka stojí těsně kolem výběru (`**|text|**`). */
function isWrappedAround(value: string, start: number, end: number, before: string, after: string): boolean {
  if (value.slice(start - before.length, start) !== before || value.slice(end, end + after.length) !== after) return false
  if (!isStarMarker(before, after)) return true
  return starMatches(runBefore(value, start, '*'), before) && starMatches(runAfter(value, end, '*'), before)
}

/** Výběr značku obsahuje (`|**text**|`). */
function isWrappedInside(selected: string, before: string, after: string): boolean {
  if (selected.length <= before.length + after.length) return false
  if (!selected.startsWith(before) || !selected.endsWith(after)) return false
  if (!isStarMarker(before, after)) return true
  return starMatches(runAfter(selected, 0, '*'), before) && starMatches(runBefore(selected, selected.length, '*'), before)
}

/**
 * Obalí vybraný text značkou (nebo ji vloží s ukázkovým slovem, když není nic
 * vybráno) a výběr obnoví, aby šlo psát dál bez sáhnutí po myši. Když značka
 * kolem výběru už je, sundá ji — druhé Ctrl+B tučné písmo vypne, nezdvojí.
 */
async function wrap(before: string, after = before, sample = ''): Promise<void> {
  const el = area.value
  if (!el || props.disabled) return
  const value = text.value
  const start = el.selectionStart
  const end = el.selectionEnd
  const selected = value.slice(start, end)

  if (selected !== '' && isWrappedAround(value, start, end, before, after)) {
    replaceRange(el, start - before.length, end + after.length, selected)
    await nextTick()
    el.focus()
    el.setSelectionRange(start - before.length, end - before.length)
    return
  }
  if (isWrappedInside(selected, before, after)) {
    const inner = selected.slice(before.length, selected.length - after.length)
    replaceRange(el, start, end, inner)
    await nextTick()
    el.focus()
    el.setSelectionRange(start, start + inner.length)
    return
  }

  const body = selected || sample
  replaceRange(el, start, end, before + body + after)
  await nextTick()
  el.focus()
  el.setSelectionRange(start + before.length, start + before.length + body.length)
}

/** Předřadí značku na začátek každého vybraného řádku (nadpis, odrážka). */
async function prefixLines(prefix: string): Promise<void> {
  const el = area.value
  if (!el || props.disabled) return
  const value = text.value
  const lineStart = value.lastIndexOf('\n', el.selectionStart - 1) + 1
  const lineEndRaw = value.indexOf('\n', el.selectionEnd)
  const lineEnd = lineEndRaw === -1 ? value.length : lineEndRaw
  const block = value.slice(lineStart, lineEnd) || t('markdown_editor.sample_line')
  // Stávající blokovou značku nejdřív sundej, ať se přepnutím typu nezdvojí
  // (`1. - text`) — týká se i číslovaného seznamu, ne jen odrážek a nadpisů.
  const prefixed = block.split('\n')
    .map(line => prefix + line.replace(/^(#{1,6}|>+|[-*]|\d+\.)\s+/, ''))
    .join('\n')
  replaceRange(el, lineStart, lineEnd, prefixed)
  await nextTick()
  el.focus()
  el.setSelectionRange(lineStart, lineStart + prefixed.length)
}

/** Nadpis zvolené úrovně; „Odstavec" (0) značku odebere. */
async function applyHeading(value: string): Promise<void> {
  heading.value = ''
  const level = Number(value)
  await prefixLines(level > 0 ? '#'.repeat(level) + ' ' : '')
}

/** Vloží kostru tabulky na nový řádek — psát pajpy ručně je ta nejotravnější část. */
async function insertTable(): Promise<void> {
  const el = area.value
  if (!el || props.disabled) return
  const value = text.value
  const at = el.selectionStart
  const newline = String.fromCharCode(10)
  const lead = at === 0 || value[at - 1] === newline ? '' : newline
  const skeleton = lead + [
    `| ${t('markdown_editor.table_col')} 1 | ${t('markdown_editor.table_col')} 2 |`,
    '| --- | --- |',
    '|  |  |',
    '|  |  |',
  ].join(newline) + newline
  replaceRange(el, at, at, skeleton)
  await nextTick()
  el.focus()
  el.setSelectionRange(at + skeleton.length, at + skeleton.length)
}

const bold = () => wrap('**', '**', t('markdown_editor.sample_bold'))
const italic = () => wrap('*', '*', t('markdown_editor.sample_italic'))
const strike = () => wrap('~~', '~~', t('markdown_editor.sample_strike'))
const code = () => wrap('`', '`', t('markdown_editor.sample_code'))
const link = () => wrap('[', '](https://)', t('markdown_editor.sample_link'))
const bullet = () => prefixLines('- ')
const numbered = () => prefixLines('1. ')
const quote = () => prefixLines('> ')

/**
 * Zkratky jako v běžných editorech (GitHub, Google Docs). Písmena podle `key`, s Shiftem
 * podle `code` — nezávisle na rozložení klávesnice. Ctrl+K (paleta příkazů) a Alt+Q
 * zůstávají globální, jsou vidět v patičce aplikace; odkaz se proto vkládá jen tlačítkem.
 */
const shortcuts: Record<string, () => Promise<void>> = { b: bold, i: italic, e: code }
const shiftShortcuts: Record<string, () => Promise<void>> = { KeyX: strike, Digit8: bullet, Digit7: numbered, Period: quote }

function insertImage(event: Event): void {
  const el = area.value
  const select = event.target as HTMLSelectElement
  const image = props.images.find(file => file.id === Number(select.value))
  select.value = ''
  if (!el || !image) return
  const alt = image.name.replace(/[\[\]\r\n]/g, ' ')
  replaceRange(el, el.selectionStart, el.selectionEnd, `![${alt}](quote-file:${image.id})`)
}

function onKeydown(e: KeyboardEvent): void {
  // AltGr na Windows posílá Ctrl+Alt — to je psaní znaků (@, {…}), ne zkratka.
  if (!(e.ctrlKey || e.metaKey) || e.altKey || props.disabled) return
  const action = e.shiftKey ? shiftShortcuts[e.code] : shortcuts[e.key.toLowerCase()]
  if (!action) return
  // V editoru vyhrává jeho zkratka: globální useHotkey poslouchá na window ve fázi bublání.
  e.preventDefault()
  e.stopPropagation()
  void action()
}

const shortcutHelp = computed(() => [
  `${shortcut('B')} ${t('markdown_editor.bold')}`,
  `${shortcut('I')} ${t('markdown_editor.italic')}`,
  `${shortcut('E')} ${t('markdown_editor.code')}`,
  `${shortcut('X', true)} ${t('markdown_editor.strike')}`,
  `${shortcut('8', true)} ${t('markdown_editor.bullet')}`,
  `${shortcut('7', true)} ${t('markdown_editor.numbered')}`,
  `${shortcut('.', true)} ${t('markdown_editor.quote')}`,
].join('\n'))

const heading = ref('')
const toolButton = 'cursor-pointer h-7 px-2 rounded border border-neutral-300 bg-surface text-xs text-neutral-600 '
  + 'hover:bg-neutral-50 hover:text-neutral-900 disabled:opacity-40 disabled:cursor-not-allowed'
</script>

<template>
  <div class="rounded-md border border-neutral-300 bg-surface">
    <div class="flex flex-wrap items-center gap-1 border-b border-neutral-200 px-2 py-1.5">
      <button type="button" :disabled="disabled || preview" :class="toolButton" class="font-bold"
        :title="titled('markdown_editor.bold', 'B')" aria-keyshortcuts="Control+B Meta+B" @click="bold()">B</button>
      <button type="button" :disabled="disabled || preview" :class="toolButton" class="italic"
        :title="titled('markdown_editor.italic', 'I')" aria-keyshortcuts="Control+I Meta+I" @click="italic()">I</button>
      <select :value="heading" :disabled="disabled || preview" :title="t('markdown_editor.heading')"
        class="cursor-pointer h-7 px-1 rounded border border-neutral-300 bg-surface text-xs text-neutral-600
               hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed"
        @change="applyHeading(($event.target as HTMLSelectElement).value)">
        <option value="">{{ t('markdown_editor.heading') }}</option>
        <option value="1">H1</option>
        <option value="2">H2</option>
        <option value="3">H3</option>
        <option value="4">H4</option>
        <option value="0">{{ t('markdown_editor.paragraph') }}</option>
      </select>
      <button type="button" :disabled="disabled || preview" :class="toolButton" class="line-through"
        :title="titled('markdown_editor.strike', 'X', true)" aria-keyshortcuts="Control+Shift+X Meta+Shift+X" @click="strike()">S</button>
      <button type="button" :disabled="disabled || preview" :class="toolButton" class="font-mono"
        :title="titled('markdown_editor.code', 'E')" aria-keyshortcuts="Control+E Meta+E" @click="code()">&lt;/&gt;</button>
      <button type="button" :disabled="disabled || preview" :class="toolButton"
        :title="titled('markdown_editor.bullet', '8', true)" aria-keyshortcuts="Control+Shift+8 Meta+Shift+8" @click="bullet()">•</button>
      <button type="button" :disabled="disabled || preview" :class="toolButton"
        :title="titled('markdown_editor.numbered', '7', true)" aria-keyshortcuts="Control+Shift+7 Meta+Shift+7" @click="numbered()">1.</button>
      <button type="button" :disabled="disabled || preview" :class="toolButton"
        :title="titled('markdown_editor.quote', '.', true)" aria-keyshortcuts="Control+Shift+. Meta+Shift+." @click="quote()">&rdquo;</button>
      <button type="button" :disabled="disabled || preview" :class="toolButton"
        :title="t('markdown_editor.link')" @click="link()">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" />
        </svg>
      </button>
      <button type="button" :disabled="disabled || preview" :class="toolButton"
        :title="t('markdown_editor.table')" @click="insertTable()">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" />
        </svg>
      </button>
      <select v-if="images.length" class="h-7 max-w-48 rounded border border-neutral-300 bg-surface px-1 text-xs"
        :disabled="disabled || preview" :aria-label="imageLabel" @change="insertImage">
        <option value="">{{ imageLabel }}</option>
        <option v-for="image in images" :key="image.id" :value="image.id">{{ image.name }}</option>
      </select>
      <span class="ml-auto flex items-center gap-2">
        <slot name="toolbar" />
        <span class="hidden sm:inline text-[11px] text-neutral-400 cursor-help" :title="shortcutHelp">{{ t('markdown_editor.hint') }}</span>
        <button type="button" :class="[toolButton, preview ? 'bg-neutral-100 text-neutral-900' : '']"
          :aria-pressed="preview" @click="preview = !preview">
          {{ preview ? t('markdown_editor.edit') : t('markdown_editor.preview') }}
        </button>
      </span>
    </div>

    <textarea v-if="!preview" ref="area" :value="text" :rows="rows" :disabled="disabled" :placeholder="placeholder"
      class="w-full resize-y bg-transparent px-2 py-1.5 text-sm outline-none disabled:text-neutral-400"
      @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
      @keydown="onKeydown"></textarea>

    <!-- Bezpečné: miniMarkdown vstup escapuje, teprve pak doplňuje značky. -->
    <div v-else class="markdown-preview px-2 py-1.5 text-sm" :style="{ minHeight: rows * 1.5 + 'rem' }">
      <div v-if="text.trim() !== ''" v-html="rendered"></div>
      <p v-else class="text-neutral-400">{{ t('markdown_editor.preview_empty') }}</p>
    </div>
  </div>
</template>

<style scoped>
.markdown-preview :deep(img) { max-width: 100%; max-height: 22rem; object-fit: contain; }
/* Minimální typografie náhledu — projekt nemá @tailwindcss/typography. */
.markdown-preview :deep(h1),
.markdown-preview :deep(h2),
.markdown-preview :deep(h3),
.markdown-preview :deep(h4),
.markdown-preview :deep(h5),
.markdown-preview :deep(h6) { font-weight: 600; margin: 0.6em 0 0.3em; }
.markdown-preview :deep(h1) { font-size: 1.25em; }
.markdown-preview :deep(h2) { font-size: 1.15em; }
.markdown-preview :deep(h3) { font-size: 1.05em; }
.markdown-preview :deep(p) { margin: 0.4em 0; }
.markdown-preview :deep(ul),
.markdown-preview :deep(ol) { margin: 0.4em 0; padding-left: 1.25em; }
.markdown-preview :deep(ul) { list-style: disc; }
.markdown-preview :deep(ol) { list-style: decimal; }
.markdown-preview :deep(a) { color: var(--color-primary-600, #4f46e5); text-decoration: underline; }
.markdown-preview :deep(code) {
  font-family: ui-monospace, SFMono-Regular, monospace;
  font-size: 0.9em;
  background: var(--color-neutral-100, #f4f4f5);
  border-radius: 0.25rem;
  padding: 0.05em 0.3em;
}
.markdown-preview :deep(pre) {
  background: var(--color-neutral-100, #f4f4f5);
  border-radius: 0.375rem;
  padding: 0.5rem;
  overflow-x: auto;
  margin: 0.4em 0;
}
.markdown-preview :deep(pre code) { background: none; padding: 0; }
.markdown-preview :deep(table) { border-collapse: collapse; margin: 0.5em 0; width: 100%; }
.markdown-preview :deep(th),
.markdown-preview :deep(td) {
  border: 1px solid var(--color-neutral-200, #e4e4e7);
  padding: 0.25rem 0.5rem;
  text-align: left;
}
.markdown-preview :deep(th) { background: var(--color-neutral-50, #fafafa); font-weight: 600; }
.markdown-preview :deep(blockquote) {
  border-left: 3px solid var(--color-neutral-300, #d4d4d8);
  color: var(--color-neutral-600, #52525b);
  margin: 0.4em 0;
  padding: 0.1em 0 0.1em 0.6rem;
}
.markdown-preview :deep(hr) { border: 0; border-top: 1px solid var(--color-neutral-200, #e4e4e7); margin: 0.8em 0; }
.markdown-preview :deep(del) { color: var(--color-neutral-500, #71717a); }
</style>

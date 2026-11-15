<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { renderMarkdown } from '@/utils/miniMarkdown'

// Odpověď modelu je nedůvěryhodný text: renderMarkdown nejdřív escapuje celý
// vstup a značky doplňuje až potom, takže v-html dostane jen vlastní HTML.
const props = defineProps<{ text: string | null | undefined }>()
const html = computed(() => renderMarkdown(props.text ?? ''))
const router = useRouter()

// Odkaz na stránku aplikace (cesta od `/`) otevře router v aktuálním okně; manuál,
// API a cizí adresy zůstávají v nové záložce. Ctrl/Cmd/Shift klik nechá prohlížeči.
function onClick(e: MouseEvent) {
  if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return
  const a = (e.target as HTMLElement | null)?.closest?.('a')
  const href = a?.getAttribute('href') ?? ''
  if (!router || !href.startsWith('/') || href.startsWith('//') || /^\/(manual|api)(\/|\?|#|$)/.test(href)) return
  e.preventDefault()
  void router.push(href)
}
</script>

<template>
  <div class="ai-markdown text-sm leading-relaxed text-neutral-800 break-words" @click="onClick" v-html="html"></div>
</template>

<style scoped>
.ai-markdown :deep(h1),
.ai-markdown :deep(h2),
.ai-markdown :deep(h3),
.ai-markdown :deep(h4),
.ai-markdown :deep(h5),
.ai-markdown :deep(h6) { font-weight: 600; margin: 0.6em 0 0.3em; }
.ai-markdown :deep(h1) { font-size: 1.2em; }
.ai-markdown :deep(h2) { font-size: 1.1em; }
.ai-markdown :deep(h3) { font-size: 1.05em; }
.ai-markdown :deep(> :first-child) { margin-top: 0; }
.ai-markdown :deep(> :last-child) { margin-bottom: 0; }
.ai-markdown :deep(p) { margin: 0.4em 0; }
.ai-markdown :deep(ul),
.ai-markdown :deep(ol) { margin: 0.4em 0; padding-left: 1.25em; }
.ai-markdown :deep(ul) { list-style: disc; }
.ai-markdown :deep(ol) { list-style: decimal; }
.ai-markdown :deep(a) { color: var(--color-primary-600, #4f46e5); text-decoration: underline; }
.ai-markdown :deep(code) {
  font-family: ui-monospace, SFMono-Regular, monospace;
  font-size: 0.9em;
  background: var(--color-neutral-100, #f4f4f5);
  border-radius: 0.25rem;
  padding: 0.05em 0.3em;
}
.ai-markdown :deep(pre) {
  background: var(--color-neutral-100, #f4f4f5);
  border-radius: 0.375rem;
  padding: 0.5rem;
  overflow-x: auto;
  margin: 0.4em 0;
}
.ai-markdown :deep(pre code) { background: none; padding: 0; }
.ai-markdown :deep(table) { border-collapse: collapse; margin: 0.5em 0; width: 100%; display: block; overflow-x: auto; }
.ai-markdown :deep(th),
.ai-markdown :deep(td) {
  border: 1px solid var(--color-neutral-200, #e4e4e7);
  padding: 0.25rem 0.5rem;
  text-align: left;
}
.ai-markdown :deep(th) { background: var(--color-neutral-50, #fafafa); font-weight: 600; }
.ai-markdown :deep(blockquote) {
  border-left: 3px solid var(--color-neutral-300, #d4d4d8);
  color: var(--color-neutral-600, #52525b);
  margin: 0.4em 0;
  padding: 0.1em 0 0.1em 0.6rem;
}
.ai-markdown :deep(hr) { border: 0; border-top: 1px solid var(--color-neutral-200, #e4e4e7); margin: 0.8em 0; }
</style>

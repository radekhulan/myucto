<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  aiAssistantApi, aiErrorMessage, appliedId,
  type AiApplied, type AiAppliedKind, type AiAssistantStatus, type AiConversationSummary,
  type AiDocumentSuggestion, type AiHistoryTurn, type AiStoredMessage,
} from '@/api/aiAssistant'
import { documentsApi, type DocFolder, type DocItem } from '@/api/documents'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline, btnOutlineSm, btnIconSm } from '@/components/ui/buttonStyles'
import { formatBytes } from '@/components/documents/docFormat'
import { formatDate } from '@/composables/useFormat'
import AiMarkdown from '@/components/ai/AiMarkdown.vue'
import AiJournalProposal from '@/components/ai/AiJournalProposal.vue'
import AiOtherItemProposal from '@/components/ai/AiOtherItemProposal.vue'
import AiInternalDocumentProposal from '@/components/ai/AiInternalDocumentProposal.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

const MAX_FILES = 5
const MAX_DOCS = 5
const MAX_FILE_BYTES = 15 * 1024 * 1024
const MAX_QUESTION = 8000
const ACCEPT = '.pdf,.png,.jpg,.jpeg,.webp,.gif,.txt,.csv,.xml,.isdoc,.json,.docx,.xlsx,.odt'

// ── Stav AI ──────────────────────────────────────────────────────────────────
const status = ref<AiAssistantStatus | null>(null)
const statusLoading = ref(true)
const available = computed(() => status.value?.available === true)
const selectedModel = ref('')
const reasonText = computed(() => {
  const code = status.value?.reason
  if (!code) return t('accounting.ai_assistant.reason.unknown')
  const key = `accounting.ai_assistant.reason.${code}`
  const v = t(key)
  return v === key ? t('accounting.ai_assistant.reason.unknown') : v
})

// ── Přílohy (zůstávají přiložené pro další dotazy, dokud je uživatel neodebere) ──
interface AttachedDoc { id: number; title: string }
const files = ref<File[]>([])
const docs = ref<AttachedDoc[]>([])
const includeChart = ref(true)
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

function addFiles(list: FileList | File[] | null | undefined) {
  if (!list) return
  for (const f of Array.from(list)) {
    if (files.value.length >= MAX_FILES) {
      toast.warning(t('accounting.ai_assistant.too_many_files', { max: MAX_FILES }))
      break
    }
    if (f.size > MAX_FILE_BYTES) {
      toast.warning(t('accounting.ai_assistant.file_too_large', { name: f.name, max: formatBytes(MAX_FILE_BYTES) }))
      continue
    }
    if (files.value.some(x => x.name === f.name && x.size === f.size && x.lastModified === f.lastModified)) continue
    files.value.push(f)
  }
}
function onFilePick(e: Event) {
  const input = e.target as HTMLInputElement
  addFiles(input.files)
  input.value = ''
}
function onDrop(e: DragEvent) {
  dragOver.value = false
  addFiles(e.dataTransfer?.files)
}
function removeFile(i: number) { files.value.splice(i, 1) }

function addDoc(d: AttachedDoc) {
  if (docs.value.some(x => x.id === d.id)) return
  if (docs.value.length >= MAX_DOCS) {
    toast.warning(t('accounting.ai_assistant.too_many_docs', { max: MAX_DOCS }))
    return
  }
  docs.value.push(d)
}
function removeDoc(i: number) { docs.value.splice(i, 1) }

// ── Výběr z Dokumentů ────────────────────────────────────────────────────────
const pickerOpen = ref(false)
const pickerQuery = ref('')
const pickerResults = ref<DocItem[]>([])
const pickerLoading = ref(false)
let pickerTimer: ReturnType<typeof setTimeout> | null = null
let pickerSeq = 0

function onPickerInput() {
  if (pickerTimer) clearTimeout(pickerTimer)
  const q = pickerQuery.value.trim()
  if (q.length < 2) { pickerResults.value = []; return }
  pickerTimer = setTimeout(async () => {
    const seq = ++pickerSeq
    pickerLoading.value = true
    try {
      const res = await documentsApi.search(q)
      if (seq === pickerSeq) pickerResults.value = res
    } catch {
      if (seq === pickerSeq) pickerResults.value = []
    } finally {
      if (seq === pickerSeq) pickerLoading.value = false
    }
  }, 300)
}
function pickDoc(d: DocItem) {
  addDoc({ id: d.id, title: d.title })
}

// ── Konverzace ───────────────────────────────────────────────────────────────
type ChatResponse = NonNullable<AiStoredMessage['response']>
interface ChatMessage {
  id: number
  role: 'user' | 'assistant'
  content: string
  model?: string | null
  fileNames?: string[]
  /** Přiložené dokumenty; title null = zpráva z historie (jen id). */
  docRefs?: { id: number; title: string | null }[]
  response?: ChatResponse
  /** Soubory odeslané s tímto dotazem — index v document_suggestions míří sem. */
  sentFiles?: File[]
  /** Názvy souborů dotazu z historie — samotné bajty už v prohlížeči nejsou. */
  historicFileNames?: string[]
  /** Dokumenty přiložené k tomuto dotazu (navážou se na zaúčtovaný zápis). */
  sentDocIds?: number[]
  /** Id uložené odpovědi na serveru (značky použitých návrhů). */
  serverId?: number | null
  applied?: Record<string, Record<string, number>>
}
const messages = ref<ChatMessage[]>([])
const question = ref('')
const sending = ref(false)
const transcriptEnd = ref<HTMLElement | null>(null)
let msgSeq = 0

/** Dokumenty nahrané z této konverzace (soubor → id dokumentu). */
const uploadedDocs = reactive(new Map<File, number>())

function linkIdsFor(m: ChatMessage): number[] {
  const ids = new Set<number>(m.sentDocIds ?? [])
  for (const id of uploadedDocs.values()) ids.add(id)
  // Dokumenty nahrané z návrhů dříve v téže konverzaci (i z historie).
  for (const msg of messages.value) {
    for (const id of Object.values(msg.applied?.document ?? {})) ids.add(id)
  }
  return [...ids]
}

/** Značky ze serveru mohou dorazit jako pole (PHP list) — sjednotit na {index: id}. */
function normalizeApplied(raw: AiApplied | null | undefined): Record<string, Record<string, number>> {
  const out: Record<string, Record<string, number>> = {}
  if (!raw || Array.isArray(raw)) return out
  for (const [kind, bucket] of Object.entries(raw)) {
    if (!bucket) continue
    const map: Record<string, number> = {}
    for (const [idx, id] of Object.entries(bucket)) {
      const n = Number(id)
      if (Number.isInteger(n) && n > 0) map[idx] = n
    }
    out[kind] = map
  }
  return out
}

function appliedFor(m: ChatMessage, kind: AiAppliedKind, index: number): number | null {
  return appliedId(m.applied, kind, index)
}

/** Návrh byl použit — zapsat lokálně a na server (selhání značky nic neblokuje). */
async function markApplied(m: ChatMessage, kind: AiAppliedKind, index: number, targetId: number) {
  const applied = m.applied ?? (m.applied = {})
  applied[kind] = { ...(applied[kind] ?? {}), [String(index)]: targetId }
  if (!m.serverId) return
  try {
    m.applied = normalizeApplied(await aiAssistantApi.markApplied(m.serverId, kind, index, targetId))
  } catch (e) {
    console.warn('AI assistant: applied marker not saved', e)
  }
}

// ── Historie dotazů ─────────────────────────────────────────────────────────
const conversationId = ref<number | null>(null)
const conversations = ref<AiConversationSummary[]>([])
const historyLoading = ref(false)
const historyOpen = ref(false)
const loadingConversation = ref(false)

async function loadConversations() {
  historyLoading.value = true
  try {
    conversations.value = await aiAssistantApi.conversations()
  } catch {
    conversations.value = []
  } finally {
    historyLoading.value = false
  }
}

function setConversationQuery(id: number | null) {
  const query = { ...route.query }
  if (id) query.c = String(id)
  else delete query.c
  void router.replace({ query })
}

function resetChat() {
  messages.value = []
  for (const k of Object.keys(suggestionState)) delete suggestionState[k]
}

async function loadConversation(id: number) {
  if (sending.value || loadingConversation.value) return
  loadingConversation.value = true
  try {
    const conv = await aiAssistantApi.conversation(id)
    resetChat()
    let lastUser: ChatMessage | null = null
    for (const sm of conv.messages ?? []) {
      const atts = sm.attachments ?? []
      if (sm.role === 'user') {
        const fileNames: string[] = []
        const docRefs: { id: number; title: string | null }[] = []
        for (const a of atts) {
          if (a.kind === 'file') fileNames.push(a.name)
          else if (a.kind === 'document') docRefs.push({ id: a.id, title: null })
        }
        lastUser = { id: ++msgSeq, role: 'user', content: sm.content, fileNames, docRefs }
        messages.value.push(lastUser)
      } else {
        const msg: ChatMessage = {
          id: ++msgSeq,
          role: 'assistant',
          content: sm.content,
          model: sm.model,
          response: sm.response ?? undefined,
          historicFileNames: lastUser?.fileNames ?? [],
          sentDocIds: (lastUser?.docRefs ?? []).map(d => d.id),
          serverId: sm.id,
          applied: normalizeApplied(sm.applied),
        }
        messages.value.push(msg)
        initSuggestions(msg)
      }
    }
    conversationId.value = conv.id
    setConversationQuery(conv.id)
    historyOpen.value = false
    void scrollToEnd()
  } catch (e) {
    toast.error(aiErrorMessage(e, t('accounting.ai_assistant.history.load_failed')))
    if (Number(route.query.c) === id) setConversationQuery(conversationId.value)
  } finally {
    loadingConversation.value = false
  }
}

function newConversation() {
  if (sending.value) return
  if (messages.value.length && !conversationId.value && !window.confirm(t('accounting.ai_assistant.clear_confirm'))) return
  resetChat()
  conversationId.value = null
  setConversationQuery(null)
  historyOpen.value = false
}

async function deleteConversation(c: AiConversationSummary) {
  if (!window.confirm(t('accounting.ai_assistant.history.delete_confirm', { title: c.title }))) return
  try {
    await aiAssistantApi.deleteConversation(c.id)
    conversations.value = conversations.value.filter(x => x.id !== c.id)
    if (conversationId.value === c.id) {
      resetChat()
      conversationId.value = null
      setConversationQuery(null)
    }
    toast.success(t('accounting.ai_assistant.history.deleted'))
  } catch (e) {
    toast.error(aiErrorMessage(e, t('accounting.ai_assistant.history.delete_failed')))
  }
}

function shortDate(iso: string): string {
  if (!iso) return ''
  const now = new Date()
  const ymd = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  return iso.slice(0, 10) === ymd ? iso.slice(11, 16) : formatDate(iso.slice(0, 10))
}

function buildHistory(): AiHistoryTurn[] {
  return messages.value
    .map(m => ({ role: m.role, content: m.content }))
    .filter(m => m.content.trim() !== '')
    .map(m => ({ role: m.role, content: m.content.slice(0, MAX_QUESTION) }))
    .slice(-10)
}

async function scrollToEnd() {
  await nextTick()
  transcriptEnd.value?.scrollIntoView({ behavior: 'smooth', block: 'end' })
}

const canSend = computed(() =>
  available.value && !sending.value && question.value.trim() !== '' && question.value.length <= MAX_QUESTION)

async function send() {
  if (!canSend.value) return
  const q = question.value.trim()
  const sentFiles = [...files.value]
  const sentDocs = [...docs.value]
  const history = buildHistory()
  const userMsg: ChatMessage = {
    id: ++msgSeq,
    role: 'user',
    content: q,
    fileNames: sentFiles.map(f => f.name),
    docRefs: sentDocs.map(d => ({ id: d.id, title: d.title })),
  }
  messages.value.push(userMsg)
  question.value = ''
  sending.value = true
  void scrollToEnd()
  try {
    const res = await aiAssistantApi.ask({
      question: q,
      history,
      files: sentFiles,
      documentIds: sentDocs.map(d => d.id),
      includeChart: includeChart.value,
      model: selectedModel.value || undefined,
      conversationId: conversationId.value,
    })
    const msg: ChatMessage = {
      id: ++msgSeq,
      role: 'assistant',
      content: res.answer,
      model: res.model,
      response: res,
      sentFiles,
      sentDocIds: sentDocs.map(d => d.id),
      serverId: res.message_id ?? null,
      applied: {},
    }
    messages.value.push(msg)
    initSuggestions(msg)
    if (res.conversation_id && res.conversation_id !== conversationId.value) {
      conversationId.value = res.conversation_id
      setConversationQuery(res.conversation_id)
    }
    void loadConversations()
    void scrollToEnd()
  } catch (e) {
    messages.value = messages.value.filter(m => m.id !== userMsg.id)
    if (question.value.trim() === '') question.value = q
    toast.error(aiErrorMessage(e, t('accounting.ai_assistant.ask_failed')))
  } finally {
    sending.value = false
  }
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    void send()
  }
}

// ── Návrhy zařazení souborů do Dokumentů ─────────────────────────────────────
const canUpload = computed(() => auth.canWrite('documents.upload'))
const folders = ref<DocFolder[]>([])
const foldersLoaded = ref(false)
const NEW_FOLDER = -1

interface SuggestionState {
  target: number | null
  newFolderName: string
  newFolderParent: number | null
  title: string
  uploading: boolean
  documentId: number | null
}
const suggestionState = reactive<Record<string, SuggestionState>>({})

function suggestionKey(m: ChatMessage, i: number): string { return `${m.id}:${i}` }

interface FileSuggestion { s: AiDocumentSuggestion; i: number; fileIndex: number; file: File | null; name: string }

function fileSuggestions(m: ChatMessage): FileSuggestion[] {
  const out: FileSuggestion[] = []
  ;(m.response?.document_suggestions ?? []).forEach((s, i) => {
    if (s.attachment.kind !== 'file') return
    const fileIndex = s.attachment.index
    const file = m.sentFiles?.[fileIndex] ?? null
    const name = file?.name ?? m.historicFileNames?.[fileIndex]
    if (name) out.push({ s, i, fileIndex, file, name })
  })
  return out
}

function initSuggestions(m: ChatMessage) {
  const list = fileSuggestions(m)
  if (!list.length) return
  for (const { s, i, file, fileIndex } of list) {
    const existing = (file ? uploadedDocs.get(file) : null) ?? appliedFor(m, 'document', fileIndex)
    suggestionState[suggestionKey(m, i)] = {
      target: s.new_folder_name ? NEW_FOLDER : s.folder_id,
      newFolderName: s.new_folder_name ?? '',
      newFolderParent: s.new_folder_name ? s.folder_id : null,
      title: s.title ?? '',
      uploading: false,
      documentId: existing,
    }
  }
  if (list.some(x => x.file)) void loadFolders()
}

async function loadFolders() {
  if (foldersLoaded.value || !canUpload.value) return
  try {
    folders.value = await documentsApi.allFolders()
    foldersLoaded.value = true
  } catch { /* bez složek lze nahrát do kořene */ }
}

const folderOptions = computed(() => {
  const byId = new Map(folders.value.map(f => [f.id, f]))
  const pathOf = (f: DocFolder): string => {
    const parts: string[] = [f.name]
    let p = f.parent_id
    let guard = 0
    while (p != null && guard++ < 20) {
      const parent = byId.get(p)
      if (!parent) break
      parts.unshift(parent.name)
      p = parent.parent_id
    }
    return parts.join(' / ')
  }
  return folders.value.map(f => ({ id: f.id, label: pathOf(f) })).sort((a, b) => a.label.localeCompare(b.label))
})
function folderLabel(id: number | null): string {
  if (id == null) return t('accounting.ai_assistant.suggestion.root')
  return folderOptions.value.find(o => o.id === id)?.label ?? `#${id}`
}

async function uploadSuggestion(m: ChatMessage, i: number, fileIndex: number, file: File) {
  const st = suggestionState[suggestionKey(m, i)]
  if (!st || st.uploading || st.documentId) return
  st.uploading = true
  try {
    let folderId: number | null = st.target
    if (st.target === NEW_FOLDER) {
      const name = st.newFolderName.trim()
      if (!name) {
        toast.warning(t('accounting.ai_assistant.suggestion.folder_name_required'))
        return
      }
      const created = await documentsApi.createFolder(name, st.newFolderParent)
      folderId = created.id
      foldersLoaded.value = false
      void loadFolders()
      st.target = created.id
    }
    const res = await documentsApi.upload([file], { folderId })
    const rootId = res.root_ids?.[0]
    if (!rootId) {
      const reason = [...(res.errors ?? []), ...(res.skipped ?? [])].map(x => x.reason).join(', ')
      toast.error(t('accounting.ai_assistant.suggestion.upload_failed') + (reason ? ` (${reason})` : ''))
      return
    }
    const title = st.title.trim()
    if (title) {
      try { await documentsApi.update(rootId, { title }) } catch { /* název lze upravit v Dokumentech */ }
    }
    st.documentId = rootId
    uploadedDocs.set(file, rootId)
    void markApplied(m, 'document', fileIndex, rootId)
    // Další dotazy už posílají uložený dokument místo opětovného nahrání souboru.
    const idx = files.value.indexOf(file)
    if (idx >= 0) {
      files.value.splice(idx, 1)
      addDoc({ id: rootId, title: title || file.name })
    }
    // Stejný soubor může mít návrh i v jiných odpovědích — označit jako nahraný.
    for (const msg of messages.value) {
      for (const fs of fileSuggestions(msg)) {
        const other = suggestionState[suggestionKey(msg, fs.i)]
        if (fs.file === file && other && !other.documentId) {
          other.documentId = rootId
          if (msg !== m) void markApplied(msg, 'document', fs.fileIndex, rootId)
        }
      }
    }
    toast.success(t('accounting.ai_assistant.suggestion.uploaded'))
  } catch (e) {
    toast.error(aiErrorMessage(e, t('accounting.ai_assistant.suggestion.upload_failed')))
  } finally {
    st.uploading = false
  }
}

// ── Start ────────────────────────────────────────────────────────────────────
onMounted(async () => {
  try {
    status.value = await aiAssistantApi.status()
  } catch (e) {
    status.value = { available: false, reason: null, provider: null, model: null, supports_files: false }
    toast.error(aiErrorMessage(e, t('accounting.ai_assistant.status_failed')))
  } finally {
    statusLoading.value = false
  }
  if (available.value) {
    void loadConversations()
    const c = Number(route.query.c || 0)
    if (Number.isInteger(c) && c > 0) await loadConversation(c)
  }
  const docId = Number(route.query.document_id || 0)
  if (Number.isInteger(docId) && docId > 0) {
    try {
      const d = await documentsApi.get(docId)
      addDoc({ id: d.id, title: d.title })
    } catch {
      toast.warning(t('accounting.ai_assistant.document_not_found'))
    }
  }
})
</script>

<template>
  <div class="max-w-6xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold">{{ t('accounting.ai_assistant.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.ai_assistant.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span v-if="status?.available && status.model" class="text-xs text-neutral-400 whitespace-nowrap">
          {{ status.provider }} · {{ selectedModel || status.model }}
        </span>
        <button v-if="available" type="button" :class="btnOutline('primary')" :disabled="sending" data-test="ai-assistant-new" @click="newConversation">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.ai_assistant.history.new') }}
        </button>
      </div>
    </div>

    <div v-if="statusLoading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}
    </div>

    <div v-else-if="!available" class="bg-warning-50 border border-warning-500/40 rounded-lg p-4 text-sm text-neutral-700 space-y-2" data-test="ai-assistant-unavailable">
      <p class="font-medium text-warning-700">{{ t('accounting.ai_assistant.unavailable_title') }}</p>
      <p>{{ reasonText }}</p>
      <RouterLink to="/admin/integrations?tab=ai" :class="btnOutline('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
        {{ t('accounting.ai_assistant.open_settings') }}
      </RouterLink>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-[16rem_minmax(0,1fr)] gap-4 items-start">
      <!-- Historie dotazů -->
      <aside class="bg-surface border border-neutral-200 rounded-lg shadow-sm lg:sticky lg:top-4" data-test="ai-assistant-history">
        <button
          type="button"
          class="cursor-pointer w-full flex items-center justify-between gap-2 px-3 py-2 text-sm font-medium text-neutral-700 lg:cursor-default"
          :aria-expanded="historyOpen"
          @click="historyOpen = !historyOpen"
        >
          <span class="inline-flex items-center gap-1.5">
            <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" /></svg>
            {{ t('accounting.ai_assistant.history.title') }}
            <span v-if="conversations.length" class="text-xs text-neutral-400 font-normal">({{ conversations.length }})</span>
          </span>
          <svg class="w-4 h-4 text-neutral-400 transition-transform lg:hidden" :class="historyOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
        </button>
        <div :class="historyOpen ? 'block' : 'hidden lg:block'" class="border-t border-neutral-100">
          <p v-if="historyLoading && !conversations.length" class="px-3 py-3 text-xs text-neutral-400">{{ t('common.loading') }}</p>
          <p v-else-if="!conversations.length" class="px-3 py-3 text-xs text-neutral-400">{{ t('accounting.ai_assistant.history.empty') }}</p>
          <ul v-else class="max-h-[60vh] overflow-y-auto py-1">
            <li
              v-for="c in conversations"
              :key="c.id"
              class="group flex items-start gap-1 px-1"
            >
              <button
                type="button"
                class="cursor-pointer min-w-0 flex-1 text-left rounded-md px-2 py-1.5 hover:bg-neutral-50"
                :class="c.id === conversationId ? 'bg-primary-50 text-primary-800' : 'text-neutral-700'"
                :aria-current="c.id === conversationId ? 'true' : undefined"
                :disabled="loadingConversation || sending"
                @click="loadConversation(c.id)"
              >
                <span class="block text-sm line-clamp-2 break-words" :class="c.id === conversationId ? 'font-medium' : ''">{{ c.title }}</span>
                <span class="block text-xs text-neutral-400 whitespace-nowrap">
                  {{ shortDate(c.updated_at) }} · {{ t('accounting.ai_assistant.history.questions', c.question_count) }}
                </span>
              </button>
              <button
                type="button"
                class="mt-1"
                :class="btnIconSm('danger')"
                :title="t('accounting.ai_assistant.history.delete')"
                :aria-label="t('accounting.ai_assistant.history.delete')"
                :disabled="sending"
                @click="deleteConversation(c)"
              >
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </li>
          </ul>
        </div>
      </aside>

      <div class="min-w-0 space-y-4">
      <div v-if="loadingConversation" class="text-sm text-neutral-400">{{ t('common.loading') }}</div>
      <!-- Přepis konverzace -->
      <div class="space-y-3" data-test="ai-assistant-transcript">
        <div v-if="!messages.length" class="bg-primary-50 border border-primary-200 rounded-lg p-4 text-sm text-neutral-700">
          <p class="font-medium text-primary-800 mb-1">{{ t('accounting.ai_assistant.intro_title') }}</p>
          <p>{{ t('accounting.ai_assistant.intro_body') }}</p>
          <p class="mt-2 text-neutral-500 italic">{{ t('accounting.ai_assistant.intro_example') }}</p>
        </div>

        <template v-for="m in messages" :key="m.id">
          <div v-if="m.role === 'user'" class="flex justify-end">
            <div class="max-w-[85%] rounded-lg rounded-br-sm bg-primary-600 text-white px-3 py-2 text-sm shadow-sm">
              <p class="whitespace-pre-wrap break-words">{{ m.content }}</p>
              <div v-if="(m.fileNames?.length ?? 0) + (m.docRefs?.length ?? 0) > 0" class="mt-1.5 flex flex-wrap gap-1">
                <span v-for="(n, ni) in m.fileNames" :key="'f' + ni" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-white/15 text-xs break-all">
                  <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>{{ n }}
                </span>
                <RouterLink v-for="d in m.docRefs" :key="'d' + d.id" :to="`/documents/${d.id}`" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-white/15 hover:bg-white/25 text-xs underline-offset-2 hover:underline">
                  <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>{{ d.title ?? t('accounting.ai_assistant.history.document_label', { id: d.id }) }}
                </RouterLink>
              </div>
            </div>
          </div>

          <div v-else class="flex justify-start">
            <div class="w-full max-w-[95%] rounded-lg rounded-bl-sm bg-surface border border-neutral-200 px-4 py-3 shadow-sm space-y-3">
              <AiMarkdown :text="m.content" />
              <p v-if="m.model" class="text-xs text-neutral-400">{{ t('accounting.ai_assistant.model') }}: {{ m.model }}</p>

              <ul v-if="m.response?.warnings?.length" class="space-y-1">
                <li v-for="(w, wi) in m.response.warnings" :key="wi" class="text-xs text-warning-700 bg-warning-50 rounded px-2 py-1">{{ w }}</li>
              </ul>

              <div v-if="(m.response?.other_items ?? []).length" class="space-y-2">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.ai_assistant.other_item.heading') }}</h4>
                <AiOtherItemProposal
                  v-for="(oi, oii) in m.response?.other_items ?? []"
                  :key="oii"
                  :item="oi"
                  :link-document-ids="linkIdsFor(m)"
                  :applied-id="appliedFor(m, 'other_item', oii)"
                  @created="id => markApplied(m, 'other_item', oii, id)"
                />
              </div>

              <div v-if="(m.response?.internal_documents ?? []).length" class="space-y-2">
                <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.ai_assistant.internal_document.heading') }}</h4>
                <AiInternalDocumentProposal
                  v-for="(idoc, idi) in m.response?.internal_documents ?? []"
                  :key="idi"
                  :doc="idoc"
                  :link-document-ids="linkIdsFor(m)"
                  :applied-id="appliedFor(m, 'internal_document', idi)"
                  @created="id => markApplied(m, 'internal_document', idi, id)"
                />
              </div>

              <div v-if="m.response?.journal_entries?.length" class="space-y-2">
                <AiJournalProposal
                  v-for="(je, ji) in m.response.journal_entries"
                  :key="ji"
                  :entry="je"
                  :link-document-ids="linkIdsFor(m)"
                  :applied-id="appliedFor(m, 'journal_entry', ji)"
                  @posted="id => markApplied(m, 'journal_entry', ji, id)"
                />
              </div>

              <!-- Návrhy zařazení přiložených souborů do Dokumentů -->
              <div v-if="fileSuggestions(m).length" class="space-y-2">
                <div
                  v-for="fs in fileSuggestions(m)"
                  :key="fs.i"
                  class="border border-neutral-200 rounded-lg p-3 space-y-2 bg-neutral-50"
                  data-test="ai-document-suggestion"
                >
                  <div class="flex flex-wrap items-center gap-2 text-sm">
                    <svg class="w-4 h-4 text-primary-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.folderOpen" /></svg>
                    <span class="font-medium text-neutral-800 break-all">{{ fs.name }}</span>
                    <span v-if="fs.s.folder_path" class="text-xs text-neutral-500">{{ t('accounting.ai_assistant.suggestion.proposed', { path: fs.s.folder_path }) }}</span>
                  </div>
                  <template v-if="suggestionState[suggestionKey(m, fs.i)]?.documentId">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                      <span class="inline-flex items-center gap-1.5 text-success-700">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
                        {{ t('accounting.ai_assistant.suggestion.uploaded') }}
                      </span>
                      <RouterLink :to="{ name: 'document-detail', params: { id: suggestionState[suggestionKey(m, fs.i)]!.documentId! } }" :class="btnOutlineSm('primary')">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                        {{ t('accounting.ai_assistant.suggestion.open_document') }}
                      </RouterLink>
                    </div>
                  </template>
                  <p v-else-if="!fs.file" class="text-xs text-neutral-500">
                    <template v-if="fs.s.new_folder_name">{{ t('accounting.ai_assistant.suggestion.new_folder_option', { name: fs.s.new_folder_name, parent: fs.s.folder_path || t('accounting.ai_assistant.suggestion.root') }) }}<br></template>
                    <template v-if="fs.s.title">{{ t('accounting.ai_assistant.suggestion.title') }}: {{ fs.s.title }}<br></template>
                    <span class="text-warning-700">{{ t('accounting.ai_assistant.suggestion.file_unavailable') }}</span>
                  </p>
                  <template v-else-if="canUpload && suggestionState[suggestionKey(m, fs.i)]">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                      <label class="block">
                        <span class="block text-xs text-neutral-500 mb-1">{{ t('accounting.ai_assistant.suggestion.folder') }}</span>
                        <select v-model="suggestionState[suggestionKey(m, fs.i)]!.target" class="w-full px-2 py-1.5 text-sm border border-neutral-300 rounded-md bg-surface">
                          <option v-if="fs.s.new_folder_name" :value="NEW_FOLDER">{{ t('accounting.ai_assistant.suggestion.new_folder_option', { name: suggestionState[suggestionKey(m, fs.i)]!.newFolderName || fs.s.new_folder_name, parent: folderLabel(suggestionState[suggestionKey(m, fs.i)]!.newFolderParent) }) }}</option>
                          <option :value="null">{{ t('accounting.ai_assistant.suggestion.root') }}</option>
                          <option v-for="o in folderOptions" :key="o.id" :value="o.id">{{ o.label }}</option>
                        </select>
                      </label>
                      <label v-if="suggestionState[suggestionKey(m, fs.i)]!.target === NEW_FOLDER" class="block">
                        <span class="block text-xs text-neutral-500 mb-1">{{ t('accounting.ai_assistant.suggestion.new_folder_name') }}</span>
                        <input v-model="suggestionState[suggestionKey(m, fs.i)]!.newFolderName" type="text" maxlength="255" class="w-full px-2 py-1.5 text-sm border border-neutral-300 rounded-md bg-surface" />
                      </label>
                      <label class="block sm:col-span-2">
                        <span class="block text-xs text-neutral-500 mb-1">{{ t('accounting.ai_assistant.suggestion.title') }}</span>
                        <input v-model="suggestionState[suggestionKey(m, fs.i)]!.title" type="text" maxlength="255" :placeholder="fs.name" class="w-full px-2 py-1.5 text-sm border border-neutral-300 rounded-md bg-surface" />
                      </label>
                    </div>
                    <div class="flex flex-wrap gap-2">
                      <button type="button" :class="btnOutline('primary')" :disabled="suggestionState[suggestionKey(m, fs.i)]!.uploading" @click="fs.file && uploadSuggestion(m, fs.i, fs.fileIndex, fs.file)">
                        <svg v-if="suggestionState[suggestionKey(m, fs.i)]!.uploading" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
                        {{ t('accounting.ai_assistant.suggestion.upload') }}
                      </button>
                    </div>
                  </template>
                  <p v-else-if="!canUpload" class="text-xs text-neutral-500">{{ t('accounting.ai_assistant.suggestion.no_permission') }}</p>
                </div>
              </div>
            </div>
          </div>
        </template>

        <div v-if="sending" class="flex justify-start" role="status" aria-live="polite">
          <div class="inline-flex items-center gap-2 rounded-lg bg-surface border border-neutral-200 px-4 py-3 text-sm text-neutral-500 shadow-sm">
            <svg class="w-4 h-4 animate-spin text-primary-600" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
            {{ t('accounting.ai_assistant.thinking') }}
          </div>
        </div>
        <div ref="transcriptEnd"></div>
      </div>

      <!-- Vstup -->
      <div
        class="bg-surface border rounded-lg shadow-sm p-3 space-y-3 transition-colors"
        :class="dragOver ? 'border-primary-400 bg-primary-50' : 'border-neutral-200'"
        data-test="ai-assistant-composer"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="onDrop"
      >
        <textarea
          v-model="question"
          rows="4"
          :maxlength="MAX_QUESTION"
          :placeholder="t('accounting.ai_assistant.placeholder')"
          class="w-full px-3 py-2 text-sm border border-neutral-300 rounded-lg focus:ring-2 focus:ring-primary-200 outline-none resize-y"
          :disabled="sending"
          @keydown="onKeydown"
        ></textarea>

        <!-- Přiložené soubory a dokumenty -->
        <div v-if="files.length || docs.length" class="flex flex-wrap gap-1.5">
          <span v-for="(f, i) in files" :key="'f' + i + f.name" class="inline-flex items-center gap-1 pl-2 pr-1 h-7 rounded-full bg-neutral-100 text-neutral-700 text-xs max-w-full">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            <span class="truncate">{{ f.name }}</span>
            <span class="text-neutral-400 whitespace-nowrap">{{ formatBytes(f.size) }}</span>
            <button type="button" class="cursor-pointer w-5 h-5 inline-flex items-center justify-center rounded-full hover:bg-neutral-200" :title="t('accounting.ai_assistant.remove_attachment')" :aria-label="t('accounting.ai_assistant.remove_attachment')" @click="removeFile(i)">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            </button>
          </span>
          <span v-for="(d, i) in docs" :key="'d' + d.id" class="inline-flex items-center gap-1 pl-2 pr-1 h-7 rounded-full bg-primary-50 text-primary-700 text-xs max-w-full">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            <RouterLink :to="{ name: 'document-detail', params: { id: d.id } }" class="truncate hover:underline">{{ d.title }}</RouterLink>
            <button type="button" class="cursor-pointer w-5 h-5 inline-flex items-center justify-center rounded-full hover:bg-primary-100" :title="t('accounting.ai_assistant.remove_attachment')" :aria-label="t('accounting.ai_assistant.remove_attachment')" @click="removeDoc(i)">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            </button>
          </span>
        </div>

        <p v-if="status && !status.supports_files" class="text-xs text-warning-700">{{ t('accounting.ai_assistant.no_binary_files') }}</p>

        <!-- Výběr z Dokumentů -->
        <div v-if="pickerOpen" class="border border-neutral-200 rounded-lg p-2 space-y-2">
          <div class="flex items-center gap-2">
            <input
              v-model="pickerQuery"
              type="search"
              class="flex-1 min-w-0 px-3 py-1.5 text-sm border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-200 outline-none"
              :placeholder="t('accounting.ai_assistant.picker_placeholder')"
              @input="onPickerInput"
            />
            <button type="button" :class="btnIconSm('neutral')" :title="t('common.close')" :aria-label="t('common.close')" @click="pickerOpen = false">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            </button>
          </div>
          <p v-if="pickerLoading" class="text-xs text-neutral-400 px-1">{{ t('common.loading') }}</p>
          <p v-else-if="pickerQuery.trim().length >= 2 && !pickerResults.length" class="text-xs text-neutral-400 px-1">{{ t('accounting.ai_assistant.picker_empty') }}</p>
          <ul v-if="pickerResults.length" class="max-h-60 overflow-y-auto divide-y divide-neutral-100">
            <li v-for="d in pickerResults" :key="d.id">
              <button
                type="button"
                class="cursor-pointer w-full text-left px-2 py-1.5 text-sm hover:bg-neutral-50 disabled:opacity-50 disabled:cursor-default"
                :disabled="docs.some(x => x.id === d.id)"
                @click="pickDoc(d)"
              >
                <span class="block truncate text-neutral-800">{{ d.title }}</span>
                <span class="block text-xs text-neutral-400 truncate">
                  <template v-if="d.folder_path">{{ d.folder_path }} · </template>{{ d.created_at.slice(0, 10) }}
                </span>
                <span v-if="d.ai_summary_short" class="block text-xs text-neutral-500 line-clamp-2">{{ d.ai_summary_short }}</span>
              </button>
            </li>
          </ul>
        </div>

        <div v-if="status?.models?.length" class="flex flex-wrap items-center gap-2">
          <label for="assistant-model" class="text-sm text-neutral-700">{{ t('accounting.ai_assistant.model') }}</label>
          <select id="assistant-model" v-model="selectedModel" :disabled="sending" data-test="ai-assistant-model"
                  class="max-w-full px-3 py-1.5 text-sm border border-neutral-300 rounded-md bg-surface">
            <option value="">{{ t('accounting.ai_assistant.default_model', { model: status.model }) }}</option>
            <option v-for="model in status.models" :key="model" :value="model">{{ model }}</option>
          </select>
          <span class="text-xs text-neutral-400">{{ t('accounting.ai_assistant.model_hint') }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <input ref="fileInput" type="file" multiple :accept="ACCEPT" class="hidden" @change="onFilePick" />
          <button type="button" :class="btnOutline('neutral')" :disabled="sending" @click="fileInput?.click()">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            {{ t('accounting.ai_assistant.attach_files') }}
          </button>
          <button type="button" :class="btnOutline('neutral')" :disabled="sending" @click="pickerOpen = !pickerOpen">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.folderOpen" /></svg>
            {{ t('accounting.ai_assistant.attach_documents') }}
          </button>
          <label class="inline-flex items-center gap-2 text-sm text-neutral-700 whitespace-nowrap cursor-pointer">
            <input v-model="includeChart" type="checkbox" class="rounded border-neutral-300" />
            {{ t('accounting.ai_assistant.include_chart') }}
          </label>
          <span class="flex-1"></span>
          <span class="text-xs text-neutral-400 whitespace-nowrap hidden sm:inline">{{ t('accounting.ai_assistant.send_hint') }}</span>
          <button type="button" :class="btnFilled('primary')" :disabled="!canSend" data-test="ai-assistant-send" @click="send">
            <svg v-if="sending" class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" /><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" /></svg>
            {{ t('accounting.ai_assistant.send') }}
          </button>
        </div>
        <p class="text-xs text-neutral-400">{{ t('accounting.ai_assistant.disclaimer') }}</p>
      </div>
      </div>
    </div>
  </div>
</template>

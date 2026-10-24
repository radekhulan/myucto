<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'
import { normalizeStereoAccountCode as normalize, stereoTargetAccounts, type StereoTargetAccount as Account } from './stereoNxAccountTargets'

const props = defineProps<{
  modelValue: string | null | undefined
  accounts: Account[]
  sourceCode: string
  bank?: boolean
  disabled?: boolean
  invalid?: boolean
  label: string
  testId: string
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const { t } = useI18n()
const root = ref<HTMLElement | null>(null)
const list = ref<HTMLElement | null>(null)
const query = ref(props.modelValue ?? '')
const open = ref(false)
const editing = ref(false)
const showAll = ref(false)
const highlight = ref(0)
const listId = `stereo-account-target-${useId()}`
const menuStyle = reactive<Record<string, string>>({ position: 'fixed', zIndex: '60' })
let originalValue = ''

function fold(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}
const options = computed(() => stereoTargetAccounts(props.accounts).filter(account => account.is_active !== false
  && normalize(account.code).slice(0, 3) === props.sourceCode.slice(0, 3)
  && (!props.bank || /^221\.[0-9]{1,6}$/.test(normalize(account.code)))))
const filtered = computed(() => {
  const search = fold(query.value.trim())
  const normalizedSearch = fold(normalize(query.value))
  return showAll.value || !search ? options.value : options.value.filter(account =>
    fold(account.code).includes(search) || fold(normalize(account.code)).includes(normalizedSearch) || fold(account.name).includes(search))
})
watch(() => props.modelValue, value => { if (!editing.value) query.value = value ?? '' })
watch(() => props.disabled, disabled => { if (disabled) open.value = false })
watch(filtered, () => { highlight.value = Math.min(highlight.value, Math.max(0, filtered.value.length - 1)) })

function positionMenu(): void {
  if (!open.value || !root.value) return
  const rect = root.value.getBoundingClientRect()
  const width = Math.min(Math.max(rect.width, 260), window.innerWidth - 16)
  menuStyle.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`
  menuStyle.width = `${width}px`
  const below = window.innerHeight - rect.bottom
  if (below < 200 && rect.top > below) {
    menuStyle.top = ''
    menuStyle.bottom = `${window.innerHeight - rect.top + 4}px`
    menuStyle.maxHeight = `${Math.max(120, Math.min(rect.top - 12, 288))}px`
  } else {
    menuStyle.bottom = ''
    menuStyle.top = `${rect.bottom + 4}px`
    menuStyle.maxHeight = `${Math.max(120, Math.min(below - 12, 288))}px`
  }
}
function focus(): void {
  if (props.disabled) return
  originalValue = props.modelValue ?? ''
  editing.value = true
  showAll.value = true
  open.value = true
  highlight.value = Math.max(0, options.value.findIndex(account => normalize(account.code) === normalize(props.modelValue ?? '')))
  void nextTick(positionMenu)
}
function change(event: Event): void {
  query.value = (event.target as HTMLInputElement).value
  showAll.value = false
  open.value = true
  highlight.value = 0
  const code = normalize(query.value)
  // Název slouží jen k hledání; do potvrzené mapy se zapisuje výhradně kód účtu.
  emit('update:modelValue', /^[0-9]{3}[0-9A-Za-z./-]*$/.test(code) ? code : '')
  void nextTick(positionMenu)
}
function choose(account: Account): void {
  const code = normalize(account.code)
  emit('update:modelValue', code)
  query.value = code
  originalValue = code
  open.value = false
}
function finish(): void {
  editing.value = false
  open.value = false
  query.value = props.modelValue ?? ''
}
function keydown(event: KeyboardEvent): void {
  if (props.disabled) return
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    open.value = true
    highlight.value = Math.max(0, Math.min(highlight.value + (event.key === 'ArrowDown' ? 1 : -1), filtered.value.length - 1))
    void nextTick(() => {
      positionMenu()
      list.value?.querySelector<HTMLElement>(`[data-option-index="${highlight.value}"]`)?.scrollIntoView?.({ block: 'nearest' })
    })
  } else if (event.key === 'Enter' && open.value) {
    event.preventDefault()
    const account = filtered.value[highlight.value]
    if (account) choose(account)
    else finish()
  } else if (event.key === 'Escape') {
    event.preventDefault()
    emit('update:modelValue', originalValue)
    query.value = originalValue
    open.value = false
  } else if (event.key === 'Tab') finish()
}
function outside(event: MouseEvent): void {
  if (open.value && !root.value?.contains(event.target as Node) && !list.value?.contains(event.target as Node)) finish()
}
onMounted(() => {
  document.addEventListener('mousedown', outside)
  window.addEventListener('scroll', positionMenu, true)
  window.addEventListener('resize', positionMenu)
})
onUnmounted(() => {
  document.removeEventListener('mousedown', outside)
  window.removeEventListener('scroll', positionMenu, true)
  window.removeEventListener('resize', positionMenu)
})
</script>

<template>
  <div ref="root" class="relative">
    <input :value="query" :data-testid="testId" type="text" role="combobox" aria-autocomplete="list" :aria-label="label" :aria-expanded="open" :aria-controls="open ? listId : undefined" :aria-activedescendant="open && filtered[highlight] ? `${listId}-${highlight}` : undefined" :aria-invalid="invalid || undefined" :placeholder="t('stereo_nx.mapping.search_accounts')" autocomplete="off" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" :class="invalid ? 'border-danger-500' : ''" :disabled="disabled" @focus="focus" @input="change" @keydown="keydown" @blur="finish" />
    <WorkspaceTeleport>
      <div v-if="open" :id="listId" ref="list" role="listbox" :style="menuStyle" class="overflow-y-auto rounded-md border border-neutral-200 bg-surface shadow-lg">
        <p v-if="!filtered.length" class="px-3 py-2 text-sm text-neutral-500">{{ t('stereo_nx.mapping.no_matching_accounts') }}</p>
        <button v-for="(account, index) in filtered" :id="`${listId}-${index}`" :key="account.code" type="button" role="option" :aria-selected="normalize(account.code) === normalize(modelValue ?? '')" :data-option-index="index" class="block w-full px-3 py-2 text-left text-sm" :class="index === highlight ? 'bg-primary-50 text-primary-700' : 'text-neutral-800 hover:bg-neutral-50'" @mousedown.prevent @mouseenter="highlight = index" @click="choose(account)"><span class="block font-mono">{{ normalize(account.code) }}</span><span class="block text-xs text-neutral-600">{{ account.name }}</span></button>
      </div>
    </WorkspaceTeleport>
  </div>
</template>

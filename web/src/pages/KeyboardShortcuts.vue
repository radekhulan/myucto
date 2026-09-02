<script setup lang="ts">
import { computed, onMounted, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import {
  formatShortcut,
  shortcutProblem,
  shortcutFromEvent,
  useKeyboardShortcuts,
  type ShortcutGroup,
} from '@/composables/useKeyboardShortcuts'
import { isApplePlatform } from '@/utils/clientPlatform'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = withDefaults(defineProps<{ embedded?: boolean }>(), {
  embedded: false,
})
const { t } = useI18n()
const toast = useToast()
const shortcuts = useKeyboardShortcuts()
const applePlatform = isApplePlatform()
const draft = reactive<Record<string, string>>({})
const saving = reactive({ value: false })

const groupOrder: ShortcutGroup[] = ['general', 'menu', 'create']
const groupedActions = computed(() => groupOrder.map(group => ({
  group,
  actions: shortcuts.actions.value.filter(action => action.group === group),
})).filter(section => section.actions.length))

const duplicateCombos = computed(() => {
  const byCombo = new Map<string, string[]>()
  for (const action of shortcuts.actions.value) {
    const combo = draft[action.id] ?? ''
    if (!combo) continue
    const ids = byCombo.get(combo) ?? []
    ids.push(action.id)
    byCombo.set(combo, ids)
  }
  return new Set([...byCombo.values()].filter(ids => ids.length > 1).flat())
})

watch(
  () => shortcuts.actions.value,
  actions => {
    for (const action of actions) {
      if (!Object.prototype.hasOwnProperty.call(draft, action.id)) {
        draft[action.id] = shortcuts.comboFor(action.id)
      }
    }
  },
  { immediate: true, deep: true },
)

onMounted(async () => {
  try {
    await shortcuts.load()
    for (const action of shortcuts.actions.value) draft[action.id] = shortcuts.comboFor(action.id)
  } catch {
    toast.error(t('keyboard_shortcuts.load_error'))
  }
})

function capture(event: KeyboardEvent, id: string): void {
  event.preventDefault()
  event.stopPropagation()
  if (event.key === 'Escape') {
    (event.currentTarget as HTMLInputElement).blur()
    return
  }
  if (event.key === 'Backspace' || event.key === 'Delete') {
    draft[id] = ''
    return
  }
  const combo = shortcutFromEvent(event)
  if (!combo) return
  const problem = shortcutProblem(combo)
  if (problem) {
    toast.error(t(`keyboard_shortcuts.${problem}`, {
      primary: applePlatform ? 'Cmd' : 'Ctrl',
      alternate: applePlatform ? 'Option' : 'Alt',
    }))
    return
  }
  draft[id] = combo
}

async function save(): Promise<void> {
  if (duplicateCombos.value.size || saving.value) return
  saving.value = true
  try {
    await shortcuts.save({ ...draft })
    toast.success(t('keyboard_shortcuts.saved'))
  } catch {
    toast.error(t('keyboard_shortcuts.save_error'))
  } finally {
    saving.value = false
  }
}

async function reset(): Promise<void> {
  if (!confirm(t('keyboard_shortcuts.reset_confirm'))) return
  saving.value = true
  try {
    await shortcuts.reset()
    for (const action of shortcuts.actions.value) draft[action.id] = shortcuts.comboFor(action.id)
    toast.success(t('keyboard_shortcuts.reset_done'))
  } catch {
    toast.error(t('keyboard_shortcuts.save_error'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div :class="props.embedded ? '' : 'max-w-5xl'">
    <div v-if="!props.embedded" class="mb-6">
      <h1 class="text-2xl font-semibold">{{ t('keyboard_shortcuts.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('keyboard_shortcuts.subtitle') }}</p>
    </div>

    <div class="mb-5 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-800">
      {{ t('keyboard_shortcuts.hint') }}
    </div>

    <div class="space-y-6">
      <section v-for="section in groupedActions" :key="section.group">
        <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-neutral-500">
          {{ t(`keyboard_shortcuts.group_${section.group}`) }}
        </h2>
        <div class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
          <div
            v-for="(action, index) in section.actions"
            :key="action.id"
            class="grid gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_15rem_auto] sm:items-center"
            :class="{ 'border-t border-neutral-100': index > 0 }"
          >
            <div class="min-w-0">
              <div class="font-medium text-neutral-800">{{ action.label }}</div>
              <div v-if="action.to" class="truncate text-xs text-neutral-400">{{ action.to }}</div>
            </div>
            <input
              :value="formatShortcut(draft[action.id] ?? '')"
              readonly
              data-shortcut-capture
              class="h-10 w-full cursor-keyboard rounded-md border bg-neutral-50 px-3 text-center font-mono text-sm outline-none focus:border-primary-500 focus:bg-surface focus:ring-2 focus:ring-primary-100"
              :class="duplicateCombos.has(action.id) ? 'border-danger-400 text-danger-600' : 'border-neutral-300 text-neutral-800'"
              :aria-label="t('keyboard_shortcuts.capture_label', { action: action.label })"
              @keydown="capture($event, action.id)"
            />
            <button
              type="button"
              class="cursor-pointer px-2 py-1 text-xs text-neutral-500 hover:text-danger-600 sm:w-16"
              @click="draft[action.id] = ''"
            >
              {{ t('keyboard_shortcuts.clear') }}
            </button>
            <p v-if="duplicateCombos.has(action.id)" class="text-xs text-danger-600 sm:col-start-2 sm:col-span-2">
              {{ t('keyboard_shortcuts.duplicate') }}
            </p>
          </div>
        </div>
      </section>
    </div>

    <div class="mt-6 flex flex-wrap justify-end gap-3">
      <button type="button" :class="btnOutline('neutral')" :disabled="saving.value" @click="reset">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" />
        </svg>
        {{ t('keyboard_shortcuts.reset') }}
      </button>
      <button type="button" :class="btnFilled('primary')" :disabled="saving.value || duplicateCombos.size > 0" @click="save">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
        </svg>
        {{ saving.value ? t('common.loading') : t('common.save') }}
      </button>
    </div>
  </div>
</template>

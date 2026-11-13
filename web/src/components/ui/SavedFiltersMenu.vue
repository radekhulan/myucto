<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { onClickOutside } from '@vueuse/core'
import type { SavedFilter } from '@/api/preferences'
import type { SavedFiltersCtrl } from '@/composables/useSavedFilters'

const props = defineProps<{ ctrl: SavedFiltersCtrl }>()
const { t } = useI18n()

const root = ref<HTMLElement | null>(null)
const open = ref(false)
onClickOutside(root, () => { open.value = false; resetInline() })

const newName = ref('')
const newDefault = ref(false)
const renamingId = ref<number | null>(null)
const renameName = ref('')

const activeFilter = computed<SavedFilter | null>(() =>
  props.ctrl.filters.value.find(f => f.id === props.ctrl.activeId.value) ?? null,
)
const canSave = computed(() => Object.keys(props.ctrl.getQuery()).length > 0)

function resetInline() {
  newName.value = ''
  newDefault.value = false
  renamingId.value = null
  renameName.value = ''
}

function onApply(f: SavedFilter) {
  props.ctrl.apply(f)
  open.value = false
}

async function onSaveCurrent() {
  const name = newName.value.trim()
  if (!name || !canSave.value) return
  await props.ctrl.saveCurrent(name, newDefault.value)
  newName.value = ''
  newDefault.value = false
}

function startRename(f: SavedFilter) {
  renamingId.value = f.id
  renameName.value = f.name
}
async function confirmRename() {
  const id = renamingId.value
  const name = renameName.value.trim()
  if (id === null || !name) { renamingId.value = null; return }
  await props.ctrl.rename(id, name)
  renamingId.value = null
}

async function onToggleDefault(f: SavedFilter) {
  await props.ctrl.setDefault(f.id, !f.is_default)
}

async function onRemove(f: SavedFilter) {
  if (!window.confirm(t('common.filter_delete_confirm'))) return
  await props.ctrl.remove(f.id)
}

async function onOverwrite() {
  const f = activeFilter.value
  if (!f) return
  await props.ctrl.overwrite(f.id)
}
</script>

<template>
  <div ref="root" class="relative">
    <button
      type="button"
      @click="open = !open"
      :aria-expanded="open"
      :title="t('common.saved_filters')"
      class="cursor-pointer shrink-0 whitespace-nowrap h-9 px-2.5 inline-flex items-center gap-1.5 rounded-md border border-neutral-300 bg-surface text-sm text-neutral-700 hover:bg-neutral-50 transition-colors"
      :class="open ? 'bg-neutral-50' : ''"
    >
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16l-7-4-7 4V5z" />
      </svg>
      <span class="max-md:hidden">{{ t('common.saved_filters') }}</span><span class="hidden max-md:inline">{{ t('common.saved_filters_short') }}</span>
      <span
        v-if="activeFilter"
        class="max-w-28 truncate inline-flex items-center px-1.5 h-5 rounded bg-primary-100 text-primary-700 text-xs font-medium"
      >{{ activeFilter.name }}</span>
    </button>

    <transition
      enter-active-class="transition duration-100 ease-out"
      enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100"
      leave-active-class="transition duration-75 ease-in"
      leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="open"
        class="absolute right-0 mt-1 w-72 bg-surface border border-neutral-200 rounded-lg shadow-lg py-1 z-40 max-h-96 overflow-y-auto"
      >
        <!-- Seznam uložených filtrů -->
        <div v-if="ctrl.filters.value.length === 0" class="px-3 py-3 text-sm text-neutral-500">
          {{ t('common.saved_filters_empty') }}
        </div>

        <template v-else>
          <div v-for="f in ctrl.filters.value" :key="f.id" class="px-1">
            <!-- Inline rename -->
            <div v-if="renamingId === f.id" class="flex items-center gap-1.5 px-2 py-1.5">
              <input
                v-model="renameName"
                type="text"
                class="flex-1 h-8 px-2 border border-neutral-300 rounded-md text-sm bg-surface focus:border-primary-500 outline-none"
                @keydown.enter="confirmRename"
                @keydown.esc="renamingId = null"
              />
              <button type="button" @click="confirmRename"
                class="cursor-pointer h-8 px-2 text-sm rounded-md bg-primary-600 text-white hover:bg-primary-700">{{ t('common.ok') }}</button>
            </div>

            <div
              v-else
              class="group flex items-center gap-1 rounded-md px-2 py-1.5 hover:bg-neutral-50"
              :class="ctrl.activeId.value === f.id ? 'bg-primary-50' : ''"
            >
              <button
                type="button"
                @click="onApply(f)"
                class="cursor-pointer flex-1 min-w-0 text-left text-sm truncate"
                :class="ctrl.activeId.value === f.id ? 'text-primary-700 font-medium' : 'text-neutral-700'"
              >{{ f.name }}</button>

              <!-- Hvězdička = toggle default -->
              <button
                type="button"
                @click="onToggleDefault(f)"
                :title="t('common.filter_set_default')"
                :aria-pressed="f.is_default"
                class="cursor-pointer shrink-0 w-6 h-6 inline-flex items-center justify-center rounded"
                :class="f.is_default ? 'text-warning-500' : 'text-neutral-300 hover:text-neutral-500'"
              >
                <svg class="w-4 h-4" :fill="f.is_default ? 'currentColor' : 'none'" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.5l2.09 4.24 4.68.68-3.39 3.3.8 4.66-4.18-2.2-4.18 2.2.8-4.66-3.39-3.3 4.68-.68L11.48 3.5z" />
                </svg>
              </button>

              <!-- Tužka = rename -->
              <button
                type="button"
                @click="startRename(f)"
                :title="t('common.edit')"
                class="cursor-pointer shrink-0 w-6 h-6 inline-flex items-center justify-center rounded text-neutral-300 hover:text-neutral-600"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.5-9.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 8.5-8.5z" />
                </svg>
              </button>

              <!-- Koš = remove -->
              <button
                type="button"
                @click="onRemove(f)"
                :title="t('common.delete')"
                class="cursor-pointer shrink-0 w-6 h-6 inline-flex items-center justify-center rounded text-neutral-300 hover:text-danger-500"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.87 12.14A2 2 0 0 1 16.14 21H7.86a2 2 0 0 1-1.99-1.86L5 7m5 4v6m4-6v6M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>
        </template>

        <!-- Aktualizovat aktivní filtr -->
        <div v-if="activeFilter" class="border-t border-neutral-100 mt-1 pt-1 px-1">
          <button
            type="button"
            @click="onOverwrite"
            class="cursor-pointer w-full text-left px-3 py-1.5 text-sm text-neutral-600 hover:bg-neutral-50 hover:text-primary-700"
          >{{ t('common.filter_update_current') }}</button>
        </div>

        <!-- Uložit aktuální filtry -->
        <div class="border-t border-neutral-100 mt-1 pt-2 px-3 pb-2 space-y-2">
          <div class="text-xs font-medium text-neutral-500">{{ t('common.save_current_as_view') }}</div>
          <input
            v-model="newName"
            type="text"
            :placeholder="t('common.filter_name')"
            :disabled="!canSave"
            class="w-full h-8 px-2 border border-neutral-300 rounded-md text-sm bg-surface focus:border-primary-500 outline-none disabled:bg-neutral-50 disabled:text-neutral-400"
            @keydown.enter="onSaveCurrent"
          />
          <label class="flex items-center gap-2 text-sm text-neutral-700" :class="!canSave ? 'opacity-50' : ''">
            <input v-model="newDefault" type="checkbox" :disabled="!canSave" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('common.filter_default') }}</span>
          </label>
          <button
            type="button"
            @click="onSaveCurrent"
            :disabled="!canSave || !newName.trim()"
            class="cursor-pointer w-full h-8 rounded-md bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 disabled:bg-neutral-300"
          >{{ t('common.save') }}</button>
        </div>
      </div>
    </transition>
  </div>
</template>

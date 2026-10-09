<script setup lang="ts">
/**
 * Správa kategorií a pravidel příchozích zpráv datové schránky.
 *
 * Přejmenování a přesměrování pravidel se sbírají a ukládají JEDNÍM tlačítkem
 * v patičce (s upozorněním „Neuloženo" přímo v seznamu). Přidání a smazání
 * jsou samostatné úkony s vlastním potvrzením — nejsou to úpravy formuláře,
 * ale založení nebo zánik záznamu.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  dataBoxApi,
  type InboxCategory,
  type InboxCategoryOverview,
  type InboxRuleField,
} from '@/api/dataBox'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import { inboxCategoryLabel } from '@/utils/databoxInboxCategories'

const props = defineProps<{
  senders: Array<{ box_id: string; name: string | null }>
}>()

const emit = defineEmits<{
  close: []
  changed: []
}>()

const { t } = useI18n()
const toast = useToast()

const overview = ref<InboxCategoryOverview>({ categories: [], rules: [] })
const loading = ref(true)
const busy = ref(false)
const nameDrafts = ref<Record<number, string>>({})
const ruleDrafts = ref<Record<number, number>>({})
const newCategoryName = ref('')
const newRuleField = ref<InboxRuleField>('sender_box')
const newRulePattern = ref('')
const newRuleCategory = ref<number | ''>('')

const ruleFields: InboxRuleField[] = ['sender_box', 'sender_name', 'subject']

const senderNames = computed(() => new Map(props.senders.map(sender => [sender.box_id, sender.name])))

function apply(next: InboxCategoryOverview) {
  overview.value = next
  nameDrafts.value = Object.fromEntries(next.categories.map(category => [category.id, category.name ?? '']))
  ruleDrafts.value = Object.fromEntries(next.rules.map(rule => [rule.id, rule.category_id]))
}

const changedNames = computed(() => overview.value.categories.filter(category =>
  (nameDrafts.value[category.id] ?? '').trim() !== (category.name ?? ''),
))
const changedRules = computed(() => overview.value.rules.filter(rule =>
  ruleDrafts.value[rule.id] !== undefined && ruleDrafts.value[rule.id] !== rule.category_id,
))
const dirty = computed(() => changedNames.value.length > 0 || changedRules.value.length > 0)

async function load() {
  loading.value = true
  try {
    apply(await dataBoxApi.inboxCategories())
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function run(work: () => Promise<InboxCategoryOverview>, message: string): Promise<boolean> {
  busy.value = true
  try {
    apply(await work())
    toast.success(message)
    emit('changed')
    return true
  } catch (e) {
    toast.error(apiErrorMessage(e))
    return false
  } finally {
    busy.value = false
  }
}

async function saveChanges() {
  busy.value = true
  try {
    let latest: InboxCategoryOverview | null = null
    for (const category of changedNames.value) {
      const name = (nameDrafts.value[category.id] ?? '').trim()
      latest = await dataBoxApi.renameInboxCategory(category.id, name === '' ? null : name)
    }
    for (const rule of changedRules.value) {
      latest = await dataBoxApi.updateInboxRule(rule.id, ruleDrafts.value[rule.id])
    }
    if (latest) apply(latest)
    toast.success(t('databox.inboxBrowse.manager.saved'))
    emit('changed')
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await load()
  } finally {
    busy.value = false
  }
}

function discard() {
  apply(overview.value)
}

async function addCategory() {
  const name = newCategoryName.value.trim()
  if (name === '') return
  if (await run(() => dataBoxApi.createInboxCategory(name), t('databox.inboxBrowse.manager.created'))) {
    newCategoryName.value = ''
  }
}

async function deleteCategory(category: InboxCategory) {
  if (!window.confirm(t('databox.inboxBrowse.manager.deleteCategoryConfirm', { name: inboxCategoryLabel(category, t) }))) return
  await run(() => dataBoxApi.deleteInboxCategory(category.id), t('databox.inboxBrowse.manager.deleted'))
}

async function addRule() {
  if (newRuleCategory.value === '' || newRulePattern.value.trim() === '') return
  const categoryId = newRuleCategory.value
  if (await run(
    () => dataBoxApi.createInboxRule(categoryId, newRuleField.value, newRulePattern.value.trim()),
    t('databox.inboxBrowse.manager.ruleCreated'),
  )) {
    newRulePattern.value = ''
  }
}

async function deleteRule(id: number) {
  if (!window.confirm(t('databox.inboxBrowse.manager.deleteRuleConfirm'))) return
  await run(() => dataBoxApi.deleteInboxRule(id), t('databox.inboxBrowse.manager.deleted'))
}

function rulePatternLabel(field: InboxRuleField, pattern: string): string {
  if (field !== 'sender_box') return pattern
  const name = senderNames.value.get(pattern)
  return name ? `${name} (${pattern})` : pattern
}

function close() {
  if (dirty.value && !window.confirm(t('databox.inboxBrowse.manager.closeUnsavedConfirm'))) return
  emit('close')
}

onMounted(load)
</script>

<template>
  <Modal :title="t('databox.inboxBrowse.manager.title')" width-class="max-w-4xl" @close="close">
    <div class="space-y-6" data-test="inbox-category-manager">
      <p class="text-sm text-neutral-500">{{ t('databox.inboxBrowse.manager.intro') }}</p>

      <section class="space-y-3">
        <h3 class="font-medium text-neutral-900">{{ t('databox.inboxBrowse.manager.categories') }}</h3>
        <ul class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li
            v-for="category in overview.categories"
            :key="category.id"
            class="flex flex-wrap items-center gap-2 px-3 py-2"
            data-test="inbox-manager-category"
          >
            <label class="min-w-[12rem] flex-1">
              <span class="sr-only">{{ t('databox.inboxBrowse.manager.categoryName') }}</span>
              <input
                v-model="nameDrafts[category.id]"
                type="text"
                maxlength="100"
                class="form-input w-full"
                :placeholder="category.code ? t(`databox.inboxBrowse.system.${category.code}`) : ''"
              />
            </label>
            <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">
              {{ category.is_system ? t('databox.inboxBrowse.manager.systemCategory') : t('databox.inboxBrowse.manager.customCategory') }}
            </span>
            <span
              v-if="changedNames.some(c => c.id === category.id)"
              class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-800 dark:bg-warning-900/30 dark:text-warning-200"
            >{{ t('databox.inboxBrowse.manager.unsaved') }}</span>
            <button
              v-if="category.is_system && category.name"
              type="button"
              :class="btnOutlineSm('neutral')"
              :disabled="busy"
              @click="nameDrafts[category.id] = ''"
            >
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" />
              </svg>
              {{ t('databox.inboxBrowse.manager.resetName') }}
            </button>
            <button
              v-if="!category.is_system"
              type="button"
              :class="btnOutlineSm('danger')"
              :disabled="busy"
              :title="t('databox.inboxBrowse.manager.deleteCategory')"
              data-test="inbox-manager-delete-category"
              @click="deleteCategory(category)"
            >
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
              </svg>
              {{ t('databox.inboxBrowse.manager.deleteCategory') }}
            </button>
          </li>
        </ul>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="addCategory">
          <label class="min-w-[14rem] flex-1">
            <span class="sr-only">{{ t('databox.inboxBrowse.manager.newCategoryPlaceholder') }}</span>
            <input
              v-model="newCategoryName"
              type="text"
              maxlength="100"
              class="form-input w-full"
              :placeholder="t('databox.inboxBrowse.manager.newCategoryPlaceholder')"
              data-test="inbox-manager-new-category"
            />
          </label>
          <button type="submit" :class="[btnOutline('primary'), 'whitespace-nowrap']" :disabled="busy || newCategoryName.trim() === ''">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" />
            </svg>
            {{ t('databox.inboxBrowse.manager.addCategory') }}
          </button>
        </form>
      </section>

      <section class="space-y-3">
        <h3 class="font-medium text-neutral-900">{{ t('databox.inboxBrowse.manager.rules') }}</h3>
        <p v-if="!loading && overview.rules.length === 0" class="text-sm text-neutral-500">
          {{ t('databox.inboxBrowse.manager.noRules') }}
        </p>
        <ul v-else class="divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li
            v-for="rule in overview.rules"
            :key="rule.id"
            class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm"
            data-test="inbox-manager-rule"
          >
            <div class="min-w-[14rem] flex-1">
              <div class="text-xs text-neutral-500">
                {{ t(`databox.inboxBrowse.manager.ruleField.${rule.match_field}`) }}
                · {{ t(`databox.inboxBrowse.manager.origin.${rule.origin}`) }}
              </div>
              <div class="break-words font-medium">{{ rulePatternLabel(rule.match_field, rule.pattern) }}</div>
            </div>
            <label class="min-w-[12rem]">
              <span class="sr-only">{{ t('databox.inboxBrowse.manager.targetCategory') }}</span>
              <select v-model.number="ruleDrafts[rule.id]" class="form-select w-full">
                <option v-for="category in overview.categories" :key="category.id" :value="category.id">
                  {{ inboxCategoryLabel(category, t) }}
                </option>
              </select>
            </label>
            <span
              v-if="changedRules.some(r => r.id === rule.id)"
              class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-800 dark:bg-warning-900/30 dark:text-warning-200"
            >{{ t('databox.inboxBrowse.manager.unsaved') }}</span>
            <button
              type="button"
              :class="btnOutlineSm('danger')"
              :disabled="busy"
              :title="t('databox.inboxBrowse.manager.deleteRule')"
              @click="deleteRule(rule.id)"
            >
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
              </svg>
              <span class="sr-only">{{ t('databox.inboxBrowse.manager.deleteRule') }}</span>
            </button>
          </li>
        </ul>

        <form class="grid gap-2 rounded-lg border border-dashed border-neutral-300 p-3 sm:grid-cols-[auto_1fr_auto_auto]" @submit.prevent="addRule">
          <select v-model="newRuleField" class="form-select" :aria-label="t('databox.inboxBrowse.manager.rules')">
            <option v-for="field in ruleFields" :key="field" :value="field">
              {{ t(`databox.inboxBrowse.manager.ruleField.${field}`) }}
            </option>
          </select>
          <input
            v-model="newRulePattern"
            type="text"
            maxlength="190"
            class="form-input"
            :aria-label="t('databox.inboxBrowse.manager.pattern')"
            :placeholder="t(`databox.inboxBrowse.manager.patternPlaceholder.${newRuleField}`)"
            :list="newRuleField === 'sender_box' ? 'inbox-manager-senders' : undefined"
            data-test="inbox-manager-rule-pattern"
          />
          <datalist id="inbox-manager-senders">
            <option v-for="sender in senders" :key="sender.box_id" :value="sender.box_id">{{ sender.name ?? sender.box_id }}</option>
          </datalist>
          <select v-model="newRuleCategory" class="form-select" :aria-label="t('databox.inboxBrowse.manager.targetCategory')">
            <option value="" disabled>{{ t('databox.inboxBrowse.manager.targetCategory') }}</option>
            <option v-for="category in overview.categories" :key="category.id" :value="category.id">
              {{ inboxCategoryLabel(category, t) }}
            </option>
          </select>
          <button
            type="submit"
            :class="[btnOutline('primary'), 'whitespace-nowrap']"
            :disabled="busy || newRuleCategory === '' || newRulePattern.trim() === ''"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" />
            </svg>
            {{ t('databox.inboxBrowse.manager.addRule') }}
          </button>
        </form>
      </section>
    </div>

    <template #footer>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <span
          v-if="dirty"
          class="mr-auto rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-800 dark:bg-warning-900/30 dark:text-warning-200"
        >{{ t('databox.inboxBrowse.manager.unsaved') }}</span>
        <button v-if="dirty" type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="discard">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" />
          </svg>
          {{ t('databox.inboxBrowse.manager.discard') }}
        </button>
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="close">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
          </svg>
          {{ t('databox.inboxBrowse.manager.close') }}
        </button>
        <button
          type="button"
          :class="[btnFilled('primary'), 'whitespace-nowrap']"
          :disabled="busy || !dirty"
          data-test="inbox-manager-save"
          @click="saveChanges"
        >
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
          </svg>
          {{ t('databox.inboxBrowse.manager.save') }}
        </button>
      </div>
    </template>
  </Modal>
</template>

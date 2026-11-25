<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { suggestTags, type EntityTag, type TagResource, type TagSelection } from '@/api/entityTags'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'

const props = defineProps<{ resource: TagResource }>()
const selection = defineModel<TagSelection>({ required: true })
const { t } = useI18n()
const toast = useToast()
const results = ref<EntityTag[]>([])
const query = ref('')
const selected = computed(() => selection.value === 'none' ? [{ id: 0, name: t('entity_tags.none') }] : selection.value)
const options = computed(() => [
  ...results.value,
  ...(!query.value || t('entity_tags.none').toLowerCase().includes(query.value.toLowerCase())
    ? [{ id: 0, name: t('entity_tags.none') }] : []),
]
  .filter(tag => !selected.value.some(item => item.id === tag.id))
  .map(tag => ({ value: tag.id, label: tag.name })))
let request = 0
async function search(q: string) {
  query.value = q
  const version = ++request
  try {
    const tags = await suggestTags(props.resource, q)
    if (version === request) results.value = tags
  } catch (err) {
    if (version === request) toast.error(apiErrorMessage(err))
  }
}
function add(id: number | null) {
  if (id === 0) { selection.value = 'none'; return }
  if (selection.value !== 'none' && selection.value.length >= 64) return
  const tag = results.value.find(item => item.id === id)
  if (tag) selection.value = [...(selection.value === 'none' ? [] : selection.value), tag]
}
</script>

<template>
  <div class="flex flex-wrap items-center gap-1">
    <SearchableSelect :key="selected.map(tag => tag.id).join(',')" :model-value="null" :options="options" remote
      class="w-44" :disabled="selection !== 'none' && selection.length >= 64" :placeholder="t('entity_tags.label')" :aria-label="t('entity_tags.label')"
      @search="search" @update:model-value="add" />
    <button v-for="tag in selected" :key="tag.id" type="button" class="rounded-full bg-primary-50 text-primary-700 px-2 py-1 text-xs whitespace-nowrap"
      :aria-label="t('entity_tags.remove', { name: tag.name })"
      @click="selection = selected.filter(item => item.id !== tag.id)">{{ tag.name }} ×</button>
  </div>
</template>

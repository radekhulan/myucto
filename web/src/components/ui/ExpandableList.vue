<script setup lang="ts" generic="T">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import PaginationBar from './PaginationBar.vue'
import { btnOutlineSm, ICONS } from './buttonStyles'

/**
 * Seznam, který roste s počtem lidí (nálezy kontrol, odkazy k opravě …).
 *
 * U firmy s pěti sty zaměstnanci by se pod jediným nálezem vypsaly stovky
 * řádků a obrazovka by se nedala číst. Sbalený ukazuje jen prvních pár
 * položek, rozbalený stránkuje a dovolí hledat — obsah položky kreslí volající
 * přes slot `item`, takže odkazy k nápravě zůstávají tam, kde byly.
 *
 * `total` je skutečný počet ze serveru, když poslal jen ořezaný výčet; rozdíl
 * se vypíše, aby „a dalších" nelhalo délkou toho, co dorazilo.
 */
const props = withDefaults(defineProps<{
  items: T[]
  itemKey: (item: T, index: number) => string | number
  searchText?: (item: T) => string
  total?: number | null
  previewLimit?: number
  pageSize?: number
  listTag?: 'ul' | 'ol' | 'div'
  listClass?: string
  itemClass?: string
  testId?: string
}>(), {
  total: null,
  previewLimit: 8,
  pageSize: 25,
  listTag: 'ul',
  listClass: 'space-y-2',
  itemClass: '',
  testId: 'expandable-list',
})

defineSlots<{
  item(props: { item: T, index: number }): unknown
}>()

const { t } = useI18n()

const expanded = ref(false)
const query = ref('')
const page = ref(1)

function normalize(value: string): string {
  return value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLocaleLowerCase()
}

const indexed = computed(() => props.items.map((item, index) => ({ item, index })))
const canExpand = computed(() => props.items.length > props.previewLimit)
const searchable = computed(() => expanded.value && props.searchText !== undefined)
const needle = computed(() => normalize(query.value.trim()))

const filtered = computed(() => {
  const searchText = props.searchText
  if (!expanded.value || needle.value === '' || searchText === undefined) return indexed.value
  return indexed.value.filter(entry => normalize(searchText(entry.item)).includes(needle.value))
})

const visible = computed(() => {
  if (!expanded.value) return indexed.value.slice(0, props.previewLimit)
  const start = (page.value - 1) * props.pageSize
  return filtered.value.slice(start, start + props.pageSize)
})

const notListed = computed(() =>
  Math.max(0, (props.total ?? props.items.length) - props.items.length))

watch(query, () => { page.value = 1 })

// Po přenačtení může seznam zkrátit; stránka za koncem by ukázala prázdno.
watch(() => filtered.value.length, (length) => {
  const last = Math.max(1, Math.ceil(length / props.pageSize))
  if (page.value > last) page.value = last
})

function toggle() {
  expanded.value = !expanded.value
  if (!expanded.value) {
    query.value = ''
    page.value = 1
  }
}
</script>

<template>
  <div :data-test="testId">
    <label v-if="searchable" class="mb-2 block max-w-sm">
      <span class="sr-only">{{ t('common.expandable_list.search_label') }}</span>
      <input
        v-model="query"
        type="search"
        :placeholder="t('common.expandable_list.search')"
        class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
        :data-test="`${testId}-search`"
      >
    </label>

    <component :is="listTag" v-if="visible.length" :class="listClass">
      <component
        :is="listTag === 'div' ? 'div' : 'li'"
        v-for="entry in visible"
        :key="itemKey(entry.item, entry.index)"
        :class="itemClass"
      >
        <slot name="item" :item="entry.item" :index="entry.index" />
      </component>
    </component>
    <p v-else-if="expanded" class="text-xs opacity-80" :data-test="`${testId}-no-results`">
      {{ t('common.expandable_list.no_results') }}
    </p>

    <div v-if="expanded && filtered.length > pageSize" class="mt-2" :data-test="`${testId}-pagination`">
      <PaginationBar :page="page" :per-page="pageSize" :total="filtered.length" @update:page="page = $event" />
    </div>

    <div v-if="canExpand || notListed > 0" class="mt-2 flex flex-wrap items-center gap-2">
      <button
        v-if="canExpand"
        type="button"
        :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
        :aria-expanded="expanded"
        :data-test="`${testId}-toggle`"
        @click="toggle"
      >
        <svg
          class="h-4 w-4 transition-transform"
          :class="expanded ? 'rotate-180' : ''"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          aria-hidden="true"
        >
          <path :d="ICONS.chevron" />
        </svg>
        {{ expanded
          ? t('common.expandable_list.collapse')
          : notListed > 0
            ? t('common.expandable_list.show_listed', { count: items.length, total: total ?? items.length })
            : t('common.expandable_list.show_all', { count: items.length }) }}
      </button>
      <span v-if="notListed > 0" class="text-xs opacity-80" :data-test="`${testId}-not-listed`">
        {{ t('common.expandable_list.not_listed', { shown: items.length, total: total ?? items.length, rest: notListed }) }}
      </span>
    </div>
  </div>
</template>

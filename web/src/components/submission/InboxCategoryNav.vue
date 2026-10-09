<script setup lang="ts">
/**
 * Přehled kategorií příchozích zpráv s počty.
 *
 * Na desktopu svislý seznam vedle zpráv, na mobilu řada odznaků nad nimi.
 * Prázdné kategorie se neukazují (kromě zvolené), aby seznam nezahltilo
 * devět systémových kategorií, které firma nikdy nepotřebuje. Všechny
 * kategorie jsou ve správě kategorií.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { InboxCategory } from '@/api/dataBox'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'
import { inboxCategoryLabel } from '@/utils/databoxInboxCategories'

const props = defineProps<{
  categories: InboxCategory[]
  counts: Array<{ category_id: number | null; count: number; unread: number }>
  selected: number | null
  canManage: boolean
}>()

const emit = defineEmits<{
  select: [id: number | null]
  manage: []
}>()

const { t } = useI18n()

const rows = computed(() => {
  const byId = new Map(props.counts.map(row => [row.category_id, row]))
  return props.categories
    .map(category => ({
      category,
      count: byId.get(category.id)?.count ?? 0,
      unread: byId.get(category.id)?.unread ?? 0,
    }))
    .filter(row => row.count > 0 || row.category.id === props.selected)
})

const total = computed(() => props.counts.reduce((sum, row) => sum + row.count, 0))
const totalUnread = computed(() => props.counts.reduce((sum, row) => sum + row.unread, 0))

function itemClass(active: boolean): string {
  return active
    ? 'border-primary-500 bg-primary-50 text-primary-800 dark:bg-primary-900/30 dark:text-primary-100'
    : 'border-neutral-200 bg-surface text-neutral-700 hover:bg-neutral-50 dark:text-neutral-200'
}
</script>

<template>
  <nav :aria-label="t('databox.inboxBrowse.categoriesTitle')" data-test="inbox-category-nav">
    <div class="mb-2 flex items-center justify-between gap-2">
      <h2 class="text-xs font-medium uppercase text-neutral-500">{{ t('databox.inboxBrowse.categoriesTitle') }}</h2>
    </div>
    <ul class="flex flex-wrap gap-2 lg:flex-col lg:flex-nowrap lg:gap-1">
      <li>
        <button
          type="button"
          class="flex w-full cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-md border px-3 py-1.5 text-left text-sm"
          :class="itemClass(selected === null)"
          data-test="inbox-category-all"
          @click="emit('select', null)"
        >
          <span class="truncate">{{ t('databox.inboxBrowse.allMessages') }}</span>
          <span class="flex shrink-0 items-center gap-1 tabular-nums">
            <span
              v-if="totalUnread > 0"
              class="rounded-full bg-primary-600 px-1.5 text-xs font-medium text-white"
              :title="t('databox.inboxBrowse.unreadBadge', { count: totalUnread })"
            >{{ totalUnread }}</span>
            <span class="text-xs text-neutral-500">{{ total }}</span>
          </span>
        </button>
      </li>
      <li v-for="row in rows" :key="row.category.id">
        <button
          type="button"
          class="flex w-full cursor-pointer items-center justify-between gap-3 whitespace-nowrap rounded-md border px-3 py-1.5 text-left text-sm"
          :class="itemClass(selected === row.category.id)"
          data-test="inbox-category-item"
          @click="emit('select', row.category.id)"
        >
          <span class="truncate" :class="row.unread > 0 ? 'font-semibold' : ''">{{ inboxCategoryLabel(row.category, t) }}</span>
          <span class="flex shrink-0 items-center gap-1 tabular-nums">
            <span
              v-if="row.unread > 0"
              class="rounded-full bg-primary-600 px-1.5 text-xs font-medium text-white"
              :title="t('databox.inboxBrowse.unreadBadge', { count: row.unread })"
            >{{ row.unread }}</span>
            <span class="text-xs text-neutral-500">{{ row.count }}</span>
          </span>
        </button>
      </li>
    </ul>
    <button
      v-if="canManage"
      type="button"
      :class="[btnOutlineSm('neutral'), 'mt-3 whitespace-nowrap']"
      data-test="inbox-manage-categories"
      @click="emit('manage')"
    >
      <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.tag" />
      </svg>
      {{ t('databox.inboxBrowse.manageCategories') }}
    </button>
  </nav>
</template>

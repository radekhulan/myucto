<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MarkdownEditor from '@/components/ui/MarkdownEditor.vue'
import QuoteMarkdownVisual from './QuoteMarkdownVisual.vue'

const model = defineModel<string>({ required: true })
defineProps<{
  images: Array<{ id: number; name: string; url: string }>
  rows: number
  disabled: boolean
  placeholder?: string
}>()

const { t } = useI18n()
const visual = ref(true)
const toggleClass = 'h-7 cursor-pointer rounded border border-neutral-300 bg-surface px-2 text-xs text-neutral-600 hover:bg-neutral-50 hover:text-neutral-900'
</script>

<template>
  <QuoteMarkdownVisual v-if="visual || disabled" v-model="model" :rows="rows" :disabled="disabled" :placeholder="placeholder" :images="images">
    <button type="button" :class="[toggleClass, 'ml-auto']" @click="visual = false">{{ t('sales_quotes.markdown_mode') }}</button>
  </QuoteMarkdownVisual>
  <MarkdownEditor v-else v-model="model" :rows="rows" :placeholder="placeholder" :images="images" :image-label="t('sales_quotes.insert_image')">
    <template #toolbar>
      <button type="button" :class="toggleClass" @click="visual = true">{{ t('sales_quotes.visual_mode') }}</button>
    </template>
  </MarkdownEditor>
</template>

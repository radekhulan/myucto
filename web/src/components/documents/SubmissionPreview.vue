<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PdfFrame from './PdfFrame.vue'

defineProps<{ src: string; fileName: string; docType: string }>()
const { t } = useI18n()
</script>

<template>
  <div class="bg-neutral-50 min-h-[420px]">
    <PdfFrame v-if="docType === 'pdf'" :key="src" :src="src"
      class="block w-full h-[68vh] min-h-[420px]" :title="fileName" />
    <div v-else-if="docType === 'image'" class="p-4 flex justify-center">
      <img :key="src" :src="src" :alt="fileName" class="max-h-[68vh] object-contain" />
    </div>
    <div v-else class="min-h-[420px] flex items-center justify-center p-6 text-center text-sm text-neutral-500">
      {{ t('purchase_submissions.no_inline_preview') }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

defineProps<{ endpoint: string; description?: string; hint?: string }>()
const emit = defineEmits<{ copy: [endpoint: string] }>()
const { t } = useI18n()
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
    <h2 class="text-lg font-semibold mb-3">{{ t('mcp_server_page.connection.remote_title') }}</h2>
    <p v-if="description" class="mb-4 text-sm text-neutral-700">{{ description }}</p>
    <p class="mb-1 text-xs text-neutral-500">{{ t('mcp_server_page.connection.remote_url_label') }}</p>
    <div class="flex flex-wrap items-center gap-3">
      <code class="block min-h-9 min-w-0 max-w-full break-all rounded-md bg-neutral-100 px-3 py-2 font-mono text-sm leading-5">{{ endpoint }}</code>
      <button type="button" :class="btnFilled('primary')" @click="emit('copy', endpoint)">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" />
        </svg>
        {{ t('mcp_server_page.connection.copy_url') }}
      </button>
    </div>
    <p v-if="hint" class="mt-3 text-sm text-neutral-600">{{ hint }}</p>
  </div>
</template>

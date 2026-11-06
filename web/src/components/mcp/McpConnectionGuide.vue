<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{ clientDomain?: boolean; mainDomain?: boolean }>(), {
  clientDomain: false,
  mainDomain: false,
})
const { t } = useI18n()

const stepCopy = computed(() => {
  if (!props.clientDomain) return 'mcp_server_page.connection.step_copy'
  return props.mainDomain ? 'client_mcp.step_copy_main_domain' : 'client_mcp.step_copy'
})
const stepAuthorize = computed(() => {
  if (!props.clientDomain) return 'mcp_server_page.connection.step_authorize'
  return props.mainDomain ? 'client_mcp.step_authorize_main_domain' : 'client_mcp.step_authorize'
})
</script>

<template>
  <div class="space-y-4">
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
      <h2 class="text-lg font-semibold mb-3">{{ t('mcp_server_page.connection.how_to_title') }}</h2>
      <p v-if="clientDomain" class="mb-3 text-sm text-neutral-700">{{ t('client_mcp.endpoint_hint') }}</p>
      <ol class="list-decimal pl-5 space-y-2 text-sm text-neutral-700">
        <li>{{ t(stepCopy) }}</li>
        <li>{{ t('mcp_server_page.connection.step_add') }}</li>
        <li>{{ t(stepAuthorize) }}</li>
        <li>{{ t(clientDomain ? 'client_mcp.step_verify' : 'mcp_server_page.connection.step_verify') }}</li>
      </ol>
      <p class="mt-3 rounded-md bg-primary-50 border border-primary-600/40 px-3 py-2 text-sm text-primary-700">
        {{ t('mcp_server_page.connection.no_pat') }}
      </p>
      <p v-if="clientDomain" class="mt-3 text-sm text-neutral-600">{{ t('client_mcp.url_requirement') }}</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
        <h3 class="font-semibold mb-2">{{ t('mcp_server_page.connection.chatgpt_title') }}</h3>
        <ol class="list-decimal pl-5 space-y-2 text-sm text-neutral-700">
          <li>{{ t('mcp_server_page.connection.chatgpt_1') }}</li>
          <li>{{ t('mcp_server_page.connection.chatgpt_2') }}</li>
          <li>{{ t('mcp_server_page.connection.chatgpt_3') }}</li>
        </ol>
        <p class="mt-3 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-neutral-700">
          {{ t('mcp_server_page.connection.chatgpt_mobile') }}
        </p>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
        <h3 class="font-semibold mb-2">{{ t('mcp_server_page.connection.claude_title') }}</h3>
        <ol class="list-decimal pl-5 space-y-2 text-sm text-neutral-700">
          <li>{{ t('mcp_server_page.connection.claude_1') }}</li>
          <li>{{ t('mcp_server_page.connection.claude_2') }}</li>
          <li>{{ t('mcp_server_page.connection.claude_3') }}</li>
        </ol>
        <p class="mt-3 rounded-md bg-success-50 border border-success-500/40 px-3 py-2 text-sm text-neutral-700">
          {{ t('mcp_server_page.connection.claude_mobile') }}
        </p>
        <a href="https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp"
          target="_blank" rel="noopener noreferrer" class="mt-2 inline-block text-xs text-primary-700 hover:underline">
          {{ t('mcp_server_page.connection.provider_guide') }} →
        </a>
      </div>
    </div>
  </div>
</template>

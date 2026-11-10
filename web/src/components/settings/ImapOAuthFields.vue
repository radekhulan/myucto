<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const tenantId = defineModel<string | null>('tenantId')
const clientId = defineModel<string | null>('clientId')
const clientSecret = defineModel<string | null>('clientSecret')
defineProps<{ secretSaved?: boolean }>()
</script>

<template>
  <label class="block text-sm font-medium text-neutral-700">
    {{ t('settings.imap_oauth_tenant_id') }}
    <input v-model.trim="tenantId" required maxlength="36" spellcheck="false"
      class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
  </label>
  <label class="block text-sm font-medium text-neutral-700">
    {{ t('settings.imap_oauth_client_id') }}
    <input v-model.trim="clientId" required maxlength="36" spellcheck="false"
      class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
  </label>
  <label class="block text-sm font-medium text-neutral-700">
    {{ t('settings.imap_oauth_client_secret') }}
    <input v-model="clientSecret" type="password" autocomplete="new-password" :required="!secretSaved" maxlength="1024"
      :placeholder="secretSaved ? t('settings.imap_oauth_secret_saved') : undefined"
      class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
  </label>
</template>

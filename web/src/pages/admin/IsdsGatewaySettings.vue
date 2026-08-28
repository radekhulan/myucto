<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import IsdsGatewayRegistrations from '@/components/settings/IsdsGatewayRegistrations.vue'

const { t } = useI18n()
</script>

<template>
  <div class="space-y-6">
    <header class="space-y-2">
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('databox.gateway.settingsTitle') }}</h1>
      <p class="max-w-4xl text-sm text-neutral-500">{{ t('databox.gateway.settingsSubtitle') }}</p>
      <!--
        Proč brána neumí číst, patří nad seznam registrací, ne až k jedné
        konkrétní zprávě: uživatel se jinak diví, kde má doručenku, až ve chvíli,
        kdy ji shání. Odkaz na schránku firmy odlišuje obě stránky — jsou to
        dvě různé věci a ptali se na to.
      -->
      <p class="max-w-4xl text-sm text-neutral-500">{{ t('databox.gateway.settingsReadOnly') }}</p>
      <p class="max-w-4xl text-sm text-neutral-500">
        {{ t('databox.gateway.settingsCompanyLink') }}
        <RouterLink to="/admin/databox" class="font-medium text-primary-700 underline">
          {{ t('databox.gateway.settingsCompanyLinkAction') }}
        </RouterLink>
      </p>
    </header>

    <IsdsGatewayRegistrations />
  </div>
</template>

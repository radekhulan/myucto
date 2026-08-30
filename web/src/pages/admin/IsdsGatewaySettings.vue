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
        Čtyři odstavce pod sebou nikdo nečetl. Kroky odeslání jsou číslované,
        protože je to postup, ne popis. Proč brána neumí číst, patří nad seznam
        registrací, ne až k jedné konkrétní zprávě: uživatel se jinak diví, kde
        má doručenku, až ve chvíli, kdy ji shání.
      -->
      <ol class="max-w-4xl list-decimal space-y-1 pl-5 text-sm text-neutral-600">
        <li v-for="step in ['redirect', 'signIn', 'confirm', 'return']" :key="step">
          {{ t(`databox.gateway.settingsStep_${step}`) }}
        </li>
      </ol>
      <div class="max-w-4xl space-y-1 border-l-2 border-neutral-200 pl-3 text-sm text-neutral-500">
        <p>{{ t('databox.gateway.settingsReadOnly') }}</p>
        <p>
          {{ t('databox.gateway.settingsCompanyLink') }}
          <RouterLink to="/admin/databox" class="font-medium text-primary-700 underline">
            {{ t('databox.gateway.settingsCompanyLinkAction') }}
          </RouterLink>
        </p>
      </div>
    </header>

    <IsdsGatewayRegistrations />
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

onMounted(() => {
  const target = route.query.target
  if (typeof target === 'string' && target.length <= 4096
    && (target.startsWith('/oauth/authorize?') || target.startsWith('/api/auth/mobile/authorize?'))) {
    window.location.replace(target)
    return
  }
  void router.replace('/profile/mcp-server')
})
</script>

<template><div /></template>

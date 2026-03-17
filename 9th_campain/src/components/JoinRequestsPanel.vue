<script setup lang="ts">
import type { JoinRequest } from '@/composables/useMapIO'

const props = defineProps<{
  requests: JoinRequest[]
}>()

const emit = defineEmits<{
  approve: [map_uid: string, user_id: number]
  deny: [map_uid: string, user_id: number]
}>()
</script>

<template>
  <div class="requests-panel">
    <div class="requests-header">
      🔔 Join Requests
      <span class="requests-badge">{{ requests.length }}</span>
    </div>
    <div class="requests-list">
      <div v-for="req in requests" :key="`${req.map_uid}-${req.user_id}`" class="request-row">
        <div class="request-info">
          <span class="request-user">{{ req.user_name }}</span>
          <span class="request-map">→ {{ req.map_name }}</span>
        </div>
        <div class="request-actions">
          <button class="btn-approve" @click="emit('approve', req.map_uid, req.user_id)" title="Approve">✓</button>
          <button class="btn-deny"    @click="emit('deny',    req.map_uid, req.user_id)" title="Deny">✕</button>
        </div>
      </div>
    </div>
  </div>
</template>
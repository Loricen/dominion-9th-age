<script setup lang="ts">
import type { MapListItem } from '@/composables/useMapIO'

const props = defineProps<{
  maps: MapListItem[]
  isLoggedIn: boolean
  isAdvancedPlayer: boolean
}>()

const emit = defineEmits<{
  load: [uid: string]
  delete: [uid: string]
}>()

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: '2-digit' })
}
</script>

<template>
  <div class="maps-list">
    <div v-if="!isLoggedIn" class="not-logged">Log in to see your maps</div>
    <div v-else-if="maps.length === 0" class="empty">No maps yet</div>
    <div v-else v-for="map in maps" :key="map.hexmap_uid" class="map-row">
      <div class="map-info">
        <span class="map-name">
          {{ map.name }}
          <span v-if="map.mapStatus==='ended'"    class="map-tag tag-ended">ended</span>
          <span v-else-if="map.mapStatus==='started'" class="map-tag tag-started">started</span>
          <span v-else-if="map.mapStatus==='ongoing'" class="map-tag tag-locked">locked</span>
          <span v-if="!map.is_owner" class="map-tag tag-joined">joined</span>
        </span>
        <span class="map-meta">{{ map.hexmap_uid }}<br/>
          {{ formatDate(map.savedAt) }}</span>
      </div>
      <div class="map-actions">
        <button class="btn-load" @click="emit('load', map.hexmap_uid)" title="Load">▶</button>
        <button v-if="map.is_owner && map.mapStatus!=='ended'" class="btn-del" @click="emit('delete', map.hexmap_uid)" title="Delete">🗑</button>
      </div>
    </div>
  </div>
</template>

<style>
.map-tag {
  display: inline-block;
  font-size: 0.62rem;
  padding: 1px 5px;
  border-radius: 3px;
  margin-left: 5px;
  vertical-align: middle;
  font-family: sans-serif;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  font-weight: bold;
}
.tag-locked { background: #2a3a10; color: #8adf4a; border: 1px solid #4a7a20; }
.tag-ended  { background: #3a1010; color: #df6060; border: 1px solid #7a2020; }
.tag-joined   { background: #1a2a3a; color: #6aafdf; border: 1px solid #2a5a8a; }
.tag-started  { background: #1a2a1a; color: #4adf8a; border: 1px solid #2a7a4a; }
</style>
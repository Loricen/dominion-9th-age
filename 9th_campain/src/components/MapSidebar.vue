<script setup lang="ts">
import { ref } from 'vue'
import { TERRAIN_TYPES, TERRAIN_COLOR, TERRAIN_LABEL, type TerrainType, type MapSizeKey } from '@/composables/useHexMap'
import type { ProceduralSettings } from '@/composables/useProceduralGen'
import type { MapListItem, MapPlayer, ChatMessage } from '@/composables/useMapIO'
import ProceduralPanel from './ProceduralPanel.vue'
import MapsList from './MapsList.vue'

const props = defineProps<{
  selectedQ: number | null
  selectedR: number | null
  selectedTerrainLabel: string
  imageLoaded: boolean
  selectedSize: MapSizeKey
  proceduralSettings: ProceduralSettings
  userMaps: MapListItem[]
  isLoggedIn: boolean
  isAdvancedPlayer: boolean
  activeTerrain: TerrainType
  mapPlayers: MapPlayer[]
  mapStatus: string
  currentUserId: number
}>()

const emit = defineEmits<{
  terrainChange: [t: TerrainType]
  generate: []
  resetProcedural: []
  sizeChange: [size: MapSizeKey]
  loadMap: [uid: string]
  deleteMap: [uid: string]
  'update:proceduralSettings': [s: ProceduralSettings]
}>()

const showGettingStarted = ref(true)
const showPlayers        = ref(true)
const showMaps           = ref(true)
const showProcedural     = ref(true)
const showControls       = ref(true)
</script>

<template>
  <aside class="sidebar">

    <!-- Getting Started -->
    <div class="panel import-hint" v-if="!imageLoaded && showGettingStarted">
      <div class="panel-title">
        Getting Started
        <button @click="showGettingStarted = false">✕</button>
      </div>
      <p v-if="isAdvancedPlayer">
        Use <strong>🎲 Generate</strong> for procedural maps, or
        <strong>🗺️ Load Image</strong> to trace a reference image.
      </p>
      <p v-else>
        Use <strong>🔑 Load by UID</strong> in the toolbar to load a map,
        then request to join it.
      </p>
    </div>

    <!-- My Maps -->
    <div class="panel">
      <div class="panel-title" @click="showMaps = !showMaps">
        🗂️ My Maps <span class="chevron">{{ showMaps ? '▲' : '▼' }}</span>
      </div>
      <div class="collapsible" :class="{ collapsed: !showMaps }">
        <MapsList
          :maps="userMaps"
          :is-logged-in="isLoggedIn"
          :is-advanced-player="isAdvancedPlayer"
          @load="emit('loadMap', $event)"
          @delete="emit('deleteMap', $event)"
        />
      </div>
    </div>

    <!-- Generation Settings — advanced_player only -->
    <div class="panel" v-if="isAdvancedPlayer">
      <div class="collapsible" :class="{ collapsed: !showProcedural }">
        <ProceduralPanel
          v-if="showProcedural"
          :settings="proceduralSettings"
          :selected-size="selectedSize"
          :is-advanced-player="isAdvancedPlayer"
          :active-terrain="activeTerrain"
          @generate="emit('generate')"
          @reset="emit('resetProcedural')"
          @size-change="emit('sizeChange', $event)"
          @terrain-change="emit('terrainChange', $event)"
          @update:settings="emit('update:proceduralSettings', $event)"
        />
      </div>
    </div>


    <!-- Players — only when map is ongoing -->
    <div class="panel" v-if="mapStatus !== 'created' && mapPlayers.length > 0">
      <div class="panel-title" @click="showPlayers = !showPlayers">
        ⚔️ Players <span class="chevron">{{ showPlayers ? '▲' : '▼' }}</span>
      </div>
      <div class="collapsible" :class="{ collapsed: !showPlayers }">
        <div class="players-list">
          <div v-for="p in mapPlayers" :key="p.user_id" class="player-row">
            <span class="player-icon">{{ p.is_owner ? '👑' : '⚔️' }}</span>
            <span class="player-name">{{ p.name }}</span>
            <span class="online-dot" :class="Date.now() / 1000 - p.last_seen < 30 ? 'online' : 'offline'" :title="Date.now() / 1000 - p.last_seen < 30 ? 'Online' : 'Offline'" />
            <span v-if="p.is_owner" class="player-tag">GM</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Selected hex -->
    <div class="panel" v-if="selectedQ !== null">
      <div class="panel-title">Selected Hex</div>
      <div class="info-row">Coords: {{ selectedQ }}, {{ selectedR }}</div>
      <div class="info-row">Terrain: {{ selectedTerrainLabel }}</div>
    </div>

    <!-- Controls — advanced_player only -->
    <div class="panel" v-if="isAdvancedPlayer && showControls">
      <div class="panel-title">Controls <button class="close-btn" @click="showControls = false">✕</button></div>
      <div class="info-row"><kbd>Click</kbd> Paint terrain</div>
      <div class="info-row"><kbd>Drag</kbd> Paint area</div>
      <div class="info-row"><kbd>Scroll</kbd> Zoom</div>
      <div class="info-row"><kbd>Mid-drag</kbd> Pan</div>
    </div>
    <div class="panel" v-else-if="showControls">
      <div class="panel-title">Controls <button class="close-btn" @click="showControls = false">✕</button></div>
      <div class="info-row"><kbd>Scroll</kbd> Zoom</div>
      <div class="info-row"><kbd>Mid-drag</kbd> Pan</div>
    </div>


  </aside>
</template>
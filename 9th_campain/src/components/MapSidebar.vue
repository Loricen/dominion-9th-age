<script setup lang="ts">
import { ref, computed} from 'vue'
import { TERRAIN_TYPES, TERRAIN_COLOR, TERRAIN_LABEL, type TerrainType, type MapSizeKey, type Hex } from '@/composables/useHexMap'
import type { ProceduralSettings } from '@/composables/useProceduralGen'
import type { MapListItem, MapPlayer, ChatMessage, PlayerSetupWithId } from '@/composables/useMapIO'
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
  allPlayerSetups?: PlayerSetupWithId[]
  hexes?: Hex[]
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
const showTerrain        = ref(true)
const showTerrainBrushes = ref(true)

const terrainBreakdown = computed(() => {
  const hexes = props.hexes ?? []
  if (hexes.length === 0) return []
  const counts: Record<string, number> = {}
  for (const h of hexes) counts[h.terrain] = (counts[h.terrain] ?? 0) + 1
  const total = hexes.length
  return Object.entries(counts)
    .map(([terrain, count]) => ({
      terrain: terrain as TerrainType,
      label: TERRAIN_LABEL[terrain as TerrainType] ?? terrain,
      color: TERRAIN_COLOR[terrain as TerrainType] ?? '#888',
      count,
      pct: (count / total) * 100,
    }))
    .sort((a, b) => b.pct - a.pct)
})
</script>

<template>
  <aside class="sidebar">
    <!-- My Maps -->
    <div class="panel">
      <div class="panel-title" @click="showMaps = !showMaps">
        My Maps <span class="chevron">{{ showMaps ? '▲' : '▼' }}</span>
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

    <!-- Generation Settings -->
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

    <!-- Terrain Brush -->
    <div class="panel" v-if="isAdvancedPlayer">
      <div>
        <div class="panel-title" @click="showTerrainBrushes = !showTerrainBrushes">
          Terrain Brushes <span class="chevron">{{ showTerrainBrushes ? '▲' : '▼' }}</span>
        </div>
        <div class="collapsible" :class="{ collapsed: !showTerrainBrushes }">
          <button
            v-for="t in TERRAIN_TYPES" :key="t"
            class="terrain-btn"
            :class="{ active: activeTerrain === t }"
            :style="{ background: activeTerrain === t ? TERRAIN_COLOR[t] : 'transparent', borderColor: TERRAIN_COLOR[t] }"
            @click="emit('terrainChange', t)"
          >{{ TERRAIN_LABEL[t] }}</button>
        </div>
      </div>
    </div>

    <!-- Players — only when map is awaiting -->
    <div class="panel" v-if="mapStatus !== 'created' && mapPlayers.length > 0">
      <div class="panel-title" @click="showPlayers = !showPlayers">
        ⚔️ Players <span class="chevron">{{ showPlayers ? '▲' : '▼' }}</span>
      </div>
      <div class="collapsible" :class="{ collapsed: !showPlayers }">
        <div class="players-list">
          <div v-for="p in mapPlayers" :key="p.user_id" class="player-row">
            <span class="player-name">{{ p.name }}</span>
            <span
              v-if="(allPlayerSetups ?? []).find(s => s.user_id === p.user_id)?.turn_done"
              class="turn-done-badge"
              title="Turn finished"
            >✓</span>
            <span class="online-dot" :class="Date.now() / 1000 - p.last_seen < 30 ? 'online' : 'offline'" :title="Date.now() / 1000 - p.last_seen < 30 ? 'Online' : 'Offline'" /> 
          </div>
        </div>
      </div>
    </div>

    <!-- Terrain composition — shown while browsing/joining a map, before the game starts -->
    <div class="panel" v-if="mapStatus !== 'started' && terrainBreakdown.length > 0">
      <div class="panel-title" @click="showTerrain = !showTerrain">
        Terrains coverage <span class="chevron">{{ showTerrain ? '▲' : '▼' }}</span>
      </div>
      <div class="collapsible" :class="{ collapsed: !showTerrain }">
        <div v-for="t in terrainBreakdown" :key="t.terrain" class="terrain-row">
          <span class="terrain-swatch" :style="{ background: t.color }" />
          <span class="terrain-name">{{ t.label }}</span>
          <span class="terrain-pct">{{ t.pct.toFixed(1) }}%</span>
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
      <div class="info-row"><kbd>+Drag</kbd> Paint area</div>
      <div class="info-row"><kbd>Scroll</kbd> Zoom</div>
      <div class="info-row"><kbd>Mid-drag</kbd> Move map</div>
    </div>
    <div class="panel" v-else-if="showControls">
      <div class="panel-title">Controls <button class="close-btn" @click="showControls = false">✕</button></div>
      <div class="info-row"><kbd>Scroll</kbd> Zoom</div>
      <div class="info-row"><kbd>Mid-drag</kbd> Move map</div>
    </div>


  </aside>
</template>
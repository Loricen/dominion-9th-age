<script setup lang="ts">
import { ref } from 'vue'
import { TERRAIN_TYPES, TERRAIN_LABEL, TERRAIN_COLOR, type TerrainType } from '@/composables/useHexMap'
import type { ProceduralSettings } from '@/composables/useProceduralGen'
import { MAP_SIZES, type MapSizeKey } from '@/composables/useHexMap'

const props = defineProps<{
  selectedSize: MapSizeKey
  settings: ProceduralSettings
  activeTerrain: TerrainType
  isAdvancedPlayer: boolean
}>()

const emit = defineEmits<{
  generate: []
  reset: []
  sizeChange: [size: MapSizeKey]
  terrainChange: [t: TerrainType]
  'update:settings': [s: ProceduralSettings]
}>()
const showProcedural = ref(true)
const showTerrain = ref(true)

function updateInfluence(v: string) {
  emit('update:settings', { ...props.settings, neighborInfluence: parseFloat(v) })
}

function updatePasses(v: string) {
  emit('update:settings', { ...props.settings, smoothingPasses: parseInt(v) })
}

function updateBorderWater(v: string) {
  emit('update:settings', { ...props.settings, borderWaterSize: parseFloat(v) })
}

function updateWeight(terrain: string, v: string) {
  emit('update:settings', {
    ...props.settings,
    terrainWeights: { ...props.settings.terrainWeights, [terrain]: parseInt(v) }
  })
}
</script>

<template>
  <div class="panel-title" @click="showProcedural = !showProcedural">
    ⚙️ Generation Settings
    <span class="chevron">{{ showProcedural ? '▲' : '▼' }}</span>
  </div>
  <div class="proc-panel collapsible" v-bind:class = "!showProcedural?'collapsed':''">
    <!-- Border water -->
    <div class="setting-row">
      <div class="setting-label">
        <span>Ocean Border</span>
        <span class="setting-val">{{ Math.round(settings.borderWaterSize * 100) }}%</span>
      </div>
      <input
        type="range" min="0" max="1" step="0.05"
        :value="settings.borderWaterSize"
        @input="updateBorderWater(($event.target as HTMLInputElement).value)"
        class="slider ocean-slider"
      />
      <div class="setting-hint">How much of the map edge fades to ocean</div>
    </div>

    <!-- Neighbor influence -->
    <div class="setting-row">
      <div class="setting-label">
        <span>Neighbor Influence</span>
        <span class="setting-val">{{ Math.round(settings.neighborInfluence * 100) }}%</span>
      </div>
      <input
        type="range" min="0" max="1" step="0.05"
        :value="settings.neighborInfluence"
        @input="updateInfluence(($event.target as HTMLInputElement).value)"
        class="slider"
      />
      <div class="setting-hint">Higher = larger terrain regions</div>
    </div>

    <!-- Smoothing passes -->
    <div class="setting-row">
      <div class="setting-label">
        <span>Smoothing Passes</span>
        <span class="setting-val">{{ settings.smoothingPasses }}</span>
      </div>
      <input
        type="range" min="0" max="6" step="1"
        :value="settings.smoothingPasses"
        @input="updatePasses(($event.target as HTMLInputElement).value)"
        class="slider"
      />
      <div class="setting-hint">More passes = smoother terrain blobs</div>
    </div>

    <!-- Terrain weights -->
    <div class="setting-row">
      <div class="setting-label"><span>Terrain Frequency</span></div>
      <div class="weights-list">
        <div v-for="t in TERRAIN_TYPES.filter(t => t !== 'water')" :key="t" class="weight-row">
          <span class="weight-icon">{{ TERRAIN_LABEL[t].split(' ')[0] }}</span>
          <span class="weight-name">{{ TERRAIN_LABEL[t].split(' ')[1] }}</span>
          <input
            type="range" min="0" max="60" step="1"
            :value="settings.terrainWeights[t]"
            @input="updateWeight(t, ($event.target as HTMLInputElement).value)"
            class="slider weight-slider"
            :style="{ '--tc': TERRAIN_COLOR[t] }"
          />
          <span class="weight-num">{{ settings.terrainWeights[t] }}</span>
        </div>
      </div>
      <div class="setting-hint">Inland water is controlled by Ocean Border above</div>
    </div>

    <!-- Actions -->
    <div class="proc-actions">
      <button class="btn-generate" @click="emit('generate')">🎲 Generate</button>
      <button class="btn-reset-s" @click="emit('reset')">↺ Reset</button>
    </div>
    <select
      class="size-select"
      :value="selectedSize"
      @change="emit('sizeChange', ($event.target as HTMLSelectElement).value as MapSizeKey)"
    >
      <option v-for="(s, key) in MAP_SIZES" :key="key" :value="key">{{ s.label }}</option>
    </select>
    
    <!-- Terrain Brush — advanced_player only -->
    <div class="panel" v-if="isAdvancedPlayer">
      <div class="collapsible" :class="{ collapsed: !showTerrain }">
        <div class="panel-title" @click="showTerrain = !showTerrain">
          🖌️ Terrain Brush <span class="chevron">{{ showTerrain ? '▲' : '▼' }}</span>
        </div>
        <div v-if="showTerrain">
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
  </div>
</template>
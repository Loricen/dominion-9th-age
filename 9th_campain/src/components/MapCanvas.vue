<script setup lang="ts">
import { type Hex, TERRAIN_COLOR, TERRAIN_BORDER, hexCenter, hexPoints, getSvgW, getSvgH } from '@/composables/useHexMap'
import type { PlayerSetupWithId, OwnedTile } from '@/composables/useMapIO'

const props = defineProps<{
  hexes: Hex[]
  selectedQ: number | null
  selectedR: number | null
  cols: number
  rows: number
  zoom: number
  panX: number
  panY: number
  selectedBorderColor?: string
  playerSetups?: PlayerSetupWithId[]
  ownedTiles?: OwnedTile[]
  claimingMode?: boolean
}>()

const emit = defineEmits<{
  clickHex: [payload: { q: number; r: number }]
  hoverHex: [payload: { e: MouseEvent; q: number; r: number }]
  wheel: [e: WheelEvent]
  mousedown: [e: MouseEvent]
}>()

function isSelected(q: number, r: number): boolean {
  return props.selectedQ === q && props.selectedR === r
}

function tileOwnerColor(q: number, r: number): string | null {
  // City hex
  const citySetup = (props.playerSetups ?? []).find(s => s.city_q === q && s.city_r === r)
  if (citySetup) return citySetup.color
  // Owned tile
  const owned = (props.ownedTiles ?? []).find(t => t.q === q && t.r === r)
  if (owned) {
    const setup = (props.playerSetups ?? []).find(s => s.user_id === owned.user_id)
    return setup?.color ?? null
  }
  return null
}
</script>

<template>
  <div
    class="map-container"
    @wheel.prevent="emit('wheel', $event)"
    @mousedown="emit('mousedown', $event)"
    @contextmenu.prevent
  >
    <div
      class="map-inner"
      :style="{
        transform: `translate(${panX}px, ${panY}px) scale(${zoom})`,
        transformOrigin: '0 0'
      }"
    >
      <svg v-if="hexes.length > 0" :width="getSvgW(cols)" :height="getSvgH(rows)">
        <g v-for="hex in hexes" :key="`${hex.q}-${hex.r}`">
          <polygon
            :points="hexPoints(...hexCenter(hex.q, hex.r))"
            :fill="TERRAIN_COLOR[hex.terrain]"
            :stroke="isSelected(hex.q, hex.r) ? selectedBorderColor : (tileOwnerColor(hex.q, hex.r) ?? (claimingMode ? '#ffffff33' : TERRAIN_BORDER[hex.terrain]))"
            :stroke-width="isSelected(hex.q, hex.r) ? 2 : (tileOwnerColor(hex.q, hex.r) ? 2 : 0.8)"
            :class="['hex-cell', claimingMode && !tileOwnerColor(hex.q, hex.r) && hex.terrain !== 'water' ? 'hex-claimable' : claimingMode && hex.terrain === 'water' ? 'hex-no-claim' : '']"
            @click="emit('clickHex', { q: hex.q, r: hex.r })"
            @mouseover="(e: MouseEvent) => emit('hoverHex', { e, q: hex.q, r: hex.r })"
          />
        </g>
        <!-- Player city markers -->
        <g v-for="setup in (playerSetups ?? [])" :key="`city-${setup.user_id}`">
          <template v-if="setup.city_q != null && setup.city_r != null">
            <text
              :x="hexCenter(setup.city_q, setup.city_r)[0]"
              :y="hexCenter(setup.city_q, setup.city_r)[1] + 1"
              text-anchor="middle"
              dominant-baseline="middle"
              font-size="12"
              :fill="setup.color"
              style="pointer-events: none; user-select: none"
            >🏰</text>
          </template>
        </g>
      </svg>
      <div v-else class="loading">Generating map...</div>
    </div>
  </div>
</template>
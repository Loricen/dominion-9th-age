<script setup lang="ts">
import { type Hex, TERRAIN_COLOR, TERRAIN_BORDER, hexCenter, hexPoints, getSvgW, getSvgH } from '@/composables/useHexMap'
import type { PlayerSetupWithId } from '@/composables/useMapIO'

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

function cityColor(q: number, r: number): string | null {
  const setup = (props.playerSetups ?? []).find(s => s.city_q === q && s.city_r === r)
  return setup?.color ?? null
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
            :stroke="isSelected(hex.q, hex.r) ? selectedBorderColor : (cityColor(hex.q, hex.r) ?? TERRAIN_BORDER[hex.terrain])"
            :stroke-width="isSelected(hex.q, hex.r) ? 2 : (cityColor(hex.q, hex.r) ? 2 : 0.8)"
            class="hex-cell"
            @click="emit('clickHex', { q: hex.q, r: hex.r })"
            @mouseover="(e: MouseEvent) => emit('hoverHex', { e, q: hex.q, r: hex.r })"
          />
        </g>
        <!-- Player city markers -->
        <g v-for="setup in (playerSetups ?? [])" :key="`city-${setup.user_id}`">
          <template v-if="setup.city_q != null && setup.city_r != null">
            <polygon
              :points="hexPoints(...hexCenter(setup.city_q, setup.city_r))"
              :fill="'rgba(0,0,0,0)'"
              :stroke="setup.color"
              :stroke-width="isSelected(setup.city_q, setup.city_r) ? 2 : (cityColor(setup.city_q, setup.city_r) ? 2 : 0.8)"
              class="hex-cell"
              @click="emit('clickHex', { q: setup.city_q, r: setup.city_r })"
              @mouseover="(e: MouseEvent) => emit('hoverHex', { e, q: setup.city_q, r: setup.city_r })"
            />
            <text
              :x="hexCenter(setup.city_q, setup.city_r)[0]"
              :y="hexCenter(setup.city_q, setup.city_r)[1] + 1"
              text-anchor="middle"
              dominant-baseline="middle"
              font-size="10"
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
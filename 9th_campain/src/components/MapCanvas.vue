<script setup lang="ts">
import { computed } from 'vue'
import { type Hex, TERRAIN_COLOR, TERRAIN_BORDER, hexCenter, hexPoints, getSvgW, getSvgH } from '@/composables/useHexMap'
import type { PlayerSetupWithId, OwnedTile, Army } from '@/composables/useMapIO'

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
  armies?: Army[]
  claimingMode?: boolean
  selectedArmyId?: number | null
  movingMode?: boolean
}>()

const emit = defineEmits<{
  clickHex: [payload: { q: number; r: number }]
  clickArmy: [payload: { armyId: number }]
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

// Compute the set of valid move destinations when an army is selected
const validMoveTiles = computed<Set<string>>(() => {
  const result = new Set<string>()
  if (!props.movingMode || props.selectedArmyId == null) return result

  const army = (props.armies ?? []).find(a => a.id === props.selectedArmyId)
  if (!army) return result

  const q = army.q
  const r = army.r
  const colParity = q % 2
  const offsets = colParity === 0
    ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
    : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]]

  // Collect enemy army positions so we can block those tiles
  const enemyArmyTiles = new Set<string>()
  const selectedArmy = army
  for (const a of (props.armies ?? [])) {
    if (a.user_id !== selectedArmy.user_id) {
      enemyArmyTiles.add(`${a.q},${a.r}`)
    }
  }

  for (const [dq, dr] of offsets) {
    const nq = q + dq
    const nr = r + dr
    const hex = (props.hexes ?? []).find(h => h.q === nq && h.r === nr)
    if (!hex || hex.terrain === 'water') continue
    if (enemyArmyTiles.has(`${nq},${nr}`)) continue
    result.add(`${nq},${nr}`)
  }
  return result
})

function isValidMoveTile(q: number, r: number): boolean {
  return validMoveTiles.value.has(`${q},${r}`)
}

function hexStroke(hex: Hex): string {
  if (isSelected(hex.q, hex.r)) return props.selectedBorderColor ?? '#FFD700'
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return '#00e5ff'
  const ownerColor = tileOwnerColor(hex.q, hex.r)
  if (ownerColor) return ownerColor
  if (props.claimingMode) return '#ffffff33'
  return TERRAIN_BORDER[hex.terrain]
}

function hexStrokeWidth(hex: Hex): number {
  if (isSelected(hex.q, hex.r)) return 2
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return 2
  if (tileOwnerColor(hex.q, hex.r)) return 2
  return 0.8
}

function hexClass(hex: Hex): string {
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return 'hex-move-target'
  if (props.claimingMode && !tileOwnerColor(hex.q, hex.r) && hex.terrain !== 'water') return 'hex-claimable'
  if (props.claimingMode && hex.terrain === 'water') return 'hex-no-claim'
  return ''
}

function armyColor(army: Army): string {
  return (props.playerSetups ?? []).find(s => s.user_id === army.user_id)?.color ?? '#ffffff'
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
            :stroke="hexStroke(hex)"
            :stroke-width="hexStrokeWidth(hex)"
            :class="['hex-cell', hexClass(hex)]"
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

        <!-- Army markers -->
        <g v-for="army in (armies ?? [])" :key="army.id">
          <template v-if="army.q != null && army.r != null">
            <!-- Selection ring (larger, behind) -->
            <circle
              v-if="selectedArmyId === army.id"
              :cx="hexCenter(army.q, army.r)[0] + 4"
              :cy="hexCenter(army.q, army.r)[1] - 3"
              r="7"
              fill="none"
              stroke="#00e5ff"
              stroke-width="1.5"
              style="pointer-events: none"
            />
            <!-- Army icon -->
            <text
              :x="hexCenter(army.q, army.r)[0] + 4"
              :y="hexCenter(army.q, army.r)[1] - 3"
              text-anchor="middle"
              dominant-baseline="middle"
              font-size="6"
              style="user-select: none; cursor: pointer"
              @click.stop="emit('clickArmy', { armyId: army.id })"
            >⚔️</text>
            <!-- Player colour ring -->
            <circle
              :cx="hexCenter(army.q, army.r)[0] + 4"
              :cy="hexCenter(army.q, army.r)[1] - 3"
              r="4"
              fill="none"
              :stroke="armyColor(army)"
              stroke-width="1"
              style="pointer-events: none"
            />
          </template>
        </g>
      </svg>
      <div v-else class="loading">Generating map...</div>
    </div>
  </div>
</template>
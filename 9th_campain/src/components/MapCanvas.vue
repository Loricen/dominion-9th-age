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
  buyingArmyMode?: boolean
  currentUserId?: number | null
  backVis ?: string | null
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
  const citySetup = (props.playerSetups ?? []).find(s => s.city_q === q && s.city_r === r)
  if (citySetup) return citySetup.color
  const owned = (props.ownedTiles ?? []).find(t => t.q === q && t.r === r)
  if (owned) {
    const setup = (props.playerSetups ?? []).find(s => s.user_id === owned.user_id)
    return setup?.color ?? null
  }
  return null
}

// Compute the set of valid move destinations when an army is selected
// enemyArmyPositions: enemy armies that sit on an adjacent valid move tile (battle targets, shown red)
// validMoveTiles: all adjacent non-water tiles (including battle targets)
const enemyArmyPositions = computed<Set<string>>(() => {
  const result = new Set<string>()
  if (!props.movingMode || props.selectedArmyId == null) return result
  const army = (props.armies ?? []).find(a => a.id === props.selectedArmyId)
  if (!army) return result
  // Only flag if the enemy is on a tile adjacent to the selected army
  const colParity = army.q % 2
  const offsets: [number, number][] = colParity === 0
    ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
    : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]]
  const adjacent = new Set(offsets.map(([dq, dr]) => `${army.q + dq},${army.r + dr}`))
  for (const a of (props.armies ?? [])) {
    if (a.user_id !== army.user_id && adjacent.has(`${a.q},${a.r}`)) {
      result.add(`${a.q},${a.r}`)
    }
  }
  return result
})

const validMoveTiles = computed<Set<string>>(() => {
  const result = new Set<string>()
  if (!props.movingMode || props.selectedArmyId == null) return result

  const army = (props.armies ?? []).find(a => a.id === props.selectedArmyId)
  if (!army) return result

  const q = army.q
  const r = army.r
  const colParity = q % 2
  const offsets: [number, number][] = colParity === 0
    ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
    : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]]

  for (const [dq, dr] of offsets) {
    const nq = q + dq
    const nr = r + dr
    const hex = (props.hexes ?? []).find(h => h.q === nq && h.r === nr)
    if (!hex || hex.terrain === 'water') continue
    result.add(`${nq},${nr}`)
  }
  return result
})

function isValidMoveTile(q: number, r: number): boolean {
  return validMoveTiles.value.has(`${q},${r}`)
}

function isEnemyArmyTile(q: number, r: number): boolean {
  return enemyArmyPositions.value.has(`${q},${r}`)
}

// Tiles where the current player can build an army (their own cities)
const cityTiles = computed<Set<string>>(() => {
  const result = new Set<string>()
  if (!props.buyingArmyMode || props.currentUserId == null) return result
  for (const s of (props.playerSetups ?? [])) {
    if (s.user_id === props.currentUserId && s.city_q != null && s.city_r != null) {
      result.add(`${s.city_q},${s.city_r}`)
    }
  }
  return result
})

function isCityTile(q: number, r: number): boolean {
  return cityTiles.value.has(`${q},${r}`)
}

function hexStroke(hex: Hex): string {
  if (isSelected(hex.q, hex.r)) return props.selectedBorderColor ?? '#FFD700'
  if (props.movingMode && isEnemyArmyTile(hex.q, hex.r)) return '#ff3333'
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return '#00e5ff'
  if (props.buyingArmyMode && isCityTile(hex.q, hex.r)) return '#FFD700'
  const ownerColor = tileOwnerColor(hex.q, hex.r)
  if (ownerColor) return ownerColor
  if (props.claimingMode) return '#ffffff33'
  return TERRAIN_BORDER[hex.terrain]
}

function hexStrokeWidth(hex: Hex): number {
  if (isSelected(hex.q, hex.r)) return 2
  if (props.movingMode && isEnemyArmyTile(hex.q, hex.r)) return 2.5
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return 2
  if (props.buyingArmyMode && isCityTile(hex.q, hex.r)) return 2.5
  if (tileOwnerColor(hex.q, hex.r)) return 2
  return 0.8
}

function hexClass(hex: Hex): string {
  if (props.movingMode && isEnemyArmyTile(hex.q, hex.r)) return 'hex-battle-target'
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return 'hex-move-target'
  if (props.buyingArmyMode && isCityTile(hex.q, hex.r)) return 'hex-city-target'
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
      <svg :style="`backface-visibility: ${backVis ?? 'visible'}`" v-if="hexes.length > 0" :width="getSvgW(cols)" :height="getSvgH(rows)" >
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

        <!-- Buy-army glow rings around own cities -->
        <g v-if="buyingArmyMode">
          <template v-for="setup in (playerSetups ?? [])" :key="`glow-${setup.user_id}`">
            <template v-if="setup.city_q != null && setup.city_r != null && setup.user_id === currentUserId">
              <circle
                :cx="hexCenter(setup.city_q, setup.city_r)[0]"
                :cy="hexCenter(setup.city_q, setup.city_r)[1]"
                r="11"
                fill="none"
                stroke="#FFD700"
                stroke-width="2"
                opacity="0.5"
                class="city-glow-ring city-glow-ring--outer"
                style="pointer-events: none"
              />
              <circle
                :cx="hexCenter(setup.city_q, setup.city_r)[0]"
                :cy="hexCenter(setup.city_q, setup.city_r)[1]"
                r="7"
                fill="#FFD70022"
                stroke="#FFD700"
                stroke-width="1.5"
                class="city-glow-ring"
                style="pointer-events: none"
              />
            </template>
          </template>
        </g>

        <!-- Player city markers -->
        <g v-for="setup in (playerSetups ?? [])" :key="`city-${setup.user_id}`">
          <template v-if="setup.city_q != null && setup.city_r != null">
            <image
              :x="hexCenter(setup.city_q, setup.city_r)[0] - 8.5"
              :y="hexCenter(setup.city_q, setup.city_r)[1] - 6.5"
              width="17"
              height="17"
              :href="`/src/assets/img/town-${setup.randomUserId}.svg`"
              style="pointer-events: none" />
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
            <image
              :x="hexCenter(army.q, army.r)[0]-1"
              :y="hexCenter(army.q, army.r)[1]-7"
              width="10"
              height="10"
              :href="`/src/assets/img/army-1.svg`"
              style="user-select: none; cursor: pointer"
              @click.stop="emit('clickArmy', { armyId: army.id })" /> <!-- ${army.armyIcon} -->
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
      <div v-else class="loading">Loading...</div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { computed } from 'vue'
import { type Hex, TERRAIN_COLOR, TERRAIN_BORDER, HEX_SIZE, hexCenter, hexPoints, getSvgW, getSvgH } from '@/composables/useHexMap'
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

// ── Terrain icons ────────────────────────────────────────────────────────
const TERRAIN_ICON: Record<string, string> = {
  plains:   'HillsGreen',
  desert:   'Desert',
  swamp:    'Swamp',
  mountain: 'Mountains',
  forest:   'Woods',
  water:    'Water',
}

// Seeded pseudo-random so icons are stable per tile
function seededRand(seed: number): () => number {
  let s = seed
  return () => {
    s = (s * 1664525 + 1013904223) & 0xffffffff
    return (s >>> 0) / 0xffffffff
  }
}

interface TerrainIcon { x: number; y: number; size: number; opacity: number }

function terrainIcons(q: number, r: number, terrain: string): TerrainIcon[] {
  const file = TERRAIN_ICON[terrain]
  if (!file) return []

  const rand = seededRand(q * 73856093 ^ r * 19349663)

  // Weighted count: 0→10%, 1→55%, 2→25%, 3→10%
  const roll = rand()
  const count = roll < 0.10 ? 0 : roll < 0.65 ? 1 : roll < 0.90 ? 2 : 3
  if (count === 0) return []

  const [cx, cy] = hexCenter(q, r)
  // Spread area: keep icons inside the hex (radius ~8px from center)
  const icons: TerrainIcon[] = []
  const placed: Array<[number, number]> = []
  for (let i = 0; i < count; i++) {
    let x = 0, y = 0, tries = 0
    do {
      const angle = rand() * Math.PI * 2
      const dist  = rand() * 7
      x = cx + Math.cos(angle) * dist
      y = cy + Math.sin(angle) * dist
      tries++
    } while (tries < 10 && placed.some(([px, py]) => Math.hypot(x - px, y - py) < 4))
    placed.push([x, y])
    const size    = 10 + rand() * 8   // 10–18px
    const opacity = 1
    icons.push({ x: x - size / 2, y: y - size / 2, size, opacity })
  }
  return icons
}

function hexStroke(hex: Hex): string {
  if (isSelected(hex.q, hex.r)) return props.selectedBorderColor ?? '#FFD700'
  if (props.movingMode && isEnemyArmyTile(hex.q, hex.r)) return '#ff3333'
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return '#00e5ff'
  if (props.buyingArmyMode && isCityTile(hex.q, hex.r)) return '#FFD700'
  if (props.claimingMode) return '#ffffff33'
  return TERRAIN_BORDER[hex.terrain]
}

function hexStrokeWidth(hex: Hex): number {
  if (isSelected(hex.q, hex.r)) return 2
  if (props.movingMode && isEnemyArmyTile(hex.q, hex.r)) return 2.5
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return 2
  if (props.buyingArmyMode && isCityTile(hex.q, hex.r)) return 2.5
  return 0.8
}

// ── Territory border edges ───────────────────────────────────────────────
// For a flat-top hex, vertex i is at angle (60°*i - 30°), i.e. 30°,90°,150°,210°,270°,330°
// Edge i connects vertex i and vertex (i+1)%6
// Neighbour offsets for each edge (flat-top, offset cols) — even col / odd col
const EDGE_NEIGHBORS_EVEN: [number, number][] = [
  [1, -1], [1, 0], [0, 1], [-1, 0], [-1, -1], [0, -1]
]
const EDGE_NEIGHBORS_ODD: [number, number][] = [
  [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [0, -1]
]

interface BorderEdge { x1: number; y1: number; x2: number; y2: number; color: string }

function hexVertices(cx: number, cy: number): Array<[number, number]> {
  const verts: Array<[number, number]> = []
  for (let i = 0; i < 6; i++) {
    const a = (Math.PI / 180) * (60 * i - 60)
    verts.push([cx + (HEX_SIZE - 1) * Math.cos(a), cy + (HEX_SIZE - 1) * Math.sin(a)])
  }
  return verts
}

function territoryBorderEdges(hex: Hex): BorderEdge[] {
  const ownerColor = tileOwnerColor(hex.q, hex.r)
  if (!ownerColor) return []

  const ownerUserId = (() => {
    const citySetup = (props.playerSetups ?? []).find(s => s.city_q === hex.q && s.city_r === hex.r)
    if (citySetup) return citySetup.user_id
    return (props.ownedTiles ?? []).find(t => t.q === hex.q && t.r === hex.r)?.user_id ?? null
  })()
  if (ownerUserId === null) return []

  const offsets = hex.q % 2 === 0 ? EDGE_NEIGHBORS_EVEN : EDGE_NEIGHBORS_ODD
  const [cx, cy] = hexCenter(hex.q, hex.r)
  const verts = hexVertices(cx, cy)
  const edges: BorderEdge[] = []

  for (let i = 0; i < 6; i++) {
    const [dq, dr] = offsets[i]!
    const nq = hex.q + dq
    const nr = hex.r + dr
    // Check if neighbor is owned by the same player
    const neighborOwner = (() => {
      const citySetup = (props.playerSetups ?? []).find(s => s.city_q === nq && s.city_r === nr)
      if (citySetup) return citySetup.user_id
      return (props.ownedTiles ?? []).find(t => t.q === nq && t.r === nr)?.user_id ?? null
    })()
    if (neighborOwner !== ownerUserId) {
      const [x1, y1] = verts[i]!
      const [x2, y2] = verts[(i + 1) % 6]!
      edges.push({ x1, y1, x2, y2, color: ownerColor })
    }
  }
  return edges
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
      <svg :style="`backface-visibility: ${backVis ?? 'visible'}`" v-if="hexes.length > 0" :width="getSvgW(cols)" :height="getSvgH(rows)">
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
          <!-- Terrain decoration icons — sorted by y so lower icons render on top -->
          <image
            :key="`ti-${hex.q}-${hex.r}`"
            :x="hexCenter(hex.q, hex.r)[0]-13"
            :y="hexCenter(hex.q, hex.r)[1]-12.5"
            height= "25"
            width="26"
            :href="`/src/assets/img/tiles/${TERRAIN_ICON[hex.terrain]}${ Math.floor(Math.random() * 4) + 1 }.png`"
          />
          <!-- Territory border edges -->
          <line
            v-for="(edge, ei) in territoryBorderEdges(hex)"
            :key="`be-${hex.q}-${hex.r}-${ei}`"
            :x1="edge.x1" :y1="edge.y1"
            :x2="edge.x2" :y2="edge.y2"
            :stroke="edge.color"
            stroke-width="2"
            stroke-linecap="round"
            style="pointer-events: none"
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
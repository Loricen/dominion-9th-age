<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { type Hex, TERRAIN_COLOR, TERRAIN_BORDER, HEX_SIZE, hexCenter, hexPoints, getSvgW, getSvgH } from '@/composables/useHexMap'
import type { PlayerSetupWithId, OwnedTile, Army, Building } from '@/composables/useMapIO'

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
  buildings?: Building[]
  claimingMode?: boolean
  selectedArmyId?: number | null
  movingMode?: boolean
  buyingArmyMode?: boolean
  buyingSpyMode?: boolean
  buyingSettlerMode?: boolean
  buyingFarmerMode?: boolean
  currentUserId?: number | null
  backVis ?: string | null
  mapStatus?: string
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

// Memoized ownership lookup — O(1) per tile instead of scanning playerSetups/ownedTiles
// arrays on every call. Rebuilt only when playerSetups or ownedTiles actually change.
const ownerIdMap = computed<Map<string, number>>(() => {
  const map = new Map<string, number>()
  for (const t of (props.ownedTiles ?? [])) map.set(`${t.q},${t.r}`, t.user_id)
  // Buildings (cities) always win, even if not separately listed in ownedTiles
  for (const b of (props.buildings ?? [])) {
    if (b.q != null && b.r != null) map.set(`${b.q},${b.r}`, b.user_id)
  }
  return map
})

const ownerColorMap = computed<Map<number, string>>(() => {
  const map = new Map<number, string>()
  for (const s of (props.playerSetups ?? [])) map.set(s.user_id, s.color)
  return map
})

function tileOwnerId(q: number, r: number): number | null {
  return ownerIdMap.value.get(`${q},${r}`) ?? null
}

function tileOwnerColor(q: number, r: number): string | null {
  const ownerId = tileOwnerId(q, r)
  if (ownerId === null) return null
  return ownerColorMap.value.get(ownerId) ?? null
}

// ── Fog of war ──────────────────────────────────────────────────────────
// Offset (odd-q, flat-top) → cube coordinates, for correct hex distance
function offsetToCube(q: number, r: number): [number, number, number] {
  const x = q
  const z = r - (q - (q & 1)) / 2
  const y = -x - z
  return [x, y, z]
}

function hexDistance(q1: number, r1: number, q2: number, r2: number): number {
  const [x1, y1, z1] = offsetToCube(q1, r1)
  const [x2, y2, z2] = offsetToCube(q2, r2)
  return Math.max(Math.abs(x1 - x2), Math.abs(y1 - y2), Math.abs(z1 - z2))
}

const FOG_RADIUS       = 5   // tiles within this range of your territory/city are fully visible
const PNG_LOAD_RADIUS  = 10  // tiles beyond this range never load their texture, even if fogged
const SPY_SIGHT_RADIUS = 7   // spies see (and reveal fog) further than plain territory

interface VisionSource { q: number; r: number; radius: number }

// Tiles/units owned by the current player, each with its own vision radius
// (spies see further than plain territory or a city)
const myOwnedTileList = computed<VisionSource[]>(() => {
  if (props.currentUserId == null) return []
  const list: VisionSource[] = []
  for (const b of (props.buildings ?? [])) {
    // Only cities grant territorial vision — a farm is just a production site
    if (b.user_id === props.currentUserId && b.building_type !== 'farm' && b.q != null && b.r != null) {
      list.push({ q: b.q, r: b.r, radius: FOG_RADIUS })
    }
  }
  for (const t of (props.ownedTiles ?? [])) {
    if (t.user_id === props.currentUserId) list.push({ q: t.q, r: t.r, radius: FOG_RADIUS })
  }
  // Spies grant a larger vision radius than territory
  for (const a of (props.armies ?? [])) {
    if (a.user_id === props.currentUserId && a.unit_type === 'spy' && a.q != null && a.r != null) {
      list.push({ q: a.q, r: a.r, radius: SPY_SIGHT_RADIUS })
    }
  }
  return list
})

// Positions of the current player's own spies — the ONLY source of enemy
// unit detection. Owning territory, an army, or a city is not enough on
// its own; enemy units are only spotted within range of a friendly spy.
const mySpyPositions = computed<Array<{ q: number; r: number }>>(() => {
  if (props.currentUserId == null) return []
  const list: Array<{ q: number; r: number }> = []
  for (const a of (props.armies ?? [])) {
    if (a.user_id === props.currentUserId && a.unit_type === 'spy' && a.q != null && a.r != null) {
      list.push({ q: a.q, r: a.r })
    }
  }
  return list
})

function canSeeEnemyUnit(q: number, r: number): boolean {
  for (const t of mySpyPositions.value) {
    if (hexDistance(q, r, t.q, t.r) <= SPY_SIGHT_RADIUS) return true
  }
  return false
}

// Visibility rule for a unit marker:
// - your own units are always visible
// - any enemy unit (army, spy or settler) is only spotted within SPY_SIGHT_RADIUS
//   of one of your own spies — general territory/fog vision is not enough
function isUnitVisible(unit: Army): boolean {
  if (unit.q == null || unit.r == null) return false
  if (unit.user_id === props.currentUserId) return true
  return canSeeEnemyUnit(unit.q, unit.r)
}

// Memoized per-tile fog state: computed ONCE per hexes/ownedTiles/playerSetups change,
// instead of recalculating distance-to-territory on every template evaluation.
interface FogState { fogged: boolean; loadTexture: boolean }
const fogMap = computed<Map<string, FogState>>(() => {
  const map = new Map<string, FogState>()
  if (props.currentUserId == null || props.mapStatus !== 'started') {
    // No fog when there's no logged-in player context (e.g. map creation mode)
    for (const h of props.hexes) map.set(`${h.q},${h.r}`, { fogged: false, loadTexture: true })
    return map
  }

  const mine = myOwnedTileList.value
  for (const h of props.hexes) {
    let visible = false
    let nearest  = Infinity
    for (const t of mine) {
      const d = hexDistance(h.q, h.r, t.q, t.r)
      if (d < nearest) nearest = d
      if (d <= t.radius) visible = true
      if (visible && nearest === 0) break
    }
    map.set(`${h.q},${h.r}`, {
      fogged: !visible,
      loadTexture: nearest <= PNG_LOAD_RADIUS,
    })
  }
  return map
})

function isFogged(q: number, r: number): boolean {
  if (props.mapStatus !== 'started') return false
  return fogMap.value.get(`${q},${r}`)?.fogged ?? false
}

function shouldLoadTexture(q: number, r: number): boolean {
  return fogMap.value.get(`${q},${r}`)?.loadTexture ?? true
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

// Tiles where the current player can build a unit (any of their own cities)
const cityTiles = computed<Set<string>>(() => {
  const result = new Set<string>()
  if ((!props.buyingArmyMode && !props.buyingSpyMode && !props.buyingSettlerMode && !props.buyingFarmerMode) || props.currentUserId == null) return result
  for (const b of (props.buildings ?? [])) {
    if (b.user_id === props.currentUserId && b.building_type !== 'farm' && b.q != null && b.r != null) {
      result.add(`${b.q},${b.r}`)
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

// Preload all tile PNGs into the browser cache on mount
// so SVG <image> elements never trigger a network fetch during zoom/pan
onMounted(() => {
  const toPreload: string[] = []
  for (const name of Object.values(TERRAIN_ICON)) {
    for (let i = 1; i <= 4; i++) {
      toPreload.push(`/src/assets/img/tiles/${name}${i}.png`)
    }
  }
  toPreload.forEach(src => { const img = new Image(); img.src = src })
})

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
  if ((props.buyingArmyMode || props.buyingSpyMode || props.buyingSettlerMode || props.buyingFarmerMode) && isCityTile(hex.q, hex.r)) return '#FFD700'
  if (props.claimingMode) return '#ffffff33'
  return TERRAIN_BORDER[hex.terrain]
}

function hexStrokeWidth(hex: Hex): number {
  if (isSelected(hex.q, hex.r)) return 2
  if (props.movingMode && isEnemyArmyTile(hex.q, hex.r)) return 2.5
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return 2
  if ((props.buyingArmyMode || props.buyingSpyMode || props.buyingSettlerMode || props.buyingFarmerMode) && isCityTile(hex.q, hex.r)) return 2.5
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

const EMPTY_EDGES: BorderEdge[] = []

// Memoized: computed ONCE for the whole map instead of allocating a new
// array for every hex on every single render (this was the main source of
// the out-of-memory crash — v-for called this directly in the template).
const borderEdgesMap = computed<Map<string, BorderEdge[]>>(() => {
  const map = new Map<string, BorderEdge[]>()
  for (const hex of props.hexes) {
    const ownerUserId = tileOwnerId(hex.q, hex.r)
    if (ownerUserId === null) continue
    const ownerColor = ownerColorMap.value.get(ownerUserId)
    if (!ownerColor) continue

    const offsets = hex.q % 2 === 0 ? EDGE_NEIGHBORS_EVEN : EDGE_NEIGHBORS_ODD
    const [cx, cy] = hexCenter(hex.q, hex.r)
    const verts = hexVertices(cx, cy)
    const edges: BorderEdge[] = []

    for (let i = 0; i < 6; i++) {
      const [dq, dr] = offsets[i]!
      const nq = hex.q + dq
      const nr = hex.r + dr
      if (tileOwnerId(nq, nr) !== ownerUserId) {
        const [x1, y1] = verts[i]!
        const [x2, y2] = verts[(i + 1) % 6]!
        edges.push({ x1, y1, x2, y2, color: ownerColor })
      }
    }
    if (edges.length > 0) map.set(`${hex.q},${hex.r}`, edges)
  }
  return map
})

function territoryBorderEdges(hex: Hex): BorderEdge[] {
  return borderEdgesMap.value.get(`${hex.q},${hex.r}`) ?? EMPTY_EDGES
}

function hexClass(hex: Hex): string {
  if (props.movingMode && isEnemyArmyTile(hex.q, hex.r)) return 'hex-battle-target'
  if (props.movingMode && isValidMoveTile(hex.q, hex.r)) return 'hex-move-target'
  if ((props.buyingArmyMode || props.buyingSpyMode || props.buyingSettlerMode || props.buyingFarmerMode) && isCityTile(hex.q, hex.r)) return 'hex-city-target'
  if (props.claimingMode && !tileOwnerColor(hex.q, hex.r) && hex.terrain !== 'water') return 'hex-claimable'
  if (props.claimingMode && hex.terrain === 'water') return 'hex-no-claim'
  return ''
}

function UnitColor(e: Army): string {
  return (props.playerSetups ?? []).find(s => s.user_id === e.user_id)?.color ?? '#ffffff'
}
function buildingColor(e: Building): string {
  return (props.playerSetups ?? []).find(s => s.user_id === e.user_id)?.color ?? '#ffffff'
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
            :fill="isFogged(hex.q, hex.r) ? '#05070c' : TERRAIN_COLOR[hex.terrain]"
            :stroke="hexStroke(hex)"
            :stroke-width="hexStrokeWidth(hex)"
            :class="['hex-cell', hexClass(hex), { 'hex-fogged': isFogged(hex.q, hex.r) }]"
            @click="emit('clickHex', { q: hex.q, r: hex.r })"
            @mouseover="(e: MouseEvent) => emit('hoverHex', { e, q: hex.q, r: hex.r })"
          />
          <!-- Terrain decoration icons — sorted by y so lower icons render on top -->
          <!-- Only loads/renders the texture within PNG_LOAD_RADIUS; darkened while fogged -->
          <image
            v-if="shouldLoadTexture(hex.q, hex.r)"
            :key="`ti-${hex.q}-${hex.r}`"
            :x="hexCenter(hex.q, hex.r)[0]-13"
            :y="hexCenter(hex.q, hex.r)[1]-12.5"
            height= "25"
            width="26"
            :opacity="isFogged(hex.q, hex.r) ? 0.35 : 1"
            :href="`/src/assets/img/tiles/${TERRAIN_ICON[hex.terrain]}${(Math.abs(hex.q * 73856093 ^ hex.r * 19349663) % 4) + 1}.png`"
            style="pointer-events: none"
          />
          <!-- Territory border edges -->
          <line
            v-if="!isFogged(hex.q, hex.r)"
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

        <!-- Buy-unit glow rings around own cities -->
        <g v-if="buyingArmyMode || buyingSpyMode || buyingSettlerMode || buyingFarmerMode">
          <template v-for="building in (buildings ?? [])" :key="`glow-${building.id}`">
            <template v-if="building.q != null && building.r != null && building.user_id === currentUserId && building.building_type !== 'farm'">
              <circle
                :cx="hexCenter(building.q, building.r)[0]"
                :cy="hexCenter(building.q, building.r)[1]"
                r="11"
                fill="none"
                stroke="#FFD700"
                stroke-width="2"
                opacity="0.5"
                class="city-glow-ring city-glow-ring--outer"
                style="pointer-events: none"
              />
              <circle
                :cx="hexCenter(building.q, building.r)[0]"
                :cy="hexCenter(building.q, building.r)[1]"
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

        <!-- Building (city) markers -->
        <g v-for="building in (buildings ?? [])" :key="`building-${building.id}`">
          <template v-if="building.q != null && building.r != null && (building.user_id === currentUserId || !isFogged(building.q, building.r))">
            <image
              :x="hexCenter(building.q, building.r)[0] - 8.5"
              :y="hexCenter(building.q, building.r)[1] - 6.5"
              width="17"
              height="17"
              :href="building.building_type === 'farm' ? `/src/assets/img/farms/farm-${(Math.abs(building.q * 73856093 ^ building.r * 19349663) % 2) + 1}-`+buildingColor(building).replace('#','')+`.svg` : `/src/assets/img/towns/town-${(Math.abs(building.q * 73856093 ^ building.r * 19349663) % 3) + 1}-`+buildingColor(building).replace('#','')+`.svg`"
              style="pointer-events: none" />
          </template>
        </g>

        <!-- Army & spy markers -->
        <g v-for="army in (armies ?? [])" :key="army.id">
          <template v-if="isUnitVisible(army)">
            <!-- Selection ring (larger, behind) -->
            <circle
              v-if="selectedArmyId === army.id"
              :cx="hexCenter(army.q!, army.r!)[0] + 4"
              :cy="hexCenter(army.q!, army.r!)[1] - 3"
              r="7"
              fill="none"
              stroke="#00e5ff"
              stroke-width="1.5"
              style="pointer-events: none"
            />
            <!-- Unit icon -->
            <image
              :x="hexCenter(army.q!, army.r!)[0]-1"
              :y="hexCenter(army.q!, army.r!)[1]-7"
              width="10"
              height="10"
              :href="army.unit_type === 'spy' ? '/src/assets/img/spy-'+UnitColor(army).replace('#','')+'.svg' : (army.unit_type === 'settler'|| army.unit_type ==='farmer') ? '/src/assets/img/settler-'+UnitColor(army).replace('#','')+'.svg' : '/src/assets/img/army-'+UnitColor(army).replace('#','')+'.svg'"
              :opacity="(army.unit_type === 'spy' || army.unit_type === 'settler' || army.unit_type === 'farmer') && army.user_id === currentUserId ? 0.85 : 1"
              style="user-select: none; cursor: pointer"
              @click.stop="emit('clickArmy', { armyId: army.id })" />
            <!-- Player colour ring -->
            <circle
              :cx="hexCenter(army.q!, army.r!)[0] + 4"
              :cy="hexCenter(army.q!, army.r!)[1] - 2"
              r="6"
              fill="none"
              :stroke="UnitColor(army)"
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
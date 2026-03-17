import { ref } from 'vue'
import { useProceduralGen } from './useProceduralGen'

export type TerrainType = 'plains' | 'forest' | 'mountain' | 'water' | 'desert' | 'swamp'

export interface Hex {
  q: number
  r: number
  terrain: TerrainType
}

export const TERRAIN_TYPES: TerrainType[] = ['plains', 'forest', 'mountain', 'water', 'desert', 'swamp']

export const TERRAIN_COLOR: Record<TerrainType, string> = {
  plains: '#c9a84c', forest: '#2d6a2d', mountain: '#8a7560',
  water: '#3a3a8a', desert: '#d4a870', swamp: '#364c26',
}

export const TERRAIN_BORDER: Record<TerrainType, string> = {
  plains: '#a07830', forest: '#1a4a1a', mountain: '#5c4f3a',
  water: '#22226a', desert: '#9a7a2c', swamp: '#2d4a2a',
}

export const TERRAIN_LABEL: Record<TerrainType, string> = {
  plains: '🌾 Plains', forest: '🌲 Forest', mountain: '⛰️ Mountain',
  water: '🌊 Water', desert: '🏜️ Desert', swamp: '🌿 Swamp',
}

export const MAP_SIZES = {
  small:  { cols: 40,  rows: 28,  label: 'Small (40×28)' },
  medium: { cols: 80,  rows: 55,  label: 'Medium (80×55)' },
  large:  { cols: 120, rows: 80,  label: 'Large (120×80)' },
  huge:   { cols: 160, rows: 110, label: 'Huge (160×110)' },
}
export type MapSizeKey = keyof typeof MAP_SIZES

export const HEX_SIZE = 14
export const H = Math.sqrt(3) * HEX_SIZE
export const COL_STEP = HEX_SIZE * 2 * 0.75

export function hexCenter(q: number, r: number): [number, number] {
  const x = HEX_SIZE + q * COL_STEP
  const y = HEX_SIZE * Math.sqrt(3) / 2 + r * H + (q % 2 === 1 ? H / 2 : 0)
  return [x, y]
}

export function hexPoints(cx: number, cy: number): string {
  const pts: string[] = []
  for (let i = 0; i < 6; i++) {
    const a = (Math.PI / 180) * (60 * i)
    pts.push(`${cx + (HEX_SIZE - 1) * Math.cos(a)},${cy + (HEX_SIZE - 1) * Math.sin(a)}`)
  }
  return pts.join(' ')
}

export function getSvgW(cols: number) { return HEX_SIZE + (cols - 1) * COL_STEP + HEX_SIZE }
export function getSvgH(rows: number) { return H * rows + H / 2 + HEX_SIZE * Math.sqrt(3) / 2 }

export function useHexMap() {
  const hexes = ref<Hex[]>([])
  const selectedQ = ref<number | null>(null)
  const selectedR = ref<number | null>(null)
  const activeTerrain = ref<TerrainType>('plains')
  const selectedSize = ref<MapSizeKey>('medium')

  const { settings: proceduralSettings, generateMap, resetSettings } = useProceduralGen()

  function getCols() { return MAP_SIZES[selectedSize.value].cols }
  function getRows() { return MAP_SIZES[selectedSize.value].rows }

  function getHex(q: number, r: number): Hex | undefined {
    for (const h of hexes.value) {
      if (h.q === q && h.r === r) return h
    }
    return undefined
  }

  function buildMapRandom() {
    hexes.value = generateMap(getCols(), getRows())
    selectedQ.value = null
    selectedR.value = null
  }

  function buildMapFromCanvas(canvas: HTMLCanvasElement) {
    const cols = getCols()
    const rows = getRows()
    const svgW = getSvgW(cols)
    const svgH = getSvgH(rows)
    const ctx = canvas.getContext('2d')
    if (!ctx) return

    // Build water mask from image
    const waterMask: boolean[] = []
    for (let q = 0; q < cols; q++) {
      for (let r = 0; r < rows; r++) {
        const [cx, cy] = hexCenter(q, r)
        const px = Math.min(Math.floor((cx / svgW) * canvas.width), canvas.width - 1)
        const py = Math.min(Math.floor((cy / svgH) * canvas.height), canvas.height - 1)
        const d = ctx.getImageData(px, py, 1, 1).data
        const red = d[0] ?? 0
        const green = d[1] ?? 0
        const blue = d[2] ?? 0
        waterMask.push(blue > 100 && red < 100 && green < 100)
      }
    }

    // Generate with procedural settings, respecting water mask
    hexes.value = generateMap(cols, rows, waterMask)
    selectedQ.value = null
    selectedR.value = null
  }

  function isSelected(q: number, r: number): boolean {
    return selectedQ.value === q && selectedR.value === r
  }

  function onClickHex(q: number, r: number) {
    if (isSelected(q, r)) {
      selectedQ.value = null
      selectedR.value = null
    } else {
      selectedQ.value = q
      selectedR.value = r
    }
    const h = getHex(q, r)
    if (h) h.terrain = activeTerrain.value
  }

  function selectHex(q: number, r: number) {
    selectedQ.value = q
    selectedR.value = r
  }

  function onHoverHex(e: MouseEvent, q: number, r: number) {
    if (e.buttons !== 1) return
    const h = getHex(q, r)
    if (h) h.terrain = activeTerrain.value
  }

  function getSelectedTerrain(): string {
    if (selectedQ.value === null || selectedR.value === null) return ''
    const h = getHex(selectedQ.value, selectedR.value)
    return h ? TERRAIN_LABEL[h.terrain] : ''
  }

  function setHexes(newHexes: Hex[]) {
    hexes.value = newHexes
    selectedQ.value = null
    selectedR.value = null
  }

  return {
    hexes,
    selectedQ,
    selectedR,
    activeTerrain,
    selectedSize,
    proceduralSettings,
    getCols,
    getRows,
    getHex,
    buildMapRandom,
    buildMapFromCanvas,
    isSelected,
    onClickHex,
    selectHex,
    onHoverHex,
    getSelectedTerrain,
    setHexes,
    resetProceduralSettings: resetSettings,
  }
}
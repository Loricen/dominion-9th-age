import { ref } from 'vue'
import type { Hex, TerrainType } from './useHexMap'
import { TERRAIN_TYPES } from './useHexMap'

export interface ProceduralSettings {
  neighborInfluence: number
  smoothingPasses: number
  borderWaterSize: number        // 0-1: how much of the border fades to water
  terrainWeights: Record<TerrainType, number>
}

export const DEFAULT_SETTINGS: ProceduralSettings = {
  neighborInfluence: 0.65,
  smoothingPasses: 2,
  borderWaterSize: 0.25,
  terrainWeights: {
    plains:   30,
    forest:   20,
    mountain: 10,
    water:    5,
    desert:   10,
    swamp:    10,
  }
}

// Flat-top hex neighbors (6 directions)
function getNeighborCoords(q: number, r: number): [number, number][] {
  const isOdd = q % 2 === 1
  return isOdd
    ? [[q+1,r],[q-1,r],[q,r-1],[q,r+1],[q+1,r-1],[q-1,r-1]]
    : [[q+1,r],[q-1,r],[q,r-1],[q,r+1],[q+1,r+1],[q-1,r+1]]
}

function weightedRandom(weights: Record<TerrainType, number>): TerrainType {
  const total = Object.values(weights).reduce((a, b) => a + b, 0)
  if (total <= 0) return 'plains'
  let rand = Math.random() * total
  for (const terrain of TERRAIN_TYPES) {
    rand -= weights[terrain]
    if (rand <= 0) return terrain
  }
  return 'plains'
}

function blendWeights(
  base: Record<TerrainType, number>,
  neighborCounts: Record<TerrainType, number>,
  influence: number
): Record<TerrainType, number> {
  const totalNeighbors = Object.values(neighborCounts).reduce((a, b) => a + b, 0)
  const result = {} as Record<TerrainType, number>
  for (const t of TERRAIN_TYPES) {
    const baseW = base[t]
    const neighborW = totalNeighbors > 0 ? (neighborCounts[t] / totalNeighbors) * 100 : 0
    result[t] = baseW * (1 - influence) + neighborW * influence
  }
  return result
}

// Returns a 0-1 value: 0 = edge of map, 1 = center of map
// Uses an elliptical falloff so corners are also water
function borderFalloff(q: number, r: number, cols: number, rows: number): number {
  const nx = (q / (cols - 1)) * 2 - 1  // -1 to 1
  const ny = (r / (rows - 1)) * 2 - 1  // -1 to 1
  // Elliptical distance from center (0=center, 1=edge)
  const dist = Math.sqrt(nx * nx + ny * ny) / Math.SQRT2
  return Math.max(0, 1 - dist)
}

export function useProceduralGen() {
  const settings = ref<ProceduralSettings>({
    ...DEFAULT_SETTINGS,
    terrainWeights: { ...DEFAULT_SETTINGS.terrainWeights }
  })

  function generateMap(cols: number, rows: number, waterMask?: boolean[]): Hex[] {
    const hexMap = new Map<string, Hex>()
    const key = (q: number, r: number) => `${q},${r}`
    const borderSize = settings.value.borderWaterSize

    // Step 1: Initial fill with border water falloff
    for (let q = 0; q < cols; q++) {
      for (let r = 0; r < rows; r++) {
        const idx = q * rows + r
        const isMaskedWater = waterMask ? waterMask[idx] : false

        let terrain: TerrainType
        if (isMaskedWater) {
          terrain = 'water'
        } else {
          // How close to center (0=edge, 1=center)
          const centeredness = borderFalloff(q, r, cols, rows)
          // At the border zone, high chance of water
          const waterChance = Math.max(0, 1 - centeredness / borderSize)

          if (borderSize > 0 && Math.random() < waterChance) {
            terrain = 'water'
          } else {
            terrain = weightedRandom(settings.value.terrainWeights)
          }
        }

        hexMap.set(key(q, r), { q, r, terrain })
      }
    }

    // Step 2: Neighbor-influenced smoothing passes
    const passes = settings.value.smoothingPasses
    const influence = settings.value.neighborInfluence

    for (let pass = 0; pass < passes; pass++) {
      const snapshot = new Map(hexMap)

      for (let q = 0; q < cols; q++) {
        for (let r = 0; r < rows; r++) {
          const hex = snapshot.get(key(q, r))
          if (!hex) continue

          // Keep border water locked during smoothing
          const centeredness = borderFalloff(q, r, cols, rows)
          const isLockedWater = waterMask
            ? (waterMask[q * rows + r] ?? false)
            : (borderSize > 0 && centeredness / borderSize < 0.15)

          if (isLockedWater) {
            hexMap.set(key(q, r), { q, r, terrain: 'water' })
            continue
          }

          // Count neighbor terrain types
          const neighborCounts = Object.fromEntries(
            TERRAIN_TYPES.map(t => [t, 0])
          ) as Record<TerrainType, number>

          for (const [nq, nr] of getNeighborCoords(q, r)) {
            const neighbor = snapshot.get(key(nq, nr))
            if (neighbor) neighborCounts[neighbor.terrain]++
          }

          const blended = blendWeights(settings.value.terrainWeights, neighborCounts, influence)
          hexMap.set(key(q, r), { q, r, terrain: weightedRandom(blended) })
        }
      }
    }

    return Array.from(hexMap.values())
  }

  function resetSettings() {
    settings.value = {
      ...DEFAULT_SETTINGS,
      terrainWeights: { ...DEFAULT_SETTINGS.terrainWeights }
    }
  }

  return {
    settings,
    generateMap,
    resetSettings,
  }
}

import { ref } from 'vue'
import type { Hex, MapSizeKey } from './useHexMap'

export interface MapSave {
  version: number
  hexturn?: number
  cols: number
  rows: number
  size: MapSizeKey
  hexmap_uid?: string
  name?: string
  savedAt?: string
  is_owner?: boolean
  is_linked?: boolean
  is_pending?: boolean
  mapStatus?: string
  players?: MapPlayer[]
  hexes: Hex[]
}

export interface MapListItem {
  hexmap_uid: string
  name: string
  size: MapSizeKey
  savedAt: string
  mapStatus?: string
  is_owner?: boolean
}

export interface JoinRequest {
  map_uid: string
  map_name: string
  user_id: number
  user_name: string
}

export interface MapPlayer {
  user_id: number
  name: string
  is_owner: boolean
  last_seen: number
}

export interface OwnedTile {
  q: number
  r: number
  user_id: number
}

export interface PlayerSetupWithId extends PlayerSetup {
  user_id: number
  actions: number
  resources: number
}

export interface PlayerSetup {
  faction: string
  color: string
  city_q: number
  city_r: number
}

export type UserRole = 'advanced_player' | 'player' | 'none'

const WP_API = '/wp-json/hexcommand/v1'

function getNonce(): string {
  return (window as any).hexcommandNonce ?? ''
}

function authHeaders(): HeadersInit {
  return {
    'Content-Type': 'application/json',
    'X-WP-Nonce': getNonce(),
  }
}

export function useMapIO() {
  const saveMsg         = ref('')
  const imageLoaded     = ref(false)
  const showUidModal    = ref(false)
  const lastHexmapUid   = ref('')
  const lastMapName     = ref('')
  const uidCopied       = ref(false)
  const userMaps        = ref<MapListItem[]>([])
  const isLoggedIn      = ref(false)
  const userRole        = ref<UserRole>('none')
  const joinRequests    = ref<JoinRequest[]>([])
  const playerSetup     = ref<PlayerSetup | null>(null)
  const allPlayerSetups = ref<PlayerSetupWithId[]>([])
  const ownedTiles      = ref<OwnedTile[]>([])

  const loadedMapStatus = ref<{
    uid: string
    is_owner: boolean
    is_linked: boolean
    is_pending: boolean
    mapStatus?: string
    hexturn: number
    players: MapPlayer[]
  } | null>(null)

  let requestsPollInterval: ReturnType<typeof setInterval> | null = null
  let heartbeatInterval:     ReturnType<typeof setInterval> | null = null

  function showMsg(msg: string) {
    saveMsg.value = msg
    setTimeout(() => { saveMsg.value = '' }, 2500)
  }

  async function checkAuth() {
    try {
      const res = await fetch(`${WP_API}/me`, { headers: authHeaders() })
      if (res.ok) {
        const me = await res.json()
        isLoggedIn.value = true
        userRole.value   = me.role as UserRole
        await refreshMapList()
        startHeartbeat()
        if (userRole.value === 'advanced_player') {
          await refreshRequests()
          startRequestPolling()
        }
      } else {
        isLoggedIn.value = false
        userRole.value   = 'none'
      }
    } catch {
      isLoggedIn.value = false
      userRole.value   = 'none'
    }
  }

  async function refreshMapList() {
    try {
      const res = await fetch(`${WP_API}/maps`, { headers: authHeaders() })
      if (res.ok) userMaps.value = await res.json()
    } catch { /* silent */ }
  }

  async function refreshRequests() {
    try {
      const res = await fetch(`${WP_API}/requests`, { headers: authHeaders() })
      if (res.ok) joinRequests.value = await res.json()
    } catch { /* silent */ }
  }

  async function refreshPlayers(): Promise<void> {
    const uid = loadedMapStatus.value?.uid
    if (!uid) return
    try {
      const res = await fetch(`${WP_API}/maps/${uid}`, { headers: authHeaders() })
      if (res.ok) {
        const data = await res.json()
        if (loadedMapStatus.value) {
          loadedMapStatus.value.players   = data.players      ?? []
          loadedMapStatus.value.mapStatus = data.mapStatus    ?? loadedMapStatus.value.mapStatus
          loadedMapStatus.value.hexturn   = data.hexturn      ?? loadedMapStatus.value.hexturn
          allPlayerSetups.value           = data.player_setups ?? []
          ownedTiles.value                = data.owned_tiles   ?? []
        }
      }
    } catch { /* silent */ }
  }

  async function sendHeartbeat(): Promise<void> {
    try {
      await fetch(`${WP_API}/me/heartbeat`, { method: 'POST', headers: authHeaders() })
    } catch { /* silent */ }
  }

  function startHeartbeat() {
    if (heartbeatInterval) return
    sendHeartbeat()
    heartbeatInterval = setInterval(sendHeartbeat, 30000)
  }

  function startRequestPolling() {
    if (requestsPollInterval) return
    requestsPollInterval = setInterval(async () => {
      await refreshRequests()
      await refreshPlayers()
    }, 10000)
  }

  function downloadMap(hexes: Hex[], cols: number, rows: number, size: MapSizeKey) {
    const data: MapSave = {
      version: 1, cols, rows, size,
      hexes: hexes.map(h => ({ q: h.q, r: h.r, terrain: h.terrain }))
    }
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href = url; a.download = 'hexmap.json'; a.click()
    URL.revokeObjectURL(url)
    showMsg('Map downloaded!')
  }

  async function saveToServer(
    hexes: Hex[], cols: number, rows: number,
    size: MapSizeKey, name: string = 'Untitled Map'
  ): Promise<void> {
    if (userRole.value !== 'advanced_player') {
      showMsg('Only advanced players can save maps')
      return
    }
    const data: MapSave = {
      version: 1, cols, rows, size, name,
      hexes: hexes.map(h => ({ q: h.q, r: h.r, terrain: h.terrain }))
    }
    try {
      const res  = await fetch(`${WP_API}/maps`, {
        method: 'POST', headers: authHeaders(), body: JSON.stringify(data),
      })
      const json = await res.json()
      if (!res.ok || !json.success) throw new Error(json.error || 'Server error')
      lastHexmapUid.value = json.hexmap_uid
      lastMapName.value   = json.name
      uidCopied.value     = false
      showUidModal.value  = true
      // Mark newly saved map as ongoing + owned so canEdit stays true
      loadedMapStatus.value = {
        uid:         json.hexmap_uid,
        is_owner:    true,
        is_linked:   false,
        is_pending:  false,
        mapStatus:   'created',
        hexturn:     0,
        players:     [],
      }
      await refreshMapList()
    } catch (err) {
      showMsg('Error saving to server')
      console.error(err)
    }
  }

  async function loadFromServer(uid: string): Promise<MapSave> {
    const res = await fetch(
      `${WP_API}/maps/${encodeURIComponent(uid.trim().toUpperCase())}`,
      { headers: authHeaders() }
    )
    if (!res.ok) {
      const json = await res.json()
      throw new Error(json.error || 'Map not found')
    }
    const data: MapSave = await res.json()
    loadedMapStatus.value = {
      uid:         data.hexmap_uid ?? uid,
      is_owner:    data.is_owner    ?? false,
      is_linked:   data.is_linked   ?? false,
      is_pending:  data.is_pending  ?? false,
      mapStatus:   data.mapStatus ?? 'created',
      hexturn:     data.hexturn   ?? 0,
      players:     data.players     ?? [],
    }
    playerSetup.value     = (data as any).player_setup  ?? null
    allPlayerSetups.value = (data as any).player_setups ?? []
    ownedTiles.value      = (data as any).owned_tiles     ?? []
    return data
  }

  async function deleteFromServer(hexmap_uid: string): Promise<void> {
    const res = await fetch(`${WP_API}/maps/${hexmap_uid}`, {
      method: 'POST',
      headers: { ...authHeaders(), 'X-HTTP-Method-Override': 'DELETE' },
    })
    if (!res.ok) throw new Error('Failed to delete map')
    await refreshMapList()
    showMsg('Map deleted')
  }

  async function finishMap(uid: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/finish`, {
      method: 'POST', headers: authHeaders(),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to finish map')
    if (loadedMapStatus.value?.uid === uid) {
      loadedMapStatus.value.mapStatus = 'ongoing'
    }
    await refreshMapList()
    showMsg('Map validated and locked!')
  }

  async function startMap(uid: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/start`, {
      method: 'POST', headers: authHeaders(),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to start game')
    if (loadedMapStatus.value?.uid === uid) {
      loadedMapStatus.value.mapStatus = 'started'
    }
    await refreshMapList()
    showMsg('Game started!')
  }

  async function endMap(uid: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/end`, {
      method: 'POST', headers: authHeaders(),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to end game')
    if (loadedMapStatus.value?.uid === uid) {
      loadedMapStatus.value.mapStatus = 'ended'
    }
    await refreshMapList()
    showMsg('Game ended!')
  }

  async function requestJoinMap(uid: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/join`, {
      method: 'POST', headers: authHeaders(),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to send request')
    if (loadedMapStatus.value?.uid === uid) {
      loadedMapStatus.value.is_pending = true
    }
    showMsg('Join request sent!')
  }

  async function approveRequest(map_uid: string, user_id: number): Promise<void> {
    const res = await fetch(`${WP_API}/maps/${map_uid}/approve/${user_id}`, {
      method: 'POST', headers: authHeaders(),
    })
    if (!res.ok) throw new Error('Failed to approve')
    await refreshRequests()
    await refreshPlayers()
    await refreshMapList()
    showMsg('Player approved!')
  }

  async function denyRequest(map_uid: string, user_id: number): Promise<void> {
    const res = await fetch(`${WP_API}/maps/${map_uid}/deny/${user_id}`, {
      method: 'POST', headers: authHeaders(),
    })
    if (!res.ok) throw new Error('Failed to deny')
    await refreshRequests()
    showMsg('Request denied')
  }

  async function savePlayerSetup(uid: string, setup: PlayerSetup): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/setup`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify(setup),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to save setup')
    playerSetup.value = setup
    // Update allPlayerSetups immediately so the map re-renders
    const userId = json.user_id
    const existing = allPlayerSetups.value.find(s => s.user_id === userId)
    const setupWithId = { ...setup, user_id: userId, actions: existing?.actions ?? 10 }
    const idx = allPlayerSetups.value.findIndex(s => s.user_id === userId)
    if (idx >= 0) allPlayerSetups.value[idx] = setupWithId
    else allPlayerSetups.value.push(setupWithId)
    showMsg('Setup saved!')
  }

  function loadMapFromFile(file: File): Promise<MapSave> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader()
      reader.onload = (ev) => {
        try {
          const data = JSON.parse(ev.target?.result as string) as MapSave
          if (!data.hexes || !Array.isArray(data.hexes)) throw new Error('Invalid file')
          resolve(data)
        } catch { reject(new Error('Invalid map file')) }
      }
      reader.readAsText(file)
    })
  }

  function loadImageAsCanvas(file: File): Promise<HTMLCanvasElement> {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file)
      const img = new Image()
      img.onload = () => {
        const canvas = document.createElement('canvas')
        canvas.width = img.naturalWidth; canvas.height = img.naturalHeight
        canvas.getContext('2d')!.drawImage(img, 0, 0)
        URL.revokeObjectURL(url); resolve(canvas)
      }
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Failed to load image')) }
      img.src = url
    })
  }

  async function claimTile(uid: string, q: number, r: number): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/claim`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify({ q, r }),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to claim tile')
    ownedTiles.value = json.owned_tiles ?? ownedTiles.value
    // Update current player's actions count
    const mySetup = allPlayerSetups.value.find(s => s.user_id === json.user_id)
    if (mySetup) mySetup.actions = json.actions
  }

  async function nextTurn(uid: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/nextturn`, {
      method: 'POST', headers: authHeaders(),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to advance turn')
    if (loadedMapStatus.value?.uid === uid) {
      loadedMapStatus.value.hexturn = json.hexturn
      allPlayerSetups.value = json.player_setups ?? allPlayerSetups.value
    }
    showMsg(`Turn ${json.hexturn} started!`)
  }

  async function copyUidToClipboard() {
    await navigator.clipboard.writeText(lastHexmapUid.value)
    uidCopied.value = true
    setTimeout(() => { uidCopied.value = false }, 2000)
  }

  return {
    saveMsg, imageLoaded, showUidModal, lastHexmapUid, lastMapName,
    uidCopied, userMaps, isLoggedIn, userRole, joinRequests, loadedMapStatus, playerSetup, allPlayerSetups, ownedTiles,
    showMsg, checkAuth, refreshMapList, refreshRequests, refreshPlayers,
    downloadMap, saveToServer, loadFromServer, deleteFromServer,
    finishMap, startMap, endMap, nextTurn, claimTile, requestJoinMap, approveRequest, denyRequest, savePlayerSetup,
    loadMapFromFile, loadImageAsCanvas, copyUidToClipboard,
  }
}
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

export interface ChatMessage {
  user_id: number
  user_name: string
  text: string
  ts: number
}

export interface Army {
  id: number
  name: string
  user_id: number
  hexmap: number
  power: number
  stats: string
  q: number
  r: number
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
  turn_done: boolean
}

export interface PlayerSetup {
  faction: string
  color: string
  city_q: number
  city_r: number
  randomUserId: number
}

export type UserRole = 'player' | 'none'

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
  const popIn           = ref<{ msg: string; type: 'info' | 'battle' | 'error' } | null>(null)
  const imageLoaded     = ref(false)
  const showUidModal    = ref(false)
  const lastHexmapUid   = ref('')
  const lastMapName     = ref('')
  const uidCopied       = ref(false)
  const userMaps        = ref<MapListItem[]>([])
  const isLoggedIn      = ref(false)
  const userRole        = ref<UserRole>('none')
  const credits         = ref<number>(0)
  const currentUserId   = ref<number>(0)
  const chatMessages    = ref<ChatMessage[]>([])
  const joinRequests    = ref<JoinRequest[]>([])
  const playerSetup     = ref<PlayerSetup | null>(null)
  const allPlayerSetups = ref<PlayerSetupWithId[]>([])
  const ownedTiles = ref<OwnedTile[]>([])
  const armies     = ref<Army[]>([])

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

  let popInTimer: ReturnType<typeof setTimeout> | null = null
  function showPopIn(msg: string, type: 'info' | 'battle' | 'error' = 'info') {
    if (popInTimer) clearTimeout(popInTimer)
    popIn.value = { msg, type }
    popInTimer = setTimeout(() => { popIn.value = null }, type === 'battle' ? 5000 : 3000)
  }
  function showMsg(msg: string) {
    showPopIn(msg, 'info')
  }

  async function checkAuth() {
    try {
      const res = await fetch(`${WP_API}/me`, { headers: authHeaders() })
      if (res.ok) {
        const me = await res.json()
        isLoggedIn.value  = true
        userRole.value    = me.role as UserRole
        credits.value     = me.credits ?? 0
        currentUserId.value = me.id ?? 0
        await refreshMapList()
        startHeartbeat()
        if (userRole.value === 'player') {
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
          loadedMapStatus.value.is_linked = data.is_linked    ?? loadedMapStatus.value.is_linked
          allPlayerSetups.value           = (data.player_setups ?? []).map((s: any) => ({ ...s, randomUserId: s.randomuserid ?? 1 }))
          ownedTiles.value = data.owned_tiles ?? []
          armies.value     = data.armies       ?? []
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
    heartbeatInterval = setInterval(() => {
      sendHeartbeat()
    }, 30000)
  }

  function startRequestPolling() {
    if (requestsPollInterval) return
    requestsPollInterval = setInterval(async () => {
      await refreshRequests()
      await refreshPlayers()
      const uid = loadedMapStatus.value?.uid
      if (uid) await fetchChat(uid)
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
    allPlayerSetups.value = ((data as any).player_setups ?? []).map((s: any) => ({ ...s, randomUserId: s.randomuserid ?? 1 }))
    ownedTiles.value = (data as any).owned_tiles ?? []
    armies.value     = (data as any).armies       ?? []
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
    const setupWithId = {
      ...setup,
      user_id: userId,
      actions: existing?.actions ?? 10,
      resources: existing?.resources ?? 0,
      turn_done: existing?.turn_done ?? false,
      randomUserId: json.randomuserid ?? existing?.randomUserId ?? 1,
    }
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

  async function buyArmy(uid: string, q: number, r: number): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/buyArmy`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify({ q, r }),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to buy army')
    armies.value = json.armies ?? armies.value
    const mySetup = allPlayerSetups.value.find(s => s.user_id === json.user_id)
    if (mySetup) mySetup.resources = json.resources
  }

  async function upgradeArmy(uid: string, armyId: number, resources: number): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/upgradeArmy`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify({ army_id: armyId, resources }),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to upgrade army')
    armies.value = json.armies ?? armies.value
    const mySetup = allPlayerSetups.value.find(s => s.user_id === json.user_id)
    if (mySetup) mySetup.resources = json.resources
  }

  async function renameArmy(uid: string, armyId: number, name: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/renameArmy`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify({ army_id: armyId, name }),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to rename army')
    const army = armies.value.find(a => a.id === armyId)
    if (army) army.name = json.name
  }

  async function moveArmy(uid: string, armyId: number, q: number, r: number): Promise<{
    combat: null | { result: string; city_combat?: boolean; attacker_roll: number; defender_roll: number; attacker_total: number; defender_total: number; winner_power: number }
  }> {
    const res  = await fetch(`${WP_API}/maps/${uid}/moveArmy`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify({ army_id: armyId, q, r }),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to move army')
    armies.value      = json.armies      ?? armies.value
    ownedTiles.value  = json.owned_tiles ?? ownedTiles.value
    const mySetup = allPlayerSetups.value.find(s => s.user_id === json.user_id)
    if (mySetup) mySetup.actions = json.actions
    if (json.mapStatus && loadedMapStatus.value) loadedMapStatus.value.mapStatus = json.mapStatus
    if (json.player_setups) allPlayerSetups.value = (json.player_setups).map((s: any) => ({ ...s, randomUserId: s.randomuserid ?? 1 }))
    return { combat: json.combat ?? null }
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

  async function resignMap(uid: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/resign`, {
      method: 'POST', headers: authHeaders(),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to resign')
    if (loadedMapStatus.value) loadedMapStatus.value.mapStatus = json.mapStatus ?? loadedMapStatus.value.mapStatus
    showMsg('You have resigned.')
  }

  async function endTurn(uid: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/endturn`, {
      method: 'POST', headers: authHeaders(),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to end turn')
    allPlayerSetups.value = (json.player_setups ?? allPlayerSetups.value).map((s: any) => ({ ...s, randomUserId: s.randomuserid ?? 1 }))
    if (json.all_done && loadedMapStatus.value?.uid === uid) {
      loadedMapStatus.value.hexturn = json.hexturn
      showMsg(`Turn ${json.hexturn} started!`)
    }
  }
  async function nextTurn(uid: string): Promise<void> {
    const res  = await fetch(`${WP_API}/maps/${uid}/nextturn`, {
      method: 'POST', headers: authHeaders(),
    })
    const json = await res.json()
    if (!res.ok) throw new Error(json.error || 'Failed to advance turn')
    if (loadedMapStatus.value?.uid === uid) {
      loadedMapStatus.value.hexturn = json.hexturn
      allPlayerSetups.value = (json.player_setups ?? allPlayerSetups.value).map((s: any) => ({ ...s, randomUserId: s.randomuserid ?? 1 }))
    }
    showMsg(`Turn ${json.hexturn} started!`)
  }

  async function fetchChat(uid: string): Promise<void> {
    try {
      const res = await fetch(`${WP_API}/maps/${uid}/chat`, { headers: authHeaders() })
      if (res.ok) chatMessages.value = await res.json()
    } catch { /* silent */ }
  }

  async function sendChat(uid: string, text: string): Promise<void> {
    const res = await fetch(`${WP_API}/maps/${uid}/chat`, {
      method: 'POST', headers: authHeaders(), body: JSON.stringify({ text }),
    })
    if (!res.ok) throw new Error('Failed to send message')
    await fetchChat(uid)
  }

  async function copyUidToClipboard() {
    await navigator.clipboard.writeText(lastHexmapUid.value)
    uidCopied.value = true
    setTimeout(() => { uidCopied.value = false }, 2000)
  }

  return {
    saveMsg, popIn, imageLoaded, showUidModal, lastHexmapUid, lastMapName,
    uidCopied, userMaps, isLoggedIn, userRole, credits, currentUserId, chatMessages, joinRequests, loadedMapStatus, playerSetup, allPlayerSetups, ownedTiles, armies,
    showMsg, checkAuth, refreshMapList, refreshRequests, refreshPlayers, showPopIn,
    downloadMap, saveToServer, loadFromServer, deleteFromServer,
    finishMap, startMap, endTurn, resignMap, claimTile, requestJoinMap, approveRequest, denyRequest, savePlayerSetup, buyArmy, moveArmy, renameArmy, upgradeArmy,
    loadMapFromFile, loadImageAsCanvas, copyUidToClipboard, fetchChat, sendChat,
  }
}
<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useHexMap } from '@/composables/useHexMap'
import { useMapIO } from '@/composables/useMapIO'
import type { MapPlayer } from '@/composables/useMapIO'
import { useMapView } from '@/composables/useMapView'
import { useSound } from '@/composables/useSound'
import type { MapSizeKey, TerrainType } from '@/composables/useHexMap'
import type { ProceduralSettings } from '@/composables/useProceduralGen'
import MapToolbar from './MapToolbar.vue'
import MapSidebar from './MapSidebar.vue'
import MapCanvas from './MapCanvas.vue'
import UidModal from './UidModal.vue'
import SaveModal from './SaveModal.vue'
import JoinRequestsPanel from './JoinRequestsPanel.vue'
import JoinMapBanner from './JoinMapBanner.vue'
import MapRightBar from './MapRightBar.vue'
import ChatBox from './ChatBox.vue'


const {
  hexes, selectedQ, selectedR, activeTerrain, selectedSize,
  proceduralSettings, getCols, getRows,
  buildMapRandom, buildMapFromCanvas,
  onClickHex, selectHex, onHoverHex,
  getSelectedTerrain, setHexes, resetProceduralSettings,
} = useHexMap()

const {
  saveMsg, imageLoaded,popIn,
  showUidModal, lastHexmapUid, uidCopied, chatMessages, currentUserId,
  userMaps, isLoggedIn, userRole, credits, joinRequests, loadedMapStatus, playerSetup, allPlayerSetups, ownedTiles, armies, buildings,
  showMsg, checkAuth, downloadMap, saveToServer,showPopIn,
  loadFromServer, deleteFromServer, finishMap, startMap, endTurn, resignMap, forceStart, claimTile, buyArmy, buySpy, buySettler, buyFarmer, foundCity, foundFarm, moveArmy, mergeArmy, renameArmy, upgradeArmy,
  requestJoinMap, approveRequest, denyRequest, savePlayerSetup, refreshPlayers,
  loadMapFromFile, loadImageAsCanvas, copyUidToClipboard, fetchChat, sendChat,
} = useMapIO()

const { playTileClick, playTileCapture, playActionsReload, playJoinRequest, playGameStarts, playBattle } = useSound()

// Play sound when new join requests arrive (owner only)
watch(() => joinRequests.value.length, (newLen, oldLen) => {
  if (newLen > oldLen) playJoinRequest()
})

// Play sound for everyone when game starts
watch(() => loadedMapStatus.value?.mapStatus, (newStatus, oldStatus) => {
  if (newStatus === 'started' && oldStatus !== 'started') playGameStarts()
})

// Play sound when turn advances (actions reset) — covers both manual and refresh
watch(() => loadedMapStatus.value?.hexturn, (newTurn, oldTurn) => {
  if (newTurn !== undefined && oldTurn !== undefined && newTurn > oldTurn) playActionsReload()
})

const {
  zoom, panX, panY, backVis,
  onWheel, onMouseDown, resetView, zoomIn, zoomOut,
} = useMapView()

// Directional pad — pans the map by a fixed screen-space step, scaled so the
// perceived movement stays consistent whatever the current zoom level.
const PAN_STEP = 80
function panBy(dx: number, dy: number) {
  panX.value += dx * PAN_STEP
  panY.value += dy * PAN_STEP
}

// --- Computed state ---
const isAdvancedPlayer = computed(() => !!loadedMapStatus.value?.is_owner || appMode === 'create')
const isOwner = computed(() => isAdvancedPlayer.value && userMaps.value.length > 0)

const canEdit = computed(() =>
  isAdvancedPlayer.value &&
  (loadedMapStatus.value === null || loadedMapStatus.value.mapStatus === "created")
)

const canFinish = computed(() =>
  isAdvancedPlayer.value &&
  loadedMapStatus.value !== null &&
  loadedMapStatus.value.is_owner &&
  loadedMapStatus.value.mapStatus === 'created'
)

const canForceStart = computed(() => {
  if (!loadedMapStatus.value?.is_owner) return false
  if (loadedMapStatus.value.mapStatus !== 'awaiting') return false
  const awaitingSince = loadedMapStatus.value.awaiting_since ?? 0
  return awaitingSince > 0 && (Date.now() / 1000 - awaitingSince) >= 86400
})

const canResign = computed(() =>
  loadedMapStatus.value !== null &&
  (loadedMapStatus.value.is_owner || loadedMapStatus.value.is_linked) &&
  (loadedMapStatus.value.mapStatus === 'awaiting' || loadedMapStatus.value.mapStatus === 'started')
)
const canEndTurn = computed(() =>
  loadedMapStatus.value?.mapStatus === 'started' &&
  (loadedMapStatus.value.is_owner || loadedMapStatus.value.is_linked)
)

const myTurnDone = computed(() => mySetup.value?.turn_done ?? false)

const showRightBar = computed(() =>
  loadedMapStatus.value?.mapStatus === 'awaiting' || loadedMapStatus.value?.mapStatus === 'started'
)

const appMode = (window as any).hexcommandMode ?? 'game'

const filteredMaps = computed(() =>
  appMode === 'create'
    ? userMaps.value.filter(m => m.mapStatus === 'created')
    : userMaps.value.filter(m => ['awaiting', 'started', 'ended'].includes(m.mapStatus!))
)
const setupLocked = computed(() =>
  loadedMapStatus.value?.mapStatus === 'started' || loadedMapStatus.value?.mapStatus === 'ended'
)

// Join banner: map is awaiting (validated), not owner, not linked/pending
const showJoinBanner = computed(() =>
  loadedMapStatus.value !== null &&
  !loadedMapStatus.value.is_owner &&
  !loadedMapStatus.value.is_linked
)

// Ended overlay: anyone loading an ended map
const hideEndedOverlay = ref(false)
const showEndedOverlay = computed(() =>
  !hideEndedOverlay.value &&
  loadedMapStatus.value !== null && loadedMapStatus.value.mapStatus === 'ended'
)
const winner = computed(() => {
  const lastAlive = allPlayerSetups.value.find(s => !s.resigned)
  return lastAlive?.faction ?? lastAlive?.user_id?.toString() ?? 'Unknown'
})

// ── Auto-start countdown ─────────────────────────────────────────────────
const nowTick            = ref(Math.floor(Date.now() / 1000))
let countdownInterval: ReturnType<typeof setInterval> | null = null

const allCitiesSet = computed(() => {
  if (!loadedMapStatus.value) return false
  if (loadedMapStatus.value.players.length === 0) return false
  return loadedMapStatus.value.players.every(p =>
    allPlayerSetups.value.some(s => s.user_id === p.user_id && s.city_q != null)
  )
})

// Shown while the map is being set up but not everyone has picked a city yet
const waitingForPlayers = computed(() =>
  loadedMapStatus.value?.mapStatus === 'awaiting' && !allCitiesSet.value
)

const AUTO_START_WAIT    = 60   // seconds without any setup change before the game auto-starts
const AUTO_START_POLL_MS = 2000 // how often every player asks the server if the countdown should begin

const autoStartSecondsLeft = computed<number | null>(() => {
  if (loadedMapStatus.value?.mapStatus !== 'awaiting') return null
  if (!allCitiesSet.value) return null
  const lastSetup = loadedMapStatus.value.last_setup_at ?? 0
  if (!lastSetup) return null
  const remaining = AUTO_START_WAIT - (nowTick.value - lastSetup)
  return remaining > 0 ? remaining : 0
})

// Every player polls the server every 2 seconds while the map is 'awaiting' —
// both while waiting for the others to be ready, and once the countdown is
// running. This is what detects the moment everyone becomes ready (starting
// the countdown), picks up a reset if anyone changes their setup, and triggers
// the server's own auto-start check once the wait has elapsed.
let autoStartPollInterval: ReturnType<typeof setInterval> | null = null
watch(() => loadedMapStatus.value?.mapStatus, (status) => {
  if (status === 'awaiting' && !autoStartPollInterval) {
    autoStartPollInterval = setInterval(() => { refreshPlayers() }, AUTO_START_POLL_MS)
  } else if (status !== 'awaiting' && autoStartPollInterval) {
    clearInterval(autoStartPollInterval)
    autoStartPollInterval = null
  }
}, { immediate: true })

// Map status label for toolbar
const mapStatus = computed(() => {
  const s = loadedMapStatus.value
  if (!s) return null
  if (s.mapStatus === 'ended')   return { label: '💀 Game Ended',   cls: 'status-ended' }
  if (s.mapStatus === 'started') return { label: '⚔️ Game Started', cls: 'status-started' }
  if (s.mapStatus === 'awaiting') return { label: '🔒 Map Locked',   cls: 'status-locked' }
  if (s.mapStatus === 'created') return { label: '✏️ In Progress',  cls: 'status-awaiting' }
  return null
})

const loadedMapName     = ref('')
const showSaveModal     = ref(false)
const pendingDeleteUid  = ref<string | null>(null)
const showFinishConfirm = ref(false)
const showResignConfirm    = ref(false)
const showForceStartConfirm = ref(false)

const isSelectingCity = ref(false)
const isClaiming      = ref(false)
const isBuyingArmy    = ref(false)
const isBuyingSpy     = ref(false)
const isBuyingSettler = ref(false)
const isBuyingFarmer  = ref(false)
const isMovingArmy    = ref(false)
const selectedArmyId  = ref<number | null>(null)
const inspectedArmy   = ref<import('@/composables/useMapIO').Army | null>(null)
const pendingMerge    = ref<{ sourceId: number; sourcePower: number; targetId: number; targetPower: number; q: number; r: number } | null>(null)
// Current user's full setup (with actions) from allPlayerSetups
const mySetup = computed(() => {
  if (!currentUserId.value) return null
  const found = allPlayerSetups.value.find(s => s.user_id === currentUserId.value)
  return found ? { ...found } : null
})
const playerBorderColor = ref<string | null>(null)

// ── Settler / farmer build conditions (mirror the server rules) ─────────────
const selectedUnit = computed(() =>
  selectedArmyId.value != null ? armies.value.find(a => a.id === selectedArmyId.value) ?? null : null
)

function isFactionBonusTile(faction: string, q: number, r: number): boolean {
  q = Number(q); r = Number(r)
  const terrainAt = (tq: number, tr: number) => hexes.value.find(h => Number(h.q) === tq && Number(h.r) === tr)?.terrain ?? ''
  faction = String(faction ?? '').trim().toLowerCase()
  const here = terrainAt(q, r)
  if (faction === 'elves')     return here === 'forest'
  if (faction === 'dwarves')   return here === 'mountain'
  if (faction === 'goblins')   return here === 'swamp'
  if (faction === 'orcs')      return here === 'desert'
  if (faction === 'humans')    return here === 'plains'
  if (faction === 'froglings') {
    const offsets: [number, number][] = q % 2 === 0
      ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
      : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]]
    return offsets.some(([dq, dr]) => terrainAt(q + dq, r + dr) === 'water')
  }
  return false
}

const FACTION_BONUS_LABEL: Record<string, string> = {
  elves: 'forest', dwarves: 'mountain', goblins: 'swamp', orcs: 'desert', humans: 'plains',
  froglings: 'a tile next to water',
}

// '' when (q,r) is a valid starting-city tile for the faction, else the reason why not
function cityTileProblem(faction: string, q: number, r: number): string {
  if (!faction) return 'Choose a faction first'
  const hex = hexes.value.find(h => Number(h.q) === Number(q) && Number(h.r) === Number(r))
  if (!hex) return 'Invalid tile'
  if (hex.terrain === 'water') return 'You cannot settle on water'
  if (!isFactionBonusTile(faction, q, r)) {
    return `Your starting city must be on ${FACTION_BONUS_LABEL[faction] ?? 'a faction bonus tile'} (your faction's resource bonus)`
  }
  return ''
}

// Returns '' when the selected unit may build here, otherwise the reason why not
const foundBlockReason = computed<{ city: string; farm: string }>(() => {
  const u = selectedUnit.value
  const setup = mySetup.value
  const none = { city: '', farm: '' }
  if (!u || !setup || u.q == null || u.r == null) return { city: 'No unit selected', farm: 'No unit selected' }
  const common =
    (setup.actions ?? 0) <= 0 ? 'No actions remaining'
    : buildings.value.some(b => b.q === u.q && b.r === u.r) ? 'A building already exists on this tile'
    : !isFactionBonusTile(setup.faction, u.q, u.r) ? "This tile does not grant your faction's resource bonus"
    : ''
  if (common) return { city: common, farm: common }
  const owns = ownedTiles.value.some(t => t.q === u.q && t.r === u.r && t.user_id === currentUserId.value)
  return { ...none, farm: owns ? '' : 'You must own this tile to build a farm on it' }
})

// --- Handlers ---
async function handleLoadImage(file: File) {
  try {
    const canvas = await loadImageAsCanvas(file)
    buildMapFromCanvas(canvas)
    imageLoaded.value = true
    showMsg('Map generated from image!')
  } catch { showMsg('Error loading image') }
}

async function handleLoadMap(file: File) {
  try {
    const data = await loadMapFromFile(file)
    setHexes(data.hexes)
    if (data.size) selectedSize.value = data.size as MapSizeKey
    showMsg('Map loaded!')
  } catch { showMsg('Error: invalid map file') }
}

async function handleLoadFromServer(uid: string) {
  try {
    const data = await loadFromServer(uid)
    setHexes(data.hexes)
    if (data.size) selectedSize.value = data.size as MapSizeKey
    loadedMapName.value = data.name ?? uid
    hideEndedOverlay.value = false
    showMsg(`Map "${data.name ?? uid}" loaded!`)
    await fetchChat(uid)
    if (playerSetup.value?.color) playerBorderColor.value = playerSetup.value.color
  } catch (err: unknown) {
    showMsg(err instanceof Error ? err.message : 'Map not found')
  }
}

function handleDeleteMap(hexmap_uid: string) {
  pendingDeleteUid.value = hexmap_uid
}

async function confirmDelete() {
  if (!pendingDeleteUid.value) return
  try { await deleteFromServer(pendingDeleteUid.value) }
  catch { showMsg('Error deleting map') }
  pendingDeleteUid.value = null
}

async function confirmFinish() {
  if (!loadedMapStatus.value) return
  try { await finishMap(loadedMapStatus.value.uid) }
  catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error validating map') }
  showFinishConfirm.value = false
}

async function confirmForceStart() {
  if (!loadedMapStatus.value) return
  try { await forceStart(loadedMapStatus.value.uid) }
  catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error force starting') }
  showForceStartConfirm.value = false
}

async function confirmResign() {
  if (!loadedMapStatus.value) return
  try { await resignMap(loadedMapStatus.value.uid) }
  catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error resigning') }
  showResignConfirm.value = false
}

function handleSizeChange(size: MapSizeKey) {
  selectedSize.value = size
  buildMapRandom()
}

function handleTerrainChange(t: TerrainType) {
  activeTerrain.value = t
}

function handleUpdateProceduralSettings(s: ProceduralSettings) {
  proceduralSettings.value = s
}

function handleSaveToServer() {
  if (!canEdit.value) { showMsg('Cannot save: map is locked'); return }
  showSaveModal.value = true
}

async function handleConfirmSave(name: string) {
  showSaveModal.value = false
  await saveToServer(hexes.value, getCols(), getRows(), selectedSize.value, name)
}

function handleClickHex(e: { q: number; r: number }) {
  selectedQ.value = e.q
  selectedR.value = e.r
  if (isSelectingCity.value) { selectHex(e.q, e.r); return }
  if (isMovingArmy.value && selectedArmyId.value !== null) { handleMoveArmy(e.q, e.r); return }
  if (isBuyingArmy.value) { handleBuyArmy(e.q, e.r); return }
  if (isBuyingSpy.value) { handleBuySpy(e.q, e.r); return }
  if (isBuyingSettler.value) { handleBuySettler(e.q, e.r); return }
  if (isBuyingFarmer.value) { handleBuyFarmer(e.q, e.r); return }
  if (isClaiming.value) { handleClaimTile(e.q, e.r); return }
  inspectedArmy.value = null
  if (!canEdit.value) return
  playTileClick()
  onClickHex(e.q, e.r)
}

// Is this tile one of the current player's own CITIES? (farms don't count —
// units can only be bought in cities)
function isOwnBuildingTile(q: number, r: number): boolean {
  return buildings.value.some(b =>
    b.user_id === currentUserId.value && b.building_type !== 'farm' && b.q === q && b.r === r
  )
}

function handleClickArmy(armyId: number) {
  // Only allow interacting with armies during an active game
  if (loadedMapStatus.value?.mapStatus !== 'started') return
  const army = armies.value.find(a => a.id === armyId)
  if (!army) return

  if (army.user_id !== currentUserId.value) {
    // Enemy unit — a spy (or general vision) let us see it, so inspect its power.
    // Clicking the same enemy again closes the panel.
    inspectedArmy.value = inspectedArmy.value?.id === armyId ? null : army
    return
  }

  inspectedArmy.value = null
  if (selectedArmyId.value === armyId) {
    // Deselect
    selectedArmyId.value = null
    isMovingArmy.value = false
  } else {
    selectedArmyId.value = armyId
    isMovingArmy.value = true
    isClaiming.value = false
    isBuyingArmy.value = false
    isBuyingSpy.value = false
    isBuyingSettler.value = false
    isBuyingFarmer.value = false
  }
}

async function handleBuyArmy(q: number, r: number) {
  if (!loadedMapStatus.value) return
  if (!isOwnBuildingTile(q, r)) {
    showMsg('Armies can only be built on one of your cities')
    return
  }
  try {
    await buyArmy(loadedMapStatus.value.uid, q, r)
    isBuyingArmy.value = false
    showMsg('Army deployed!')
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot buy army') }
}

async function handleBuySpy(q: number, r: number) {
  if (!loadedMapStatus.value) return
  if (!isOwnBuildingTile(q, r)) {
    showMsg('Spies can only be built on one of your cities')
    return
  }
  try {
    await buySpy(loadedMapStatus.value.uid, q, r)
    isBuyingSpy.value = false
    showMsg('Spy deployed!')
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot buy spy') }
}

async function handleBuySettler(q: number, r: number) {
  if (!loadedMapStatus.value) return
  if (!isOwnBuildingTile(q, r)) {
    showMsg('Settlers can only be built on one of your cities')
    return
  }
  try {
    await buySettler(loadedMapStatus.value.uid, q, r)
    isBuyingSettler.value = false
    showMsg('Settler deployed!')
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot buy settler') }
}

async function handleBuyFarmer(q: number, r: number) {
  if (!loadedMapStatus.value) return
  if (!isOwnBuildingTile(q, r)) {
    showMsg('Farmers can only be built on one of your cities')
    return
  }
  try {
    await buyFarmer(loadedMapStatus.value.uid, q, r)
    isBuyingFarmer.value = false
    showMsg('Farmer deployed!')
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot buy farmer') }
}

async function handleFoundFarm(unitId: number) {
  if (!loadedMapStatus.value) return
  try {
    await foundFarm(loadedMapStatus.value.uid, unitId)
    selectedArmyId.value = null
    isMovingArmy.value = false
    showMsg('Farm built!')
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot build a farm here') }
}

async function handleFoundCity(unitId: number) {
  if (!loadedMapStatus.value) return
  try {
    await foundCity(loadedMapStatus.value.uid, unitId)
    selectedArmyId.value = null
    isMovingArmy.value = false
    showMsg('City founded!')
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot found city here') }
}

async function handleMoveArmy(q: number, r: number) {
  if (!loadedMapStatus.value || selectedArmyId.value === null) return

  // If a friendly army already sits on the target tile, offer to merge instead of moving
  const selected = armies.value.find(a => a.id === selectedArmyId.value)
  if (selected && selected.unit_type === 'army') {
    const friendly = armies.value.find(a =>
      a.id !== selected.id && a.user_id === currentUserId.value && a.unit_type === 'army' && a.q === q && a.r === r
    )
    if (friendly) {
      pendingMerge.value = {
        sourceId: selected.id,
        sourcePower: selected.power,
        targetId: friendly.id,
        targetPower: friendly.power,
        q, r,
      }
      return
    }
  }

  try {
    const { combat } = await moveArmy(loadedMapStatus.value.uid, selectedArmyId.value, q, r)
    if (combat) {
      playBattle()
      const won = combat.result === 'attacker_wins'
      const myFaction    = allPlayerSetups.value.find(s => s.user_id === currentUserId.value)?.faction ?? 'You'
      const enemyFaction = allPlayerSetups.value.find(s => s.user_id !== currentUserId.value)?.faction ?? 'Enemy'
      const yourRoll   = won ? combat.attacker_roll  : combat.defender_roll
      const enemyRoll  = won ? combat.defender_roll  : combat.attacker_roll
      const yourTotal  = won ? combat.attacker_total : combat.defender_total
      const enemyTotal = won ? combat.defender_total : combat.attacker_total
      if (combat.city_combat) {
        if (won) {
          showPopIn(`🏰 ${myFaction} razed ${enemyFaction}'s city! +${yourRoll} (${yourTotal}) vs +${enemyRoll} (${enemyTotal}) — Power left: ${combat.winner_power}`, 'battle')
        } else {
          showPopIn(`🏰 ${enemyFaction}'s city repelled ${myFaction}! +${enemyRoll} (${enemyTotal}) vs +${yourRoll} (${yourTotal}) — Your army was destroyed.`, 'battle')
          selectedArmyId.value = null
          isMovingArmy.value = false
        }
      } else if (won) {
        showPopIn(`⚔️ ${myFaction} defeated ${enemyFaction}! +${yourRoll} (${yourTotal}) vs +${enemyRoll} (${enemyTotal}) — Power left: ${combat.winner_power}`, 'battle')
      } else {
        showPopIn(`💀 ${enemyFaction} repelled ${myFaction}! +${enemyRoll} (${enemyTotal}) vs +${yourRoll} (${yourTotal}) — Your army was destroyed.`, 'battle')
        selectedArmyId.value = null
        isMovingArmy.value = false
      }
    } else {
      showMsg('Army moved!')
    }
    // Check if a player was auto-eliminated after the move
    if (loadedMapStatus.value?.mapStatus === 'ended') {
      showPopIn('🏁 The game has ended — all players have been eliminated or resigned.', 'battle')
    }
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot move army') }
}

async function confirmMergeArmy() {
  if (!loadedMapStatus.value || !pendingMerge.value) return
  const { sourceId, q, r } = pendingMerge.value
  try {
    await mergeArmy(loadedMapStatus.value.uid, sourceId, q, r)
    showMsg('Armies merged!')
    selectedArmyId.value = null
    isMovingArmy.value = false
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot merge armies') }
  pendingMerge.value = null
}

async function handleClaimTile(q: number, r: number) {
  if (!loadedMapStatus.value) return
  const hex = hexes.value.find(h => h.q === q && h.r === r)
  if (hex?.terrain === 'water') { showMsg('Water tiles cannot be claimed'); return }
  try {
    await claimTile(loadedMapStatus.value.uid, q, r)
    playTileCapture()
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot claim tile') }
}

function handleHoverHex(e: { e: MouseEvent; q: number; r: number }) {
  if (!canEdit.value) return
  onHoverHex(e.e, e.q, e.r)
}

async function handleSaveSetup(setup: import('@/composables/useMapIO').PlayerSetup) {
  if (!loadedMapStatus.value) return
  try { await savePlayerSetup(loadedMapStatus.value.uid, setup) }
  catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error saving setup') }
}

async function handleEndTurn() {
  if (!loadedMapStatus.value) return
  try { await endTurn(loadedMapStatus.value.uid) }
  catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error advancing turn') }
}

async function handleSendChat(text: string) {
  if (!loadedMapStatus.value) return
  try { await sendChat(loadedMapStatus.value.uid, text) }
  catch { showMsg('Failed to send message') }
}

async function handleRefreshMap() {
  if (!loadedMapStatus.value) return
  try {
    await refreshPlayers()
  } catch { showMsg('Error refreshing map') }
}

async function handleJoinMap() {
  if (!loadedMapStatus.value) return
  try {
    await requestJoinMap(loadedMapStatus.value.uid)
    playJoinRequest()
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error sending request') }
}

async function handleApprove(map_uid: string, user_id: number) {
  try { await approveRequest(map_uid, user_id) }
  catch { showMsg('Error approving request') }
}

async function handleDeny(map_uid: string, user_id: number) {
  try { await denyRequest(map_uid, user_id) }
  catch { showMsg('Error denying request') }
}

onMounted(async () => {
  countdownInterval = setInterval(() => { nowTick.value = Math.floor(Date.now() / 1000) }, 1000)
  await checkAuth()

  const params = new URLSearchParams(window.location.search)
  const uid = params.get('uid')

  if (appMode === 'game') {
    if (uid) {
      await handleLoadFromServer(uid)
    }
  } else {
    buildMapRandom()
  }
})

onUnmounted(() => {
  if (autoStartPollInterval) clearInterval(autoStartPollInterval)
})
</script>

<template>
  <!-- Root must be position:relative so fixed children anchor to viewport, not a transform ancestor -->
  <div class="hex-app">
    <MapToolbar
      :selected-size="selectedSize"
      :save-msg="saveMsg"
      :image-loaded="imageLoaded"
      :zoom="zoom"
      :is-advanced-player="isAdvancedPlayer"
      :can-edit="canEdit && appMode === 'create'"
      :can-finish="canFinish"
      :can-end-turn="canEndTurn"
      :can-resign="canResign"
      :can-force-start="canForceStart"
      :turn-done="myTurnDone"
      :hexturn="loadedMapStatus?.hexturn ?? 0"
      :map-status="mapStatus"
      :map-loaded="loadedMapStatus !== null"
      :map-players="loadedMapStatus?.players ?? []"
      @regenerate="buildMapRandom"
      @reset-view="resetView"
      @zoom-in="zoomIn"
      @zoom-out="zoomOut"
      @download-map="downloadMap(hexes, getCols(), getRows(), selectedSize)"
      @save-to-server="handleSaveToServer"
      @load-map="handleLoadMap"
      @load-from-server="handleLoadFromServer"
      @load-image="handleLoadImage"
      @size-change="handleSizeChange"
      @finish-map="showFinishConfirm = true"
      @end-turn="handleEndTurn"
      @resign="showResignConfirm = true"
      @force-start="showForceStartConfirm = true"
      @refresh-map="handleRefreshMap"
    />

    <div class="workspace">
      <MapSidebar
        :active-terrain="activeTerrain"
        :selected-q="selectedQ"
        :selected-r="selectedR"
        :selected-terrain-label="getSelectedTerrain()"
        :image-loaded="imageLoaded"
        :selected-size="selectedSize"
        :procedural-settings="proceduralSettings"
        :user-maps="filteredMaps"
        :is-logged-in="isLoggedIn"
        :is-advanced-player="canEdit && appMode === 'create'"
        :map-players="loadedMapStatus?.players ?? []"
        :map-status="loadedMapStatus?.mapStatus ?? ''"
        :chat-messages="chatMessages"
        :current-user-id="currentUserId"
        :all-player-setups="allPlayerSetups"
        :hexes="hexes"
        @terrain-change="handleTerrainChange"
        @generate="buildMapRandom"
        @reset-procedural="resetProceduralSettings"
        @size-change="handleSizeChange"
        @load-map="handleLoadFromServer"
        @delete-map="handleDeleteMap"
        @update:procedural-settings="handleUpdateProceduralSettings"
      />

      <!-- Canvas area — no backdrop-filter, no transform, so fixed children work -->
      <div class="canvas-wrapper" :style="playerBorderColor ? { borderColor: playerBorderColor } : {}">
        <MapCanvas
          :hexes="hexes"
          :selected-q="selectedQ"
          :selected-r="selectedR"
          :cols="getCols()"
          :rows="getRows()"
          :zoom="zoom"
          :pan-x="panX"
          :pan-y="panY"
          :selected-border-color="playerBorderColor ?? '#FFD700'"
          :player-setups="allPlayerSetups"
          :owned-tiles="ownedTiles"
          :armies="armies"
          :buildings="buildings"
          :claiming-mode="isClaiming"
          :selected-army-id="selectedArmyId"
          :moving-mode="isMovingArmy"
          :buying-army-mode="isBuyingArmy"
          :buying-spy-mode="isBuyingSpy"
          :buying-settler-mode="isBuyingSettler"
          :buying-farmer-mode="isBuyingFarmer"
          :current-user-id="currentUserId"
          :back-vis = "backVis"
          :map-status="loadedMapStatus?.mapStatus"
          @click-hex="handleClickHex"
          @click-army="e => handleClickArmy(e.armyId)"
          @hover-hex="handleHoverHex"
          @wheel="onWheel"
          @mousedown="onMouseDown"
        />

        <!-- Ended overlay — inside canvas-wrapper, uses rgba NOT backdrop-filter -->
        <div v-if="showEndedOverlay" class="ended-overlay">
          <div class="ended-overlay__box">
            <div class="ended-overlay__icon">🏆</div>
            <div class="ended-overlay__title">Game Over</div>
            <div class="ended-overlay__sub">{{ loadedMapName }} — this campaign has ended.</div>
            <div class="ended-overlay__winner">🎖 Winner: <strong>{{ winner }}</strong></div>
            <button class="ended-overlay__close" @click="hideEndedOverlay = true">Watch the map ›</button>
          </div>
        </div>

        <!-- Directional pad — pans the map without needing mouse-drag -->
        <div class="dpad">
          <button class="dpad__btn dpad__btn--up" title="Pan up" @click="panBy(0, 1)">▲</button>
          <button class="dpad__btn dpad__btn--left" title="Pan left" @click="panBy(1, 0)">◀</button>
          <button class="dpad__btn dpad__btn--center" title="Recenter" @click="resetView">●</button>
          <button class="dpad__btn dpad__btn--right" title="Pan right" @click="panBy(-1, 0)">▶</button>
          <button class="dpad__btn dpad__btn--down" title="Pan down" @click="panBy(0, -1)">▼</button>
        </div>
      </div>
      <MapRightBar
        v-if="showRightBar"
        :map-status="loadedMapStatus?.mapStatus ?? ''"
        :setup-locked="setupLocked"
        :selected-hex="isSelectingCity ? (selectedQ !== null && selectedR !== null ? { q: selectedQ, r: selectedR } : null) : null"
        :existing-setup="mySetup"
        :is-selecting-city="isSelectingCity"
        :all-player-setups="allPlayerSetups"
        :buildings="buildings"
        :is-claiming="isClaiming"
        :is-buying-army="isBuyingArmy"
        :is-buying-spy="isBuyingSpy"
        :is-buying-settler="isBuyingSettler"
        :is-buying-farmer="isBuyingFarmer"
        :is-moving-army="isMovingArmy"
        :selected-army="isMovingArmy && selectedArmyId ? armies.find(a => a.id === selectedArmyId) ?? null : null"
        :inspected-army="inspectedArmy"
        :city-tile-problem="cityTileProblem"
        :can-found-city="foundBlockReason.city === ''"
        :can-found-farm="foundBlockReason.farm === ''"
        :found-city-hint="foundBlockReason.city"
        :found-farm-hint="foundBlockReason.farm"
        @rename-army="(id, name) => loadedMapStatus && renameArmy(loadedMapStatus.uid, id, name)"
        @upgrade-army="(id: number, res: number) => loadedMapStatus && upgradeArmy(loadedMapStatus.uid, id, res).catch((e: Error) => showMsg(e.message))"
        @found-city="(id: number) => handleFoundCity(id)"
        @found-farm="(id: number) => handleFoundFarm(id)"
        @save-setup="handleSaveSetup"
        @start-city-select="isSelectingCity = true"
        @cancel-city-select="isSelectingCity = false"
        @toggle-claim="isClaiming = !isClaiming; isMovingArmy = false; selectedArmyId = null; inspectedArmy = null"
        @toggle-buy-army="isBuyingArmy = !isBuyingArmy; isBuyingSpy = false; isBuyingSettler = false; isBuyingFarmer = false; isMovingArmy = false; selectedArmyId = null; inspectedArmy = null"
        @toggle-buy-spy="isBuyingSpy = !isBuyingSpy; isBuyingArmy = false; isBuyingSettler = false; isBuyingFarmer = false; isMovingArmy = false; selectedArmyId = null; inspectedArmy = null"
        @toggle-buy-settler="isBuyingSettler = !isBuyingSettler; isBuyingArmy = false; isBuyingSpy = false; isBuyingFarmer = false; isMovingArmy = false; selectedArmyId = null; inspectedArmy = null"
        @toggle-buy-farmer="isBuyingFarmer = !isBuyingFarmer; isBuyingArmy = false; isBuyingSpy = false; isBuyingSettler = false; isMovingArmy = false; selectedArmyId = null; inspectedArmy = null"
        @cancel-move-army="isMovingArmy = false; selectedArmyId = null"
        @close-inspect-army="inspectedArmy = null"
        @color-change="playerBorderColor = $event"
      />
    </div>

    <!-- Waiting for other players banner -->
    <Transition name="popin">
      <div v-if="waitingForPlayers" class="autostart-banner">
        ⏳ Waiting for other players to choose their starting point...
      </div>
    </Transition>

    <!-- Auto-start countdown banner -->
    <Transition name="popin">
      <div v-if="autoStartSecondsLeft !== null" class="autostart-banner">
        All players have selected a starting point. Game will start in:
        <strong class="autostart-count">{{ autoStartSecondsLeft }}s</strong>
      </div>
    </Transition>

    <!-- ═══ All fixed pop-ins live here, at root level, outside canvas-wrapper ═══ -->

    <!-- UID modal -->
    <UidModal
      v-if="showUidModal"
      :hexmap_uid="lastHexmapUid"
      :copied="uidCopied"
      @close="showUidModal = false"
      @copy="copyUidToClipboard"
    />

    <!-- Save modal -->
    <SaveModal
      v-if="showSaveModal"
      @confirm="handleConfirmSave"
      @cancel="showSaveModal = false"
    />
    <ChatBox
      v-if="loadedMapStatus && loadedMapStatus?.mapStatus !== 'created' && loadedMapStatus?.players.length > 0"
      :messages="chatMessages"
      :current-user-id="currentUserId"
      :popped="true"
      @send="handleSendChat"
    />

    <!-- Delete confirm -->
    <div v-if="pendingDeleteUid" class="modal-overlay" @click.self="pendingDeleteUid = null">
      <div class="confirm-modal">
        <p>Delete map <strong>{{ pendingDeleteUid }}</strong>?</p>
        <div class="confirm-actions">
          <button class="btn-danger" @click="confirmDelete">🗑️ Delete</button>
          <button class="btn-cancel" @click="pendingDeleteUid = null">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Validate confirm -->
    <div v-if="showFinishConfirm" class="modal-overlay" @click.self="showFinishConfirm = false">
      <div class="confirm-modal">
        <p>Valdiating will cost<strong>100</strong> Credits</p>
        <p>This cannot be undone <strong>lock</strong> this map permanently?</p>
        <p class="confirm-hint">Players will be able to join.</p>
        <div class="confirm-actions">
          <button class="btn-confirm" @click="confirmFinish">✅ Validate</button>
          <button class="btn-cancel" @click="showFinishConfirm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Force start confirm -->
    <div v-if="showForceStartConfirm" class="modal-overlay" @click.self="showForceStartConfirm = false">
      <div class="confirm-modal">
        <p>Force start <strong>{{ loadedMapName }}</strong>?</p>
        <p class="confirm-hint">Players without a starting city will be automatically resigned.</p>
        <div class="confirm-actions">
          <button class="btn-confirm" @click="confirmForceStart">▶ Force Start</button>
          <button class="btn-cancel" @click="showForceStartConfirm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Resign confirm -->
    <div v-if="showResignConfirm" class="modal-overlay" @click.self="showResignConfirm = false">
      <div class="confirm-modal">
        <p>Resign from <strong>{{ loadedMapName }}</strong>?</p>
        <p class="confirm-hint">You will forfeit all your territory. If all players resign, the game ends.</p>
        <div class="confirm-actions">
          <button class="btn-danger" @click="confirmResign">🏳 Resign</button>
          <button class="btn-cancel" @click="showResignConfirm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Merge army confirm -->
    <div v-if="pendingMerge" class="modal-overlay" @click.self="pendingMerge = null">
      <div class="confirm-modal">
        <p>Merge with your friendly army here?</p>
        <p class="confirm-hint">
          ⚔️ {{ pendingMerge.sourcePower }} + ⚔️ {{ pendingMerge.targetPower }} → ⚔️ {{ pendingMerge.sourcePower + pendingMerge.targetPower }}
        </p>
        <div class="confirm-actions">
          <button class="btn-confirm" @click="confirmMergeArmy">🤝 Merge</button>
          <button class="btn-cancel" @click="pendingMerge = null">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Join banner — fixed, at root level -->
    <JoinMapBanner
      v-if="showJoinBanner"
      :map-name="loadedMapName"
      :is-pending="loadedMapStatus?.is_pending ?? false"
      :map-status="loadedMapStatus?.mapStatus ?? ''"
      @join="handleJoinMap"
    />

    <!-- Requests panel — fixed, at root level -->
    <JoinRequestsPanel
      v-if="isOwner && joinRequests.length > 0"
      :requests="joinRequests"
      @approve="handleApprove"
      @deny="handleDeny"
    />
  </div>

  <!-- Pop-in notification -->
  <Transition name="popin">
    <div v-if="popIn" :class="['map-popin', `map-popin--${popIn.type}`]">
      <span>{{ popIn.msg }}</span>
      <button class="map-popin__close" @click="popIn = null">✕</button>
    </div>
  </Transition>
</template>

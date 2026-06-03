<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
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
  userMaps, isLoggedIn, userRole, credits, joinRequests, loadedMapStatus, playerSetup, allPlayerSetups, ownedTiles, armies, 
  showMsg, checkAuth, downloadMap, saveToServer,showPopIn,
  loadFromServer, deleteFromServer, finishMap, startMap, endMap, endTurn, claimTile, buyArmy, moveArmy, renameArmy,
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

const canStart = computed(() => {
  if (!loadedMapStatus.value?.is_owner) return false
  if (loadedMapStatus.value.mapStatus !== 'ongoing') return false
  const players = loadedMapStatus.value.players
  if (players.length <= 1) return false  // need at least one other player
  return players.every(p => allPlayerSetups.value.some(s => s.user_id === p.user_id))
})

const canEnd = computed(() =>
  isAdvancedPlayer.value &&
  loadedMapStatus.value !== null &&
  loadedMapStatus.value.is_owner &&
  (loadedMapStatus.value.mapStatus === 'ongoing' || loadedMapStatus.value.mapStatus === 'started')
)
const canEndTurn = computed(() =>
  loadedMapStatus.value?.mapStatus === 'started' &&
  (loadedMapStatus.value.is_owner || loadedMapStatus.value.is_linked)
)

const myTurnDone = computed(() => mySetup.value?.turn_done ?? false)

const showRightBar = computed(() =>
  loadedMapStatus.value?.mapStatus === 'ongoing' || loadedMapStatus.value?.mapStatus === 'started'
)

const appMode = (window as any).hexcommandMode ?? 'game'

const filteredMaps = computed(() =>
  appMode === 'create'
    ? userMaps.value.filter(m => m.mapStatus === 'created')
    : userMaps.value.filter(m => ['ongoing', 'started', 'ended'].includes(m.mapStatus!))
)
const setupLocked = computed(() =>
  loadedMapStatus.value?.mapStatus === 'started' || loadedMapStatus.value?.mapStatus === 'ended'
)

// Join banner: map is ongoing (validated), not owner, not linked/pending
const showJoinBanner = computed(() =>
  loadedMapStatus.value !== null &&
  !loadedMapStatus.value.is_owner &&
  !loadedMapStatus.value.is_linked
)

// Ended overlay: anyone loading an ended map
const showEndedOverlay = computed(() =>
  loadedMapStatus.value !== null && loadedMapStatus.value.mapStatus ==='ended'
)

// Map status label for toolbar
const mapStatus = computed(() => {
  const s = loadedMapStatus.value
  if (!s) return null
  if (s.mapStatus === 'ended')   return { label: '💀 Game Ended',   cls: 'status-ended' }
  if (s.mapStatus === 'started') return { label: '⚔️ Game Started', cls: 'status-started' }
  if (s.mapStatus === 'ongoing') return { label: '🔒 Map Locked',   cls: 'status-locked' }
  if (s.mapStatus === 'created') return { label: '✏️ In Progress',  cls: 'status-ongoing' }
  return null
})

const loadedMapName     = ref('')
const showSaveModal     = ref(false)
const pendingDeleteUid  = ref<string | null>(null)
const showFinishConfirm = ref(false)
const showStartConfirm  = ref(false)
const showEndConfirm    = ref(false)

const isSelectingCity = ref(false)
const isClaiming      = ref(false)
const isBuyingArmy    = ref(false)
const isMovingArmy    = ref(false)
const selectedArmyId  = ref<number | null>(null)
// Current user's full setup (with actions) from allPlayerSetups
const mySetup = computed(() => {
  if (!currentUserId.value) return null
  const found = allPlayerSetups.value.find(s => s.user_id === currentUserId.value)
  return found ? { ...found } : null
})
const playerBorderColor = ref<string | null>(null)

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

async function confirmStart() {
  if (!loadedMapStatus.value) return
  try { await startMap(loadedMapStatus.value.uid) }
  catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error starting game') }
  showStartConfirm.value = false
}

async function confirmFinish() {
  if (!loadedMapStatus.value) return
  try { await finishMap(loadedMapStatus.value.uid) }
  catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error validating map') }
  showFinishConfirm.value = false
}

async function confirmEnd() {
  if (!loadedMapStatus.value) return
  try { await endMap(loadedMapStatus.value.uid) }
  catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Error ending game') }
  showEndConfirm.value = false
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
  if (isSelectingCity.value) { selectHex(e.q, e.r); return }
  if (isMovingArmy.value && selectedArmyId.value !== null) { handleMoveArmy(e.q, e.r); return }
  if (isBuyingArmy.value) { handleBuyArmy(e.q, e.r); return }
  if (isClaiming.value) { handleClaimTile(e.q, e.r); return }
  if (!canEdit.value) return
  playTileClick()
  onClickHex(e.q, e.r)
}

function handleClickArmy(armyId: number) {
  // Only allow selecting own armies during game
  if (loadedMapStatus.value?.mapStatus !== 'started') return
  const army = armies.value.find(a => a.id === armyId)
  if (!army || army.user_id !== currentUserId.value) return
  if (selectedArmyId.value === armyId) {
    // Deselect
    selectedArmyId.value = null
    isMovingArmy.value = false
  } else {
    selectedArmyId.value = armyId
    isMovingArmy.value = true
    isClaiming.value = false
    isBuyingArmy.value = false
  }
}

async function handleBuyArmy(q: number, r: number) {
  if (!loadedMapStatus.value) return
  const myCity = allPlayerSetups.value.find(s => s.user_id === currentUserId.value)
  if (!myCity || myCity.city_q !== q || myCity.city_r !== r) {
    showMsg('Armies can only be built on your city tile')
    return
  }
  try {
    await buyArmy(loadedMapStatus.value.uid, q, r)
    isBuyingArmy.value = false
    showMsg('Army deployed!')
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot buy army') }
}

async function handleMoveArmy(q: number, r: number) {
  if (!loadedMapStatus.value || selectedArmyId.value === null) return
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
      if (won) {
        showPopIn(`⚔️ ${myFaction} defeated ${enemyFaction}! +${yourRoll} (${yourTotal}) vs +${enemyRoll} (${enemyTotal}) — Power left: ${combat.winner_power}`, 'battle')
      } else {
        showPopIn(`💀 ${enemyFaction} repelled ${myFaction}! +${enemyRoll} (${enemyTotal}) vs +${yourRoll} (${yourTotal}) — Your army was destroyed.`, 'battle')
        selectedArmyId.value = null
        isMovingArmy.value = false
      }
    } else {
      showMsg('Army moved!')
    }
  } catch (err: unknown) { showMsg(err instanceof Error ? err.message : 'Cannot move army') }
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
      :can-start="canStart"
      :can-end="canEnd"
      :can-end-turn="canEndTurn"
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
      @start-game="showStartConfirm = true"
      @end-turn="handleEndTurn"
      @end-game="showEndConfirm = true"
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
          :claiming-mode="isClaiming"
          :selected-army-id="selectedArmyId"
          :moving-mode="isMovingArmy"
          :buying-army-mode="isBuyingArmy"
          :current-user-id="currentUserId"
          :back-vis = "backVis"
          @click-hex="handleClickHex"
          @click-army="e => handleClickArmy(e.armyId)"
          @hover-hex="handleHoverHex"
          @wheel="onWheel"
          @mousedown="onMouseDown"
        />

        <!-- Ended overlay — inside canvas-wrapper, uses rgba NOT backdrop-filter -->
        <div v-if="showEndedOverlay" class="ended-overlay">
          <div class="ended-overlay__box">
            <div class="ended-overlay__icon">⚔️</div>
            <div class="ended-overlay__title">Game Over</div>
            <div class="ended-overlay__sub">{{ loadedMapName }} — this campaign has ended.</div>
          </div>
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
        :is-claiming="isClaiming"
        :is-buying-army="isBuyingArmy"
        :is-moving-army="isMovingArmy"
        :selected-army="isMovingArmy && selectedArmyId ? armies.find(a => a.id === selectedArmyId) ?? null : null"
        @rename-army="(id, name) => loadedMapStatus && renameArmy(loadedMapStatus.uid, id, name)"
        @save-setup="handleSaveSetup"
        @start-city-select="isSelectingCity = true"
        @cancel-city-select="isSelectingCity = false"
        @toggle-claim="isClaiming = !isClaiming; isMovingArmy = false; selectedArmyId = null"
        @toggle-buy-army="isBuyingArmy = !isBuyingArmy; isMovingArmy = false; selectedArmyId = null"
        @cancel-move-army="isMovingArmy = false; selectedArmyId = null"
        @color-change="playerBorderColor = $event"
      />
    </div>

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
      v-if="loadedMapStatus"
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
        <p>Validate and <strong>lock</strong> this map permanently?</p>
        <p class="confirm-hint">This cannot be undone. Players will be able to join.</p>
        <div class="confirm-actions">
          <button class="btn-confirm" @click="confirmFinish">✅ Validate</button>
          <button class="btn-cancel" @click="showFinishConfirm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- Start game confirm -->
    <div v-if="showStartConfirm" class="modal-overlay" @click.self="showStartConfirm = false">
      <div class="confirm-modal">
        <p>Start the game for <strong>{{ loadedMapName }}</strong>?</p>
        <p class="confirm-hint">All players have chosen their starting city. This cannot be undone.</p>
        <div class="confirm-actions">
          <button class="btn-confirm" @click="confirmStart">⚔️ Start Game</button>
          <button class="btn-cancel" @click="showStartConfirm = false">Cancel</button>
        </div>
      </div>
    </div>

    <!-- End game confirm -->
    <div v-if="showEndConfirm" class="modal-overlay" @click.self="showEndConfirm = false">
      <div class="confirm-modal">
        <p>End the game for <strong>{{ loadedMapName }}</strong>?</p>
        <p class="confirm-hint">The map will be archived and marked as ended for all players.</p>
        <div class="confirm-actions">
          <button class="btn-danger" @click="confirmEnd">⚔️ End Game</button>
          <button class="btn-cancel" @click="showEndConfirm = false">Cancel</button>
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
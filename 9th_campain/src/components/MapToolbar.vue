<script setup lang="ts">
import { ref } from 'vue'
import { type MapSizeKey } from '@/composables/useHexMap'
import type {MapPlayer } from '@/composables/useMapIO'

const props = defineProps<{
  selectedSize: MapSizeKey
  saveMsg: string
  imageLoaded: boolean
  zoom: number
  isAdvancedPlayer: boolean
  canEdit: boolean
  canFinish: boolean
  canStart: boolean
  canEnd: boolean
  canEndTurn: boolean
  turnDone: boolean
  hexturn: number
  mapStatus: { label: string; cls: string } | null
  mapLoaded: boolean
  mapPlayers: MapPlayer[]
}>()

const emit = defineEmits<{
  regenerate: []
  resetView: []
  downloadMap: []
  saveToServer: []
  finishMap: []
  startGame: []
  endGame: []
  zoomIn: []
  zoomOut: []
  loadMap: [file: File]
  loadFromServer: [uid: string]
  loadImage: [file: File]
  sizeChange: [size: MapSizeKey]
  refreshMap: []
  endTurn: []
  forceEndTurn: []
}>()

const jsonFileInput  = ref<HTMLInputElement | null>(null)
const imageFileInput = ref<HTMLInputElement | null>(null)
const uidInput       = ref('')
const showUidLoad    = ref(false)
var allowSkipTurn:boolean    = ref( false)

props.mapPlayers.forEach(element => { if (Date.now() / 1000 - element.last_seen >=  72000){
  allowSkipTurn = true
})

function onJsonChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) emit('loadMap', file)
  ;(e.target as HTMLInputElement).value = ''
}

function onImageChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) emit('loadImage', file)
  ;(e.target as HTMLInputElement).value = ''
}

function submitUidLoad() {
  if (uidInput.value.trim()) {
    emit('loadFromServer', uidInput.value.trim())
    uidInput.value = ''
    showUidLoad.value = false
  }
}
</script>

<template>
  <header class="toolbar">
    <div class="toolbar-title"><img fetchpriority="high" src="https://dominion.aerolith-models.com/wp-content/uploads/2026/03/9_dominion_logo_full.png" class="neve-site-logo skip-lazy" alt="" data-variant="logo" decoding="async" srcset="https://dominion.aerolith-models.com/wp-content/uploads/2026/03/9_dominion_logo_full.png 1867w, https://dominion.aerolith-models.com/wp-content/uploads/2026/03/9_dominion_logo_full-300x278.png 300w, https://dominion.aerolith-models.com/wp-content/uploads/2026/03/9_dominion_logo_full-1024x948.png 1024w, https://dominion.aerolith-models.com/wp-content/uploads/2026/03/9_dominion_logo_full-768x711.png 768w, https://dominion.aerolith-models.com/wp-content/uploads/2026/03/9_dominion_logo_full-1536x1422.png 1536w"/><span>IX Dominion</span></div>

    <!-- Zoom -->
    <div class="btn-group">
      <div class="title">Zoom</div>
      <div class="zoom-row">
        <button @click="emit('zoomOut')">−</button>
        <span>{{ Math.round(zoom * 100) }}%</span>
        <button @click="emit('zoomIn')">+</button>
      </div>
    </div>

    <!-- Map status pill — updates reactively when a map is loaded -->
    <div v-if="mapStatus" class="map-status-pill" :class="mapStatus.cls">
      {{ mapStatus.label }}
    </div>

    <div class="toolbar-actions">

      <!-- Editing actions — only when canEdit -->
      <template v-if="canEdit">
        <button class="btn-primary" @click="imageFileInput?.click()">🗺️ Load Image</button>
        <div class="btn-group">
          <button @click="emit('downloadMap')">💾 Download</button>
          <button @click="emit('saveToServer')">☁️ Save Online</button>
        </div>
      </template>

      <!-- Validate — owner, not yet finished -->
      <button v-if="canFinish" class="btn-finish" @click="emit('finishMap')">
        ✅ Validate Map
      </button>

      <!-- End Game — owner, finished, not yet ended -->
      <button v-if="canStart" class="btn-start" @click="emit('startGame')">
        ⚔️ Start Game
      </button>
      <template v-if="canEndTurn">
        <button v-if="!turnDone" class="btn-nextturn" @click="emit('endTurn')" :title="`Current turn: ${hexturn}`">
          ⏹ End Turn <span class="turn-badge">{{ hexturn }}</span>
        </button>
        <span v-else class="turn-waiting">⏳ Waiting… <span class="turn-badge">{{ hexturn }}</span></span>
      </template>
      <button v-if="allowSkipTurn && canEndTurn" class="btn-nextturn" @click="emit('forceEndTurn')" :title="`Current turn: ${hexturn}`">
        ⏹ Force Next Turn <span class="turn-badge">{{ hexturn }}</span>
      </button>
      
      <button v-if="canEnd" class="btn-end" @click="emit('endGame')">
        💀 End Game
      </button>

      <!-- Load options -->
      <div class="btn-group">
        <button v-if="canEdit" @click="jsonFileInput?.click()">📂 Load File</button>
        <button @click="showUidLoad = !showUidLoad" :class="{ active: showUidLoad }">🔑 Load by UID</button>
      </div>

      <button @click="emit('resetView')">⌖ Center View</button>
      <button v-if="mapLoaded" @click="emit('refreshMap')" title="Refresh map data">🔄 Refresh</button>
    </div>

    <!-- UID load bar -->
    <div v-if="showUidLoad" class="uid-load-bar">
      <span>Enter map UID:</span>
      <input
        v-model="uidInput"
        class="uid-input"
        placeholder="e.g. A1B2C3D4"
        maxlength="8"
        @keydown.enter="submitUidLoad"
      />
      <button class="btn-go" @click="submitUidLoad">Load →</button>
      <button class="btn-cancel" @click="showUidLoad = false">✕</button>
    </div>

    <div v-if="saveMsg" class="save-msg">{{ saveMsg }}</div>

    <input ref="jsonFileInput"  type="file" accept=".json"   style="display:none" @change="onJsonChange" />
    <input ref="imageFileInput" type="file" accept="image/*" style="display:none" @change="onImageChange" />
  </header>
</template>
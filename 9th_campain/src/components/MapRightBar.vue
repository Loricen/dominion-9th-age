<script lang="ts">
// Defined outside setup so Vue never tries to make them reactive
const FACTIONS = Object.freeze([
  { id: 'equitaine', label: 'Kingdom of Equitaine' },
  { id: 'dread',     label: 'Dread Elves'          },
  { id: 'dwarven',   label: 'Dwarven Holds'         },
  { id: 'vermin',    label: 'Vermin Swarm'          },
  { id: 'daemon',    label: 'Daemon Legions'        },
  { id: 'sonnstahl', label: 'Empire of Sonnstahl'   },
])

const BORDER_COLORS = Object.freeze([
  '#ffba00', '#7bea27', '#4fb678', '#df4a4a',
  '#88b5f1', '#8831ac', '#df4adf', '#1eebeb',
  '#eeeeee', '#ff8c00',
])
</script>

<script setup lang="ts">
import { ref, computed } from 'vue'
import type { PlayerSetup, PlayerSetupWithId } from '@/composables/useMapIO'

const props = defineProps<{
  mapStatus: string
  selectedHex: { q: number; r: number } | null
  existingSetup: PlayerSetupWithId | null
  isSelectingCity: boolean
  allPlayerSetups: PlayerSetupWithId[]
  setupLocked: boolean
  isClaiming: boolean
}>()

const emit = defineEmits<{
  saveSetup: [setup: import('@/composables/useMapIO').PlayerSetup]
  startCitySelect: []
  cancelCitySelect: []
  colorChange: [color: string]
  toggleClaim: []
}>()

const selectedFaction  = ref(props.existingSetup?.faction   ?? '')
const selectedColor    = ref<string>(props.existingSetup?.color ?? '#ffba00')
const cityQ            = ref(props.existingSetup?.city_q    ?? null as number | null)
const cityR            = ref(props.existingSetup?.city_r    ?? null as number | null)

const cityLabel = computed(() => {
  if (cityQ.value !== null && cityR.value !== null)
    return `(${cityQ.value}, ${cityR.value})`
  return 'None selected'
})

const isLocked = computed(() => props.mapStatus === 'started' || props.mapStatus === 'ended')
const myActions = computed(() => props.existingSetup?.actions ?? 0)
const actionsMax = 10

const takenColors = computed(() =>
  props.allPlayerSetups
    .filter(s => s.color !== props.existingSetup?.color)  // exclude own current color
    .map(s => s.color)
)

const canSave = computed(() =>
  selectedFaction.value !== '' &&
  cityQ.value !== null &&
  cityR.value !== null
)

function confirmCity() {
  if (!props.selectedHex) return
  cityQ.value = props.selectedHex.q
  cityR.value = props.selectedHex.r
  emit('cancelCitySelect')
}

function save() {
  if (!canSave.value) return
  emit('saveSetup', {
    faction: selectedFaction.value,
    color:   selectedColor.value,
    city_q:  cityQ.value!,
    city_r:  cityR.value!,
  })
}
</script>

<template>
  <aside class="rightbar">

    <!-- City selection mode overlay -->
    <div v-if="isSelectingCity" class="city-select-mode">
      <div class="city-select-icon">🏰</div>
      <p class="city-select-hint">Click a hex on the map to set your starting city</p>
      <div v-if="selectedHex" class="city-preview">
        Hover: ({{ selectedHex.q }}, {{ selectedHex.r }})
      </div>
      <div class="city-select-actions">
        <button class="btn-confirm" :disabled="!selectedHex" @click="confirmCity">✓ Confirm</button>
        <button class="btn-cancel" @click="emit('cancelCitySelect')">✕ Stop</button>
      </div>
    </div>

    <template v-else-if="setupLocked || isLocked">
      <div class="rightbar-title">⚔️ My Setup</div>
      <div v-if="existingSetup" class="rb-section">
        <div class="rb-label">Faction</div>
        <div class="setup-value">{{ FACTIONS.find(f => f.id === existingSetup?.faction)?.label ?? existingSetup?.faction }}</div>
      </div>
      <div v-if="existingSetup" class="rb-section">
        <div class="rb-label">Border Colour</div>
        <div class="color-preview">
          <span class="color-sample" :style="{ background: existingSetup.color }" />
          <span class="color-hex">{{ existingSetup.color }}</span>
        </div>
      </div>
      <div v-if="existingSetup" class="rb-section">
        <div class="rb-label">Starting City</div>
        <div class="setup-value city-coords set">🏰 ({{ existingSetup.city_q }}, {{ existingSetup.city_r }})</div>
      </div>

      <!-- Actions gauge -->
      <div class="rb-section">
        <div class="rb-label">Actions <span class="actions-count">{{ myActions }} / {{ actionsMax }}</span></div>
        <div class="actions-gauge">
          <div
            class="actions-fill"
            :style="{ width: (myActions / actionsMax * 100) + '%', background: myActions > 3 ? '#4adf8a' : myActions > 0 ? '#ffba00' : '#df4a4a' }"
          />
        </div>
        <button
          class="btn-claim"
          :class="{ active: isClaiming }"
          :disabled="myActions <= 0"
          @click="emit('toggleClaim')"
        >
          {{ isClaiming ? '✕ Cancel' : '⚔️ Claim Territory' }}
        </button>
      </div>
    </template>

    <template v-else>

      <!-- Header -->
      <div class="rightbar-title">⚔️ My Setup</div>

      <!-- Faction -->
      <div class="rb-section">
        <div class="rb-label">Faction</div>
        <div class="faction-grid">
          <button
            v-for="f in FACTIONS"
            :key="f.id"
            class="faction-btn"
            :class="{ active: selectedFaction === f.id }"
            :style="selectedFaction === f.id ? { borderColor: selectedColor, color: selectedColor, background: selectedColor + '22' } : {}"
            @click="selectedFaction = f.id"
          >
            <img :src="`/src/assets/img/${f.id}.svg`" :alt="f.label" class="faction-icon" />
            <span class="faction-name">{{ f.label }}</span>
          </button>
        </div>
      </div>

      <!-- Border color -->
      <div class="rb-section">
        <div class="rb-label">Border Colour</div>
        <div class="color-row">
          <button
            v-for="c in BORDER_COLORS"
            :key="c"
            class="color-swatch"
            :class="{ active: selectedColor === c, taken: takenColors.includes(c) }"
            :style="{ background: c, borderColor: selectedColor === c ? '#fff' : 'transparent' }"
            :disabled="takenColors.includes(c)"
            :title="takenColors.includes(c) ? 'Already taken by another player' : ''"
            @click="!takenColors.includes(c) && (selectedColor = c, emit('colorChange', c))"
          />
        </div>
        <div class="color-preview">
          <span class="color-sample" :style="{ background: selectedColor }" />
          <span class="color-hex">{{ selectedColor }}</span>
        </div>
      </div>

      <!-- Starting city -->
      <div class="rb-section">
        <div class="rb-label">Starting City</div>
        <div class="city-row">
          <span class="city-coords" :class="{ set: cityQ !== null }">
            {{ cityQ !== null ? `🏰 ${cityLabel}` : '— not set' }}
          </span>
          <button class="btn-pick-city" @click="emit('startCitySelect')">
            {{ cityQ !== null ? '✏️ Change' : '📍 Pick' }}
          </button>
        </div>
      </div>

      <!-- Save -->
      <button class="btn-save-setup" :disabled="!canSave" @click="save">
        {{ existingSetup ? '💾 Update Setup' : '💾 Save Setup' }}
      </button>
      <p v-if="!canSave" class="rb-hint">Select a faction and a starting city to save.</p>

    </template>
  </aside>
</template>
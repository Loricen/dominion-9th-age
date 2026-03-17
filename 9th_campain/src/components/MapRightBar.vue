<script setup lang="ts">
import { ref, computed } from 'vue'
import type { PlayerSetup } from '@/composables/useMapIO'

const FACTIONS = [
  { id: 'equitaine', label: 'Kingdom of Equitaine' },
  { id: 'dread',     label: 'Dread Elves'          },
  { id: 'dwarven',   label: 'Dwarven Holds'         },
  { id: 'vermin',    label: 'Vermin Swarm'          },
  { id: 'daemon',    label: 'Daemon Legions'        },
  { id: 'sonnstahl', label: 'Empire of Sonnstahl'   },
] as const

const BORDER_COLORS = [
  '#ffba00', '#7bea27', '#4fb678', '#df4a4a',
  '#88b5f1', '#8831ac', '#df4adf', '#1eebeb',
  '#eeeeee', '#ff8c00',
]

const props = defineProps<{
  mapStatus: string
  selectedHex: { q: number; r: number } | null
  existingSetup: PlayerSetup | null
  isSelectingCity: boolean
}>()

const emit = defineEmits<{
  saveSetup: [setup: PlayerSetup]
  startCitySelect: []
  cancelCitySelect: []
  colorChange: [color: string]
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
        <button class="btn-cancel" @click="emit('cancelCitySelect')">✕ Cancel</button>
      </div>
    </div>

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
            :class="{ active: selectedColor === c }"
            :style="{ background: c, borderColor: selectedColor === c ? '#fff' : 'transparent' }"
            @click="selectedColor = c; emit('colorChange', c)"
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

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
import { ref, computed, watch } from 'vue'
import type { PlayerSetup, PlayerSetupWithId, Building } from '@/composables/useMapIO'

const props = defineProps<{
  mapStatus: string
  selectedHex: { q: number; r: number } | null
  existingSetup: PlayerSetupWithId | null
  isSelectingCity: boolean
  allPlayerSetups: PlayerSetupWithId[]
  buildings?: Building[]
  setupLocked: boolean
  isClaiming: boolean
  isBuyingArmy: boolean
  isBuyingSpy: boolean
  isBuyingSettler: boolean
  isBuyingFarmer: boolean
  isMovingArmy: boolean
  selectedArmy: import('@/composables/useMapIO').Army | null
  inspectedArmy?: import('@/composables/useMapIO').Army | null
}>()

const emit = defineEmits<{
  saveSetup: [setup: import('@/composables/useMapIO').PlayerSetup]
  startCitySelect: []
  cancelCitySelect: []
  colorChange: [color: string]
  toggleClaim: []
  toggleBuyArmy: []
  toggleBuySpy: []
  toggleBuySettler: []
  toggleBuyFarmer: []
  cancelMoveArmy: []
  renameArmy: [id: number, name: string]
  upgradeArmy: [id: number, resources: number]
  foundCity: [id: number]
  foundFarm: [id: number]
  closeInspectArmy: []
}>()

const selectedFaction  = ref(props.existingSetup?.faction   ?? '')
const selectedColor    = ref<string>(props.existingSetup?.color ?? '#ffba00')
const cityQ            = ref(props.existingSetup?.city_q    ?? null as number | null)
const cityR            = ref(props.existingSetup?.city_r    ?? null as number | null)
const randomUser_Id     = ref(props.existingSetup?.randomUserId    ?? null as number | null)

const inspectedOwnerSetup = computed(() =>
  props.inspectedArmy ? props.allPlayerSetups.find(s => s.user_id === props.inspectedArmy!.user_id) ?? null : null
)
const inspectedFactionLabel = computed(() =>
  FACTIONS.find(f => f.id === inspectedOwnerSetup.value?.faction)?.label ?? inspectedOwnerSetup.value?.faction ?? 'Unknown'
)

const cityLabel = computed(() => {
  if (cityQ.value !== null && cityR.value !== null)
    return `(${cityQ.value}, ${cityR.value})`
  return 'None selected'
})

const isLocked = computed(() => props.mapStatus === 'started' || props.mapStatus === 'ended')
const armyName    = ref('')
const renamingArmy   = ref(false)
const upgradingArmy  = ref(false)
const upgradeAmount  = ref(10)
watch(() => props.selectedArmy, (a) => { armyName.value = a?.name ?? ''; renamingArmy.value = false }, { immediate: true })
const myActions      = computed(() => props.existingSetup?.actions   ?? 0)
const myResources    = computed(() => props.existingSetup?.resources ?? 0)
const armyOnCity     = computed(() =>
  props.selectedArmy !== null &&
  (props.buildings ?? []).some(b =>
    b.user_id === props.existingSetup?.user_id &&
    b.q === props.selectedArmy!.q &&
    b.r === props.selectedArmy!.r
  )
)
const isSettlerSelected = computed(() => props.selectedArmy?.unit_type === 'settler')
const isFarmerSelected  = computed(() => props.selectedArmy?.unit_type === 'farmer')
const canFoundFarmHere  = computed(() =>
  props.isMovingArmy && isFarmerSelected.value && props.selectedArmy != null &&
  !(props.buildings ?? []).some(b => b.q === props.selectedArmy!.q && b.r === props.selectedArmy!.r)
)
const canFoundCityHere  = computed(() =>
  props.isMovingArmy && isSettlerSelected.value && props.selectedArmy != null &&
  !(props.buildings ?? []).some(b => b.q === props.selectedArmy!.q && b.r === props.selectedArmy!.r)
)
const upgradePowerGain = computed(() => Math.floor(upgradeAmount.value / 10))
const upgradeMaxAmount = computed(() => Math.floor(myResources.value / 10) * 10)
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
    randomUserId: randomUser_Id.value!
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

    <!-- ── Enemy unit inspection — spotted via spy/vision, read-only ── -->
    <template v-else-if="inspectedArmy">
      <div class="rightbar-title">🔎 Enemy Unit Spotted</div>
      <div class="rb-section">
        <div class="rb-label">Faction</div>
        <div class="setup-value" :style="{ color: inspectedOwnerSetup?.color }">{{ inspectedFactionLabel }}</div>
      </div>
      <div class="rb-section">
        <div class="rb-label">Unit</div>
        <div class="setup-value">
          {{ inspectedArmy.unit_type === 'spy' ? '🕵️ Spy' : inspectedArmy.unit_type === 'settler' ? '🏗️ Settler' : inspectedArmy.unit_type === 'farmer' ? '🌾 Farmer' : '⚔️ Army' }}
          — {{ inspectedArmy.name }}
        </div>
      </div>
      <div v-if="inspectedArmy.unit_type === 'army'" class="rb-section">
        <div class="rb-label">Strength</div>
        <div class="army-power">⚔️ {{ inspectedArmy.power }}</div>
      </div>
      <button class="btn-cancel" style="margin-top: 12px" @click="emit('closeInspectArmy')">✕ Close</button>
    </template>

    <!-- ── Army panel — shown when an army is selected (must be before setupLocked) ── -->
    <template v-else-if="isMovingArmy && selectedArmy">
      <div class="rightbar-title">⚔️ Army Selected</div>

      <!-- Faction -->
      <div class="rb-section">
        <div class="rb-label">Faction</div>
        <div class="setup-value">{{ FACTIONS.find(f => f.id === existingSetup?.faction)?.label ?? existingSetup?.faction ?? '—' }}</div>
      </div>

      <!-- Army name + rename -->
      <div class="rb-section">
        <div class="rb-label">Unit Name</div>
        <div v-if="!renamingArmy" class="army-name-row">
          <span class="setup-value">{{ selectedArmy.name }}</span>
          <button class="btn-rename" @click="renamingArmy = true">✏️</button>
        </div>
        <div v-else class="army-name-row">
          <input
            v-model="armyName"
            class="army-name-input"
            maxlength="40"
            @keydown.enter="emit('renameArmy', selectedArmy.id, armyName); renamingArmy = false"
            @keydown.escape="renamingArmy = false"
            autofocus
          />
          <button class="btn-confirm" @click="emit('renameArmy', selectedArmy.id, armyName); renamingArmy = false">✓</button>
          <button class="btn-cancel" @click="renamingArmy = false">✕</button>
        </div>
      </div>

      <!-- Strength (armies & spies only — settlers have no combat power) -->
      <div v-if="!isSettlerSelected && !isFarmerSelected" class="rb-section">
        <div class="rb-label">Strength</div>
        <div class="army-power-row">
          <span class="army-power">⚔️ {{ selectedArmy.power }}</span>
          <button
            class="btn-upgrade"
            :class="{ 'btn-upgrade--disabled': !armyOnCity || myResources < 10 }"
            :title="!armyOnCity ? 'Army must be on your city to upgrade' : myResources < 10 ? 'Not enough resources' : 'Upgrade army'"
            :disabled="!armyOnCity || myResources < 10"
            @click="upgradingArmy = !upgradingArmy; upgradeAmount = Math.min(10, upgradeMaxAmount)"
          >⬆</button>
        </div>

        <!-- Upgrade pop-in -->
        <div v-if="upgradingArmy && armyOnCity" class="upgrade-popin">
          <div class="upgrade-label">Resources to spend</div>
          <div class="upgrade-row">
            <input
              v-model.number="upgradeAmount"
              type="range"
              :min="10"
              :max="upgradeMaxAmount"
              :step="10"
              class="upgrade-slider"
            />
            <span class="upgrade-value">{{ upgradeAmount }}</span>
          </div>
          <div class="upgrade-result">+{{ upgradePowerGain }} strength → {{ selectedArmy.power + upgradePowerGain }}</div>
          <div class="upgrade-actions">
            <button class="btn-confirm" :disabled="upgradeAmount < 10" @click="emit('upgradeArmy', selectedArmy.id, upgradeAmount); upgradingArmy = false">✓ Confirm</button>
            <button class="btn-cancel" @click="upgradingArmy = false">✕</button>
          </div>
        </div>
      </div>

      <!-- Found Farm (farmers only) -->
      <div v-if="isFarmerSelected" class="rb-section">
        <div class="rb-label">Farmer</div>
        <div class="setup-value" style="margin-bottom: 6px">🌾 Can build a farm on one of your resource-bonus tiles (doubles its output).</div>
        <button
          class="btn-claim"
          :class="{ active: canFoundFarmHere }"
          :disabled="!canFoundFarmHere"
          :title="canFoundFarmHere ? 'Build a farm here' : 'A building already exists on this tile'"
          @click="emit('foundFarm', selectedArmy.id)"
        >
          🌾 Build Farm
        </button>
      </div>

      <!-- Found City (settlers only) -->
      <div v-if="isSettlerSelected" class="rb-section">
        <div class="rb-label">Settler</div>
        <div class="setup-value" style="margin-bottom: 6px">🏗️ Can found a new city on a qualifying resource tile.</div>
        <button
          class="btn-claim"
          :class="{ active: canFoundCityHere }"
          :disabled="!canFoundCityHere"
          :title="canFoundCityHere ? 'Found a city here' : 'A city already exists on this tile'"
          @click="emit('foundCity', selectedArmy.id)"
        >
          🏛️ Found City
        </button>
      </div>

      <!-- Actions -->
      <div class="rb-section">
        <div class="rb-label">Actions <span class="actions-count">{{ myActions }} / {{ actionsMax }}</span></div>
        <div class="actions-gauge">
          <div
            class="actions-fill"
            :style="{ width: (myActions / actionsMax * 100) + '%', background: myActions > 3 ? '#4adf8a' : myActions > 0 ? '#ffba00' : '#df4a4a' }"
          />
        </div>
      </div>

      <!-- Finish move button -->
      <button class="btn-claim active" style="margin-top: 12px" @click="emit('cancelMoveArmy')">
        ✕ Finish Move
      </button>
    </template>

    <template v-else-if="setupLocked || isLocked">
      <div class="rightbar-title">⚔️ My Setup</div>
      <div v-if="existingSetup" class="rb-section">
        <div class="rb-label">Faction</div>
        <div class="color-preview">
          <span class="color-sample" :style="{ background: existingSetup.color }"> <span class="color-hex">{{ existingSetup.color }}</span></span>
        </div>
        <div class="setup-value">{{ FACTIONS.find(f => f.id === existingSetup?.faction)?.label ?? existingSetup?.faction }}</div>
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

      <!-- Resources -->
      <div class="rb-section">
        <div class="rb-label">Resources</div>
        <div class="resources-row">
          <span class="resources-icon">💰</span>
          <span class="resources-value">{{ myResources.toLocaleString() }}</span>
        </div>
        <button
          class="btn-claim"
          :class="{ active: isBuyingArmy }"
          :disabled="myResources < 500"
          :title="myResources < 500 ? 'Need 500 resources' : ''"
          @click="emit('toggleBuyArmy')"
          style="margin-top: 6px"
        >
          {{ isBuyingArmy ? '✕ Cancel' : '⚔️ Buy Army (500)' }}
        </button>
        <button
          class="btn-claim"
          :class="{ active: isBuyingSpy }"
          :disabled="myResources < 2000"
          :title="myResources < 2000 ? 'Need 2000 resources' : 'Spies reveal fog of war around them'"
          @click="emit('toggleBuySpy')"
          style="margin-top: 6px"
        >
          {{ isBuyingSpy ? '✕ Cancel' : '🕵️ Buy Spy (2000)' }}
        </button>
        <button
          class="btn-claim"
          :class="{ active: isBuyingSettler }"
          :disabled="myResources < 5000"
          :title="myResources < 5000 ? 'Need 5000 resources' : 'Settlers can found new cities'"
          @click="emit('toggleBuySettler')"
          style="margin-top: 6px"
        >
          {{ isBuyingSettler ? '✕ Cancel' : '🏕️ Buy Settler (5000)' }}
        </button>
        <button
          class="btn-claim"
          :class="{ active: isBuyingFarmer }"
          :disabled="myResources < 300"
          :title="myResources < 300 ? 'Need 300 resources' : 'Farmers can build farms on your resource-bonus tiles'"
          @click="emit('toggleBuyFarmer')"
          style="margin-top: 6px"
        >
          {{ isBuyingFarmer ? '✕ Cancel' : '🌾 Buy Farmer (300)' }}
        </button>
      </div>
    </template>

    <template v-else>

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

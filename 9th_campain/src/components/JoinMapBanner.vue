<script setup lang="ts">
defineProps<{
  mapName: string
  isPending: boolean
  mapStatus: string
}>()

const emit = defineEmits<{
  join: []
}>()
</script>

<template>
  <div class="join-banner">
    <div class="join-banner__text">
      <span class="join-banner__title">{{ mapName }}</span>
      <template v-if="mapStatus !== 'ongoing'">
        <span class="join-banner__sub locked">🔒 This map is not accepting new players.</span>
      </template>
      <template v-else-if="isPending">
        <span class="join-banner__sub pending">⏳ Join request pending approval...</span>
      </template>
      <template v-else>
        <span class="join-banner__sub">You are viewing this map as a guest.</span>
      </template>
    </div>
    <button
      v-if="mapStatus === 'ongoing' && !isPending"
      class="join-banner__btn"
      @click="emit('join')"
    >
      ⚔️ Request to Join
    </button>
  </div>
</template>

<style>
.join-banner {
  position: fixed;
  bottom: 20px;
  right: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  background: #241c10;
  border: 2px solid #d4a843;
  border-radius: 8px;
  padding: 12px 18px;
  box-shadow: 0 0 30px rgba(212, 168, 67, 0.2);
  z-index: 900;
  max-width: 360px;
}

.join-banner__text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.join-banner__title {
  font-family: 'Cinzel', serif;
  font-size: 0.9rem;
  color: #d4a843;
}

.join-banner__sub {
  font-size: 0.78rem;
  color: #8a7455;
}

.join-banner__sub.pending {
  color: #c4a86a;
}

.join-banner__sub.locked {
  color: #df6060;
}

.join-banner__btn {
  flex-shrink: 0;
  background: #3a2800;
  border: 1px solid #d4a843;
  color: #d4a843;
  padding: 8px 14px;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.85rem;
  white-space: nowrap;
  transition: all 0.2s;
}
.join-banner__btn:hover { background: #5a3800; }
</style>

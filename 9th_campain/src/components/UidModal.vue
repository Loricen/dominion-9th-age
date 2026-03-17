<script setup lang="ts">
defineProps<{
  hexmap_uid: string
  copied: boolean
}>()

const emit = defineEmits<{
  close: []
  copy: []
}>()
</script>

<template>
  <div class="modal-overlay" @click.self="emit('close')">
    <div class="modal">
      <div class="modal-header">
        <span>☁️ Map Saved to Server</span>
        <button class="close-btn" @click="emit('close')">✕</button>
      </div>

      <div class="modal-body">
        <p class="modal-desc">Your map has been saved. Use this UID to share invite others:</p>

        <div class="uid-display">
          <span class="uid-code">{{ hexmap_uid }}</span>
        </div>

        <button class="copy-btn" @click="emit('copy')">
          {{ copied ? '✅ Copied!' : '📋 Copy UID' }}
        </button>

        <p class="modal-note">Keep this code safe — it's the only way to retrieve your map.</p>
      </div>
    </div>
  </div>
</template>

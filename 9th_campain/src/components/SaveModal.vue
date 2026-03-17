<script setup lang="ts">
import { ref } from 'vue'

const emit = defineEmits<{
  confirm: [name: string]
  cancel: []
}>()

const mapName = ref('')

function submit() {
  emit('confirm', mapName.value.trim() || 'Untitled Map')
  mapName.value = ''
}
</script>

<template>
  <div class="modal-overlay" @click.self="emit('cancel')">
    <div class="modal">
      <div class="modal-header">
        <span>☁️ Save Map Online</span>
        <button class="close-btn" @click="emit('cancel')">✕</button>
      </div>
      <div class="modal-body">
        <p class="modal-desc">Give your map a name before saving:</p>
        <input v-model="mapName" class="name-input" placeholder="e.g. The Northern Wastes"
          maxlength="60" @keydown.enter="submit" autofocus />
        <div class="modal-actions">
          <button class="btn-confirm" @click="submit">💾 Save</button>
          <button class="btn-cancel" @click="emit('cancel')">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import type { ChatMessage } from '@/composables/useMapIO'

const props = defineProps<{
  messages: ChatMessage[]
  currentUserId: number
}>()

const emit = defineEmits<{
  send: [text: string]
}>()

const input    = ref('')
const listRef  = ref<HTMLElement | null>(null)

// --- Drag ---
const posX     = ref(350)
const posY     = ref(window.screen.height - 850)
const dragging = ref(false)
let dragOffsetX = 0
let dragOffsetY = 0

function onDragStart(e: MouseEvent) {
  if (e.button !== 0) return
  dragging.value = true
  dragOffsetX = e.clientX - posX.value
  dragOffsetY = e.clientY - posY.value
  e.preventDefault()
}

function onDragMove(e: MouseEvent) {
  if (!dragging.value) return
  posX.value = Math.max(0, Math.min(window.innerWidth  - 300, e.clientX - dragOffsetX))
  posY.value = Math.max(0, Math.min(window.innerHeight - 200, e.clientY - dragOffsetY))
}

function onDragEnd() { dragging.value = false }

onMounted(() => {
  window.addEventListener('mousemove', onDragMove)
  window.addEventListener('mouseup',   onDragEnd)
  scrollToBottom()
})
onUnmounted(() => {
  window.removeEventListener('mousemove', onDragMove)
  window.removeEventListener('mouseup',   onDragEnd)
})

// --- Chat ---
function formatTime(ts: number): string {
  return new Date(ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function submit() {
  const text = input.value.trim()
  if (!text) return
  emit('send', text)
  input.value = ''
}

function scrollToBottom() {
  nextTick(() => {
    if (listRef.value) listRef.value.scrollTop = listRef.value.scrollHeight
  })
}

watch(() => props.messages.length, scrollToBottom)
</script>

<template>
  <div
    class="chatbox chatbox--popped"
    :style="{ left: posX + 'px', top: posY + 'px', bottom: 'auto', right: 'auto' }"
    :class="{ dragging }"
  >
    <div class="chatbox-header" @mousedown="onDragStart" style="cursor: grab">
      <span class="chatbox-title">💬 Chat</span>
    </div>
    <div class="chatbox-messages" ref="listRef">
      <div v-if="messages.length === 0" class="chatbox-empty">No messages yet</div>
      <div
        v-for="(msg, i) in messages"
        :key="i"
        class="chatbox-msg"
        :class="{ 'chatbox-msg--own': msg.user_id === currentUserId }"
      >
        <div class="chatbox-meta">
          <span class="chatbox-name">{{ msg.user_id === currentUserId ? 'You' : msg.user_name }}</span>
          <span class="chatbox-time">{{ formatTime(msg.ts) }}</span>
        </div>
        <div class="chatbox-text">{{ msg.text }}</div>
      </div>
    </div>
    <div class="chatbox-input-row">
      <input
        v-model="input"
        class="chatbox-input"
        placeholder="Type a message…"
        maxlength="500"
        @keydown.enter.prevent="submit"
      />
      <button class="chatbox-send" @click="submit">➤</button>
    </div>
  </div>
</template>
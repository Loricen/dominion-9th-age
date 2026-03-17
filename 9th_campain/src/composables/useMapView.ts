import { ref, onMounted, onUnmounted } from 'vue'

export function useMapView() {
  const zoom = ref(1)
  const panX = ref(0)
  const panY = ref(0)
  const isPanning = ref(false)
  const panStartX = ref(0)
  const panStartY = ref(0)
  const panOriginX = ref(0)
  const panOriginY = ref(0)

  function onWheel(e: WheelEvent) {
    e.preventDefault()
    zoom.value = Math.min(5, Math.max(0.2, zoom.value * (e.deltaY > 0 ? 0.9 : 1.1)))
  }

  function onMouseDown(e: MouseEvent) {
    if (e.button === 1 || e.button === 2) {
      isPanning.value = true
      panStartX.value = e.clientX
      panStartY.value = e.clientY
      panOriginX.value = panX.value
      panOriginY.value = panY.value
      e.preventDefault()
    }
  }

  function onMouseMove(e: MouseEvent) {
    if (!isPanning.value) return
    panX.value = panOriginX.value + (e.clientX - panStartX.value)
    panY.value = panOriginY.value + (e.clientY - panStartY.value)
  }

  function onMouseUp() {
    isPanning.value = false
  }

  function resetView() {
    zoom.value = 1
    panX.value = 0
    panY.value = 0
  }

  function zoomIn() {
    zoom.value = Math.min(5, zoom.value + 0.1)
  }

  function zoomOut() {
    zoom.value = Math.max(0.2, zoom.value - 0.1)
  }

  onMounted(() => {
    window.addEventListener('mouseup', onMouseUp)
    window.addEventListener('mousemove', onMouseMove)
  })

  onUnmounted(() => {
    window.removeEventListener('mouseup', onMouseUp)
    window.removeEventListener('mousemove', onMouseMove)
  })

  return {
    zoom,
    panX,
    panY,
    onWheel,
    onMouseDown,
    resetView,
    zoomIn,
    zoomOut,
  }
}

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
  const backVis = ref<'hidden' | 'visible'>('hidden')

  function onWheel(e: WheelEvent) {
    e.preventDefault()
    const oldZoom = zoom.value
    const newZoom = Math.min(5, Math.max(0.2, oldZoom * (e.deltaY > 0 ? 0.9 : 1.1)))
    if (newZoom !== oldZoom) {
      // Keep the map point under the cursor fixed: the content is drawn with
      // translate(pan) scale(zoom) from origin (0,0), so its on-screen left/top
      // is (origin + pan). Distance cursor→content origin scales with zoom.
      const host = (e.currentTarget as HTMLElement | null) ?? (e.target as HTMLElement | null)?.closest('.map-container') as HTMLElement | null
      const inner = host?.querySelector('.map-inner') as HTMLElement | null
      if (inner) {
        const rect = inner.getBoundingClientRect()
        const k = 1 - newZoom / oldZoom
        panX.value += (e.clientX - rect.left) * k
        panY.value += (e.clientY - rect.top) * k
      }
    }
    zoom.value = newZoom
    backVis.value = backVis.value === 'visible' ? 'hidden' : 'visible'
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
    backVis.value = backVis.value === 'visible' ? 'hidden' : 'visible'
  }

  function zoomOut() {
    zoom.value = Math.max(0.2, zoom.value - 0.1)
    backVis.value = backVis.value === 'visible' ? 'hidden' : 'visible'
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
    zoom, panX, panY, backVis,
    onWheel, onMouseDown, resetView, zoomIn, zoomOut,
  }
}
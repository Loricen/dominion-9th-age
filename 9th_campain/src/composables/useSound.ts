const cache = new Map<string, HTMLAudioElement>()

function play(path: string) {
  let audio = cache.get(path)
  if (!audio) {
    audio = new Audio(path)
    cache.set(path, audio)
  }
  audio.currentTime = 0
  audio.play().catch(() => { /* blocked before user interaction */ })
}

export function useSound() {
  return {
    playTileClick:    () => play('/src/assets/audio/tile_click.mp3'),
    playTileCapture:  () => play('/src/assets/audio/tile_capture.mp3'),
    playActionsReload: () => play('/src/assets/audio/actions_reload.mp3'),
    playJoinRequest:   () => play('/src/assets/audio/join_request.mp3'),
    playGameStarts:    () => play('/src/assets/audio/game_starts.mp3'),
  }
}
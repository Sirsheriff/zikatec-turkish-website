export const STAGE_COUNT = 6;
export const JUMP_AIRTIME = 2 * 810 / 1950;
export const BASE_TIMING_WINDOW = .43;
export const OBSTACLES_PER_STAGE = 3;
const JUMP_SPEED = 810;
const TYPICAL_SPIKE_HEIGHT = 38;
const TYPICAL_GROUP_WIDTH = 58;

export function stageForScore(score) {
  return Math.min(STAGE_COUNT, 1 + Math.floor(Math.max(0, score) / OBSTACLES_PER_STAGE));
}

export function safeJumpDuration(spikeHeight, gravity = 1950) {
  const requiredRise = Math.max(0, spikeHeight - 17);
  return 2 * Math.sqrt(Math.max(0, JUMP_SPEED ** 2 - 2 * gravity * requiredRise)) / gravity;
}

export function gravityForWindow(timingWindow, speed, playerHitWidth = 50) {
  const overlapTime = (playerHitWidth + TYPICAL_GROUP_WIDTH - 10) / speed;
  const desiredSafeTime = timingWindow + overlapTime;
  let low = 1000;
  let high = 12000;
  for (let i = 0; i < 32; i++) {
    const middle = (low + high) / 2;
    if (safeJumpDuration(TYPICAL_SPIKE_HEIGHT, middle) > desiredSafeTime) low = middle;
    else high = middle;
  }
  return (low + high) / 2;
}

export function difficultyAt(score, elapsed, playerHitWidth = 50) {
  const stage = stageForScore(score);
  const timingWindow = BASE_TIMING_WINDOW * .85 ** (stage - 1);
  const speed = 360 + elapsed * 8 + score * 15;
  return {
    stage,
    // The launch window is 15% shorter at each successive stage.
    timingWindow,
    // There is no speed cap, including after stage 6.
    speed,
    gravity: gravityForWindow(timingWindow, speed, playerHitWidth),
  };
}

export function sampleObstacle(randomCount = Math.random(), randomSize = Math.random()) {
  const count = randomCount < .5 ? 1 : randomCount < .85 ? 2 : 3;
  const spikeWidth = 33 + randomSize * 10;
  return {
    w: spikeWidth * (1 + .75 * (count - 1)),
    h: 33 + randomSize * 8,
    count,
  };
}

export function touchesSpikeGroup(obstacle, playerLeft, playerRight, playerRise) {
  const spikeWidth = obstacle.w / (1 + .75 * (obstacle.count - 1));
  for (let i = 0; i < obstacle.count; i++) {
    const spikeX = obstacle.x + i * spikeWidth * .75;
    const overlapLeft = Math.max(playerLeft, spikeX);
    const overlapRight = Math.min(playerRight, spikeX + spikeWidth);
    if (overlapLeft >= overlapRight) continue;
    const peakX = spikeX + spikeWidth / 2;
    const closestX = Math.max(overlapLeft, Math.min(peakX, overlapRight));
    const surfaceHeight = obstacle.h * (1 - Math.abs(closestX - peakX) / (spikeWidth / 2));
    if (playerRise < surfaceHeight - 3) return true;
  }
  return false;
}

export function spawnDelay(random = Math.random()) {
  return 1.35 + random * .7;
}

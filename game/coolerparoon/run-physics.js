import { START_SPEED, difficultyAt, runSpeedAt, sampleObstacle, sampleObstacleGap, touchesSpikeGroup } from './difficulty.js';

export const TICKS_PER_SECOND = 120;
export const RUN_LIFETIME_MS = 60 * 60_000;
const MAX_TICKS = TICKS_PER_SECOND * 50 * 60;
const STEP = 1 / TICKS_PER_SECOND;

export function createRun(seed, width) {
  const random = () => {
    seed = seed * 48271 % 2147483647;
    return seed / 2147483647;
  };
  const playerX = Math.max(55, Math.min(115, width * .16));
  const playerWidth = width < 540 ? 70 : 80;
  const speedScale = Math.min(1, Math.max(.25, (width - (playerX + playerWidth - 15)) / 370));
  return {
    width, random, playerX, playerWidth, speedScale,
    nextObstacleAt: width + START_SPEED * speedScale * (.6 + random() * .75),
    tick: 0, distance: 0, score: 0, y: 0, velocity: 0, gravity: 1950,
    obstacles: [], ended: false,
  };
}

export function stepRun(run, jump) {
  const hitWidth = run.playerWidth - 30;
  const elapsed = ++run.tick / TICKS_PER_SECOND;
  const runSpeed = runSpeedAt(elapsed);
  const travel = runSpeed * run.speedScale * STEP;
  if (jump && run.y === 0 && run.velocity === 0) {
    run.gravity = difficultyAt(run.score, elapsed, hitWidth, run.speedScale).gravity;
    run.velocity = -810;
  }
  run.distance += travel;
  run.y += (run.velocity + run.gravity * STEP / 2) * STEP;
  run.velocity += run.gravity * STEP;
  if (run.y >= 0) {
    run.y = 0;
    run.velocity = 0;
  }

  const left = run.playerX + 15;
  for (const obstacle of run.obstacles) {
    obstacle.x -= travel;
    if (touchesSpikeGroup(obstacle, left, left + hitWidth, -run.y + 7)) {
      run.ended = true;
      return;
    }
    if (!obstacle.passed && obstacle.x + obstacle.w < run.playerX) {
      obstacle.passed = true;
      run.score++;
    }
  }
  while (run.obstacles.length && run.obstacles[0].x + run.obstacles[0].w <= -20) run.obstacles.shift();
  while (run.nextObstacleAt - run.distance <= run.width) {
    const obstacle = sampleObstacle(runSpeed, run.random(), run.random());
    run.obstacles.push({ x: run.nextObstacleAt - run.distance, ...obstacle, passed: false });
    run.nextObstacleAt += obstacle.w + sampleObstacleGap(run.random()) * run.speedScale;
  }
  run.ended = run.tick === MAX_TICKS;
}

export function verifyRun(run, endTick, jumps, durationMs) {
  if (!Number.isInteger(endTick) || endTick < 1 || endTick > MAX_TICKS ||
      endTick > Math.ceil(durationMs * TICKS_PER_SECOND / 1000) ||
      !Array.isArray(jumps) || jumps.length > 8192) return null;
  let previous = 0;
  for (const tick of jumps) {
    if (!Number.isInteger(tick) || tick <= previous || tick > endTick) return null;
    previous = tick;
  }
  const simulation = createRun(run.seed, run.width);
  let input = 0;
  while (simulation.tick < endTick && !simulation.ended) {
    const jump = jumps[input] === simulation.tick + 1;
    if (jump) {
      if (simulation.y !== 0 || simulation.velocity !== 0) return null;
      input++;
    }
    stepRun(simulation, jump);
  }
  return simulation.ended && simulation.tick === endTick ? simulation.score : null;
}

<?php
declare(strict_types=1);

const RUN_LIFETIME_MS = 3600000;
const RUN_TICKS_PER_SECOND = 120;
const RUN_MAX_TICKS = RUN_TICKS_PER_SECOND * 50 * 60;

// Keep these fixed-step physics in sync with run-physics.js and difficulty.js.
function verifyRun(array $run, int $endTick, array $jumps, int $durationMs): ?int
{
    if ($endTick < 1 || $endTick > RUN_MAX_TICKS ||
        $endTick > ceil($durationMs * RUN_TICKS_PER_SECOND / 1000) ||
        count($jumps) > 8192 || !array_is_list($jumps)) {
        return null;
    }
    $previous = 0;
    foreach ($jumps as $jumpTick) {
        if (!is_int($jumpTick) || $jumpTick <= $previous || $jumpTick > $endTick) {
            return null;
        }
        $previous = $jumpTick;
    }

    $seed = $run['seed'];
    $random = static function () use (&$seed): float {
        $seed = $seed * 48271 % 2147483647;
        return $seed / 2147483647;
    };
    $width = $run['width'];
    $playerX = max(55, min(115, $width * .16));
    $playerWidth = $width < 540 ? 70 : 80;
    $hitWidth = $playerWidth - 30;
    $left = $playerX + 15;
    $right = $left + $hitWidth;
    $speedScale = min(1, max(.25, ($width - ($playerX + $playerWidth - 15)) / 370));
    $nextObstacleAt = $width + 360 * $speedScale * (.6 + $random() * .75);
    $step = 1 / RUN_TICKS_PER_SECOND;
    $distance = $y = $velocity = 0.0;
    $gravity = 1950.0;
    $score = $input = 0;
    $obstacles = [];

    for ($tick = 1; $tick <= $endTick; $tick++) {
        $runSpeed = min(840, 360 + ($tick / RUN_TICKS_PER_SECOND) * 12);
        $speed = $runSpeed * $speedScale;
        $travel = $speed * $step;
        if (($jumps[$input] ?? null) === $tick) {
            if ($y != 0 || $velocity != 0) {
                return null;
            }
            $input++;
            $stage = min(6, 1 + intdiv($score, 3));
            $safeTime = .31 * .85 ** ($stage - 1) + ($hitWidth + 58 - 10) / $speed;
            $low = 1000;
            $high = 12000;
            for ($i = 0; $i < 32; $i++) {
                $middle = ($low + $high) / 2;
                $duration = 2 * sqrt(max(0, 810 ** 2 - 2 * $middle * 21)) / $middle;
                if ($duration > $safeTime) {
                    $low = $middle;
                } else {
                    $high = $middle;
                }
            }
            $gravity = ($low + $high) / 2;
            $velocity = -810;
        }
        $distance += $travel;
        $y += ($velocity + $gravity * $step / 2) * $step;
        $velocity += $gravity * $step;
        if ($y >= 0) {
            $y = $velocity = 0.0;
        }

        foreach ($obstacles as $index => $obstacle) {
            $x = $obstacle['x'] - $travel;
            $spikeWidth = $obstacle['w'] / (1 + .75 * ($obstacle['count'] - 1));
            for ($i = 0; $i < $obstacle['count']; $i++) {
                $spikeX = $x + $i * $spikeWidth * .75;
                $overlapLeft = max($left, $spikeX);
                $overlapRight = min($right, $spikeX + $spikeWidth);
                if ($overlapLeft >= $overlapRight) {
                    continue;
                }
                $peakX = $spikeX + $spikeWidth / 2;
                $closestX = max($overlapLeft, min($peakX, $overlapRight));
                $surfaceHeight = $obstacle['h'] * (1 - abs($closestX - $peakX) / ($spikeWidth / 2));
                if (-$y + 7 < $surfaceHeight - 3) {
                    return $tick === $endTick ? $score : null;
                }
            }
            if (!$obstacle['passed'] && $x + $obstacle['w'] < $playerX) {
                $score++;
                $obstacle['passed'] = true;
            }
            if ($x + $obstacle['w'] <= -20) {
                unset($obstacles[$index]);
            } else {
                $obstacle['x'] = $x;
                $obstacles[$index] = $obstacle;
            }
        }
        while ($nextObstacleAt - $distance <= $width) {
            $countRoll = $random();
            $sizeRoll = $random();
            $maxCount = $runSpeed < 425 ? 1 : ($runSpeed < 570 ? 2 : 3);
            $count = min($maxCount, $countRoll < .5 ? 1 : ($countRoll < .85 ? 2 : 3));
            $obstacleWidth = (33 + $sizeRoll * 10) * (1 + .75 * ($count - 1));
            $obstacles[] = [
                'x' => $nextObstacleAt - $distance, 'w' => $obstacleWidth,
                'h' => 33 + $sizeRoll * 8, 'count' => $count, 'passed' => false,
            ];
            $nextObstacleAt += $obstacleWidth + (840 * 640 / 1000 + $random() * 320) * $speedScale;
        }
    }
    return $endTick === RUN_MAX_TICKS ? $score : null;
}

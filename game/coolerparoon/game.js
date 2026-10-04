import { difficultyAt, sampleObstacle, spawnDelay, touchesSpikeGroup } from './difficulty.js';

const canvas = document.querySelector('#game');
const ctx = canvas.getContext('2d');
const stage = document.querySelector('#stage');
const overlay = document.querySelector('#overlay');
const scoreEl = document.querySelector('#score');
const bestEl = document.querySelector('#best');
const pauseBtn = document.querySelector('#pauseBtn');
const soundBtn = document.querySelector('#soundBtn');
const levelText = document.querySelector('#levelText');
const speedText = document.querySelector('#speedText');
const timingText = document.querySelector('#timingText');
const accountBtn = document.querySelector('#accountBtn');
const authModal = document.querySelector('#authModal');
const authForm = document.querySelector('#authForm');
const profileForm = document.querySelector('#profileForm');
const usernameInput = document.querySelector('#usernameInput');
const profileMessage = document.querySelector('#profileMessage');
const phoneInput = document.querySelector('#phoneInput');
const codeInput = document.querySelector('#codeInput');
const codeLabel = document.querySelector('#codeLabel');
const sendCodeBtn = document.querySelector('#sendCodeBtn');
const verifyCodeBtn = document.querySelector('#verifyCodeBtn');
const authTitle = document.querySelector('#authTitle');
const authDescription = document.querySelector('#authDescription');
const authMessage = document.querySelector('#authMessage');
const logoutBtn = document.querySelector('#logoutBtn');
const leaderboardList = document.querySelector('#leaderboardList');
const leaderboardStatus = document.querySelector('#leaderboardStatus');

const cooler = new Image();
cooler.src = './assets/cooler-front-transparent.png';
const angledCooler = new Image();
angledCooler.src = './assets/cooler-angled-transparent.png';
const number = value => Math.floor(value).toString().padStart(5, '0').replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
const persian = value => String(value).replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[digit]);
let best = 0;
let sessionToken = localStorage.getItem('kolor-session');
let currentPlayer = null;
let pendingStart = false;
let starting = false;
let runId = '';
let scoreSubmitted = false;
let resendTimer;
let soundOn = true;
let audioContext;
let width = 0;
let height = 0;
let ground = 0;
let lastTime = 0;
let mode = 'ready';
let elapsed = 0;
let distance = 0;
let score = 0;
let spawnIn = .86;
let obstacles = [];
let particles = [];
let jumpY = 0;
let jumpVelocity = 0;
let activeGravity = 1950;
let dustClock = 0;

bestEl.textContent = number(best);

function api(path, options = {}) {
  const headers = { ...(options.body ? { 'Content-Type': 'application/json' } : {}), ...(sessionToken ? { Authorization: `Bearer ${sessionToken}` } : {}), ...options.headers };
  const endpoint = new URL(path.replace(/^\/+/, ''), new URL('./', document.baseURI));
  return fetch(endpoint, { ...options, headers }).then(async response => {
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || 'ارتباط با سرور انجام نشد.');
    return data;
  });
}

function updateAccount() {
  accountBtn.textContent = currentPlayer ? currentPlayer.username : 'ورود با موبایل';
  best = currentPlayer?.bestScore || 0;
  bestEl.textContent = number(best);
}

async function refreshProfile() {
  if (!sessionToken) { currentPlayer = null; updateAccount(); return null; }
  try {
    const result = await api('/api/me');
    currentPlayer = result.player;
    updateAccount();
    return currentPlayer;
  } catch {
    sessionToken = null;
    currentPlayer = null;
    localStorage.removeItem('kolor-session');
    updateAccount();
    return null;
  }
}

function renderLeaderboard(players = []) {
  leaderboardList.replaceChildren();
  if (!players.length) {
    const empty = document.createElement('li');
    empty.className = 'empty-leaderboard';
    empty.textContent = 'هنوز رکوردی ثبت نشده است.';
    leaderboardList.append(empty);
    return;
  }
  for (const [index, player] of players.entries()) {
    const row = document.createElement('li');
    row.className = 'leaderboard-row';
    const rank = document.createElement('span');
    rank.className = 'leaderboard-rank';
    rank.textContent = persian(index + 1);
    const label = document.createElement('span');
    label.className = 'leaderboard-player';
    label.textContent = player.username || player.guestName;
    const scoreValue = document.createElement('strong');
    scoreValue.className = 'leaderboard-score';
    scoreValue.textContent = number(player.bestScore);
    row.append(rank, label, scoreValue);
    leaderboardList.append(row);
  }
}

async function refreshLeaderboard() {
  try {
    const result = await api('/api/leaderboard');
    renderLeaderboard(result.players);
    leaderboardStatus.textContent = 'به‌روزرسانی زنده';
  } catch {
    leaderboardStatus.textContent = 'اتصال برقرار نشد';
    renderLeaderboard();
  }
}

function setAuthMessage(message = '', isError = false) {
  authMessage.textContent = message;
  authMessage.classList.toggle('error', isError);
}

function openAuth() {
  authModal.classList.remove('hidden');
  authForm.classList.toggle('hidden', Boolean(currentPlayer));
  profileForm.classList.toggle('hidden', !currentPlayer);
  logoutBtn.classList.toggle('hidden', !currentPlayer);
  phoneInput.disabled = Boolean(currentPlayer);
  codeInput.required = false;
  phoneInput.value = '';
  codeInput.value = '';
  codeInput.classList.add('hidden');
  codeLabel.classList.add('hidden');
  verifyCodeBtn.classList.add('hidden');
  sendCodeBtn.classList.remove('hidden');
  sendCodeBtn.disabled = false;
  sendCodeBtn.textContent = 'ارسال کد';
  clearInterval(resendTimer);
  if (currentPlayer) {
    authTitle.textContent = 'حساب کاربری';
    authDescription.textContent = `نام پیش‌فرض: ${currentPlayer.guestName} · رکورد شخصی: ${number(currentPlayer.bestScore)}`;
    usernameInput.value = currentPlayer.username;
    profileMessage.textContent = '';
    setAuthMessage('');
  } else {
    authTitle.textContent = 'ورود به مسابقه';
    authDescription.textContent = 'شمارهٔ موبایل را وارد کن تا کد ورود برایت پیامک شود.';
    usernameInput.value = '';
    profileMessage.textContent = '';
    setAuthMessage('');
    setTimeout(() => phoneInput.focus(), 0);
  }
}

function closeAuth() {
  authModal.classList.add('hidden');
  clearInterval(resendTimer);
  if (pendingStart && !currentPlayer) pendingStart = false;
}

async function requestStart() {
  if (starting) return;
  starting = true;
  try {
    if (!currentPlayer) await refreshProfile();
    if (!currentPlayer) {
      pendingStart = true;
      openAuth();
      return;
    }
    const run = await api('/api/runs', { method: 'POST' });
    pendingStart = false;
    start(run.runId);
  } catch (error) {
    leaderboardStatus.textContent = error.message;
  } finally {
    starting = false;
  }
}

function normalizeDigits(value) {
  return String(value).replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
    .replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)));
}

async function requestCode() {
  sendCodeBtn.disabled = true;
  setAuthMessage('در حال ارسال کد…');
  try {
    const result = await api('/api/auth/request-code', {
      method: 'POST',
      body: JSON.stringify({ phone: phoneInput.value }),
    });
    codeInput.classList.remove('hidden');
    codeInput.required = true;
    codeLabel.classList.remove('hidden');
    verifyCodeBtn.classList.remove('hidden');
    sendCodeBtn.textContent = 'ارسال دوباره';
    setAuthMessage(result.devCode
      ? `کد آزمایشی: ${persian(result.devCode)} (پیامک واقعی با تنظیم SMS.ir ارسال می‌شود)`
      : result.message);
    codeInput.focus();
    let remaining = 60;
    sendCodeBtn.textContent = `ارسال دوباره (${persian(remaining)})`;
    resendTimer = setInterval(() => {
      remaining--;
      if (remaining <= 0) {
        clearInterval(resendTimer);
        sendCodeBtn.disabled = false;
        sendCodeBtn.textContent = 'ارسال دوباره';
      } else {
        sendCodeBtn.textContent = `ارسال دوباره (${persian(remaining)})`;
      }
    }, 1000);
  } catch (error) {
    setAuthMessage(error.message, true);
    sendCodeBtn.disabled = false;
  }
}

async function verifyCode(event) {
  event.preventDefault();
  verifyCodeBtn.disabled = true;
  setAuthMessage('در حال بررسی کد…');
  try {
    const result = await api('/api/auth/verify-code', {
      method: 'POST',
      body: JSON.stringify({ phone: phoneInput.value, code: normalizeDigits(codeInput.value) }),
    });
    sessionToken = result.token;
    localStorage.setItem('kolor-session', sessionToken);
    currentPlayer = result.player;
    updateAccount();
    const shouldStart = pendingStart;
    pendingStart = false;
    closeAuth();
    await refreshLeaderboard();
    if (shouldStart) void requestStart();
  } catch (error) {
    setAuthMessage(error.message, true);
  } finally {
    verifyCodeBtn.disabled = false;
  }
}

async function logout() {
  try { await api('/api/auth/logout', { method: 'POST' }); } catch {}
  sessionToken = null;
  currentPlayer = null;
  localStorage.removeItem('kolor-session');
  updateAccount();
  closeAuth();
  void refreshLeaderboard();
}

async function saveUsername(event) {
  event.preventDefault();
  const saveButton = profileForm.querySelector('button[type="submit"]');
  saveButton.disabled = true;
  profileMessage.classList.remove('error');
  profileMessage.textContent = 'در حال ذخیره…';
  try {
    const result = await api('/api/me/username', {
      method: 'PUT',
      body: JSON.stringify({ username: usernameInput.value }),
    });
    currentPlayer = result.player;
    updateAccount();
    usernameInput.value = currentPlayer.username;
    profileMessage.textContent = `نام «${currentPlayer.username}» ذخیره شد.`;
    await refreshLeaderboard();
  } catch (error) {
    profileMessage.textContent = error.message;
    profileMessage.classList.add('error');
  } finally {
    saveButton.disabled = false;
  }
}

function resize() {
  const rect = stage.getBoundingClientRect();
  width = rect.width;
  height = rect.height;
  ground = height - 49;
  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  canvas.width = Math.round(width * dpr);
  canvas.height = Math.round(height * dpr);
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  draw();
}

function beep(frequency, duration, type = 'sine', volume = 0.035) {
  if (!soundOn) return;
  try {
    audioContext ||= new (window.AudioContext || window.webkitAudioContext)();
    const oscillator = audioContext.createOscillator();
    const gain = audioContext.createGain();
    oscillator.type = type;
    oscillator.frequency.setValueAtTime(frequency, audioContext.currentTime);
    oscillator.frequency.exponentialRampToValueAtTime(Math.max(90, frequency * .62), audioContext.currentTime + duration);
    gain.gain.setValueAtTime(volume, audioContext.currentTime);
    gain.gain.exponentialRampToValueAtTime(.001, audioContext.currentTime + duration);
    oscillator.connect(gain).connect(audioContext.destination);
    oscillator.start();
    oscillator.stop(audioContext.currentTime + duration);
  } catch { /* Audio is optional. */ }
}

function setOverlay(title, message, button, icon) {
  document.querySelector('#overlayTitle').textContent = title;
  document.querySelector('#overlayText').textContent = message;
  document.querySelector('#startBtn').firstChild.textContent = button + ' ';
  document.querySelector('#overlayIcon').textContent = icon;
  overlay.classList.remove('hidden');
}

function start(serverRunId) {
  mode = 'playing';
  elapsed = 0;
  distance = 0;
  score = 0;
  runId = serverRunId;
  scoreSubmitted = false;
  spawnIn = spawnDelay();
  obstacles = [{ x: Math.max(playerX() + 230, width * .62), ...sampleObstacle(), passed: false }];
  particles = [];
  jumpY = 0;
  jumpVelocity = 0;
  activeGravity = 1950;
  scoreEl.textContent = number(0);
  levelText.textContent = 'مرحله ۱';
  speedText.textContent = 'سرعت ۱×';
  timingText.textContent = 'فرصت پرش ۰٫۴۳ ث';
  overlay.classList.add('hidden');
  pauseBtn.setAttribute('aria-label', 'مکث بازی');
  lastTime = performance.now();
  beep(440, .11);
}

function jump() {
  if (mode === 'ready' || mode === 'over') { void requestStart(); return; }
  if (mode === 'paused') { togglePause(); return; }
  if (jumpY > 0 || jumpVelocity !== 0) return;
  activeGravity = difficultyAt(score, elapsed, playerWidth() - 30).gravity;
  jumpVelocity = -810;
  beep(580, .14, 'triangle', .045);
  for (let i = 0; i < 7; i++) particles.push({ x: playerX() + playerWidth() * .5, y: ground - 4, vx: (Math.random() - .5) * 120, vy: -Math.random() * 75, life: .3 + Math.random() * .2 });
}

function togglePause() {
  if (mode === 'playing') {
    mode = 'paused';
    pauseBtn.setAttribute('aria-label', 'ادامه بازی');
    setOverlay('بازی متوقف شد', 'نفسی تازه کن و دوباره ادامه بده.', 'ادامه بازی', 'Ⅱ');
  } else if (mode === 'paused') {
    mode = 'playing';
    pauseBtn.setAttribute('aria-label', 'مکث بازی');
    overlay.classList.add('hidden');
    lastTime = performance.now();
  }
}

function finish() {
  mode = 'over';
  beep(200, .36, 'sawtooth', .025);
  setOverlay('ای وای! به خار خوردی', `امتیاز تو: ${number(score)}  ·  دوباره تلاش کن!`, 'بازی دوباره', '×');
  void submitScore();
}

async function submitScore() {
  if (scoreSubmitted || !runId || !currentPlayer) return;
  scoreSubmitted = true;
  try {
    const result = await api('/api/scores', {
      method: 'POST',
      body: JSON.stringify({
        runId,
        score,
        stage: difficultyAt(score, elapsed).stage,
      }),
    });
    currentPlayer = result.player;
    updateAccount();
    await refreshLeaderboard();
  } catch {
    leaderboardStatus.textContent = 'ذخیرهٔ رکورد انجام نشد';
  }
}

function playerWidth() { return width < 540 ? 70 : 80; }
function playerHeight() { return width < 540 ? 105 : 120; }
function playerX() { return Math.max(55, Math.min(115, width * .16)); }

function spawnObstacle() {
  obstacles.push({
    x: width + 20,
    ...sampleObstacle(),
    passed: false,
  });
}

function update(dt) {
  elapsed += dt;
  const difficulty = difficultyAt(score, elapsed, playerWidth() - 30);
  const speed = difficulty.speed;
  const nextLevel = `مرحله ${persian(difficulty.stage)}`;
  const nextSpeed = `سرعت ${persian((speed / 360).toFixed(1))}×`;
  const nextTiming = `فرصت پرش ${persian(difficulty.timingWindow.toFixed(2)).replace('.', '٫')} ث`;
  if (levelText.textContent !== nextLevel) levelText.textContent = nextLevel;
  if (speedText.textContent !== nextSpeed) speedText.textContent = nextSpeed;
  if (timingText.textContent !== nextTiming) timingText.textContent = nextTiming;
  distance += speed * dt;
  jumpVelocity += activeGravity * dt;
  jumpY += jumpVelocity * dt;
  if (jumpY >= 0) { jumpY = 0; jumpVelocity = 0; }

  spawnIn -= dt;
  if (spawnIn <= 0) {
    spawnObstacle();
    spawnIn = spawnDelay();
  }

  for (const obstacle of obstacles) {
    obstacle.x -= speed * dt;
    if (!obstacle.passed && obstacle.x + obstacle.w < playerX()) {
      obstacle.passed = true;
      score++;
      scoreEl.textContent = number(score);
      beep(720, .07, 'sine', .018);
    }
    const px = playerX() + 15;
    const pw = playerWidth() - 30;
    const playerRise = -jumpY + 7;
    if (touchesSpikeGroup(obstacle, px, px + pw, playerRise)) { finish(); return; }
  }
  obstacles = obstacles.filter(obstacle => obstacle.x + obstacle.w > -20);

  dustClock -= dt;
  if (jumpY === 0 && dustClock <= 0) {
    dustClock = .13;
    particles.push({ x: playerX() + 16, y: ground - 5, vx: -speed * .19, vy: -13 - Math.random() * 20, life: .36 });
  }
  for (const p of particles) { p.x += p.vx * dt; p.y += p.vy * dt; p.life -= dt; }
  particles = particles.filter(p => p.life > 0);
}

function roundedRect(x, y, w, h, r) {
  ctx.beginPath();
  ctx.roundRect(x, y, w, h, r);
}

function drawBackground() {
  ctx.fillStyle = '#1d252b';
  ctx.fillRect(0, 0, width, height);
  ctx.strokeStyle = '#35424b';
  ctx.lineWidth = 1;
  const offset = distance * .15 % 160;
  for (let i = -1; i < Math.ceil(width / 160) + 1; i++) {
    const x = i * 160 - offset;
    ctx.beginPath();
    ctx.moveTo(x, ground - 140);
    ctx.lineTo(x + 38, ground - 140);
    ctx.stroke();
  }
  ctx.fillStyle = '#2b3034';
  ctx.fillRect(0, ground + 2, width, height - ground);
  ctx.strokeStyle = '#D1D3D4';
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(0, ground + 1);
  ctx.lineTo(width, ground + 1);
  ctx.stroke();
  ctx.fillStyle = '#58595B';
  const dash = distance % 78;
  for (let x = -dash; x < width; x += 78) ctx.fillRect(x, ground + 19, 28, 2);
}

function drawCooler() {
  const w = playerWidth();
  const h = playerHeight();
  const x = playerX();
  const y = ground - h + jumpY + 11;
  ctx.fillStyle = '#00000055';
  ctx.beginPath();
  ctx.ellipse(x + w / 2, ground + 8, Math.max(12, w * .42 + jumpY * .05), 4, 0, 0, Math.PI * 2);
  ctx.fill();
  const image = jumpY < -8 && angledCooler.complete && angledCooler.naturalWidth ? angledCooler : cooler;
  if (image.complete && image.naturalWidth) {
    ctx.drawImage(image, x, y, w, h);
  } else {
    ctx.fillStyle = '#f5f5f5';
    ctx.strokeStyle = '#D1D3D4';
    roundedRect(x + 5, y + 5, w - 10, h - 8, 7);
    ctx.fill(); ctx.stroke();
    ctx.fillStyle = '#22262b';
    ctx.beginPath(); ctx.arc(x + w / 2, y + h * .45, w * .31, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#00A1E4';
    for (let i = 0; i < 4; i++) { ctx.save(); ctx.translate(x + w / 2, y + h * .45); ctx.rotate(i * Math.PI / 2); ctx.fillRect(-3, -w * .3, 6, w * .25); ctx.restore(); }
  }
}

function drawSpike(x, w, h) {
  ctx.fillStyle = '#D1D3D4';
  ctx.beginPath();
  ctx.moveTo(x, ground);
  ctx.lineTo(x + w * .49, ground - h);
  ctx.lineTo(x + w, ground);
  ctx.closePath();
  ctx.fill();
  ctx.fillStyle = '#00A1E4';
  ctx.beginPath();
  ctx.moveTo(x + w * .49, ground - h);
  ctx.lineTo(x + w * .35, ground - h * .71);
  ctx.lineTo(x + w * .63, ground - h * .71);
  ctx.closePath();
  ctx.fill();
  ctx.fillStyle = '#58595B';
  ctx.fillRect(x - 2, ground - 3, w + 4, 4);
}

function drawObstacles() {
  for (const o of obstacles) {
    const spikeWidth = o.w / (1 + .75 * (o.count - 1));
    for (let i = 0; i < o.count; i++) {
      drawSpike(o.x + i * spikeWidth * .75, spikeWidth, o.h);
    }
  }
}

function drawParticles() {
  for (const p of particles) {
    ctx.globalAlpha = Math.min(1, p.life * 2);
    ctx.fillStyle = '#D1D3D4';
    ctx.beginPath(); ctx.arc(p.x, p.y, 1.7, 0, Math.PI * 2); ctx.fill();
  }
  ctx.globalAlpha = 1;
}

function draw() {
  if (!width || !height) return;
  drawBackground();
  drawObstacles();
  drawParticles();
  drawCooler();
}

function frame(now) {
  const dt = Math.min((now - lastTime) / 1000 || 0, .04);
  lastTime = now;
  if (mode === 'playing') update(dt);
  draw();
  requestAnimationFrame(frame);
}

document.querySelector('#startBtn').addEventListener('click', event => {
  event.stopPropagation();
  if (mode === 'paused') togglePause(); else void requestStart();
});
document.querySelector('#jumpBtn').addEventListener('click', jump);
stage.addEventListener('pointerdown', event => { if (event.target.closest('button')) return; jump(); });
pauseBtn.addEventListener('click', togglePause);
soundBtn.addEventListener('click', () => {
  soundOn = !soundOn;
  soundBtn.classList.toggle('muted', !soundOn);
  soundBtn.setAttribute('aria-label', soundOn ? 'قطع صدا' : 'وصل صدا');
});
document.addEventListener('keydown', event => {
  if (['Space', 'ArrowUp', 'KeyW'].includes(event.code)) { event.preventDefault(); if (!event.repeat) jump(); }
  if (event.code === 'KeyP' || event.code === 'Escape') { event.preventDefault(); togglePause(); }
  if (event.code === 'KeyR') { event.preventDefault(); void requestStart(); }
});
accountBtn.addEventListener('click', openAuth);
document.querySelector('#authClose').addEventListener('click', closeAuth);
document.querySelector('#authModal').addEventListener('click', event => { if (event.target === authModal) closeAuth(); });
sendCodeBtn.addEventListener('click', requestCode);
authForm.addEventListener('submit', verifyCode);
profileForm.addEventListener('submit', saveUsername);
logoutBtn.addEventListener('click', () => { void logout(); });
window.addEventListener('resize', resize);
cooler.onload = draw;
angledCooler.onload = draw;
resize();
requestAnimationFrame(frame);
void refreshProfile().then(() => refreshLeaderboard());

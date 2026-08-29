const loginView = document.querySelector("[data-login-view]");
const dashboard = document.querySelector("[data-dashboard]");
const loginForm = document.querySelector("[data-login-form]");
const loginMessage = document.querySelector("[data-login-message]");
const dashboardMessage = document.querySelector("[data-dashboard-message]");
const requestList = document.querySelector("[data-request-list]");
const emptyState = document.querySelector("[data-empty-state]");
const searchInput = document.querySelector("[data-search]");
const statusFilter = document.querySelector("[data-status-filter]");
const dialog = document.querySelector("[data-request-dialog]");
let requests = [];

const statusLabels = {
  new: "Yeni",
  contacted: "İletişime geçildi",
  closed: "Kapatıldı",
};

function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function formatDate(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "—"
    : new Intl.DateTimeFormat("tr-TR", { dateStyle: "medium", timeStyle: "short" }).format(date);
}

async function api(path, options = {}) {
  const response = await fetch(path, {
    credentials: "same-origin",
    headers: { "Content-Type": "application/json", ...(options.headers || {}) },
    ...options,
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(data.error || "İşlem tamamlanamadı.");
    error.status = response.status;
    throw error;
  }
  return data;
}

function setAuthenticated(authenticated, username = "") {
  loginView.hidden = authenticated;
  dashboard.hidden = !authenticated;
  const adminName = document.querySelector("[data-admin-name]");
  if (adminName) adminName.textContent = username;
}

function updateStats() {
  document.querySelector("[data-stat-total]").textContent = String(requests.length);
  document.querySelector("[data-stat-new]").textContent = String(requests.filter((item) => item.status === "new").length);
  document.querySelector("[data-stat-contacted]").textContent = String(requests.filter((item) => item.status === "contacted").length);
}

function renderRequests() {
  const query = searchInput.value.trim().toLocaleLowerCase("tr-TR");
  const selectedStatus = statusFilter.value;
  const filtered = requests.filter((item) => {
    const haystack = [item.fullName, item.phone, item.city, item.industry, item.area, item.message]
      .join(" ")
      .toLocaleLowerCase("tr-TR");
    return (!query || haystack.includes(query)) && (selectedStatus === "all" || item.status === selectedStatus);
  });

  requestList.innerHTML = filtered.map((item) => `
    <tr>
      <td>${escapeHtml(formatDate(item.createdAt))}</td>
      <td><strong>${escapeHtml(item.fullName)}</strong><small>${escapeHtml(item.message).slice(0, 70)}${item.message.length > 70 ? "…" : ""}</small></td>
      <td><a href="tel:${escapeHtml(item.phone)}">${escapeHtml(item.phone)}</a></td>
      <td>${escapeHtml(item.city || "—")}<small>${escapeHtml(item.industry || "Belirtilmedi")}</small></td>
      <td>${item.area ? `${escapeHtml(item.area)} m²` : "—"}</td>
      <td>
        <select class="status-select" data-status-id="${escapeHtml(item.id)}" aria-label="${escapeHtml(item.fullName)} talep durumu">
          ${Object.entries(statusLabels).map(([value, label]) => `<option value="${value}" ${item.status === value ? "selected" : ""}>${label}</option>`).join("")}
        </select>
      </td>
      <td><button type="button" class="detail-button" data-detail-id="${escapeHtml(item.id)}">Detay</button></td>
    </tr>
  `).join("");

  emptyState.hidden = filtered.length > 0;
}

async function loadRequests() {
  dashboardMessage.textContent = "Talepler yükleniyor...";
  try {
    const data = await api("api.php?action=list");
    requests = data.items || [];
    updateStats();
    renderRequests();
    dashboardMessage.textContent = requests.length ? `Son güncelleme: ${new Date().toLocaleTimeString("tr-TR", { hour: "2-digit", minute: "2-digit" })}` : "Henüz kayıtlı talep yok.";
  } catch (error) {
    if (error.status === 401) setAuthenticated(false);
    dashboardMessage.textContent = error.message;
  }
}

function openDetails(id) {
  const item = requests.find((candidate) => candidate.id === id);
  if (!item) return;
  document.querySelector("[data-detail-name]").textContent = item.fullName;
  document.querySelector("[data-detail-grid]").innerHTML = [
    ["Tarih", formatDate(item.createdAt)],
    ["Telefon", item.phone],
    ["Şehir", item.city || "Belirtilmedi"],
    ["İşletme / sektör", item.industry || "Belirtilmedi"],
    ["Yaklaşık alan", item.area ? `${item.area} m²` : "Belirtilmedi"],
    ["Durum", statusLabels[item.status] || item.status],
  ].map(([label, value]) => `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join("");
  document.querySelector("[data-detail-message]").textContent = item.message;
  dialog.showModal();
}

loginForm?.addEventListener("submit", async (event) => {
  event.preventDefault();
  const button = loginForm.querySelector("button");
  const data = new FormData(loginForm);
  button.disabled = true;
  loginMessage.textContent = "Giriş yapılıyor...";
  try {
    const result = await api("api.php?action=login", {
      method: "POST",
      body: JSON.stringify({ username: data.get("username"), password: data.get("password") }),
    });
    loginForm.reset();
    setAuthenticated(true, result.username);
    await loadRequests();
  } catch (error) {
    loginMessage.textContent = error.message;
  } finally {
    button.disabled = false;
  }
});

document.querySelector("[data-logout]")?.addEventListener("click", async () => {
  try { await api("api.php?action=logout", { method: "POST" }); } catch {}
  requests = [];
  setAuthenticated(false);
});

document.querySelector("[data-refresh]")?.addEventListener("click", loadRequests);
searchInput?.addEventListener("input", renderRequests);
statusFilter?.addEventListener("change", renderRequests);
document.querySelector("[data-dialog-close]")?.addEventListener("click", () => dialog.close());
dialog?.addEventListener("click", (event) => { if (event.target === dialog) dialog.close(); });

requestList?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-detail-id]");
  if (button) openDetails(button.dataset.detailId);
});

requestList?.addEventListener("change", async (event) => {
  const select = event.target.closest("[data-status-id]");
  if (!select) return;
  select.disabled = true;
  try {
    await api("api.php?action=status", {
      method: "POST",
      body: JSON.stringify({ id: select.dataset.statusId, status: select.value }),
    });
    const item = requests.find((candidate) => candidate.id === select.dataset.statusId);
    if (item) item.status = select.value;
    updateStats();
    dashboardMessage.textContent = "Talep durumu güncellendi.";
  } catch (error) {
    dashboardMessage.textContent = error.message;
    await loadRequests();
  } finally {
    select.disabled = false;
  }
});

async function initialize() {
  if (location.protocol === "file:") {
    document.querySelector("[data-server-warning]").hidden = false;
    loginForm.querySelectorAll("input, button").forEach((element) => { element.disabled = true; });
    return;
  }
  try {
    const session = await api("api.php?action=session");
    setAuthenticated(true, session.username);
    await loadRequests();
  } catch {
    setAuthenticated(false);
  }
}

initialize();

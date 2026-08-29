const year = document.querySelector("#year");
const header = document.querySelector("[data-header]");
const nav = document.querySelector("[data-nav]");
const navToggle = document.querySelector("[data-nav-toggle]");
const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

if (year) {
  year.textContent = String(new Date().getFullYear());
}

function updateHeader() {
  header?.classList.toggle("is-scrolled", window.scrollY > 18);
}

updateHeader();
window.addEventListener("scroll", updateHeader, { passive: true });

function setMenu(open) {
  if (!nav || !navToggle) return;

  nav.classList.toggle("is-open", open);
  navToggle.setAttribute("aria-expanded", String(open));
  navToggle.setAttribute("aria-label", open ? "Menüyü kapat" : "Menüyü aç");
  document.body.classList.toggle("nav-open", open);
}

navToggle?.addEventListener("click", () => {
  setMenu(navToggle.getAttribute("aria-expanded") !== "true");
});

nav?.querySelectorAll("a").forEach((link) => {
  link.addEventListener("click", () => setMenu(false));
});

window.addEventListener("keydown", (event) => {
  if (event.key === "Escape") setMenu(false);
});

window.addEventListener("resize", () => {
  if (window.innerWidth > 860) setMenu(false);
});

const visual = document.querySelector(".hero__visual");

if (visual && !reduceMotion.matches && window.matchMedia("(pointer: fine)").matches) {
  window.addEventListener("pointermove", (event) => {
    const x = (event.clientX / window.innerWidth - 0.5) * 9;
    const y = (event.clientY / window.innerHeight - 0.5) * 9;

    document.documentElement.style.setProperty("--mouse-x", `${x}px`);
    document.documentElement.style.setProperty("--mouse-y", `${y}px`);
  });
}

const faqItems = [...document.querySelectorAll(".faq__list details")];

faqItems.forEach((item) => {
  item.addEventListener("toggle", () => {
    if (!item.open) return;
    faqItems.forEach((other) => {
      if (other !== item) other.open = false;
    });
  });
});

const form = document.querySelector("[data-lead-form]");
const formStatus = document.querySelector("[data-form-status]");

function validateField(field) {
  const label = field.closest("label");
  const error = label?.querySelector(".field-error");
  let message = "";

  if (field.required && !field.value.trim()) {
    message = "Bu alan zorunludur.";
  } else if (field.type === "email" && field.value && !field.validity.valid) {
    message = "Geçerli bir e-posta adresi girin.";
  } else if (field.type === "number" && field.value && Number(field.value) < 1) {
    message = "1 veya daha büyük bir değer girin.";
  }

  label?.classList.toggle("has-error", Boolean(message));
  field.setAttribute("aria-invalid", String(Boolean(message)));
  if (error) error.textContent = message;
  return !message;
}

form?.querySelectorAll("input, select, textarea").forEach((field) => {
  field.addEventListener("blur", () => validateField(field));
  field.addEventListener("input", () => {
    if (field.closest("label")?.classList.contains("has-error")) validateField(field);
    formStatus?.classList.remove("is-notice");
    if (formStatus) formStatus.textContent = "";
  });
});

form?.addEventListener("submit", (event) => {
  event.preventDefault();

  const fields = [...form.querySelectorAll("input, select, textarea")];
  const isValid = fields.map(validateField).every(Boolean);

  if (!isValid) {
    const firstInvalid = form.querySelector('[aria-invalid="true"]');
    firstInvalid?.focus();
    if (formStatus) {
      formStatus.classList.remove("is-notice");
      formStatus.textContent = "Lütfen işaretli alanları kontrol edin.";
    }
    return;
  }

  if (formStatus) {
    formStatus.classList.add("is-notice");
    formStatus.textContent =
      "Bilgileriniz doğrulandı ancak online gönderim henüz aktif değil; verileriniz kaydedilmedi. Lütfen telefon veya WhatsApp üzerinden bize ulaşın.";
  }
});

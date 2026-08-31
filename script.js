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
const successDialog = document.querySelector("[data-success-dialog]");
const successDialogClose = document.querySelector("[data-success-close]");

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

form?.addEventListener("submit", async (event) => {
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

  const data = new FormData(form);
  const submitButton = form.querySelector('[type="submit"]');
  const payload = {
    fullName: data.get("fullName"),
    phone: data.get("phone"),
    city: data.get("city"),
    industry: data.get("industry"),
    area: data.get("area"),
    message: data.get("message"),
  };
  submitButton.disabled = true;
  submitButton.setAttribute("aria-busy", "true");
  if (formStatus) {
    formStatus.classList.remove("is-notice");
    formStatus.textContent = "Talebiniz gönderiliyor...";
  }

  try {
    if (location.protocol !== "http:" && location.protocol !== "https:") {
      throw new Error("Danışmanlık formu yalnızca yayınlanmış site üzerinde kullanılabilir.");
    }

    const response = await fetch("api.php?action=create", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.error || "Talebiniz şu anda gönderilemedi.");

    form.reset();
    fields.forEach((field) => {
      field.removeAttribute("aria-invalid");
      field.closest("label")?.classList.remove("has-error");
    });
    if (formStatus) {
      formStatus.classList.remove("is-notice");
      formStatus.textContent = "";
    }
    document.body.classList.add("modal-open");
    successDialog?.showModal();
  } catch (error) {
    if (formStatus) {
      formStatus.classList.remove("is-notice");
      formStatus.textContent = error.message;
    }
  } finally {
    submitButton.disabled = false;
    submitButton.removeAttribute("aria-busy");
  }
});

successDialogClose?.addEventListener("click", () => successDialog.close());

successDialog?.addEventListener("click", (event) => {
  if (event.target === successDialog) successDialog.close();
});

successDialog?.addEventListener("close", () => {
  document.body.classList.remove("modal-open");
});

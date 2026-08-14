/* ============================================================
   BINALGO Auth UI — Shared JS (login / register)
   Exposes window.Auth — a small, dependency-free helper kit.
   ============================================================ */
(function () {
  "use strict";

  const reEmail = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

  function normalizePhone(v) {
    return (v || "").replace(/[\s\-().]/g, "");
  }
  function isValidPhone(v) {
    const n = normalizePhone(v);
    if (!n) return false;
    if (/^09\d{9}$/.test(n)) return true;
    if (/^\+?639\d{9}$/.test(n)) return true;
    if (/^9\d{9}$/.test(n)) return true;
    return false;
  }
  function isValidEmail(v) {
    return reEmail.test((v || "").trim());
  }
  function isValidAge(v) {
    const n = parseInt(v, 10);
    return Number.isFinite(n) && n >= 12 && n <= 120;
  }
  function isValidName(v) {
    return (v || "").trim().length >= 2 && (v || "").trim().length <= 100;
  }

  function debounce(fn, ms) {
    let t;
    return function () {
      const args = arguments;
      const ctx = this;
      clearTimeout(t);
      t = setTimeout(() => fn.apply(ctx, args), ms);
    };
  }

  /* Field state helpers — the input carries is-ok / is-bad, a .auth-validity
     icon and an optional .auth-field-msg message element are updated. */
  function setFieldState(input, valid, opts) {
    opts = opts || {};
    input.classList.toggle("is-ok", valid);
    input.classList.toggle("is-bad", !valid);
    const validity = input.parentElement.querySelector(".auth-validity");
    if (validity) {
      validity.className = "auth-validity " + (valid ? "is-ok" : "is-bad");
      validity.innerHTML =
        '<i class="fas fa-' + (valid ? "check-circle" : "exclamation-circle") + '"></i>';
    }
    if (opts.msgEl) {
      opts.msgEl.classList.toggle("show", !valid);
      opts.msgEl.classList.toggle("is-ok", valid);
      opts.msgEl.classList.toggle("is-bad", !valid);
      opts.msgEl.querySelector("i").className =
        "fas fa-" + (valid ? "check-circle" : "exclamation-circle");
      opts.msgEl.querySelector("span").textContent = valid
        ? (opts.okText || "")
        : (opts.badText || "");
    }
  }

  function clearFieldState(input) {
    input.classList.remove("is-ok", "is-bad");
    const validity = input.parentElement.querySelector(".auth-validity");
    if (validity) validity.className = "auth-validity";
    const msg = input.closest(".auth-input-group").querySelector(".auth-field-msg");
    if (msg) {
      msg.classList.remove("show", "is-ok", "is-bad");
    }
  }

  function focusInvalid(form) {
    const bad = form.querySelector(".is-bad, input:invalid, select:invalid");
    if (bad) {
      bad.focus();
      bad.scrollIntoView({ behavior: "smooth", block: "center" });
    }
  }

  /* Password strength: returns { score: 0-4, label } */
  function strengthOf(pw) {
    pw = pw || "";
    let score = 0;
    if (pw.length >= 8) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/\d/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    if (pw.length >= 12 && score >= 3) score = 4;
    const labels = ["Too weak", "Weak", "Fair", "Good", "Strong"];
    return { score: score, label: labels[score] };
  }

  /* Criteria checklist: items with [data-crit] keys + test map */
  const CRITERIA_TESTS = {
    len: (pw) => pw.length >= 8,
    upper: (pw) => /[A-Z]/.test(pw),
    num: (pw) => /\d/.test(pw),
    special: (pw) => /[^A-Za-z0-9]/.test(pw),
  };

  function setupPasswordToggle(input, toggle) {
    toggle.addEventListener("click", function () {
      const show = input.type === "password";
      input.type = show ? "text" : "password";
      const icon = toggle.querySelector("i");
      icon.className = "fas fa-" + (show ? "eye-slash" : "eye");
      toggle.setAttribute("aria-pressed", show ? "true" : "false");
      toggle.setAttribute("aria-label", show ? "Hide password" : "Show password");
      input.focus({ preventScroll: true });
      const end = input.value.length;
      input.setSelectionRange && input.setSelectionRange(end, end);
    });
  }

  /* Ripple feedback (event delegation, safe on elements with .auth-ripple) */
  function setupRipple() {
    document.addEventListener("click", function (e) {
      const target = e.target.closest(".auth-ripple");
      if (!target) return;
      const rect = target.getBoundingClientRect();
      const size = Math.max(rect.width, rect.height);
      const ripple = document.createElement("span");
      ripple.className = "ripple";
      ripple.style.width = ripple.style.height = size + "px";
      ripple.style.left = e.clientX - rect.left - size / 2 + "px";
      ripple.style.top = e.clientY - rect.top - size / 2 + "px";
      target.appendChild(ripple);
      setTimeout(() => ripple.remove(), 600);
    });
  }

  /* Async submit via fetch (JSON). Caller: form + {btn, onOk, onError} */
  function postJSON(form, opts) {
    const data = new FormData(form);
    return fetch(form.action, {
      method: "POST",
      body: data,
      credentials: "same-origin",
      headers: { "X-Requested-With": "xmlhttprequest" },
    })
      .then((res) => res.json())
      .then(function (json) {
        if (json && json.ok) {
          if (opts.onOk) opts.onOk(json);
        } else {
          const msg = (json && json.message) || "Something went wrong. Please try again.";
          if (opts.onError) opts.onError(msg);
        }
      })
      .catch(function () {
        if (opts.onError) opts.onError("Network error. Please try again.");
      });
  }

  function setButtonLoading(btn, loading) {
    btn.classList.toggle("is-loading", loading);
    btn.disabled = loading;
    if (loading) {
      btn.setAttribute("aria-busy", "true");
    } else {
      btn.removeAttribute("aria-busy");
    }
  }

  function showBanner(banner, msg, type) {
    if (!banner) return;
    banner.classList.remove("error", "info");
    if (type) banner.classList.add(type);
    const icon = banner.querySelector(".auth-banner-icon");
    if (icon) icon.className = "auth-banner-icon fas fa-circle-exclamation";
    const text = banner.querySelector(".auth-banner-text");
    if (text) text.textContent = msg;
    banner.classList.add("show");
    banner.setAttribute("role", type === "info" ? "status" : "alert");
  }

  function hideBanner(banner) {
    if (!banner) return;
    banner.classList.remove("show");
  }

  window.Auth = {
    isValidEmail: isValidEmail,
    isValidPhone: isValidPhone,
    isValidAge: isValidAge,
    isValidName: isValidName,
    normalizePhone: normalizePhone,
    debounce: debounce,
    setFieldState: setFieldState,
    clearFieldState: clearFieldState,
    focusInvalid: focusInvalid,
    strengthOf: strengthOf,
    CRITERIA_TESTS: CRITERIA_TESTS,
    setupPasswordToggle: setupPasswordToggle,
    setupRipple: setupRipple,
    postJSON: postJSON,
    setButtonLoading: setButtonLoading,
    showBanner: showBanner,
    hideBanner: hideBanner,
  };

  setupRipple();
})();

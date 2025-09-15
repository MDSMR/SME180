// Microsoft 365–style interactions for Discounts
// Path: /public_html/views/admin/rewards/discounts/_shared/scripts.js
(function(){
  "use strict";

  const $$ = (sel, root=document) => Array.from(root.querySelectorAll(sel));
  const $  = (sel, root=document) => root.querySelector(sel);

  // Tabs
  $$(".ms-tab").forEach(tab => {
    tab.addEventListener("click", () => {
      const parent = tab.closest("[data-tabs]");
      if (!parent) return;
      const target = tab.getAttribute("data-target");
      $$(".ms-tab", parent).forEach(t => t.classList.remove("active"));
      tab.classList.add("active");
      $$(".tab-panel", parent).forEach(p => p.classList.toggle("hidden", p.id !== target));
      try { localStorage.setItem(parent.id || "discounts-tabs", target); } catch(_){}
    });
  });

  // Restore last selected tab
  (function(){
    const holder = document.querySelector("[data-tabs]");
    if (!holder) return;
    const key = holder.id || "discounts-tabs";
    const saved = localStorage.getItem(key);
    if (saved && document.getElementById(saved)) {
      holder.querySelectorAll(".ms-tab").forEach(t => t.classList.toggle("active", t.getAttribute("data-target") === saved));
      holder.querySelectorAll(".tab-panel").forEach(p => p.classList.toggle("hidden", p.id !== saved));
    }
  })();

  // Dirty form guard + enable save on change
  $$("form[data-dirty-guard]").forEach(form => {
    let dirty = false;
    const saveBtn = form.querySelector(".js-save") || document.querySelector(".js-save-global");
    form.addEventListener("input", () => {
      dirty = true;
      if (saveBtn) saveBtn.removeAttribute("disabled");
    });
    window.addEventListener("beforeunload", (e) => {
      if (dirty) { e.preventDefault(); e.returnValue = ""; }
    });
  });

  // Back / Cancel
  $$("[data-back]").forEach(btn => btn.addEventListener("click", (e) => {
    e.preventDefault();
    if (history.length > 1) history.back(); else window.location.href = btn.getAttribute("href") || "/views/admin/rewards/discounts/index.php";
  }));
  $$("[data-cancel]").forEach(btn => btn.addEventListener("click", (e) => {
    e.preventDefault();
    window.location.href = btn.getAttribute("href") || "/views/admin/rewards/discounts/index.php";
  }));

})();

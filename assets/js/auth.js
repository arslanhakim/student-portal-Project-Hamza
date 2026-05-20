/**
 * Auth pages — small UX enhancements.
 */
(function () {
  "use strict";

  // Password show/hide toggle
  document.querySelectorAll(".password-toggle").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var targetId = btn.getAttribute("data-target");
      var input = document.getElementById(targetId);
      if (!input) return;

      if (input.type === "password") {
        input.type = "text";
        btn.textContent = "Hide";
        btn.setAttribute("aria-label", "Hide password");
      } else {
        input.type = "password";
        btn.textContent = "Show";
        btn.setAttribute("aria-label", "Show password");
      }
    });
  });
})();

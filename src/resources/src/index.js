import tippy from "tippy.js";

// Two modes, chosen by the markup: Terms.php emits <button> when the plugin's
// accessiblePopups setting is on, <span> when it is off.

const FOCUSABLE = [
  "a[href]",
  "button:not([disabled])",
  "input:not([disabled])",
  "select:not([disabled])",
  "textarea:not([disabled])",
  '[tabindex]:not([tabindex="-1"])'
].join(", ");

let counter = 0;

function buildContent(trigger, definitionHtml) {
  const id = `glossary-popup-${++counter}`;

  const wrapper = document.createElement("div");
  wrapper.className = "glossary-popup";

  // Names the dialog via aria-labelledby; hidden, as the word is already
  // visible in the sentence behind the bubble.
  const heading = document.createElement("h2");
  heading.className = "glossary-popup__term";
  heading.id = `${id}-label`;
  heading.textContent = trigger.textContent.trim();

  const body = document.createElement("div");
  body.className = "glossary-popup__body";
  body.id = `${id}-body`;
  body.innerHTML = definitionHtml;

  const close = document.createElement("button");
  close.type = "button";
  close.className = "glossary-popup__close";
  close.setAttribute("aria-label", `Close definition of ${heading.textContent}`);
  close.textContent = "X";

  wrapper.append(heading, body, close);

  return { wrapper, close, labelId: heading.id, bodyId: body.id };
}

// Esc to close, Tab trapped. Mirrors the site's video modal.
function keydownHandler(instance, box) {
  return event => {
    if (event.key === "Escape") {
      event.stopPropagation();
      instance.hide();
      return;
    }

    if (event.key !== "Tab") return;

    const focusable = [...box.querySelectorAll(FOCUSABLE)].filter(
      el => el.offsetParent !== null
    );
    if (!focusable.length) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;

    // Wrap at both ends, and pull focus back if it has escaped the bubble.
    if (event.shiftKey && (active === first || !box.contains(active))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && (active === last || !box.contains(active))) {
      event.preventDefault();
      first.focus();
    }
  };
}

export function initAccessible(root = document) {
  const triggers = [
    ...root.querySelectorAll("button.glossary[data-glossary-term]")
  ];
  if (!triggers.length) return [];

  return triggers
    .map(trigger => {
      // The bundle can load twice on one page (iste.org publishes it by hand
      // as well as via the asset bundle). Tippy does not guard this itself: it
      // would overwrite _tippy and leave the first instance's focus trap bound.
      if (trigger._tippy) return null;

      const source = document.getElementById(trigger.dataset.glossaryTerm);
      if (!source) return null;

      const { wrapper, close, labelId, bodyId } = buildContent(
        trigger,
        source.innerHTML
      );

      let onKeydown = null;

      return tippy(trigger, {
        content: wrapper,
        allowHTML: true,
        interactive: true,
        // Not hover or focus: those fire as a reader tabs past the word.
        trigger: "click",
        role: "dialog",
        theme: "glossary",
        placement: "top",
        // Tippy writes max-width inline, overriding the stylesheet.
        maxWidth: "min(22rem, calc(100vw - 32px))",
        appendTo: () => document.body,
        // Both off so the attributes below are ours: Tippy's 'auto' did not
        // track the open state, leaving aria-expanded="false" on open dialogs.
        aria: { content: null, expanded: false },

        onCreate(instance) {
          const box = instance.popper.querySelector(".tippy-box");
          if (!box) return;

          box.setAttribute("aria-labelledby", labelId);
          // Focus lands on the close button, so this is the only thing that
          // makes a screen reader read the definition.
          box.setAttribute("aria-describedby", bodyId);
          box.setAttribute("aria-modal", "true");
        },

        onShow() {
          trigger.setAttribute("aria-expanded", "true");
        },

        onShown(instance) {
          const box = instance.popper;

          close.focus();

          onKeydown = keydownHandler(instance, box);
          box.addEventListener("keydown", onKeydown);
        },

        onHidden(instance) {
          trigger.setAttribute("aria-expanded", "false");

          if (onKeydown) {
            instance.popper.removeEventListener("keydown", onKeydown);
            onKeydown = null;
          }

          // Only reclaim focus if it has nowhere else to be, so clicking
          // elsewhere on the page is not yanked back to the term.
          if (
            !document.activeElement ||
            document.activeElement === document.body
          ) {
            trigger.focus();
          }
        }
      });
    })
    .filter(Boolean);
}

export function initLegacy(root = document) {
  // :not(button) is load-bearing — a bare .glossary also matches an accessible
  // trigger and would hang a hover tooltip off every dialog button.
  const triggers = [
    ...root.querySelectorAll(".glossary[data-glossary-term]:not(button)")
  ];
  if (!triggers.length) return [];

  return triggers
    .map(trigger => {
      if (trigger._tippy) return null;

      return tippy(trigger, {
        content(reference) {
          const id = reference.getAttribute("data-glossary-term");
          const template = document.getElementById(id);

          return template ? template.innerHTML : "";
        },
        theme: "light",
        allowHTML: true
      });
    })
    .filter(Boolean);
}

// Each is a no-op when its own markup is absent.
export function initGlossary(root = document) {
  return [...initAccessible(root), ...initLegacy(root)];
}

// Delegated: Tippy owns the bubble DOM and rebuilds it.
document.addEventListener("click", event => {
  const close = event.target.closest?.(".glossary-popup__close");
  if (!close) return;

  const box = close.closest(".tippy-box");
  box?._tippy?.hide() ?? box?.parentElement?._tippy?.hide();
});

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => initGlossary());
} else {
  initGlossary();
}

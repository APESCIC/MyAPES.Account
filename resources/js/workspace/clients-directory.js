export function initClientsDirectory() {
  const root = document.querySelector("[data-clients-directory]");
  if (!(root instanceof HTMLElement)) {
    return;
  }

  const tablist = root.querySelector("[data-clients-tablist]");
  if (tablist instanceof HTMLElement) {
    const tabs = [...tablist.querySelectorAll('[role="tab"]')].filter(
      (el) => el instanceof HTMLAnchorElement,
    );

    tablist.addEventListener("keydown", (event) => {
      if (!(event instanceof KeyboardEvent)) {
        return;
      }
      if (event.key !== "ArrowRight" && event.key !== "ArrowLeft" && event.key !== "Home" && event.key !== "End") {
        return;
      }
      const currentIndex = tabs.findIndex((tab) => tab === document.activeElement);
      if (currentIndex < 0) {
        return;
      }
      event.preventDefault();
      let next = currentIndex;
      if (event.key === "ArrowRight") {
        next = (currentIndex + 1) % tabs.length;
      } else if (event.key === "ArrowLeft") {
        next = (currentIndex - 1 + tabs.length) % tabs.length;
      } else if (event.key === "Home") {
        next = 0;
      } else if (event.key === "End") {
        next = tabs.length - 1;
      }
      tabs[next]?.focus();
    });
  }

  const stateFilter = document.querySelector("[data-clients-state-filter]");
  if (stateFilter instanceof HTMLSelectElement) {
    stateFilter.addEventListener("change", () => {
      const form = stateFilter.form;
      if (form instanceof HTMLFormElement) {
        const hiddenTab = form.querySelector('input[name="directory_tab"]');
        if (hiddenTab instanceof HTMLInputElement) {
          hiddenTab.remove();
        }
        form.requestSubmit();
      }
    });
  }
}
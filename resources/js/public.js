import "../css/app.css";
import "../css/public.css";
import { dateTime } from "./dates";
document.querySelectorAll("time[data-public-date]").forEach((element) => {
    element.textContent = dateTime(element.dateTime);
});
const menu = document.querySelector("[data-public-menu]");
const toggle = document.querySelector("[data-menu-toggle]");
toggle?.addEventListener("click", () => {
    const open = toggle.getAttribute("aria-expanded") !== "true";
    toggle.setAttribute("aria-expanded", String(open));
    menu?.classList.toggle("is-open", open);
});
document.querySelector("[data-theme-toggle]")?.addEventListener("click", () => {
    const theme =
        document.documentElement.dataset.theme === "dark" ? "light" : "dark";
    document.documentElement.dataset.theme = theme;
    try {
        localStorage.setItem("kd-theme", theme);
    } catch {}
});
document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
        toggle?.setAttribute("aria-expanded", "false");
        menu?.classList.remove("is-open");
    }
});

<script setup>
import { computed, ref } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import Icon from "../Components/Icon.vue";
const props = defineProps({
    title: String,
    role: { type: String, default: "student" },
});
const page = usePage();
const mobile = ref(false);
const roleOpen = ref(false);
const roles = {
    student: "پنل دانشجو",
    coach: "پنل مربی",
    org_admin: "پنل سازمان",
    admin: "مدیریت کاردرست",
};
const urls = {
    student: "/student",
    coach: "/coach",
    org_admin: "/organization",
    admin: "/admin",
};
const menus = computed(() =>
    props.role === "admin"
        ? [
              ["خانه مدیریت", "LayoutDashboard", "/admin"],
              ["دوره‌ها", "BookOpen", "/admin/courses"],
              ["مالی و رسیدها", "ReceiptText", "/admin/finance"],
              ["مشاوره", "MessagesSquare", "/consultations?view=admin"],
              ["کاربران", "Users", "/admin/users"],
              ["سازمان‌ها", "Building2", "/admin/organizations"],
              ["سابقه فعالیت", "History", "/admin/activity"],
          ]
        : props.role === "org_admin"
          ? [
                ["سازمان من", "Building2", "/organization"],
                ["حساب من", "UserRound", "/account"],
                ["پرداخت‌ها و اقساط", "ReceiptText", "/finance"],
            ]
          : props.role === "coach"
            ? [
                  ["خانه مربی", "LayoutDashboard", "/coach"],
                  ["مشاوره", "MessagesSquare", "/consultations?view=coach"],
                  ["حساب من", "UserRound", "/account"],
                  ["پرداخت‌ها و اقساط", "ReceiptText", "/finance"],
              ]
            : [
                  ["خانه", "House", "/student"],
                  ["یادگیری من", "BookOpen", "/learning"],
                  ["دوره‌ها", "Compass", "/courses"],
                  ["خودشناسی", "Fingerprint", null],
                  ["مشاوره", "MessagesSquare", "/consultations"],
                  ["حساب من", "UserRound", "/account"],
                  ["پرداخت‌ها و اقساط", "ReceiptText", "/finance"],
              ],
);
function switchRole(role) {
    roleOpen.value = false;
    router.post("/panel/role", { role });
}
const dark = ref(document.documentElement.dataset.theme === "dark");
function toggleTheme() {
    dark.value = !dark.value;
    document.documentElement.dataset.theme = dark.value ? "dark" : "light";
    try {
        localStorage.setItem("kd-theme", dark.value ? "dark" : "light");
    } catch (e) {}
}
const current = (href) => {
    const path = page.url.split("?")[0];
    return (
        href &&
        (page.url === href ||
            path === href ||
            (href === "/learning" && path.startsWith("/learning/")) ||
            (href === "/admin/courses" && path.startsWith("/teaching/")) ||
            (href === "/coach" && path.startsWith("/teaching/")))
    );
};
</script>
<template>
    <div
        class="app-shell"
        @keydown.esc="
            mobile = false;
            roleOpen = false;
        "
    >
        <button
            v-if="mobile"
            class="mobile-scrim"
            @click="mobile = false"
            aria-label="بستن منو"
        ></button>
        <aside
            class="sidebar glass"
            :class="{ 'is-open': mobile }"
            aria-label="ناوبری اصلی"
        >
            <Link class="brand" :href="urls[role]"
                ><img
                    src="/brand/logo.svg"
                    class="logo-light"
                    alt="کاردرست"
                    width="150" /><img
                    src="/brand/logo-reverse.svg"
                    class="logo-dark"
                    alt="کاردرست"
                    width="150"
            /></Link>
            <div class="workspace-label">
                <span class="online-dot"></span>{{ roles[role] }}
            </div>
            <nav>
                <template v-for="[label, icon, href] in menus" :key="label">
                    <Link
                        v-if="href"
                        :href="href"
                        :class="['nav-item', { active: current(href) }]"
                        :aria-current="current(href) ? 'page' : undefined"
                        @click="mobile = false"
                        ><Icon :name="icon" /><span>{{ label }}</span
                        ><span
                            v-if="current(href)"
                            class="nav-active-dot"
                        ></span
                    ></Link>
                    <span
                        v-else
                        class="nav-item unavailable"
                        aria-disabled="true"
                        ><Icon :name="icon" /><span>{{ label }}</span
                        ><small>به‌زودی</small></span
                    >
                </template>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-note">
                    <Icon name="Waypoints" :size="25" />
                    <p>مسیر شما،<br /><strong>قدم‌به‌قدم روشن‌تر.</strong></p>
                    <span class="note-line"></span>
                </div>
                <div class="account-mini">
                    <span class="avatar">{{
                        page.props.auth.user?.name?.slice(0, 1)
                    }}</span>
                    <div>
                        <strong>{{ page.props.auth.user?.name }}</strong
                        ><small>{{ roles[role] }}</small>
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="icon-btn"
                        aria-label="خروج از حساب"
                        ><Icon name="LogOut" :size="18"
                    /></Link>
                </div>
            </div>
        </aside>
        <div class="workspace">
            <header class="topbar">
                <div class="topbar-start">
                    <button
                        class="icon-btn mobile-toggle"
                        @click="mobile = !mobile"
                        :aria-expanded="mobile"
                        aria-label="باز کردن منو"
                    >
                        <Icon name="Menu" /></button
                    ><span class="breadcrumb"
                        >کاردرست <Icon name="ChevronLeft" :size="14" />
                        <strong>{{ title }}</strong></span
                    >
                </div>
                <div class="topbar-actions">
                    <Link
                        href="/notifications"
                        class="icon-btn notification-bell"
                        aria-label="اعلان‌ها"
                        ><Icon name="Bell" :size="19" /><span
                            v-if="page.props.unreadNotifications"
                            class="notification-count"
                            >{{
                                new Intl.NumberFormat("fa-IR").format(
                                    page.props.unreadNotifications,
                                )
                            }}</span
                        ></Link
                    >
                    <span class="today">{{
                        new Intl.DateTimeFormat("fa-IR", {
                            day: "numeric",
                            month: "long",
                            year: "numeric",
                            timeZone: "Asia/Tehran",
                        }).format(new Date())
                    }}</span
                    ><button
                        class="icon-btn"
                        @click="toggleTheme"
                        :aria-label="dark ? 'حالت روشن' : 'حالت تیره'"
                    >
                        <Icon :name="dark ? 'Sun' : 'Moon'" :size="19" />
                    </button>
                    <div
                        v-if="page.props.auth.roles.length > 1"
                        class="role-control"
                    >
                        <button
                            class="role-button"
                            @click="roleOpen = !roleOpen"
                            :aria-expanded="roleOpen"
                        >
                            <Icon name="PanelsTopLeft" :size="17" />تغییر
                            پنل<Icon name="ChevronDown" :size="14" />
                        </button>
                        <div v-if="roleOpen" class="role-menu glass">
                            <button
                                v-for="r in page.props.auth.roles"
                                :key="r"
                                @click="switchRole(r)"
                            >
                                {{ roles[r] }}
                            </button>
                        </div>
                    </div>
                </div>
            </header>
            <main id="main" tabindex="-1">
                <div
                    v-if="page.props.flash.success"
                    class="flash success"
                    role="status"
                >
                    <Icon name="CircleCheck" />{{ page.props.flash.success }}
                </div>
                <div
                    v-if="page.props.flash.error"
                    class="flash error"
                    role="alert"
                >
                    <Icon name="CircleAlert" />{{ page.props.flash.error }}
                </div>
                <div
                    v-if="Object.keys(page.props.errors || {}).length"
                    class="flash error"
                    role="alert"
                >
                    {{ Object.values(page.props.errors).join(" · ") }}
                </div>
                <slot />
            </main>
            <footer class="app-footer">
                <span>کاردرست · همراه مسیر حرفه‌ای شما</span
                ><span>خودشناسی، تصمیم‌گیری، بازنگری</span>
            </footer>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from "vue";
import { Link, router, usePage } from "@inertiajs/vue3";
import Icon from "../Components/Icon.vue";
import PanelNavLink from "../Components/PanelNavLink.vue";
const props = defineProps({
    title: String,
    role: { type: String, default: "student" },
});
const page = usePage();
const mobile = ref(false);
const roleOpen = ref(false);
function handleEscape(event) {
    if (event.key === "Escape") {
        mobile.value = false;
        roleOpen.value = false;
    }
}
onMounted(() => document.addEventListener("keydown", handleEscape));
onBeforeUnmount(() => document.removeEventListener("keydown", handleEscape));
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
const item = (label, icon, href) => ({ label, icon, href });
const group = (label, icon, items) => ({ label, icon, items });
const accountItems = [
    ["پروفایل و امنیت", "UserRound", "/account"],
    ["پرداخت‌ها و اقساط", "ReceiptText", "/finance"],
    ["امتیاز و نشان یادگیری", "Sparkles", "/learning-rewards"],
    ["بازپرداخت‌ها", "ReceiptText", "/refunds"],
    ["عضویت‌های سازمانی من", "Building2", "/organization-memberships"],
];
const menus = computed(() => {
    if (props.role === "admin")
        return [
            item("خانه مدیریت", "LayoutDashboard", "/admin"),
            group("آموزش", "BookOpen", [
                ["دوره‌ها", "BookOpen", "/admin/courses"],
                ["گواهی‌ها", "GraduationCap", "/admin/certificates"],
                ["معیارهای گواهی", "FileText", "/admin/certificate-policies"],
                [
                    "قواعد امتیاز یادگیری",
                    "Sparkles",
                    "/admin/learning-reward-policies",
                ],
                ["سابقه امتیاز کاربران", "History", "/admin/learning-rewards"],
                ["نظرسنجی دوره‌ها", "FileText", "/admin/surveys"],
                ["گفت‌وگوی دوره‌ها", "MessagesSquare", "/discussions"],
            ]),
            group("خودشناسی و مسیر شغلی", "Fingerprint", [
                [
                    "مدیریت ابزارهای خودشناسی",
                    "Fingerprint",
                    "/admin/assessments",
                ],
                ["کارتابل رزومه‌ها", "FileText", "/career/resumes?view=admin"],
            ]),
            group("مشاوره", "MessagesSquare", [
                ["پروفایل مربیان", "UserRound", "/admin/coach-profiles"],
                ["مدارک خصوصی مربیان", "FileText", "/admin/coach-credentials"],
                [
                    "جلسات و درخواست‌ها",
                    "CalendarDays",
                    "/consultations?view=admin",
                ],
                [
                    "سهمیه مشاوره",
                    "MessagesSquare",
                    "/admin/consultation-grants",
                ],
                [
                    "فروش پکیج مشاوره",
                    "MessagesSquare",
                    "/admin/consultation-packages",
                ],
                [
                    "مشاوره همراه دوره",
                    "MessagesSquare",
                    "/admin/course-benefits",
                ],
            ]),
            group("مالی", "ReceiptText", [
                ["مالی و رسیدها", "ReceiptText", "/admin/finance"],
                ["گزارش و خروجی‌ها", "Activity", "/admin/reports"],
                ["بازپرداخت‌ها", "ReceiptText", "/refunds?view=admin"],
            ]),
            group("سازمان‌ها", "Building2", [
                ["سازمان‌ها", "Building2", "/admin/organizations"],
                ["مدیران سازمان", "Users", "/admin/organization-managers"],
                ["سهمیه سازمان‌ها", "Users", "/admin/organization-licenses"],
                [
                    "صورتحساب سازمان‌ها",
                    "ReceiptText",
                    "/admin/organization-invoices",
                ],
            ]),
            item("محتوای سایت", "FileText", "/admin/content"),
            group("مدیریت و عملیات", "ShieldCheck", [
                ["کاربران", "Users", "/admin/users"],
                ["وضعیت اجرا", "Activity", "/admin/readiness"],
                ["سابقه فعالیت", "History", "/admin/activity"],
                ["پیامک و یادآوری‌ها", "Bell", "/admin/communications"],
                ["درخواست‌های سایت", "MessagesSquare", "/admin/contact-leads"],
                ["اعلان گروهی", "Bell", "/admin/announcements"],
            ]),
        ];
    if (props.role === "org_admin")
        return [
            item("سازمان من", "Building2", "/organization"),
            group("اعضا و دعوت‌ها", "Users", [
                ["دعوت اعضا", "UserPlus", "/organization/invitations"],
                [
                    "عضویت‌های سازمانی من",
                    "Building2",
                    "/organization-memberships",
                ],
            ]),
            group("دوره‌ها و قرارداد", "BookOpen", [
                ["ثبت‌نام گروهی", "Users", "/organization/licenses"],
                ["صورتحساب سازمان", "ReceiptText", "/organization/invoices"],
            ]),
            item("رزومه شخصی", "FileText", "/career/resumes"),
            group(
                "حساب من",
                "UserRound",
                accountItems.filter(
                    (row) => row[2] !== "/organization-memberships",
                ),
            ),
        ];
    if (props.role === "coach")
        return [
            item("خانه مربی", "LayoutDashboard", "/coach"),
            group("گفت‌وگوهای آموزشی", "BookOpen", [
                ["گفت‌وگوی دوره‌ها", "MessagesSquare", "/discussions"],
                [
                    "پیام‌های خصوصی دانشجویان",
                    "MessagesSquare",
                    "/support?view=coach",
                ],
            ]),
            item("جلسات مشاوره", "CalendarDays", "/consultations?view=coach"),
            item("رزومه شخصی", "FileText", "/career/resumes"),
            group("حساب من", "UserRound", [
                ["پروفایل حرفه‌ای مربی", "UserRound", "/coach/profile"],
                ["مدارک خصوصی من", "FileText", "/coach-credentials"],
                ...accountItems,
            ]),
        ];
    return [
        item("خانه", "House", "/student"),
        group("یادگیری من", "BookOpen", [
            ["امتیاز و نشان یادگیری", "Sparkles", "/learning-rewards"],
            ["دوره‌های من", "BookOpen", "/learning"],
            ["پیدا کردن دوره", "Compass", "/courses"],
            ["گواهی‌های من", "GraduationCap", "/certificates"],
            ["گفت‌وگوی دوره‌ها", "MessagesSquare", "/discussions"],
            ["نظرسنجی دوره‌ها", "FileText", "/surveys"],
        ]),
        group("خودشناسی و مسیر شغلی", "Fingerprint", [
            ["ابزارهای خودشناسی", "Fingerprint", "/assessments"],
            ["رزومه و بررسی متنی", "FileText", "/career/resumes"],
        ]),
        group("مشاوره", "MessagesSquare", [
            ["جلسات و درخواست‌ها", "CalendarDays", "/consultations"],
            ["پکیج‌های مشاوره", "MessagesSquare", "/consultation-packages"],
        ]),
        group("حساب من", "UserRound", [
            ...accountItems.filter((row) => row[2] !== "/learning-rewards"),
            ["دعوت سازمان‌ها", "Building2", "/organization-invitations"],
        ]),
    ];
});
const supportItem = computed(() => [
    "پشتیبانی و تیکت‌ها",
    "MessagesSquare",
    props.role === "admin" ? "/support?view=admin" : "/support",
]);
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
    const actual = new URL(page.url, window.location.origin);
    const target = new URL(href, window.location.origin);
    const path = actual.pathname;
    const base = target.pathname;
    const aliases =
        (base === "/career/resumes" && path.startsWith("/career/checks/")) ||
        (base === "/surveys" && path.startsWith("/survey-responses/")) ||
        (base === "/assessments" && path.startsWith("/assessment-attempts/")) ||
        (base === "/admin/courses" && path.startsWith("/teaching/")) ||
        (base === "/coach" && path.startsWith("/teaching/")) ||
        (base === "/organization" && path.startsWith("/organization/members/"));
    if (aliases) return true;
    if (
        base === "/career/resumes" &&
        (path === base || path.startsWith(base + "/"))
    )
        return true;
    if (
        base === "/support" &&
        !target.searchParams.has("view") &&
        (actual.searchParams.get("view") === "coach" ||
            (props.role === "coach" &&
                path.startsWith("/support/") &&
                !page.props.own))
    )
        return false;
    if (
        !(
            path === base ||
            (!Object.values(urls).includes(base) && path.startsWith(base + "/"))
        )
    )
        return false;
    return Array.from(target.searchParams).every(
        ([key, value]) =>
            actual.searchParams.get(key) === value ||
            (base === "/support" &&
                path.startsWith("/support/") &&
                key === "view" &&
                ((value === "admin" && props.role === "admin") ||
                    (value === "coach" &&
                        props.role === "coach" &&
                        !page.props.own))),
    );
};
</script>
<template>
    <div class="app-shell">
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
            <nav aria-label="بخش‌های پنل" data-panel-navigation>
                <template v-for="entry in menus" :key="entry.label">
                    <details
                        v-if="entry.items"
                        class="nav-group"
                        :open="entry.items.some((row) => current(row[2]))"
                    >
                        <summary class="nav-item">
                            <Icon :name="entry.icon" /><span>{{
                                entry.label
                            }}</span
                            ><Icon
                                name="ChevronDown"
                                class="nav-group-chevron"
                                :size="15"
                            />
                        </summary>
                        <div class="nav-group-items">
                            <PanelNavLink
                                v-for="row in entry.items"
                                :key="row[2]"
                                :item="row"
                                :active="current(row[2])"
                                :invitation-count="
                                    page.props.organizationInvitationCount
                                "
                                @navigate="mobile = false"
                            />
                        </div>
                    </details>
                    <PanelNavLink
                        v-else
                        :item="[entry.label, entry.icon, entry.href]"
                        :active="current(entry.href)"
                        @navigate="mobile = false"
                    />
                </template>
            </nav>
            <div class="sidebar-bottom">
                <PanelNavLink
                    :item="supportItem"
                    :active="current(supportItem[2])"
                    @navigate="mobile = false"
                />
                <a href="/" class="nav-item"
                    ><Icon name="ExternalLink" /><span
                        >سایت عمومی کاردرست</span
                    ></a
                >
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

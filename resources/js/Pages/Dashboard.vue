<script setup>
import { computed } from "vue";
import { Head, Link, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
import Empty from "../Components/Empty.vue";
const props = defineProps({
    role: String,
    enrollments: Array,
    stats: Object,
    courses: Array,
});
const page = usePage();
const labels = { student: "خانه", admin: "خانه مدیریت", coach: "خانه مربی" };
const fa = (n) => new Intl.NumberFormat("fa-IR").format(n);
const active = computed(() =>
    props.enrollments.filter((e) => e.status === "active"),
);
const pending = computed(() =>
    props.enrollments.filter((e) => e.status === "pending_payment"),
);
const steps = [
    ["Fingerprint", "خودشناسی شغلی", "خودتان را عمیق‌تر بشناسید", "teal"],
    ["Signpost", "تصمیم‌گیری شغلی", "گزینه‌ها را آگاهانه بسنجید", "gold"],
    ["RefreshCw", "بازنگری شغلی", "مسیرتان را دوباره مرور کنید", "navy"],
];
</script>
<template>
    <Head :title="labels[role]" /><Shell :title="labels[role]" :role="role">
        <section class="page-intro">
            <div>
                <span class="eyebrow">{{
                    role === "student"
                        ? "اینجا، شروع قدم بعدی شماست"
                        : "نمای کلی فضای کاری شما"
                }}</span>
                <h1>
                    {{ page.props.auth.user.name.split(" ")[0] }} عزیز، خوش
                    آمدید<span class="greeting-dot">.</span>
                </h1>
                <p class="muted">
                    {{
                        role === "student"
                            ? "هر قدم کوچک، می‌تواند مسیر تازه‌ای باز کند. امروز از کجا ادامه می‌دهیم؟"
                            : role === "coach"
                              ? "دوره‌های تخصیص‌یافته و کارهای آموزشی خود را از اینجا دنبال کنید."
                              : "وضعیت کاربران، دوره‌ها و سازمان‌ها را در یک نگاه ببینید."
                    }}
                </p>
            </div>
            <span class="small-chip"
                ><Icon name="Sparkles" :size="16" />مسیر رشد شما</span
            >
        </section>
        <template v-if="role === 'student'">
            <section class="welcome-card glass">
                <div class="welcome-copy">
                    <span class="section-kicker">قدم بعدی شما</span>
                    <h2>
                        {{
                            active.length
                                ? "به مسیر یادگیری‌تان برگردید"
                                : pending.length
                                  ? "ثبت‌نامتان را کامل کنید"
                                  : "یک شروع آگاهانه، یک مسیر تازه"
                        }}
                    </h2>
                    <p>
                        {{
                            active.length
                                ? "دوره‌های شما آماده‌اند. مسیر یادگیری و وضعیت ثبت‌نامتان را ببینید."
                                : pending.length
                                  ? "یک ثبت‌نام در انتظار پرداخت دارید. وضعیت آن را در یادگیری من بررسی کنید."
                                  : "برای شروع، دوره‌ها را ببینید و مسیری متناسب با نیاز امروزتان انتخاب کنید."
                        }}
                    </p>
                    <Link
                        :href="
                            active.length || pending.length
                                ? '/learning'
                                : '/courses'
                        "
                        class="btn primary"
                        >{{
                            active.length
                                ? "رفتن به یادگیری من"
                                : pending.length
                                  ? "مشاهده ثبت‌نام"
                                  : "کشف دوره‌ها"
                        }}<Icon name="ArrowLeft" :size="19" /></Link
                    ><span class="subtle-note"
                        ><Icon name="Leaf" :size="15" />با سرعت خودتان، در مسیر
                        خودتان</span
                    >
                </div>
                <div class="hero-art" aria-hidden="true">
                    <svg viewBox="0 0 330 260">
                        <defs>
                            <linearGradient
                                id="pathGradient"
                                x1="0"
                                y1="0"
                                x2="1"
                                y2="1"
                            >
                                <stop stop-color="#1abcad" />
                                <stop offset="1" stop-color="#e1b12c" />
                            </linearGradient>
                        </defs>
                        <ellipse
                            cx="165"
                            cy="230"
                            rx="110"
                            ry="10"
                            fill="currentColor"
                            opacity=".035"
                        />
                        <path
                            d="M60 225C260 235 60 130 185 130S275 60 230 25"
                            fill="none"
                            stroke="url(#pathGradient)"
                            stroke-width="42"
                            opacity=".13"
                        />
                        <path
                            d="M60 225C260 235 60 130 185 130S275 60 230 25"
                            fill="none"
                            stroke="url(#pathGradient)"
                            stroke-width="2"
                            stroke-dasharray="5 8"
                        />
                        <circle
                            cx="185"
                            cy="130"
                            r="35"
                            fill="var(--surface-raised)"
                            stroke="var(--line)"
                        />
                        <path
                            d="M174 140V119M174 127Q194 111 196 127Q185 139 174 127"
                            fill="none"
                            stroke="#1abcad"
                            stroke-width="3"
                        />
                        <circle cx="230" cy="25" r="8" fill="#e1b12c" />
                        <circle cx="60" cy="225" r="6" fill="#1abcad" /></svg
                    ><span class="art-label"
                        ><Icon name="Route" :size="17" />هر مسیر، با یک
                        قدم</span
                    >
                </div>
            </section>
            <section class="stats-grid">
                <div class="stat-card glass">
                    <span class="stat-icon teal"><Icon name="BookOpen" /></span>
                    <div>
                        <strong>{{ fa(active.length) }}</strong
                        ><span>دوره فعال</span>
                    </div>
                    <Link
                        href="/learning"
                        class="stat-arrow"
                        aria-label="مشاهده دوره‌های فعال"
                        ><Icon name="ArrowUpLeft" :size="18"
                    /></Link>
                </div>
                <div class="stat-card glass">
                    <span class="stat-icon gold"><Icon name="Clock3" /></span>
                    <div>
                        <strong>{{ fa(pending.length) }}</strong
                        ><span>ثبت‌نام در انتظار پرداخت</span>
                    </div>
                </div>
                <div class="stat-card glass">
                    <span class="stat-icon navy"><Icon name="Compass" /></span>
                    <div>
                        <strong>{{ fa(enrollments.length) }}</strong
                        ><span>کل ثبت‌نام‌ها</span>
                    </div>
                </div>
            </section>
            <div class="section-heading">
                <h2>مسیر شما در کاردرست</h2>
                <span class="muted caption">سه مرحله، یک مسیر پیوسته</span>
            </div>
            <section class="journey-grid">
                <article
                    v-for="(step, i) in steps"
                    :key="step[1]"
                    class="journey-card glass"
                >
                    <span class="step-index">۰{{ i + 1 }}</span
                    ><span :class="['journey-icon', step[3]]"
                        ><Icon :name="step[0]" :size="25"
                    /></span>
                    <h3>{{ step[1] }}</h3>
                    <p>{{ step[2] }}</p>
                    <span class="journey-line" :class="step[3]"></span>
                </article>
            </section>
            <div class="lower-grid">
                <section class="glass panel-card">
                    <div class="section-heading">
                        <h2>یادگیری من</h2>
                        <Link href="/learning" class="text-link"
                            >مشاهده همه <Icon name="ChevronLeft" :size="15"
                        /></Link>
                    </div>
                    <Empty
                        v-if="!active.length"
                        icon="BookOpen"
                        title="جای اولین دوره شما اینجاست"
                        text="پس از ثبت‌نام، دوره‌هایتان را همین‌جا پیدا می‌کنید."
                        ><Link href="/courses" class="btn secondary"
                            >دیدن دوره‌ها<Icon
                                name="ArrowLeft"
                                :size="17" /></Link></Empty
                    ><Link
                        v-for="e in active.slice(0, 3)"
                        :key="e.id"
                        href="/learning"
                        class="simple-row"
                        ><span class="row-icon teal"
                            ><Icon name="BookOpen"
                        /></span>
                        <div>
                            <strong>{{ e.course.title }}</strong
                            ><small>{{ e.cohort.title }}</small>
                        </div>
                        <Icon name="ChevronLeft" :size="18"
                    /></Link>
                </section>
                <aside class="glass reflection-card">
                    <span class="reflection-symbol">“</span
                    ><span class="eyebrow">مکث کوتاه</span>
                    <h2>چه چیزی در کار،<br />به شما انرژی می‌دهد؟</h2>
                    <p>
                        گاهی یک سؤال ساده، شروع شناختی تازه است. برای فکر کردن
                        به مسیرتان، به خودتان فرصت بدهید.
                    </p>
                    <span class="reflection-bottom"
                        ><Icon name="Sprout" :size="19" />مسیر هرکس، شبیه خودش
                        است.</span
                    >
                </aside>
            </div>
        </template>
        <template v-else-if="role === 'admin'"
            ><section class="stats-grid four">
                <Link
                    v-for="[key, label, icon, url] in [
                        ['users', 'کاربر', 'Users', 'users'],
                        ['courses', 'دوره', 'BookOpen', 'courses'],
                        [
                            'organizations',
                            'سازمان',
                            'Building2',
                            'organizations',
                        ],
                        [
                            'enrollments',
                            'ثبت‌نام فعال',
                            'GraduationCap',
                            'courses',
                        ],
                    ]"
                    :key="key"
                    :href="'/admin/' + url"
                    class="stat-card glass"
                    ><span class="stat-icon teal"><Icon :name="icon" /></span>
                    <div>
                        <strong>{{ fa(stats[key]) }}</strong
                        ><span>{{ label }}</span>
                    </div>
                    <Icon name="ArrowUpLeft" class="stat-arrow" :size="18"
                /></Link>
            </section>
            <section class="glass panel-card">
                <div class="section-heading">
                    <h2>مدیریت فضای آموزشی</h2>
                    <span class="small-chip">داده‌های واقعی پلتفرم</span>
                </div>
                <div class="admin-quick-grid">
                    <Link
                        v-for="[url, icon, title, text] in [
                            [
                                'courses',
                                'BookOpen',
                                'دوره‌ها',
                                'ساخت دوره و نوبت برگزاری',
                            ],
                            [
                                'users',
                                'Users',
                                'کاربران',
                                'مشاهده حساب‌ها و نقش‌ها',
                            ],
                            [
                                'organizations',
                                'Building2',
                                'دانشگاه‌ها و شرکت‌ها',
                                'ساخت سازمان و تعیین مدیر',
                            ],
                            [
                                'activity',
                                'History',
                                'سابقه فعالیت',
                                'پیگیری رویدادهای حساب و ثبت‌نام',
                            ],
                        ]"
                        :key="url"
                        :href="'/admin/' + url"
                        class="quick-card"
                        ><Icon :name="icon" :size="27" />
                        <h3>{{ title }}</h3>
                        <p>{{ text }}</p>
                        <Icon name="ArrowLeft" :size="18"
                    /></Link>
                </div></section
        ></template>
        <section v-else class="glass panel-card">
            <div class="section-heading">
                <h2>دوره‌های تخصیص‌یافته به شما</h2>
                <span class="small-chip">{{ fa(stats.courses) }} دوره</span>
            </div>
            <Empty
                v-if="!courses.length"
                icon="Presentation"
                title="هنوز دوره‌ای به شما اختصاص داده نشده"
                text="پس از تخصیص دوره توسط مدیریت، اطلاعات آن در این بخش نمایش داده می‌شود."
            />
            <div v-for="course in courses" :key="course.id" class="simple-row">
                <Icon name="BookOpen" /><strong>{{ course.title }}</strong>
            </div>
        </section>
    </Shell>
</template>

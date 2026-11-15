<script setup>
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { fa, dateTime } from "../dates";
const props = defineProps({
    admin: Boolean,
    subject: Object,
    phone: String,
    summary: Object,
    events: Object,
    badges: Object,
});
const page = usePage();
const lookup = useForm({ phone: props.phone || "" });
const metrics = {
    points: "امتیاز یادگیری",
    rewarded_lessons: "درس امتیازگرفته",
    badges: "نشان هدف",
    current_streak: "روز متوالی فعلی",
    best_streak: "بهترین روزهای متوالی",
    active_reward_days: "روز کسب امتیاز",
};
</script>
<template>
    <Head title="امتیاز و نشان یادگیری" /><Shell
        title="امتیاز و نشان یادگیری"
        :role="admin ? 'admin' : page.props.activeRole"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">قدم‌های کوچک، سابقه روشن</span>
                <h1>امتیاز و نشان یادگیری</h1>
                <p class="muted">
                    بر اساس تکمیل خوداظهاری درس و قواعد فعال همان زمان. امتیازها
                    ارزش مالی، مدرک یا تأیید مهارت نیستند؛ سابقه خصوصی حساب شما
                    هستند.
                </p>
            </div>
            <Link
                v-if="admin"
                href="/admin/learning-reward-policies"
                class="btn secondary"
                >قواعد نوبت‌ها</Link
            >
        </section>
        <form
            v-if="admin"
            class="panel glass consultation-card stack-form"
            @submit.prevent="lookup.get('/admin/learning-rewards')"
        >
            <label
                >موبایل حساب<input
                    name="reward_subject_phone"
                    v-model="lookup.phone"
                    inputmode="tel"
                    maxlength="30"
                    required /></label
            ><button class="btn primary" :disabled="lookup.processing">
                نمایش سابقه حساب
            </button>
            <p v-if="phone && !subject" class="muted">
                حسابی با این شماره پیدا نشد.
            </p>
        </form>
        <template v-if="subject"
            ><h2 v-if="admin">{{ subject.name }}</h2>
            <section class="reward-metrics consultation-section">
                <article
                    v-for="(label, key) in metrics"
                    :key="key"
                    :data-reward-metric="key"
                    class="panel glass consultation-card"
                >
                    <h2>{{ label }}</h2>
                    <strong>{{ fa(summary[key]) }}</strong>
                </article>
            </section>
            <p class="muted">
                روزهای متوالی فقط روزهای دارای امتیاز به وقت تهران‌اند؛ اگر
                امروز یا دیروز امتیازی ثبت نشده باشد، شمارنده فعلی صفر است.
                تکمیل‌های پیش از فعال‌سازی و تکرار درس دوباره امتیاز نمی‌گیرند.
            </p>
            <section class="consultation-section">
                <h2>نشان‌های هدف</h2>
                <p v-if="!badges.data.length" class="muted">
                    هنوز نشان هدفی ثبت نشده است.
                </p>
                <article
                    v-for="badge in badges.data"
                    :key="badge.id"
                    class="panel glass consultation-card reward-item"
                    data-learning-badge
                >
                    <h3>{{ badge.title }}</h3>
                    <p>
                        هدف {{ fa(badge.goal_points) }} امتیاز · امتیاز هنگام
                        دریافت {{ fa(badge.points_at_award) }}
                    </p>
                    <p>{{ dateTime(badge.earned_at) }}</p>
                </article>
                <Pagination :links="badges.links" />
            </section>
            <section class="consultation-section">
                <h2>سابقه کسب امتیاز</h2>
                <p v-if="!events.data.length" class="muted">
                    هنوز امتیازی ثبت نشده است. با قواعد فعال نوبت، تکمیل بعدی
                    درس در اینجا ثبت می‌شود.
                </p>
                <article
                    v-for="event in events.data"
                    :key="event.id"
                    :data-reward-event="event.id"
                    class="panel glass consultation-card reward-item"
                >
                    <h3>{{ event.lesson_title }}</h3>
                    <p>
                        {{ event.cohort_title }} · {{ fa(event.points) }} امتیاز
                    </p>
                    <p>{{ dateTime(event.earned_at) }}</p>
                </article>
                <Pagination :links="events.links" /></section></template
    ></Shell>
</template>
<style scoped>
.reward-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1rem;
}
.reward-metrics h2 {
    font-size: 1rem;
}
.reward-metrics strong {
    font-size: 1.5rem;
    color: var(--teal-ink);
}
.reward-item {
    margin-block: 0.75rem;
}
@media (max-width: 600px) {
    .reward-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>

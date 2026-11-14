<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import JalaliDateInput from "../Components/JalaliDateInput.vue";
import ConsultationGrantEdit from "../Components/ConsultationGrantEdit.vue";
import { dateOnly } from "../finance";
import { fa } from "../dates";
defineProps({ admin: Boolean, grants: Object, coaches: Array });
const form = useForm({
    student_phone: "",
    coach_id: "",
    title: "",
    source_reference: "",
    total_sessions: 1,
    session_minutes: 60,
    valid_until: "",
    source_reviewed: false,
});
</script>
<template>
    <Head title="سهمیه پکیج مشاوره" /><Shell
        title="سهمیه مشاوره"
        :role="admin ? 'admin' : 'student'"
    >
        <section class="page-intro">
            <div>
                <span class="eyebrow">جلسات و مانده روشن</span>
                <h1>سهمیه پکیج مشاوره</h1>
                <p class="muted">
                    هر سهمیه برای یک دانشجو، مربی و مدت مشخص است. جلسه باید تا
                    پایان تاریخ اعتبار برگزار و تمام شود.
                </p>
            </div>
            <Link
                :href="admin ? '/consultations?view=admin' : '/consultations'"
                class="btn secondary"
                >رزرو و سوابق مشاوره</Link
            >
        </section>
        <p class="muted">
            یک رزرو قطعی، یک سهمیه مصرف می‌کند. لغو پیش از شروع سهمیه را
            برمی‌گرداند؛ پس از شروع، لغو در این مسیر مجاز نیست. جلسه برگزارشده و
            عدم حضور، مصرف‌شده باقی می‌مانند. برگشت سهمیه، تاریخ اعتبار را تمدید
            نمی‌کند.
        </p>
        <details v-if="admin" class="panel glass consultation-card">
            <summary>ثبت سهمیه با منبع بررسی‌شده</summary>
            <p class="muted">
                این فرم فروش پکیج یا ثبت تراکنش بانکی نیست. منبع حق استفاده و
                تعداد جلسات را پیش از ثبت با سفارش یا توافق معتبر تطبیق دهید.
                منبع یکسان برای همان دانشجو دوباره ثبت نمی‌شود.
            </p>
            <form
                class="stack-form consultation-section"
                @submit.prevent="
                    form.post('/admin/consultation-grants', {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    })
                "
            >
                <label
                    >موبایل دانشجوی تأییدشده<input
                        v-model="form.student_phone"
                        name="grant_student_phone"
                        type="tel"
                        dir="ltr"
                        required
                        maxlength="30" /></label
                ><label
                    >مربی<select
                        v-model="form.coach_id"
                        name="grant_coach"
                        required
                    >
                        <option value="" disabled>انتخاب کنید</option>
                        <option
                            v-for="coach in coaches"
                            :key="coach.id"
                            :value="coach.id"
                        >
                            {{ coach.name }}
                        </option>
                    </select></label
                ><label
                    >عنوان پکیج<input
                        v-model="form.title"
                        name="grant_title"
                        required
                        maxlength="160" /></label
                ><label
                    >شناسه منبع حق استفاده<input
                        v-model="form.source_reference"
                        name="grant_source"
                        required
                        minlength="3"
                        maxlength="200" /></label
                ><label
                    >تعداد جلسات<input
                        v-model="form.total_sessions"
                        name="grant_total"
                        type="number"
                        min="1"
                        max="100"
                        required /></label
                ><label
                    >مدت دقیق هر جلسه (دقیقه)<input
                        v-model="form.session_minutes"
                        type="number"
                        min="15"
                        max="180"
                        required /></label
                ><JalaliDateInput
                    v-model="form.valid_until"
                    label="آخرین روز پایان جلسه"
                    required
                /><label
                    ><input
                        v-model="form.source_reviewed"
                        type="checkbox"
                        required
                    />منبع، حق استفاده، مربی، مدت، تعداد و مهلت را بررسی کردم؛
                    لغو قبل از شروع سهمیه را برگرداند.</label
                ><button class="btn primary" :disabled="form.processing">
                    ثبت سهمیه
                </button>
            </form>
        </details>
        <p v-if="!grants.data.length" class="muted">سهمیه‌ای ثبت نشده است.</p>
        <div class="consultation-grid consultation-section">
            <article
                v-for="grant in grants.data"
                :key="grant.id"
                class="panel glass consultation-card consultation-grant-card"
            >
                <span class="status-badge">{{
                    !grant.active
                        ? "متوقف"
                        : grant.expired
                          ? "مهلت گذشته"
                          : grant.remaining
                            ? "قابل استفاده"
                            : "مصرف‌شده"
                }}</span>
                <h2>{{ grant.title }}</h2>
                <p v-if="admin">
                    {{ grant.student.name }} ·
                    <bdi>{{ grant.student.phone }}</bdi>
                </p>
                <p>
                    {{ grant.coach_name }} ·
                    {{ fa(grant.session_minutes) }} دقیقه
                </p>
                <strong
                    >مانده: {{ fa(grant.remaining) }} از
                    {{ fa(grant.total_sessions) }} جلسه</strong
                >
                <p>آخرین روز پایان جلسه: {{ dateOnly(grant.valid_until) }}</p>
                <p v-if="grant.source_kind === 'course'">
                    همراه دوره: {{ grant.source_course }}
                </p>
                <p v-if="admin">منبع: {{ grant.source_reference }}</p>
                <ConsultationGrantEdit
                    v-if="admin && grant.source_kind !== 'course'"
                    :grant="grant"
                />
            </article>
        </div>
        <Pagination :links="grants.links" />
    </Shell>
</template>

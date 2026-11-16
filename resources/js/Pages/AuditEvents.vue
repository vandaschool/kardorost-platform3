<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Empty from "../Components/Empty.vue";
import Pagination from "../Components/Pagination.vue";
import JalaliDateInput from "../Components/JalaliDateInput.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({
    filters: Object,
    events: Object,
    actions: Array,
    subject_types: Array,
});
const form = useForm({ ...props.filters });
const labels = {
    "auth.registered": "ثبت‌نام حساب",
    "auth.login": "ورود",
    "password.reset": "بازیابی رمز",
    "password.changed": "تغییر رمز",
    "course.created": "ساخت دوره",
    "enrollment.created": "ثبت درخواست دوره",
    "organization.created": "ساخت سازمان",
    "organization.report_viewed": "مشاهده گزارش عضو",
    "payment.paid": "پرداخت تأییدشده",
    "payment.review_required": "پرداخت نیازمند بررسی",
    "user.access_updated": "تغییر دسترسی کاربر",
    "course.coach_assigned": "تخصیص مربی دوره",
};
function apply() {
    form.get("/admin/activity", { preserveScroll: true, preserveState: false });
}
</script>
<template>
    <Head title="سابقه فعالیت"
        ><meta name="robots" content="noindex, nofollow" /></Head
    ><Shell title="سابقه فعالیت" role="admin">
        <section class="page-intro">
            <div>
                <span class="eyebrow">مدیریت و عملیات</span>
                <h1>سابقه فعالیت</h1>
                <p class="muted">
                    رویدادهای ثبت‌شده از تازه به قدیم؛ بازه تاریخ به وقت تهران
                    است. شناسه‌ها و نوع عملیات دقیق تطبیق داده می‌شوند.
                </p>
            </div>
        </section>
        <form
            @submit.prevent="apply"
            class="panel glass stack-form audit-filters"
            data-testid="audit-filters"
        >
            <label
                >شناسه رویداد<input
                    name="event_id"
                    v-model="form.event_id"
                    type="number"
                    min="1"
                    inputmode="numeric"
            /></label>
            <label
                >شناسه حساب انجام‌دهنده<input
                    name="actor_id"
                    v-model="form.actor_id"
                    type="number"
                    min="1"
                    inputmode="numeric"
            /></label>
            <label
                >نوع عملیات<input
                    name="action"
                    v-model="form.action"
                    list="audit-actions"
                    maxlength="255"
                    placeholder="همه عملیات"
                    dir="ltr"
            /></label>
            <datalist id="audit-actions">
                <option v-for="value in actions" :key="value" :value="value">
                    {{ labels[value] || value }}
                </option>
            </datalist>
            <label
                >نوع موضوع<input
                    name="subject_type"
                    v-model="form.subject_type"
                    list="audit-subjects"
                    maxlength="255"
                    placeholder="همه موضوع‌ها"
                    dir="ltr"
            /></label>
            <datalist id="audit-subjects">
                <option
                    v-for="value in subject_types"
                    :key="value"
                    :value="value"
                />
            </datalist>
            <label
                >شناسه موضوع<input
                    name="subject_id"
                    v-model="form.subject_id"
                    type="number"
                    min="1"
                    inputmode="numeric"
            /></label>
            <JalaliDateInput
                v-model="form.from"
                label="از تاریخ"
            /><JalaliDateInput v-model="form.to" label="تا تاریخ" />
            <p class="muted caption">
                انجام‌دهندهٔ خالی یعنی حسابی به این رویداد متصل نیست؛ الزاماً
                مشخص نمی‌کند چه فرایندی آن را ثبت کرده است. متن خصوصی، مدارک و
                محتوای جزئی عملیات در این فهرست نمایش داده نمی‌شوند.
            </p>
            <p v-for="error in form.errors" :key="error" class="field-error">
                {{ error }}
            </p>
            <div class="button-row">
                <button class="btn primary" :disabled="form.processing">
                    جست‌وجو</button
                ><Link href="/admin/activity" class="btn secondary"
                    >پاک‌کردن فیلترها</Link
                >
            </div>
        </form>
        <section class="panel glass consultation-card">
            <h2>
                رویدادها
                <span class="muted caption">{{ fa(events.total) }} مورد</span>
            </h2>
            <Empty
                v-if="!events.data.length"
                icon="History"
                title="رویدادی با این شرایط پیدا نشد"
                text="می‌توانید بازه یا فیلترها را تغییر دهید."
            />
            <article
                v-for="event in events.data"
                :key="event.id"
                :data-audit-event="event.id"
                class="audit-event-card"
            >
                <div>
                    <h3>{{ labels[event.action] || event.action }}</h3>
                    <p class="muted caption">
                        شناسه رویداد {{ fa(event.id) }} ·
                        {{ dateTime(event.created_at) }}
                    </p>
                    <p class="caption audit-code" dir="ltr">
                        {{ event.action }}
                    </p>
                </div>
                <p v-if="event.actor_id">
                    انجام‌دهنده: {{ event.actor_name || "حساب بدون نام" }}
                    <span class="muted caption"
                        >(شناسه {{ fa(event.actor_id) }})</span
                    >
                </p>
                <p v-else class="muted">انجام‌دهندهٔ متصل ثبت نشده</p>
                <p
                    v-if="event.subject_type || event.subject_id"
                    class="caption"
                >
                    موضوع:
                    <span dir="auto">{{
                        event.subject_type || "نوع ثبت نشده"
                    }}</span
                    ><span v-if="event.subject_id">
                        · شناسه {{ fa(event.subject_id) }}</span
                    >
                </p>
            </article>
            <Pagination :page="events" />
        </section>
    </Shell>
</template>
<style scoped>
.audit-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
    gap: 1rem;
    padding: 1.25rem;
    margin-bottom: 1.25rem;
}
.audit-filters > p,
.audit-filters > .button-row {
    grid-column: 1/-1;
}
.audit-filters > .button-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
}
.audit-event-card {
    padding: 1rem 0;
    border-bottom: 1px solid var(--line);
    overflow-wrap: anywhere;
    min-width: 0;
}
.audit-event-card h3 {
    font-size: 1rem;
    margin: 0 0 0.35rem;
}
.audit-event-card p {
    margin: 0.35rem 0;
}
.audit-code {
    text-align: start;
}
</style>

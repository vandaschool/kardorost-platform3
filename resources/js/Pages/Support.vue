<script setup>
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
const props = defineProps({
    tickets: Object,
    filters: Object,
    enrollments: Array,
    role: String,
    admin: Boolean,
    coach: Boolean,
});
const categories = {
    technical: "فنی",
    finance: "مالی",
    academic: "آموزشی",
    organization: "سازمانی",
    other: "سایر",
};
const statuses = {
    waiting_staff: "منتظر پاسخ پشتیبانی / مدرس",
    waiting_requester: "منتظر پاسخ شما",
    resolved: "حل‌شده",
    closed: "بسته‌شده",
};
const form = useForm({
    subject: "",
    category: "technical",
    priority: "normal",
    cohort_id: "",
    body: "",
    file: null,
    request_key: crypto.randomUUID(),
});
function submit() {
    if (form.category !== "academic") form.cohort_id = "";
    form.post("/support");
}
function filter(key, value) {
    router.get(
        "/support",
        { ...props.filters, [key]: value || undefined },
        { preserveState: true },
    );
}
</script>
<template>
    <Head title="پشتیبانی و پیام خصوصی" /><Shell
        title="پشتیبانی و پیام خصوصی"
        :role="role"
    >
        <section class="page-intro">
            <div>
                <h1>پشتیبانی و پیام خصوصی</h1>
                <p>درخواست‌ها و پاسخ‌ها را در یک گفتگو پیگیری کنید.</p>
            </div>
        </section>
        <nav class="action-row">
            <Link class="btn secondary" href="/support">درخواست‌های من</Link
            ><Link v-if="admin" class="btn secondary" href="/support?view=admin"
                >کارتابل پشتیبانی</Link
            ><Link v-if="coach" class="btn secondary" href="/support?view=coach"
                >پیام‌های آموزشی دانشجویان</Link
            >
        </nav>
        <section
            v-if="filters.view === 'own'"
            class="glass panel-card consultation-section"
        >
            <h2>درخواست تازه</h2>
            <form @submit.prevent="submit" class="form-grid">
                <label
                    >موضوع<input
                        v-model="form.subject"
                        name="subject"
                        required
                        maxlength="160"
                /></label>
                <label
                    >بخش<select v-model="form.category" name="category">
                        <option v-for="(label, key) in categories" :value="key">
                            {{ label }}
                        </option>
                    </select></label
                >
                <label
                    >اولویت<select v-model="form.priority">
                        <option value="normal">عادی</option>
                        <option value="urgent">فوری</option>
                    </select></label
                >
                <label v-if="form.category === 'academic'"
                    >دوره برای ارتباط خصوصی با مدرس<select
                        v-model="form.cohort_id"
                        name="cohort_id"
                    >
                        <option value="">پشتیبانی آموزشی عمومی</option>
                        <option v-for="item in enrollments" :value="item.id">
                            {{ item.title }}
                        </option>
                    </select></label
                >
                <p class="muted">
                    {{
                        form.category === "academic" && form.cohort_id
                            ? "این گفتگو را شما، مدرس فعلی دوره و ادمین می‌بینید."
                            : "این گفتگو را شما و تیم ادمین می‌بینید."
                    }}
                </p>
                <label
                    >پیام<textarea
                        v-model="form.body"
                        name="body"
                        rows="5"
                        maxlength="12000"
                    />
                </label>
                <label
                    >پیوست اختیاری<input
                        type="file"
                        name="file"
                        accept=".pdf,.jpg,.jpeg,.png,.docx,.txt"
                        @change="form.file = $event.target.files[0] || null"
                /></label>
                <p class="muted">
                    PDF، تصویر، Word یا متن؛ حداکثر ۵ مگابایت. پیام یا پیوست
                    لازم است.
                </p>
                <p
                    v-for="error in form.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <progress
                    v-if="form.progress"
                    :value="form.progress.percentage"
                    max="100"
                /><button class="btn primary" :disabled="form.processing">
                    ثبت درخواست
                </button>
            </form>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>{{ filters.view === "own" ? "درخواست‌های من" : "کارتابل" }}</h2>
            <div class="action-row">
                <label
                    >وضعیت<select
                        :value="filters.status || ''"
                        @change="filter('status', $event.target.value)"
                    >
                        <option value="">همه</option>
                        <option v-for="(label, key) in statuses" :value="key">
                            {{ label }}
                        </option>
                    </select></label
                ><label
                    >بخش<select
                        :value="filters.category || ''"
                        @change="filter('category', $event.target.value)"
                    >
                        <option value="">همه</option>
                        <option v-for="(label, key) in categories" :value="key">
                            {{ label }}
                        </option>
                    </select></label
                ><label
                    >اولویت<select
                        :value="filters.priority || ''"
                        @change="filter('priority', $event.target.value)"
                    >
                        <option value="">همه</option>
                        <option value="normal">عادی</option>
                        <option value="urgent">فوری</option>
                    </select></label
                ><label v-if="filters.view === 'admin'"
                    >مسئول<select
                        :value="filters.assigned || ''"
                        @change="filter('assigned', $event.target.value)"
                    >
                        <option value="">همه</option>
                        <option value="mine">من</option>
                        <option value="unassigned">تخصیص‌نیافته</option>
                    </select></label
                >
            </div>
            <p v-if="!tickets.data.length" class="muted">
                درخواستی در این فهرست نیست.
            </p>
            <article
                v-for="ticket in tickets.data"
                :key="ticket.id"
                class="consultation-section"
            >
                <h3>
                    <Link :href="'/support/' + ticket.id">{{
                        ticket.subject
                    }}</Link>
                </h3>
                <p>
                    {{ categories[ticket.category] }} ·
                    {{ statuses[ticket.status] }} ·
                    {{ ticket.priority === "urgent" ? "فوری" : "عادی" }}
                </p>
                <p v-if="ticket.owner_name">{{ ticket.owner_name }}</p>
                <p v-if="ticket.cohort_title" class="muted">
                    {{ ticket.cohort_title }}
                </p>
            </article>
            <Pagination :page="tickets" />
        </section>
    </Shell>
</template>

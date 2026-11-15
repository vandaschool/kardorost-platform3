<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({
    resumes: Object,
    filters: Object,
    role: String,
    admin: Boolean,
    limit: Number,
});
const create = useForm({ title: "" });
const filter = useForm({
    view: props.filters.view,
    state: props.filters.state,
    phone: props.filters.phone || "",
});
function search() {
    filter.get("/career/resumes", { preserveState: true });
}
</script>
<template>
    <Head title="رزومه و مسیر شغلی" /><Shell
        title="رزومه و مسیر شغلی"
        :role="role"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">ابزارهای مسیر شغلی</span>
                <h1>رزومه‌های من</h1>
                <p>
                    پیش‌نویس خصوصی، نسخه ثابت برای چاپ و بررسی ساختار و واژه‌های
                    آگهی شغلی.
                </p>
            </div>
        </section>
        <nav v-if="admin" class="action-row">
            <Link class="btn secondary" href="/career/resumes"
                >رزومه‌های خودم</Link
            ><Link class="btn secondary" href="/career/resumes?view=admin"
                >کارتابل همه رزومه‌ها</Link
            >
        </nav>
        <section
            v-if="filters.view === 'own'"
            class="glass panel-card consultation-section"
        >
            <h2>رزومه تازه</h2>
            <p class="muted">
                عنوان فقط برای پیدا کردن رزومه در حساب شماست. حداکثر
                {{ fa(limit) }} رزومه در هر حساب نگهداری می‌شود.
            </p>
            <form
                class="stack-form"
                data-resume-create
                @submit.prevent="create.post('/career/resumes')"
            >
                <label
                    >عنوان داخلی<input
                        v-model="create.title"
                        name="title"
                        required
                        maxlength="100"
                        placeholder="مثلاً رزومه فارسی من"
                /></label>
                <p
                    v-for="error in create.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn primary" :disabled="create.processing">
                    ساخت پیش‌نویس
                </button>
            </form>
        </section>
        <section class="glass panel-card consultation-section">
            <form class="action-row" @submit.prevent="search">
                <label
                    >فهرست<select v-model="filter.state" name="state">
                        <option value="active">فعال</option>
                        <option value="archived">بایگانی</option>
                        <option value="all">همه</option>
                    </select></label
                ><label v-if="filters.view === 'admin'"
                    >موبایل صاحب رزومه<input
                        v-model="filter.phone"
                        name="phone"
                        dir="ltr"
                        maxlength="30" /></label
                ><button class="btn secondary">نمایش</button>
            </form>
            <p v-for="error in filter.errors" class="field-error" role="alert">
                {{ error }}
            </p>
            <p v-if="!resumes.data.length" class="muted">
                رزومه‌ای در این فهرست نیست.
            </p>
            <article
                v-for="resume in resumes.data"
                :key="resume.id"
                class="consultation-section"
            >
                <h2>
                    <Link :href="'/career/resumes/' + resume.id">{{
                        resume.title
                    }}</Link>
                </h2>
                <p v-if="resume.owner_name">{{ resume.owner_name }}</p>
                <p class="muted">
                    {{ resume.archived ? "بایگانی" : "فعال" }} ·
                    {{ fa(resume.current_version) }} نسخه ثابت ·
                    {{ dateTime(resume.updated_at) }}
                </p>
                <Link
                    class="btn secondary"
                    :href="'/career/resumes/' + resume.id"
                    >بازکردن رزومه</Link
                >
            </article>
            <Pagination :page="resumes" /></section
    ></Shell>
</template>

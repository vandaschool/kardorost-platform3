<script setup>
import { Head, Link, useForm, router } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime } from "../dates";
const props = defineProps({
    cohort: Object,
    course: Object,
    role: String,
    staff: Boolean,
    discussions: Object,
    filters: Object,
});
const form = useForm({
    title: "",
    kind: "question",
    body: "",
    request_key: crypto.randomUUID(),
});
const search = useForm({ ...props.filters });
function filter() {
    router.get("/cohorts/" + props.cohort.id + "/discussions", search.data());
}
</script>
<template>
    <Head :title="'گفت‌وگوی ' + course.title" /><Shell
        title="گفت‌وگوی دوره"
        :role="role"
        ><section class="page-intro">
            <div>
                <h1>{{ course.title }}</h1>
                <p>{{ cohort.title }} · گفت‌وگوهای کلاس</p>
                <p class="caption muted">
                    نام و پیام شما برای اعضای فعال همین نوبت، مدرس و مدیریت دیده
                    می‌شود.
                </p>
            </div>
            <Link href="/discussions" class="btn secondary"
                >همه نوبت‌های من</Link
            >
        </section>
        <details class="glass panel-card">
            <summary>پرسش یا گفت‌وگوی تازه</summary>
            <form
                class="form-stack"
                data-discussion-create
                @submit.prevent="
                    form.post('/cohorts/' + cohort.id + '/discussions')
                "
            >
                <label
                    >عنوان<input
                        v-model="form.title"
                        name="discussion_title"
                        maxlength="160"
                        required /></label
                ><label
                    >نوع<select v-model="form.kind" name="discussion_kind">
                        <option value="question">پرسش</option>
                        <option value="discussion">بحث و تبادل نظر</option>
                    </select></label
                ><label
                    >متن پیام<textarea
                        v-model="form.body"
                        name="discussion_body"
                        maxlength="12000"
                        rows="5"
                        required
                    />
                </label>
                <p
                    v-for="(error, key) in form.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn primary" :disabled="form.processing">
                    ثبت گفت‌وگو
                </button>
            </form>
        </details>
        <form class="form-grid consultation-section" @submit.prevent="filter">
            <label
                >جست‌وجوی عنوان<input
                    v-model="search.q"
                    maxlength="100" /></label
            ><label
                >وضعیت<select v-model="search.status">
                    <option value="all">همه گفت‌وگوها</option>
                    <option value="unanswered">پرسش‌های بدون پاسخ منتخب</option>
                    <option value="answered">پرسش‌های دارای پاسخ منتخب</option>
                </select></label
            ><label v-if="staff"
                >نمایش<select v-model="search.visibility">
                    <option value="all">همه</option>
                    <option value="visible">قابل نمایش</option>
                    <option value="hidden">پنهان‌شده</option>
                </select></label
            ><button class="btn secondary">اعمال فیلتر</button>
        </form>
        <p v-if="!discussions.data.length" class="muted">
            گفت‌وگویی با این شرایط وجود ندارد.
        </p>
        <article
            v-for="discussion in discussions.data"
            :key="discussion.id"
            class="glass panel-card consultation-section"
            :data-discussion-row="discussion.id"
        >
            <div class="learning-actions">
                <span v-if="discussion.pinned" class="badge">سنجاق‌شده</span
                ><span v-if="discussion.hidden" class="badge gold"
                    >پنهان‌شده</span
                ><span v-if="discussion.locked" class="badge gold">بسته</span
                ><span v-if="discussion.accepted_post_id" class="badge teal"
                    >دارای پاسخ منتخب</span
                >
            </div>
            <h2>
                <Link :href="'/discussions/' + discussion.id">{{
                    discussion.title
                }}</Link>
            </h2>
            <p class="caption muted">
                {{ discussion.author_name }} ·
                {{ dateTime(discussion.created_at) }}
            </p>
        </article>
        <Pagination :links="discussions.links"
    /></Shell>
</template>

<script setup>
import { watch } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import CoachProfileDefinition from "../Components/CoachProfileDefinition.vue";
import CoachProfileHistory from "../Components/CoachProfileHistory.vue";
const props = defineProps({
    profiles: Object,
    state: String,
    selected: Object,
    eligible: Boolean,
    history: Object,
});
const labels = {
    draft: "پیش‌نویس",
    submitted: "در انتظار بررسی",
    approved: "نسخه بررسی‌شده",
    rejected: "نیازمند اصلاح",
};
const review = useForm({
    revision: props.selected?.revision,
    action: "publish",
    reviewed: false,
    note: "",
});
const withdraw = useForm({ revision: props.selected?.revision, reason: "" });
watch(
    () => props.selected?.revision,
    (revision) => {
        review.revision = revision;
        review.reviewed = false;
        withdraw.revision = revision;
    },
);
</script>
<template>
    <Head title="بررسی پروفایل مربیان" /><Shell
        title="بررسی پروفایل مربیان"
        role="admin"
        ><section class="page-intro">
            <h1>بررسی پروفایل مربیان</h1>
            <a href="/mentors" class="btn secondary">مربیان در سایت عمومی</a>
        </section>
        <section
            v-if="selected"
            class="panel glass consultation-card consultation-section"
            data-admin-coach-profile
        >
            <h2>{{ selected.owner.name }}</h2>
            <p>
                <bdi>{{ selected.owner.phone }}</bdi> ·
                {{ labels[selected.state] }}
            </p>
            <p v-if="!eligible" class="muted">
                حساب فعلی شرایط نقش مربی فعال و موبایل تأییدشده را ندارد؛ انتشار
                تازه ممکن نیست.
            </p>
            <h3>
                {{
                    selected.submission
                        ? "نسخه و رضایت ارسال‌شده"
                        : "پیش‌نویس خصوصی"
                }}
            </h3>
            <CoachProfileDefinition
                :definition="selected.submission?.definition || selected.draft"
            />
            <form
                v-if="selected.state === 'submitted'"
                class="stack-form"
                data-coach-profile-review
                @submit.prevent="
                    review.post(`/admin/coach-profiles/${selected.id}/review`, {
                        preserveScroll: true,
                    })
                "
            >
                <label
                    >نتیجه<select
                        name="coach_review_action"
                        v-model="review.action"
                    >
                        <option value="publish" :disabled="!eligible">
                            انتشار همین نسخه
                        </option>
                        <option value="reject">برگشت برای اصلاح</option>
                    </select></label
                ><label
                    >توضیح<textarea
                        name="coach_review_note"
                        v-model="review.note"
                        :required="review.action === 'reject'"
                        maxlength="2000"
                    ></textarea></label
                ><label v-if="review.action === 'publish'"
                    ><input
                        type="checkbox"
                        name="coach_review_confirmed"
                        v-model="review.reviewed"
                        required
                    />اطلاعات و رضایت همین نسخه را بررسی کرده‌ام.</label
                >
                <p
                    v-for="(error, key) in review.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button
                    class="btn primary"
                    :disabled="
                        review.processing ||
                        (review.action === 'publish' && !eligible)
                    "
                >
                    ثبت نتیجه بررسی
                </button>
            </form>
            <a
                v-if="selected.public_enabled && eligible"
                :href="selected.public_url"
                class="text-link"
                >مشاهده نسخه عمومی</a
            >
        </section>
        <form
            v-if="
                selected &&
                (selected.public_enabled || selected.state === 'submitted')
            "
            class="panel glass consultation-card stack-form consultation-section"
            data-admin-coach-withdraw
            @submit.prevent="
                withdraw.post(`/admin/coach-profiles/${selected.id}/withdraw`, {
                    preserveScroll: true,
                })
            "
        >
            <label
                >دلیل بستن نمایش و بررسی<textarea
                    name="admin_coach_withdraw_reason"
                    v-model="withdraw.reason"
                    required
                    minlength="3"
                    maxlength="2000"
                ></textarea></label
            ><button class="btn secondary" :disabled="withdraw.processing">
                بستن نمایش عمومی و بررسی
            </button>
        </form>
        <CoachProfileHistory :history="history" />
        <section class="consultation-section">
            <h2>کارتابل پروفایل‌ها</h2>
            <label
                >وضعیت<select
                    name="coach_profile_state"
                    :value="state"
                    @change="
                        router.get('/admin/coach-profiles', {
                            state: $event.target.value,
                        })
                    "
                >
                    <option value="submitted">در انتظار بررسی</option>
                    <option value="draft">پیش‌نویس</option>
                    <option value="approved">نسخه بررسی‌شده</option>
                    <option value="rejected">نیازمند اصلاح</option>
                    <option value="all">همه</option>
                </select></label
            >
            <p v-if="!profiles.data.length" class="muted">
                پروفایلی با این وضعیت نیست.
            </p>
            <article
                v-for="row in profiles.data"
                :key="row.id"
                class="panel glass consultation-card consultation-section"
            >
                <h3>{{ row.owner.name }}</h3>
                <p>
                    {{ labels[row.state] }} · <bdi>{{ row.owner.phone }}</bdi>
                </p>
                <Link
                    :href="`/admin/coach-profiles/${row.id}`"
                    class="text-link"
                    >بررسی و سوابق</Link
                >
            </article>
            <Pagination :links="profiles.links" /></section
    ></Shell>
</template>

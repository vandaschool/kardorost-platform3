<script setup>
import { Head, Link, useForm, router } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { fa, dateTime } from "../dates";
import { dateOnly } from "../finance";
const props = defineProps({
    organization: Object,
    license: Object,
    members: Object,
    seats: Object,
    query: String,
});
const form = useForm({
    revision: props.license.revision,
    member_ids: [],
    confirmed: false,
});
function selectPage() {
    form.member_ids = props.members.data
        .filter((m) => m.eligible && !m.enrolled)
        .map((m) => m.id);
}
function submit() {
    form.post(`/organization/licenses/${props.license.id}/enroll`, {
        preserveScroll: true,
        onSuccess: () => {
            form.revision = props.license.revision;
            form.member_ids = [];
            form.confirmed = false;
        },
    });
}
function search(event) {
    router.get(`/organization/licenses/${props.license.id}`, {
        q: event.target.elements.search.value,
    });
}
</script>
<template>
    <Head title="ثبت‌نام از سهمیه سازمان" /><Shell
        title="ثبت‌نام از سهمیه سازمان"
        role="org_admin"
        ><Link href="/organization/licenses" class="text-link"
            >بازگشت به سهمیه‌ها</Link
        >
        <section class="page-intro">
            <div>
                <span class="eyebrow">{{ organization.name }}</span>
                <h1>{{ license.course_title }}</h1>
                <p>{{ license.cohort_title }}</p>
            </div>
            <a
                :href="`/organization/licenses/${license.id}/export`"
                class="btn secondary"
                >خروجی CSV ثبت‌نام‌ها</a
            >
        </section>
        <div class="panel glass consultation-card">
            <p>
                مانده سهمیه: {{ fa(license.remaining) }} از
                {{ fa(license.seat_limit) }} · مهلت ثبت‌نام تازه:
                {{ dateOnly(license.expires_on) }}
            </p>
            <p class="muted">
                اعضای از قبل ثبت‌نام‌شده دوباره سهمیه مصرف نمی‌کنند. اگر سهمیه
                یا ظرفیت برای همه انتخاب‌شدگان کافی نباشد، هیچ ثبت‌نام تازه‌ای
                انجام نمی‌شود.
            </p>
        </div>
        <section class="consultation-section">
            <h2>انتخاب اعضا</h2>
            <form @submit.prevent="search" class="assessment-actions">
                <label
                    >نام یا موبایل عضو<input
                        name="search"
                        :value="query"
                        maxlength="100" /></label
                ><button class="btn secondary">جستجو</button>
            </form>
            <p v-if="!members.data.length" class="muted">
                عضوی پیدا نشد. عضو باید ابتدا ثبت‌نام و به همین سازمان اضافه
                شود.
            </p>
            <form
                @submit.prevent="submit"
                class="panel glass consultation-card stack-form consultation-section organization-license-form"
            >
                <div class="assessment-actions">
                    <button
                        type="button"
                        class="btn secondary"
                        :disabled="!license.can_enroll || form.processing"
                        @click="selectPage"
                    >
                        انتخاب اعضای مجاز این صفحه</button
                    ><button
                        v-if="form.member_ids.length"
                        type="button"
                        class="btn secondary"
                        @click="form.member_ids = []"
                    >
                        پاک‌کردن انتخاب
                    </button>
                </div>
                <label
                    v-for="m in members.data"
                    :key="m.id"
                    class="assessment-option"
                    ><input
                        type="checkbox"
                        v-model="form.member_ids"
                        :value="m.id"
                        :disabled="
                            !license.can_enroll || !m.eligible || m.enrolled
                        "
                    /><span
                        >{{ m.name }} · <bdi>{{ m.phone }}</bdi
                        ><small v-if="m.enrolled">
                            · از قبل ثبت‌نام فعال دارد</small
                        ><small v-else-if="!m.eligible">
                            · حساب دانشجوی فعال و تأییدشده لازم است</small
                        ></span
                    ></label
                >
                <p>انتخاب‌شده: {{ fa(form.member_ids.length) }}</p>
                <label
                    ><input
                        type="checkbox"
                        v-model="form.confirmed"
                        required
                        :disabled="!license.can_enroll"
                    />ثبت‌نام اعضای انتخاب‌شده با پوشش سازمان تأیید است.</label
                ><button
                    class="btn primary"
                    :disabled="
                        form.processing ||
                        !license.can_enroll ||
                        !form.member_ids.length
                    "
                >
                    ثبت‌نام گروه انتخاب‌شده
                </button>
            </form>
            <Pagination :links="members.links" />
        </section>
        <section class="consultation-section">
            <h2>سوابق مصرف سهمیه</h2>
            <p v-if="!seats.data.length" class="muted">
                هنوز صندلی مصرف نشده است.
            </p>
            <div class="consultation-grid">
                <article
                    v-for="s in seats.data"
                    :key="s.id"
                    class="panel glass consultation-card"
                >
                    <h3>{{ s.member_name }}</h3>
                    <p dir="ltr">{{ s.member_phone }}</p>
                    <p>
                        {{ dateTime(s.created_at) }} ·
                        {{ s.status === "active" ? "ثبت‌نام فعال" : s.status }}
                    </p>
                </article>
            </div>
            <Pagination :links="seats.links" /></section
    ></Shell>
</template>

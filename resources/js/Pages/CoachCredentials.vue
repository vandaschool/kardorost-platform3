<script setup>
import { ref, watch } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Empty from "../Components/Empty.vue";
import Pagination from "../Components/Pagination.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({
    role: String,
    admin: Boolean,
    own: Boolean,
    can_review: Boolean,
    can_submit: Boolean,
    filters: Object,
    credentials: Object,
    selected: Object,
    events: Object,
    request_key: String,
    consent_text: String,
});
const statuses = {
    submitted: "منتظر بررسی داخلی",
    accepted: "بررسی داخلی: پذیرفته شد",
    rejected: "بررسی داخلی: رد شد",
    withdrawn: "کنارگذاشته‌شده توسط صاحب",
};
const fileInput = ref(null);
const upload = useForm({
    title: "",
    issuer: "",
    note: "",
    consent: false,
    file: null,
    request_key: props.request_key,
});
const filter = useForm({ ...props.filters });
const review = useForm({
    revision: props.selected?.revision,
    action: "accept",
    reason: "",
    reviewed: false,
});
const withdraw = useForm({
    revision: props.selected?.revision,
    action: "withdraw",
    reason: "",
});
watch(
    () => props.selected?.revision,
    (value) => {
        review.revision = value;
        withdraw.revision = value;
        review.reviewed = false;
    },
);
function submit() {
    upload.post("/coach-credentials", {
        forceFormData: true,
        onSuccess: () => {
            upload.reset();
            upload.request_key = props.request_key;
            if (fileInput.value) fileInput.value.value = "";
        },
    });
}
function apply() {
    filter.get(
        props.admin ? "/admin/coach-credentials" : "/coach-credentials",
        { preserveState: false },
    );
}
function decide() {
    review.post("/coach-credentials/" + props.selected.id + "/change", {
        preserveScroll: true,
        onSuccess: () => {
            review.reason = "";
            review.reviewed = false;
        },
    });
}
function archive() {
    withdraw.post("/coach-credentials/" + props.selected.id + "/change", {
        preserveScroll: true,
        onSuccess: () => withdraw.reset("reason"),
    });
}
</script>
<template>
    <Head title="مدارک خصوصی مربی"
        ><meta name="robots" content="noindex, nofollow" /></Head
    ><Shell title="مدارک خصوصی مربی" :role="role">
        <section class="page-intro">
            <div>
                <span class="eyebrow">پرونده خصوصی و بررسی داخلی</span>
                <h1>{{ admin ? "مدارک خصوصی مربیان" : "مدارک خصوصی من" }}</h1>
                <p class="muted">
                    فایل فقط برای صاحب آن و ادمین‌های کاردرست است. بررسی داخلی،
                    مدرک را عمومی نمی‌کند و تأیید رسمی اعتبار آن نیست. عنوان و
                    صادرکننده به اعلام صاحب پرونده‌اند.
                </p>
            </div>
            <Link
                v-if="selected"
                :href="
                    admin ? '/admin/coach-credentials' : '/coach-credentials'
                "
                class="btn secondary"
                >بازگشت به فهرست</Link
            >
        </section>
        <details
            v-if="can_submit"
            :open="!selected"
            class="panel glass credential-section"
            data-testid="credential-upload-section"
        >
            <summary>ارسال پرونده تازه</summary>
            <form
                @submit.prevent="submit"
                class="stack-form"
                data-testid="credential-upload"
            >
                <label
                    >عنوان مدرک<input
                        name="credential_title"
                        v-model="upload.title"
                        maxlength="160"
                        required
                /></label>
                <label
                    >صادرکننده به اعلام شما<input
                        name="credential_issuer"
                        v-model="upload.issuer"
                        maxlength="160"
                /></label>
                <label
                    >توضیح شما<textarea
                        name="credential_note"
                        v-model="upload.note"
                        maxlength="1500"
                        rows="3"
                    />
                </label>
                <label
                    >فایل خصوصی<input
                        ref="fileInput"
                        name="credential_file"
                        type="file"
                        accept="application/pdf,image/png,image/jpeg"
                        required
                        @change="upload.file = $event.target.files[0] || null"
                /></label>
                <p class="muted caption">
                    PDF، PNG یا JPEG تا ۴ مگابایت. محتوای فایل پس از ثبت جایگزین
                    نمی‌شود؛ برای مدرک تازه، پرونده تازه بفرستید. کنارگذاشتن
                    پرونده، فایل و سوابق قبلی را حذف نمی‌کند.
                </p>
                <label class="check-label"
                    ><input
                        name="credential_consent"
                        type="checkbox"
                        v-model="upload.consent"
                        required
                    />{{ consent_text }}</label
                >
                <p
                    v-for="error in upload.errors"
                    :key="error"
                    class="field-error"
                >
                    {{ error }}
                </p>
                <progress
                    v-if="upload.progress"
                    :value="upload.progress.percentage"
                    max="100"
                    aria-label="پیشرفت بارگذاری مدرک"
                />
                <button
                    class="btn primary"
                    :disabled="upload.processing || !upload.consent"
                >
                    ثبت پرونده خصوصی
                </button>
            </form>
        </details>
        <section
            v-if="selected"
            class="panel glass credential-section"
            data-testid="credential-selected"
        >
            <span
                class="badge"
                :class="selected.status === 'accepted' ? 'teal' : 'gold'"
                data-testid="credential-status"
                >{{ statuses[selected.status] || selected.status }}</span
            >
            <h2>{{ selected.title }}</h2>
            <p class="muted">
                {{ selected.owner_name }} · پرونده {{ fa(selected.id) }} · ثبت
                {{ dateTime(selected.created_at) }}
            </p>
            <p v-if="selected.issuer">
                صادرکننده اعلام‌شده: {{ selected.issuer }}
            </p>
            <p v-if="selected.note" class="preserve-lines">
                {{ selected.note }}
            </p>
            <p v-if="selected.unavailable" class="field-error">
                اطلاعات خصوصی این پرونده قابل نمایش نیست؛ با پشتیبانی پیگیری
                کنید.
            </p>
            <template v-else
                ><p class="caption muted">
                    {{ selected.file_name }} ·
                    {{ fa(Math.ceil(selected.file_size / 1024)) }} کیلوبایت ·
                    رضایت {{ dateTime(selected.consented_at) }}
                </p>
                <a
                    :href="selected.file_url"
                    class="btn secondary"
                    data-testid="credential-file"
                    >دریافت فایل خصوصی</a
                ></template
            >
            <form
                v-if="can_review && selected.status !== 'withdrawn'"
                @submit.prevent="decide"
                class="stack-form credential-action"
                data-testid="credential-review"
            >
                <h3>ثبت بررسی داخلی ادمین</h3>
                <label
                    >نتیجه<select
                        name="credential_review_action"
                        v-model="review.action"
                    >
                        <option value="accept">پذیرش در بررسی داخلی</option>
                        <option value="reject">رد در بررسی داخلی</option>
                    </select></label
                >
                <label
                    >دلیل یا توضیح بررسی<textarea
                        name="credential_review_reason"
                        v-model="review.reason"
                        maxlength="2000"
                        rows="3"
                        :required="review.action === 'reject'"
                    />
                </label>
                <p class="caption muted">
                    توضیح بررسی برای صاحب پرونده نیز قابل مشاهده است.
                </p>
                <label class="check-label"
                    ><input
                        name="credential_reviewed"
                        type="checkbox"
                        v-model="review.reviewed"
                        required
                    />فایل و اطلاعات همین پرونده را بررسی کرده‌ام؛ این تصمیم
                    داخلی است.</label
                >
                <p
                    v-for="error in review.errors"
                    :key="error"
                    class="field-error"
                >
                    {{ error }}
                </p>
                <button
                    class="btn primary"
                    :disabled="review.processing || !review.reviewed"
                >
                    ثبت نتیجه بررسی
                </button>
            </form>
            <details
                v-if="own && selected.status !== 'withdrawn'"
                class="credential-action"
                data-testid="credential-withdraw-section"
            >
                <summary>کنارگذاشتن این پرونده</summary>
                <form
                    @submit.prevent="archive"
                    class="stack-form"
                    data-testid="credential-withdraw"
                >
                    <p class="caption muted">
                        پرونده از بررسی بعدی کنار می‌رود؛ اصل فایل و سابقه خصوصی
                        حذف نمی‌شوند. برای ارسال دوباره، پرونده تازه با رضایت
                        تازه ثبت کنید.
                    </p>
                    <label
                        >دلیل کنارگذاشتن<textarea
                            name="credential_withdraw_reason"
                            v-model="withdraw.reason"
                            maxlength="2000"
                            required
                            rows="2"
                        />
                    </label>
                    <p
                        v-for="error in withdraw.errors"
                        :key="error"
                        class="field-error"
                    >
                        {{ error }}
                    </p>
                    <button
                        class="btn secondary"
                        :disabled="withdraw.processing"
                    >
                        ثبت کنارگذاشتن پرونده
                    </button>
                </form>
            </details>
            <h3>سابقه پرونده</h3>
            <article
                v-for="event in events?.data || []"
                :key="event.id"
                :data-credential-event="event.id"
                class="credential-event"
            >
                <strong>{{
                    event.action === "submitted"
                        ? "ارسال پرونده"
                        : event.action === "withdrawn"
                          ? "کنارگذاشتن پرونده"
                          : "بررسی داخلی"
                }}</strong>
                <p class="caption muted">
                    {{ event.actor_name }} · {{ dateTime(event.created_at) }} ·
                    {{ statuses[event.to_status] || event.to_status }}
                </p>
                <p v-if="event.reason" class="preserve-lines">
                    {{ event.reason }}
                </p>
            </article>
            <Pagination :page="events" />
        </section>
        <section class="panel glass credential-section">
            <h2>
                {{ admin ? "فهرست پرونده‌ها" : "پرونده‌های من" }}
                <span class="caption muted"
                    >{{ fa(credentials.total) }} مورد</span
                >
            </h2>
            <form
                @submit.prevent="apply"
                class="credential-filters"
                data-testid="credential-filters"
            >
                <label
                    >وضعیت<select
                        name="credential_status_filter"
                        v-model="filter.status"
                    >
                        <option value="">همه وضعیت‌ها</option>
                        <option
                            v-for="(label, key) in statuses"
                            :key="key"
                            :value="key"
                        >
                            {{ label }}
                        </option>
                    </select></label
                ><label v-if="admin"
                    >موبایل کامل صاحب پرونده<input
                        name="credential_phone_filter"
                        v-model="filter.phone"
                        dir="ltr"
                        inputmode="tel"
                        maxlength="40" /></label
                ><button class="btn secondary" :disabled="filter.processing">
                    جست‌وجو
                </button>
                <p
                    v-for="error in filter.errors"
                    :key="error"
                    class="field-error"
                >
                    {{ error }}
                </p>
            </form>
            <Empty
                v-if="!credentials.data.length"
                icon="FileText"
                title="پرونده‌ای با این شرایط وجود ندارد"
                text="مدارک فقط پس از ارسال صاحب حساب در این فهرست قرار می‌گیرند."
            />
            <article
                v-for="item in credentials.data"
                :key="item.id"
                class="credential-row"
                :data-credential-id="item.id"
            >
                <div>
                    <h3>
                        <Link :href="'/coach-credentials/' + item.id">{{
                            item.title
                        }}</Link>
                    </h3>
                    <p class="caption muted">
                        {{ item.owner_name }} · {{ dateTime(item.created_at) }}
                    </p>
                </div>
                <span
                    class="badge"
                    :class="item.status === 'accepted' ? 'teal' : 'gold'"
                    >{{ statuses[item.status] || item.status }}</span
                >
            </article>
            <Pagination :page="credentials" />
        </section>
    </Shell>
</template>
<style scoped>
.credential-section {
    padding: 1.25rem;
    margin-bottom: 1.25rem;
    min-width: 0;
    overflow-wrap: anywhere;
}
.credential-section > summary {
    font-weight: 700;
    cursor: pointer;
    margin-bottom: 1rem;
}
.credential-action {
    margin: 1.5rem 0;
    border-top: 1px solid var(--line);
    padding-top: 1rem;
}
.credential-action summary {
    cursor: pointer;
}
.credential-event,
.credential-row {
    border-bottom: 1px solid var(--line);
    padding: 1rem 0;
    min-width: 0;
    overflow-wrap: anywhere;
}
.credential-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.credential-row > div {
    min-width: 0;
    flex: 1;
}
.credential-row h3,
.credential-event p {
    margin: 0.35rem 0;
}
.credential-filters {
    display: flex;
    align-items: end;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}
.credential-filters > label {
    flex: 1;
    min-width: min(100%, 200px);
}
.credential-filters > .field-error {
    flex-basis: 100%;
}
</style>

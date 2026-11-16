<script setup>
import { computed, ref, watch } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import JalaliDateInput from "../Components/JalaliDateInput.vue";
import { fa, dateTime } from "../dates";
const props = defineProps({
    announcements: Object,
    selected: Object,
    counts: Object,
    recipients: Object,
    estimated_audience: Number,
    pending_schedule: Object,
    schedules: Object,
});
const roles = {
    student: "دانشجویان",
    coach: "مربیان",
    org_admin: "مدیران سازمان",
    admin: "ادمین‌ها",
};
const labels = {
    draft: "پیش‌نویس",
    sending: "در صف ارسال",
    completed: "پایان‌یافته",
    stopped: "متوقف‌شده",
    failed: "نیازمند پیگیری",
    pending: "منتظر ارسال",
    delivered: "ثبت‌شده در اعلان‌ها",
    skipped: "فاقد شرایط دریافت",
    cancelled: "متوقف‌شده",
};
const draft = useForm({
    revision: props.selected?.revision || 1,
    definition: {
        title: props.selected?.title || "",
        message: props.selected?.message || "",
        roles: props.selected?.roles || [],
    },
});
const saved = ref(JSON.stringify(draft.definition));
const dirty = computed(() => JSON.stringify(draft.definition) !== saved.value);
const publish = useForm({
    revision: props.selected?.revision,
    reviewed: false,
});
const scheduled = useForm({
    revision: props.selected?.revision,
    date: "",
    time: "",
    reviewed: false,
});
const cancellation = useForm({ revision: props.selected?.revision });
const busy = computed(
    () =>
        draft.processing ||
        publish.processing ||
        scheduled.processing ||
        cancellation.processing,
);
const scheduleLabels = {
    pending: "در انتظار موعد",
    published: "مخاطبان ثبت و ارسال به صف واگذار شد",
    cancelled: "لغوشده",
    blocked: "متوقف‌شده؛ تأیید تازه لازم است",
};
const reasonLabels = {
    draft_changed: "متن یا نقش‌های مخاطب ویرایش شدند",
    published_now: "انتشار فوری انجام شد",
    replaced: "برنامه دیگری جایگزین شد",
    admin_cancelled: "ادمین برنامه را لغو کرد",
    author_ineligible: "ادمین تأییدکننده دیگر دسترسی فعال ندارد",
    announcement_changed: "نسخه اطلاعیه تغییر کرده است",
    snapshot_changed: "نسخه ثابت با تأیید ثبت‌شده همخوان نیست",
    already_published: "اطلاعیه قبلاً منتشر شده است",
    invalid_content: "متن ذخیره‌شده معتبر نیست",
    empty_audience: "در موعد، مخاطب واجد شرایطی وجود نداشت",
};
const stop = useForm({ revision: props.selected?.revision, reason: "" });
const retry = useForm({ revision: props.selected?.revision });
watch(
    () => props.selected?.revision,
    (value) => {
        draft.revision = value;
        publish.revision = value;
        stop.revision = value;
        retry.revision = value;
        publish.reviewed = false;
        scheduled.revision = value;
        cancellation.revision = value;
        scheduled.reviewed = false;
    },
);
function save() {
    const submitted = JSON.stringify(draft.definition);
    const options = {
        preserveScroll: true,
        onSuccess: () => (saved.value = submitted),
    };
    if (props.selected)
        draft.patch(`/admin/announcements/${props.selected.id}`, options);
    else draft.post("/admin/announcements", options);
}
</script>
<template>
    <Head title="اعلان گروهی" /><Shell title="اعلان گروهی" role="admin">
        <section class="page-intro">
            <div>
                <span class="eyebrow">پیام روشن، مخاطب مشخص</span>
                <h1>اعلان گروهی داخل پنل</h1>
                <p class="muted">
                    مخاطبان هنگام انتشار ثابت می‌شوند؛ هر حساب چندنقشی فقط یک
                    اعلان می‌گیرد. پیامک ارسال نمی‌شود.
                </p>
            </div>
            <Link
                v-if="selected"
                href="/admin/announcements"
                class="btn secondary"
                >پیش‌نویس تازه</Link
            >
        </section>
        <form
            v-if="!selected || selected.status === 'draft'"
            @submit.prevent="save"
            class="panel glass consultation-card stack-form"
            data-testid="announcement-editor"
        >
            <h2>پیش‌نویس اطلاعیه</h2>
            <label
                >عنوان<input
                    name="announcement_title"
                    v-model="draft.definition.title"
                    required
                    minlength="3"
                    maxlength="160" /></label
            ><label
                >متن اطلاعیه<textarea
                    name="announcement_message"
                    v-model="draft.definition.message"
                    required
                    minlength="3"
                    maxlength="3000"
                ></textarea>
            </label>
            <fieldset>
                <legend>مخاطبان</legend>
                <label v-for="(label, key) in roles" :key="key"
                    ><input
                        type="checkbox"
                        :value="key"
                        :name="`audience_${key}`"
                        v-model="draft.definition.roles"
                    />{{ label }}</label
                >
            </fieldset>
            <p class="muted">
                فقط حساب فعال با موبایل تأییدشده و یکی از نقش‌های انتخاب‌شده.
                نام و اطلاعات خصوصی افراد را در پیام گروهی ننویسید.
            </p>
            <button class="btn primary" :disabled="busy">ذخیره پیش‌نویس</button>
        </form>
        <section
            v-if="selected"
            class="panel glass consultation-card consultation-section"
            data-testid="announcement-status"
        >
            <h2>{{ selected.title }}</h2>
            <p class="preserve-lines">{{ selected.message }}</p>
            <p>
                {{ labels[selected.status] }} · نسخه {{ fa(selected.revision) }}
            </p>
            <p>
                مخاطبان:
                {{ selected.roles.map((role) => roles[role]).join("، ") }}
            </p>
            <p v-if="selected.status === 'draft'">
                تعداد فعلی واجد شرایط: {{ fa(estimated_audience) }}؛ تعداد نهایی
                هنگام انتشار ثبت می‌شود.
            </p>
            <p v-else>مخاطبان نهایی: {{ fa(selected.recipient_count) }}</p>
            <div class="learning-actions">
                <span v-for="(count, key) in counts" :key="key"
                    >{{ labels[key] }}: {{ fa(count) }}</span
                >
            </div>
            <p v-if="selected.published_at">
                انتشار: {{ dateTime(selected.published_at) }}
            </p>
            <p v-if="selected.stop_reason" class="preserve-lines">
                دلیل توقف: {{ selected.stop_reason }}
            </p>
            <button
                class="btn secondary"
                type="button"
                @click="router.reload({ preserveScroll: true })"
            >
                تازه‌کردن وضعیت
            </button>
        </section>
        <form
            v-if="selected?.status === 'draft'"
            class="panel glass consultation-card stack-form consultation-section"
            @submit.prevent="
                publish.post(`/admin/announcements/${selected.id}/publish`, {
                    preserveScroll: true,
                })
            "
            data-testid="announcement-publish"
        >
            <p v-if="dirty">ابتدا تغییرات پیش‌نویس را ذخیره کنید.</p>
            <label
                ><input
                    type="checkbox"
                    name="announcement_reviewed"
                    v-model="publish.reviewed"
                    required
                />متن ذخیره‌شده و نقش‌های مخاطب را بررسی کرده‌ام.</label
            ><button
                class="btn primary"
                :disabled="busy || dirty || !estimated_audience"
            >
                {{
                    pending_schedule
                        ? "انتشار فوری و لغو زمان‌بندی"
                        : "انتشار و صف ارسال"
                }}
            </button>
        </form>
        <section
            v-if="selected?.status === 'draft'"
            class="panel glass consultation-card consultation-section"
            data-announcement-scheduling
        >
            <div
                v-if="pending_schedule"
                class="form-stack"
                data-pending-announcement-schedule
            >
                <h2>زمان‌بندی فعال</h2>
                <p>{{ dateTime(pending_schedule.scheduled_for) }}</p>
                <button
                    type="button"
                    class="btn secondary"
                    :disabled="busy"
                    data-cancel-announcement-schedule
                    @click="
                        cancellation.post(
                            `/admin/announcements/${selected.id}/schedules/${pending_schedule.id}/cancel`,
                            { preserveScroll: true },
                        )
                    "
                >
                    لغو زمان‌بندی
                </button>
            </div>
            <details>
                <summary>
                    {{
                        pending_schedule
                            ? "جایگزین‌کردن زمان‌بندی"
                            : "زمان‌بندی برای بعد"
                    }}
                </summary>
                <form
                    @submit.prevent="
                        scheduled.post(
                            `/admin/announcements/${selected.id}/schedule`,
                            { preserveScroll: true },
                        )
                    "
                    class="stack-form"
                    data-announcement-schedule
                >
                    <p class="muted">
                        متن ذخیره‌شده و نقش‌ها ثابت می‌مانند. حساب‌های واجد
                        شرایط در موعد اجرا انتخاب می‌شوند؛ تعداد فعلی تخمینی
                        است. اگر مخاطبی نباشد یا دسترسی ادمین تأییدکننده لغو
                        شود، برنامه متوقف می‌شود.
                    </p>
                    <p v-if="dirty">ابتدا تغییرات پیش‌نویس را ذخیره کنید.</p>
                    <p class="muted">
                        موعد را از یک دقیقه آینده تا یک سال انتخاب کنید.
                    </p>
                    <label
                        >تاریخ ارسال<JalaliDateInput
                            v-model="scheduled.date"
                            label="ارسال اعلان"
                            required
                    /></label>
                    <label
                        >ساعت تهران<input
                            type="time"
                            name="announcement_schedule_time"
                            v-model="scheduled.time"
                            required
                    /></label>
                    <label
                        ><input
                            type="checkbox"
                            name="announcement_schedule_reviewed"
                            v-model="scheduled.reviewed"
                            required
                        />متن ذخیره‌شده، نقش‌ها و انتخاب مخاطبان در موعد اجرا را
                        بررسی کرده‌ام.</label
                    >
                    <button
                        class="btn primary"
                        :disabled="busy || dirty || !scheduled.reviewed"
                    >
                        {{
                            pending_schedule
                                ? "ثبت برنامه جایگزین"
                                : "ثبت زمان‌بندی"
                        }}
                    </button>
                    <p
                        v-for="error in scheduled.errors"
                        :key="error"
                        class="error"
                    >
                        {{ error }}
                    </p>
                </form>
            </details>
        </section>
        <section
            v-if="schedules?.data.length"
            class="consultation-section"
            data-announcement-schedule-history
        >
            <h2>سابقه زمان‌بندی و تأییدها</h2>
            <article
                v-for="item in schedules.data"
                :key="item.id"
                class="panel glass consultation-card consultation-section"
                :data-announcement-schedule-history-row="item.id"
            >
                <h3>
                    {{ scheduleLabels[item.status] }} ·
                    {{ dateTime(item.scheduled_for) }}
                </h3>
                <p v-if="item.reason">
                    {{ reasonLabels[item.reason] || "نیازمند بررسی" }}
                </p>
                <p v-if="item.closed_at">
                    رسیدگی: {{ dateTime(item.closed_at) }}
                </p>
                <details>
                    <summary>نسخه تأییدشده</summary>
                    <h4>{{ item.definition.title }}</h4>
                    <p class="preserve-lines">{{ item.definition.message }}</p>
                    <p>
                        {{
                            item.definition.roles
                                .map((role) => roles[role])
                                .join("، ")
                        }}
                    </p>
                </details>
            </article>
            <Pagination :links="schedules.links" />
        </section>
        <form
            v-if="selected && ['sending', 'failed'].includes(selected.status)"
            @submit.prevent="
                stop.post(`/admin/announcements/${selected.id}/stop`, {
                    preserveScroll: true,
                })
            "
            class="panel glass consultation-card stack-form consultation-section"
            data-testid="announcement-stop"
        >
            <h2>توقف باقی‌مانده</h2>
            <p class="muted">
                اعلان‌های قبلاً ثبت‌شده حذف نمی‌شوند. توقف پس از پایان دسته در
                حال پردازش اعمال می‌شود.
            </p>
            <label
                >دلیل توقف<textarea
                    v-model="stop.reason"
                    name="announcement_stop_reason"
                    required
                    minlength="3"
                    maxlength="2000"
                ></textarea></label
            ><button class="btn secondary" :disabled="stop.processing">
                توقف ارسال باقی‌مانده
            </button>
        </form>
        <form
            v-if="selected?.status === 'failed'"
            @submit.prevent="
                retry.post(`/admin/announcements/${selected.id}/retry`, {
                    preserveScroll: true,
                })
            "
            class="panel glass consultation-card consultation-section"
        >
            <p>
                ارسال صف نیازمند پیگیری است. پس از بررسی worker می‌توانید فقط
                موارد باقی‌مانده را ادامه دهید.
            </p>
            <button class="btn secondary" :disabled="retry.processing">
                ادامه موارد باقی‌مانده
            </button>
        </form>
        <section v-if="recipients?.data.length" class="consultation-section">
            <h2>سابقه مخاطبان</h2>
            <article
                v-for="row in recipients.data"
                :key="row.id"
                class="panel glass consultation-card announcement-recipient"
            >
                <h3>{{ row.name }} · حساب {{ fa(row.user_id) }}</h3>
                <p>{{ labels[row.status] }}</p>
                <p v-if="row.finished_at">{{ dateTime(row.finished_at) }}</p>
            </article>
            <Pagination :links="recipients.links" />
        </section>
        <section class="consultation-section">
            <h2>اطلاعیه‌های قبلی</h2>
            <p v-if="!announcements.data.length" class="muted">
                هنوز اطلاعیه‌ای ثبت نشده است.
            </p>
            <article
                v-for="row in announcements.data"
                :key="row.id"
                class="panel glass consultation-card announcement-recipient"
            >
                <h3>{{ row.title }}</h3>
                <p
                    v-if="row.next_scheduled_for"
                    data-announcement-list-schedule
                >
                    زمان‌بندی فعال: {{ dateTime(row.next_scheduled_for) }}
                </p>
                <p>
                    {{ labels[row.status] }} ·
                    {{ fa(row.recipient_count) }} مخاطب
                </p>
                <Link :href="`/admin/announcements/${row.id}`" class="text-link"
                    >مشاهده و پیگیری</Link
                >
            </article>
            <Pagination :links="announcements.links" />
        </section>
    </Shell>
</template>
<style scoped>
.announcement-recipient {
    margin-block: 0.75rem;
}
fieldset label {
    display: flex;
    gap: 0.5rem;
    margin-block: 0.5rem;
}
</style>

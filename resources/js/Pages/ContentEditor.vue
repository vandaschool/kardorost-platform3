<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import JalaliDateInput from "../Components/JalaliDateInput.vue";
import { computed, ref } from "vue";
import ContentDocumentPreview from "../Components/ContentDocumentPreview.vue";
import ContentDocumentEditor from "../Components/ContentDocumentEditor.vue";
import ContentRedirects from "../Components/ContentRedirects.vue";
import ContentTagsEditor from "../Components/ContentTagsEditor.vue";
import ContentTagsPreview from "../Components/ContentTagsPreview.vue";
import { documentFromText, documentText } from "../content-document";
import { dateTime, fa } from "../dates";
const props = defineProps({
    page: Object,
    publications: Object,
    pending_schedule: Object,
    schedules: Object,
    redirects: { type: Array, default: () => [] },
});
const draft = useForm({
    revision: props.page.revision,
    definition: JSON.parse(JSON.stringify(props.page.draft)),
});
const rich = ref(Boolean(props.page.draft.document));
function toggleRich() {
    if (rich.value) {
        draft.definition.body = documentText(draft.definition.document);
        delete draft.definition.document;
        rich.value = false;
    } else {
        draft.definition.document = documentFromText(draft.definition.body);
        rich.value = true;
    }
}
const publish = useForm({ revision: props.page.revision });
const visibility = useForm({
    revision: props.page.revision,
    available: props.page.available,
});
const scheduled = useForm({
    revision: props.page.revision,
    date: "",
    time: "",
    reviewed: false,
});
const cancellation = useForm({ revision: props.page.revision });
const busy = computed(
    () =>
        draft.processing ||
        publish.processing ||
        visibility.processing ||
        scheduled.processing ||
        cancellation.processing,
);
const scheduleLabels = {
    pending: "در انتظار زمان انتشار",
    published: "منتشرشده",
    cancelled: "لغوشده",
    blocked: "متوقف‌شده؛ نیازمند بررسی تازه",
};
const reasonLabels = {
    draft_changed: "پیش‌نویس ویرایش شد",
    visibility_changed: "وضعیت نمایش تغییر کرد",
    published_now: "نسخه به صورت فوری منتشر شد",
    replaced: "برنامه دیگری جایگزین شد",
    admin_cancelled: "مدیر برنامه را لغو کرد",
    author_ineligible: "مدیر تأییدکننده دیگر دسترسی فعال ندارد",
    page_changed: "نسخه محتوا تغییر کرده است",
    snapshot_changed: "نسخه برنامه با تأیید ثبت‌شده همخوان نیست",
    already_published: "این متن قبلاً منتشر شده است",
    invalid_content: "نسخه محتوا معتبر نیست",
};
function scheduleVersion() {
    scheduled.revision = props.page.revision;
    scheduled.post(`/admin/content/${props.page.id}/schedule`, {
        preserveScroll: true,
        onSuccess: () => scheduled.reset("reviewed"),
    });
}
function cancelScheduled() {
    cancellation.revision = props.page.revision;
    cancellation.post(
        `/admin/content/${props.page.id}/schedules/${props.pending_schedule.id}/cancel`,
        { preserveScroll: true },
    );
}
function save() {
    draft.revision = props.page.revision;
    draft.patch("/admin/content/" + props.page.id + "/draft", {
        preserveScroll: true,
        onSuccess: () => draft.defaults(),
    });
}
function publishVersion() {
    publish.revision = props.page.revision;
    publish.post("/admin/content/" + props.page.id + "/publish", {
        preserveScroll: true,
    });
}
function toggle() {
    visibility.revision = props.page.revision;
    visibility.available = !props.page.available;
    visibility.post("/admin/content/" + props.page.id + "/availability", {
        preserveScroll: true,
    });
}
</script>
<template>
    <Head title="ویرایش محتوا" /><Shell title="ویرایش محتوا" role="admin"
        ><Link href="/admin/content" class="text-link">بازگشت به محتواها</Link>
        <section class="page-intro">
            <div>
                <h1>{{ page.draft.title }}</h1>
                <p>
                    <bdi>{{ page.public_path }}</bdi> · نسخه عمومی
                    {{ fa(page.current_version) }} ·
                    {{ page.available ? "نمایش فعال" : "نمایش بسته" }}
                </p>
            </div>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>پیش‌نویس</h2>
            <form @submit.prevent="save" class="stack-form" data-content-draft>
                <label
                    >عنوان<input
                        v-model="draft.definition.title"
                        name="title"
                        required
                        maxlength="160"
                        :disabled="draft.processing" /></label
                ><label
                    >خلاصه<textarea
                        v-model="draft.definition.excerpt"
                        name="excerpt"
                        required
                        maxlength="320"
                        :disabled="draft.processing"
                    /></label
                ><button
                    type="button"
                    class="btn secondary"
                    :disabled="busy"
                    data-toggle-content-format
                    @click="toggleRich"
                >
                    {{ rich ? "تبدیل قالب به متن ساده" : "قالب‌بندی متن" }}
                </button>
                <ContentDocumentEditor
                    v-if="rich"
                    v-model="draft.definition.document"
                    :disabled="busy"
                />
                <label v-else
                    >متن<textarea
                        v-model="draft.definition.body"
                        name="body"
                        required
                        rows="12"
                        maxlength="60000"
                        :disabled="draft.processing"
                    /></label
                ><label
                    >توضیح موتور جست‌وجو<input
                        v-model="draft.definition.meta_description"
                        name="meta_description"
                        maxlength="160"
                        :disabled="draft.processing"
                /></label>
                <p v-if="!rich" class="muted">
                    متن ساده نمایش داده می‌شود؛ کد HTML اجرا نمی‌شود. بین بندها
                    یک خط خالی بگذارید.
                </p>
                <ContentTagsEditor
                    v-if="page.kind === 'article'"
                    v-model="draft.definition.tags"
                    :disabled="busy"
                />
                <ContentTagsPreview
                    v-if="page.kind === 'article'"
                    :tags="draft.definition.tags"
                />
                <p
                    v-for="error in draft.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn secondary" :disabled="busy">
                    ذخیره پیش‌نویس
                </button>
            </form>
        </section>
        <section class="glass panel-card consultation-section">
            <h2>انتشار روی سایت</h2>
            <p>
                انتشار، نسخه‌ای ثابت از پیش‌نویس ذخیره‌شده می‌سازد و نمایش عمومی
                را فعال می‌کند.
            </p>
            <p v-if="draft.isDirty" class="muted">
                تغییرات فرم را پیش از انتشار ذخیره کنید.
            </p>
            <div class="action-row">
                <button
                    class="btn primary"
                    data-content-publish
                    :disabled="draft.isDirty || busy"
                    @click="publishVersion"
                >
                    انتشار نسخه ذخیره‌شده</button
                ><button
                    v-if="page.current_version"
                    class="btn secondary"
                    data-content-visibility
                    :disabled="busy"
                    @click="toggle"
                >
                    {{
                        page.available
                            ? "بستن نمایش عمومی"
                            : "نمایش دوباره نسخه منتشرشده"
                    }}</button
                ><a
                    v-if="page.available"
                    :href="page.public_path"
                    class="text-link"
                    target="_blank"
                    rel="noopener"
                    >مشاهده نسخه عمومی ←</a
                >
            </div>
            <p v-for="error in publish.errors" class="field-error" role="alert">
                {{ error }}
            </p>
            <p
                v-for="error in visibility.errors"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
        </section>
        <section
            class="glass panel-card consultation-section"
            data-content-scheduling
        >
            <h2>انتشار در زمان مشخص</h2>
            <p>
                همین پیش‌نویس ذخیره‌شده در زمان انتخابی به وقت تهران منتشر
                می‌شود. نسخه عمومی فعلی تا آن زمان باقی می‌ماند. ویرایش
                پیش‌نویس، تغییر نمایش یا انتشار فوری، برنامه قبلی را لغو می‌کند.
            </p>
            <div v-if="pending_schedule" data-pending-content-schedule>
                <p>
                    زمان برنامه فعلی:
                    {{ dateTime(pending_schedule.scheduled_for) }}
                </p>
                <button
                    class="btn secondary"
                    data-cancel-content-schedule
                    :disabled="busy"
                    @click="cancelScheduled"
                >
                    لغو برنامه انتشار
                </button>
            </div>
            <form @submit.prevent="scheduleVersion" data-content-schedule>
                <fieldset
                    class="stack-form content-schedule-fields"
                    :disabled="busy"
                >
                    <label
                        >تاریخ انتشار<JalaliDateInput
                            v-model="scheduled.date"
                            label="انتشار محتوا"
                            required
                    /></label>
                    <label
                        >ساعت تهران<input
                            name="content_schedule_time"
                            v-model="scheduled.time"
                            type="time"
                            required
                    /></label>
                    <label class="checkbox-label"
                        ><input
                            type="checkbox"
                            name="content_schedule_reviewed"
                            v-model="scheduled.reviewed"
                            required
                        />همین نسخه ذخیره‌شده را برای انتشار عمومی در زمان
                        انتخاب‌شده بررسی و تأیید می‌کنم.</label
                    >
                    <p v-if="draft.isDirty" class="muted">
                        ابتدا تغییرات پیش‌نویس را ذخیره کنید.
                    </p>
                    <button
                        class="btn primary"
                        :disabled="draft.isDirty || busy"
                    >
                        {{
                            pending_schedule
                                ? "جایگزینی برنامه انتشار"
                                : "ثبت برنامه انتشار"
                        }}
                    </button>
                </fieldset>
            </form>
            <p
                v-for="error in scheduled.errors"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <p
                v-for="error in cancellation.errors"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <h3>سوابق برنامه‌ها</h3>
            <p v-if="!schedules.data.length" class="muted">
                برنامه‌ای ثبت نشده است.
            </p>
            <details
                v-for="item in schedules.data"
                :key="item.id"
                class="consultation-section"
                :data-content-schedule-history="item.id"
            >
                <summary>
                    {{ scheduleLabels[item.status] }} ·
                    {{ dateTime(item.scheduled_for) }}
                </summary>
                <p>
                    تأییدکننده: {{ item.author_name }} · نسخه پیش‌نویس
                    {{ fa(item.source_revision) }}
                </p>
                <p v-if="item.reason">
                    {{ reasonLabels[item.reason] || "نیازمند بررسی" }}
                </p>
                <p v-if="item.closed_at">
                    زمان رسیدگی: {{ dateTime(item.closed_at) }}
                </p>
                <p v-if="item.publication_number">
                    نسخه عمومی حاصل: {{ fa(item.publication_number) }}
                </p>
                <h4>{{ item.definition.title }}</h4>
                <ContentTagsPreview :tags="item.definition.tags" />
                <ContentDocumentPreview
                    v-if="item.definition.document"
                    :document="item.definition.document"
                />
                <p v-else class="preserve-lines">{{ item.definition.body }}</p>
            </details>
            <Pagination :page="schedules" />
        </section>
        <ContentRedirects :page="page" :redirects="redirects" />
        <section class="glass panel-card consultation-section">
            <h2>نسخه‌های منتشرشده</h2>
            <p v-if="!publications.data.length" class="muted">
                نسخه‌ای منتشر نشده است.
            </p>
            <details
                v-for="publication in publications.data"
                :key="publication.id"
                class="consultation-section"
            >
                <summary>
                    نسخه {{ fa(publication.number) }} ·
                    {{ dateTime(publication.published_at) }}
                </summary>
                <h3>{{ publication.definition.title }}</h3>
                <p>{{ publication.definition.excerpt }}</p>
                <ContentTagsPreview :tags="publication.definition.tags" />
                <ContentDocumentPreview
                    v-if="publication.definition.document"
                    :document="publication.definition.document"
                />
                <p
                    v-else
                    style="white-space: pre-wrap; overflow-wrap: anywhere"
                >
                    {{ publication.definition.body }}
                </p>
            </details>
            <Pagination :page="publications" /></section
    ></Shell>
</template>

<style scoped>
.content-schedule-fields {
    border: 0;
    padding: 0;
    margin: 1rem 0;
    min-width: 0;
}
</style>

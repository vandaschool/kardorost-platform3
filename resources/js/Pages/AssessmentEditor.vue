<script setup>
import { computed, ref } from "vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { fa, dateTime } from "../dates";
const props = defineProps({
    assessment: Object,
    versions: Array,
    reports: Object,
});
const initial = () =>
    props.assessment.draft
        ? JSON.parse(JSON.stringify(props.assessment.draft))
        : {
              title: props.assessment.title,
              description: "",
              instructions: "",
              source: "",
              options: [
                  "کاملاً مخالفم",
                  "مخالفم",
                  "نه موافق نه مخالف",
                  "موافقم",
                  "کاملاً موافقم",
              ],
              dimensions: [{ key: "d1", title: "", description: "" }],
              questions: [
                  { key: "q1", text: "", dimension: "d1", reverse: false },
              ],
          };
const form = useForm({
    revision: props.assessment.revision,
    definition: initial(),
});
const publish = useForm({
    revision: props.assessment.revision,
    reviewed: false,
});
const visibility = useForm({
    revision: props.assessment.revision,
    available: !props.assessment.available,
});
const savedDefinition = ref(JSON.stringify(form.definition));
const unsavedDefinition = computed(
    () => JSON.stringify(form.definition) !== savedDefinition.value,
);
function refreshRevision() {
    form.revision = props.assessment.revision;
    publish.revision = props.assessment.revision;
    visibility.revision = props.assessment.revision;
    publish.reviewed = false;
}
function saveDraft() {
    form.patch(`/admin/assessments/${props.assessment.id}/draft`, {
        preserveScroll: true,
        onSuccess: () => {
            savedDefinition.value = JSON.stringify(form.definition);
            refreshRevision();
        },
    });
}
function publishDraft() {
    publish.post(`/admin/assessments/${props.assessment.id}/publish`, {
        preserveScroll: true,
        onSuccess: refreshRevision,
    });
}
function addDimension() {
    form.definition.dimensions.push({
        key: "d" + crypto.randomUUID().replaceAll("-", ""),
        title: "",
        description: "",
    });
}
function addQuestion() {
    form.definition.questions.push({
        key: "q" + crypto.randomUUID().replaceAll("-", ""),
        text: "",
        dimension: form.definition.dimensions[0]?.key || "",
        reverse: false,
    });
}
function toggle() {
    visibility.available = !props.assessment.available;
    visibility.patch(`/admin/assessments/${props.assessment.id}/availability`, {
        preserveScroll: true,
        onSuccess: refreshRevision,
    });
}
</script>
<template>
    <Head title="ویرایش پرسشنامه" /><Shell title="ویرایش پرسشنامه" role="admin"
        ><Link href="/admin/assessments" class="text-link"
            >بازگشت به پرسشنامه‌ها</Link
        >
        <section class="page-intro">
            <div>
                <h1>{{ assessment.title }}</h1>
                <p class="muted">
                    نسخه منتشرشده ثابت می‌ماند؛ ویرایش‌ها ابتدا در پیش‌نویس
                    ذخیره می‌شوند.
                </p>
            </div>
            <button
                v-if="assessment.current_version"
                class="btn secondary"
                :disabled="visibility.processing"
                @click="toggle"
            >
                {{
                    assessment.available
                        ? "بستن شروع تازه"
                        : "فعال‌کردن شروع تازه"
                }}
            </button>
        </section>
        <form
            class="panel glass consultation-card stack-form"
            @submit.prevent="saveDraft"
        >
            <h2>پیش‌نویس پرسشنامه</h2>
            <label
                >عنوان<input
                    name="assessment_title"
                    v-model="form.definition.title"
                    required
                    maxlength="160" /></label
            ><label
                >معرفی<textarea
                    name="assessment_description"
                    v-model="form.definition.description"
                    required
                    maxlength="3000"
                ></textarea></label
            ><label
                >راهنمای پاسخ‌دادن<textarea
                    name="assessment_instructions"
                    v-model="form.definition.instructions"
                    required
                    maxlength="5000"
                ></textarea></label
            ><label
                >منبع، نسخه و توضیح روش امتیازدهی<textarea
                    name="assessment_source"
                    v-model="form.definition.source"
                    required
                    maxlength="2000"
                ></textarea>
            </label>
            <p class="muted">
                روش پشتیبانی‌شده: گزینه‌های ۱ تا ۵، جمع امتیاز هر بعد و
                معکوس‌سازی ۶ منهای پاسخ. تفسیر بالینی، رتبه جامعه و فرمول
                اختصاصی محاسبه نمی‌شوند.
            </p>
            <fieldset>
                <legend>برچسب گزینه‌ها به ترتیب امتیاز ۱ تا ۵</legend>
                <label v-for="(_, i) in form.definition.options" :key="i"
                    >گزینه {{ fa(i + 1)
                    }}<input
                        v-model="form.definition.options[i]"
                        required
                        maxlength="150"
                /></label>
            </fieldset>
            <fieldset>
                <legend>ابعاد پرسشنامه</legend>
                <div
                    v-for="(d, i) in form.definition.dimensions"
                    :key="d.key"
                    class="assessment-editor-item"
                >
                    <label
                        >نام بعد {{ fa(i + 1)
                        }}<input
                            v-model="d.title"
                            :name="`dimension_title_${i}`"
                            required
                            maxlength="120" /></label
                    ><label
                        >توضیح قابل مشاهده در گزارش<textarea
                            v-model="d.description"
                            maxlength="2000"
                        ></textarea></label
                    ><button
                        v-if="form.definition.dimensions.length > 1"
                        class="text-link"
                        type="button"
                        @click="form.definition.dimensions.splice(i, 1)"
                    >
                        حذف این بعد
                    </button>
                </div>
                <button
                    type="button"
                    class="btn secondary"
                    @click="addDimension"
                    :disabled="form.definition.dimensions.length >= 20"
                >
                    افزودن بعد
                </button>
            </fieldset>
            <fieldset>
                <legend>پرسش‌ها</legend>
                <div
                    v-for="(q, i) in form.definition.questions"
                    :key="q.key"
                    class="assessment-editor-item"
                >
                    <label
                        >پرسش {{ fa(i + 1)
                        }}<textarea
                            v-model="q.text"
                            :name="`question_text_${i}`"
                            required
                            maxlength="1000"
                        ></textarea></label
                    ><label
                        >بعد مربوط<select v-model="q.dimension" required>
                            <option value="" disabled>انتخاب کنید</option>
                            <option
                                v-for="(d, j) in form.definition.dimensions"
                                :key="d.key"
                                :value="d.key"
                            >
                                {{ d.title || "بعد " + fa(j + 1) }}
                            </option>
                        </select></label
                    ><label
                        ><input type="checkbox" v-model="q.reverse" />امتیاز این
                        پرسش معکوس است.</label
                    ><button
                        v-if="form.definition.questions.length > 1"
                        type="button"
                        class="text-link"
                        @click="form.definition.questions.splice(i, 1)"
                    >
                        حذف این پرسش
                    </button>
                </div>
                <button
                    type="button"
                    class="btn secondary"
                    @click="addQuestion"
                    :disabled="form.definition.questions.length >= 300"
                >
                    افزودن پرسش
                </button>
            </fieldset>
            <button class="btn primary" :disabled="form.processing">
                ذخیره پیش‌نویس
            </button>
        </form>
        <form
            v-if="assessment.draft"
            class="panel glass consultation-card stack-form consultation-section"
            @submit.prevent="publishDraft"
        >
            <h2>انتشار نسخه تازه</h2>
            <p class="muted">
                آخرین پیش‌نویس ذخیره‌شده منتشر می‌شود. تغییرات ذخیره‌نشدهٔ فرم
                بالا در انتشار وارد نمی‌شوند. پاسخ‌ها و نتایج قبلی تغییر
                نمی‌کنند.
            </p>
            <label
                ><input
                    type="checkbox"
                    v-model="publish.reviewed"
                    required
                />پرسش‌ها، مجوز استفاده، منبع و تطبیق کلید با روش پشتیبانی‌شده
                را بررسی کرده‌ام.</label
            ><button
                class="btn primary"
                :disabled="
                    publish.processing || form.processing || unsavedDefinition
                "
            >
                انتشار نسخه تازه
            </button>
        </form>
        <section class="consultation-section">
            <h2>نسخه‌های منتشرشده</h2>
            <p v-for="v in versions" :key="v.id">
                نسخه {{ fa(v.number) }} · {{ dateTime(v.created_at) }}
            </p>
            <p v-if="!versions.length" class="muted">هنوز منتشر نشده است.</p>
        </section>
        <section class="consultation-section">
            <h2>گزارش‌های نهایی</h2>
            <p v-if="!reports.data.length" class="muted">
                هنوز گزارشی وجود ندارد.
            </p>
            <article
                v-for="r in reports.data"
                :key="r.id"
                class="panel glass consultation-card"
            >
                <p>
                    {{ r.student_name }} · نسخه {{ fa(r.number) }} ·
                    {{ dateTime(r.completed_at) }}
                </p>
                <Link
                    :href="`/assessment-attempts/${r.id}/report`"
                    class="btn secondary"
                    >مشاهده گزارش</Link
                >
            </article>
            <Pagination :links="reports.links" /></section
    ></Shell>
</template>

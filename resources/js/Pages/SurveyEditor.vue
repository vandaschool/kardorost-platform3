<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import SurveyWindowEditor from "../Components/SurveyWindowEditor.vue";
import { fa } from "../dates";
const props = defineProps({
    survey: Object,
    cohort_title: String,
    course_title: String,
    publications: Object,
});
const form = useForm({
    revision: props.survey.revision,
    definition: props.survey.draft
        ? JSON.parse(JSON.stringify(props.survey.draft))
        : {
              title: props.survey.title,
              description: "",
              mode: "",
              minimum_responses: 5,
              options: ["خیلی کم", "کم", "متوسط", "زیاد", "خیلی زیاد"],
              questions: [],
          },
});
const publish = useForm({
    revision: props.survey.revision,
    reviewed: false,
    opens_at: "",
    closes_at: "",
});
const availability = useForm({
    revision: props.survey.revision,
    available: !props.survey.available,
});
const receiving = useForm({ revision: 0, accepting: false });
function question() {
    form.definition.questions.push({
        key: "q_" + crypto.randomUUID().replaceAll("-", ""),
        text: "",
        type: "rating",
        required: true,
    });
}
function save() {
    form.patch("/admin/surveys/" + props.survey.id + "/draft", {
        preserveScroll: true,
        onSuccess: () => {
            form.revision = props.survey.revision;
            publish.revision = props.survey.revision;
        },
    });
}
function release() {
    publish.revision = props.survey.revision;
    publish.post("/admin/surveys/" + props.survey.id + "/publish", {
        preserveScroll: true,
        onSuccess: () => {
            form.revision = props.survey.revision;
            publish.reviewed = false;
        },
    });
}
function toggle() {
    availability.revision = props.survey.revision;
    availability.available = !props.survey.available;
    availability.post("/admin/surveys/" + props.survey.id + "/availability", {
        preserveScroll: true,
        onSuccess: () => (form.revision = props.survey.revision),
    });
}
function receive(publication) {
    receiving.revision = publication.revision;
    receiving.accepting = !publication.accepting;
    receiving.post(
        "/admin/survey-publications/" + publication.id + "/receiving",
        { preserveScroll: true },
    );
}
</script>
<template>
    <Head title="ویرایش نظرسنجی" /><Shell title="ویرایش نظرسنجی" role="admin"
        ><Link href="/admin/surveys" class="text-link">همه نظرسنجی‌ها</Link>
        <section class="page-intro">
            <div>
                <h1>{{ survey.title }}</h1>
                <p>{{ course_title }} · {{ cohort_title }}</p>
            </div>
        </section>
        <form
            class="glass panel-card form-stack"
            data-survey-draft
            @submit.prevent="save"
        >
            <label
                >عنوان<input
                    v-model="form.definition.title"
                    name="survey_title"
                    maxlength="160"
                    required /></label
            ><label
                >توضیح برای دانشجو<textarea
                    v-model="form.definition.description"
                    name="survey_description"
                    maxlength="3000"
                    required
                /></label
            ><label
                >نمایش هویت در گزارش<select
                    v-model="form.definition.mode"
                    name="survey_mode"
                    required
                >
                    <option value="" disabled>انتخاب شیوه گزارش</option>
                    <option value="aggregate">
                        بدون نمایش نام؛ گزارش جمعی
                    </option>
                    <option value="identified">پاسخ همراه نام</option>
                </select></label
            ><label v-if="form.definition.mode === 'aggregate'"
                >حداقل پاسخ برای گزارش هر پرسش<input
                    v-model="form.definition.minimum_responses"
                    name="survey_minimum"
                    type="number"
                    min="3"
                    max="50"
                    required
            /></label>
            <p class="caption muted">
                در گزارش جمعی نام، زمان و پاسخ امتیازی هر فرد نمایش داده
                نمی‌شود. ارتباط ثبت‌نام برای جلوگیری از تکرار در سامانه حفظ
                می‌شود.
            </p>
            <fieldset>
                <legend>برچسب امتیازهای یک تا پنج</legend>
                <label
                    v-for="(_, index) in form.definition.options"
                    :key="index"
                    >{{ fa(index + 1)
                    }}<input
                        v-model="form.definition.options[index]"
                        maxlength="100"
                        required
                /></label>
            </fieldset>
            <fieldset
                v-for="(q, index) in form.definition.questions"
                :key="q.key"
                :data-survey-question="index"
            >
                <legend>پرسش {{ fa(index + 1) }}</legend>
                <label
                    >متن<input
                        v-model="q.text"
                        maxlength="1000"
                        required /></label
                ><label
                    >نوع<select v-model="q.type">
                        <option value="rating">امتیاز یک تا پنج</option>
                        <option value="text">متن کوتاه</option>
                    </select></label
                ><label class="check-label"
                    ><input v-model="q.required" type="checkbox" />پاسخ
                    الزامی</label
                ><button
                    type="button"
                    class="text-link"
                    @click="form.definition.questions.splice(index, 1)"
                >
                    حذف از پیش‌نویس
                </button>
            </fieldset>
            <button
                type="button"
                class="btn secondary"
                :disabled="form.definition.questions.length >= 30"
                @click="question"
                data-add-survey-question
            >
                افزودن پرسش
            </button>
            <p
                v-for="(error, key) in form.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
            <button class="btn primary" :disabled="form.processing">
                ذخیره پیش‌نویس
            </button>
        </form>
        <section class="glass panel-card consultation-section form-stack">
            <h2>انتشار نسخه ثابت</h2>
            <p class="caption muted">
                تغییر پیش‌نویس، پاسخ‌های نسخه‌های قبلی را عوض نمی‌کند. پاسخ
                نیمه‌کاره نسخه قبلی تا پایان مهلت یا بستن دریافت همان نسخه ادامه
                دارد.
            </p>
            <form
                class="form-stack"
                data-survey-publish
                @submit.prevent="release"
            >
                <label
                    >شروع دریافت، اختیاری (ساعت تهران)<input
                        v-model="publish.opens_at"
                        type="datetime-local" /></label
                ><label
                    >پایان دریافت، اختیاری (ساعت تهران)<input
                        v-model="publish.closes_at"
                        type="datetime-local" /></label
                ><label class="check-label"
                    ><input
                        v-model="publish.reviewed"
                        type="checkbox"
                        required
                    />متن پرسش‌ها، شیوه نمایش هویت و مهلت را بررسی کردم.</label
                >
                <p
                    v-for="(error, key) in publish.errors"
                    :key="key"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button
                    class="btn primary"
                    :disabled="publish.processing || !survey.draft"
                >
                    انتشار نظرسنجی
                </button>
            </form>
            <button
                v-if="survey.current_version"
                class="btn secondary"
                @click="toggle"
                :disabled="availability.processing"
            >
                {{
                    survey.available
                        ? "توقف شروع پاسخ تازه"
                        : "فعال‌سازی شروع پاسخ تازه"
                }}
            </button>
            <p
                v-for="(error, key) in availability.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ error }}
            </p>
        </section>
        <h2>نسخه‌های منتشرشده</h2>
        <article
            v-for="publication in publications.data"
            :key="publication.id"
            class="glass panel-card consultation-section form-stack"
        >
            <strong>نسخه {{ fa(publication.number) }}</strong>
            <p>
                {{
                    publication.receiving
                        ? "دریافت در این زمان باز"
                        : "دریافت در این زمان بسته"
                }}
                ·
                {{
                    publication.mode === "identified" ? "با نام" : "گزارش جمعی"
                }}
            </p>
            <div class="learning-actions">
                <Link
                    :href="'/survey-publications/' + publication.id + '/report'"
                    class="btn secondary"
                    >گزارش این نسخه</Link
                ><button
                    class="text-link"
                    @click="receive(publication)"
                    :disabled="receiving.processing"
                >
                    {{
                        publication.accepting
                            ? "بستن دریافت این نسخه"
                            : "بازکردن دریافت این نسخه"
                    }}
                </button>
            </div>
            <SurveyWindowEditor :publication="publication" />
        </article>
        <p
            v-for="(error, key) in receiving.errors"
            :key="key"
            class="field-error"
            role="alert"
        >
            {{ error }}
        </p>
        <Pagination :links="publications.links"
    /></Shell>
</template>

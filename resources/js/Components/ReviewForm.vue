<script setup>
import { ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import Icon from "./Icon.vue";
import FeedbackHistory from "./FeedbackHistory.vue";
import AttemptHistory from "./AttemptHistory.vue";
import { fa, dateTime, submissionStatuses } from "../dates";
const props = defineProps({
    submission: Object,
    assignment: Object,
    memberName: String,
});
const editing = ref(false);
const form = useForm({
    status: "reviewed",
    score: null,
    feedback: "",
    expected_review_version: 0,
});
function edit() {
    form.status =
        props.submission.status === "revision_requested"
            ? "revision_requested"
            : "reviewed";
    form.score = props.submission.score;
    form.feedback = props.submission.feedback || "";
    form.expected_review_version = props.submission.review_version;
    editing.value = true;
}
function save() {
    form.patch(`/submissions/${props.submission.id}/review`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = false),
    });
}
</script>
<template>
    <article class="review-card">
        <div class="section-heading">
            <div>
                <h3>{{ memberName }}</h3>
                <span
                    v-if="
                        assignment.due_at &&
                        new Date(submission.created_at) >
                            new Date(assignment.due_at)
                    "
                    class="badge gold"
                    >پس از موعد پیشنهادی</span
                >
                <p class="muted caption">
                    {{ assignment.title }} · پاسخ {{ fa(submission.version) }} ·
                    {{ dateTime(submission.created_at) }}
                </p>
            </div>
            <span
                :class="[
                    'badge',
                    submission.status === 'reviewed' ? 'teal' : 'gold',
                ]"
                >{{ submissionStatuses[submission.status] }}</span
            >
        </div>
        <p class="lesson-text">{{ submission.body }}</p>
        <a
            v-if="submission.download_url"
            :href="submission.download_url"
            class="text-link"
            ><Icon name="Download" :size="16" />{{ submission.file_name }}</a
        >
        <div v-if="submission.feedback" class="feedback-note">
            <Icon name="MessageSquare" :size="17" />
            <p class="lesson-text">{{ submission.feedback }}</p>
        </div>
        <p v-if="submission.score !== null" class="caption">
            نمره {{ fa(submission.score) }} از {{ fa(assignment.max_score) }}
        </p>
        <FeedbackHistory :reviews="submission.reviews || []" /><AttemptHistory
            :attempts="submission.attempt_history || []"
        /><button v-if="!editing" class="btn secondary" @click="edit">
            <Icon name="Pencil" :size="16" />{{
                submission.status === "submitted"
                    ? "بررسی پاسخ"
                    : "ویرایش بازخورد"
            }}
        </button>
        <form v-else class="form-stack review-form" @submit.prevent="save">
            <label
                >نتیجه بررسی<select v-model="form.status">
                    <option value="reviewed">بررسی شد و نمره دارد</option>
                    <option value="revision_requested">
                        نیاز به اصلاح و ارسال مجدد
                    </option>
                </select></label
            ><label v-if="form.status === 'reviewed'"
                >نمره از {{ fa(assignment.max_score)
                }}<input
                    v-model.number="form.score"
                    type="number"
                    min="0"
                    :max="assignment.max_score"
                    required /></label
            ><label
                >بازخورد<textarea
                    v-model="form.feedback"
                    rows="3"
                    maxlength="12000"
                    :required="form.status === 'revision_requested'"
                ></textarea>
            </label>
            <p
                v-for="(v, k) in form.errors"
                :key="k"
                class="field-error"
                role="alert"
            >
                {{ v }}
            </p>
            <div class="learning-actions">
                <button class="btn primary" :disabled="form.processing">
                    ذخیره بازخورد</button
                ><button
                    type="button"
                    class="btn secondary"
                    @click="editing = false"
                >
                    انصراف
                </button>
            </div>
        </form>
    </article>
</template>

<script setup>
import { computed, ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import Icon from "./Icon.vue";
import FeedbackHistory from "./FeedbackHistory.vue";
import AttemptHistory from "./AttemptHistory.vue";
import { fa, dateTime, submissionStatuses } from "../dates";
const props = defineProps({ assignment: Object, enrollmentId: Number });
const latest = computed(() => props.assignment.attempts[0]);
const open = ref(false);
const input = ref(null);
const form = useForm({ body: "", file: null, expected_version: 0 });
function toggle() {
    if (!open.value) {
        form.expected_version = latest.value?.version || 0;
    }
    open.value = !open.value;
}
function submit() {
    form.post(
        `/learning/${props.enrollmentId}/assignments/${props.assignment.id}/submit`,
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                if (input.value) input.value.value = "";
                open.value = false;
            },
        },
    );
}
</script>
<template>
    <article class="assignment-card">
        <div class="section-heading">
            <div>
                <span class="eyebrow"
                    ><Icon name="FileText" :size="15" />تمرین دوره</span
                >
                <h3>{{ assignment.title }}</h3>
            </div>
            <span
                :class="[
                    'badge',
                    latest?.status === 'reviewed' ? 'teal' : 'gold',
                ]"
                >{{
                    latest
                        ? submissionStatuses[latest.status]
                        : "هنوز ارسال نشده"
                }}</span
            >
        </div>
        <p class="lesson-text">{{ assignment.instructions }}</p>
        <div class="learning-meta">
            <span>نمره از {{ fa(assignment.max_score) }}</span
            ><span v-if="assignment.due_at"
                >موعد پیشنهادی: {{ dateTime(assignment.due_at) }}</span
            ><span v-if="assignment.closes_at"
                >پایان ارسال: {{ dateTime(assignment.closes_at) }}</span
            >
        </div>
        <div v-if="latest" class="submission-feedback">
            <div class="section-heading">
                <strong>آخرین پاسخ · نسخه {{ fa(latest.version) }}</strong
                ><span
                    v-if="
                        assignment.due_at &&
                        new Date(latest.created_at) >
                            new Date(assignment.due_at)
                    "
                    class="badge gold"
                    >پس از موعد پیشنهادی</span
                ><span v-if="latest.score !== null" class="badge teal"
                    >نمره {{ fa(latest.score) }} از
                    {{ fa(assignment.max_score) }}</span
                >
            </div>
            <p v-if="latest.body" class="lesson-text">{{ latest.body }}</p>
            <a
                v-if="latest.download_url"
                :href="latest.download_url"
                class="text-link"
                ><Icon name="Download" :size="16" />{{ latest.file_name }}</a
            >
            <div v-if="latest.feedback" class="feedback-note">
                <Icon name="MessageSquare" :size="17" />
                <p class="lesson-text">{{ latest.feedback }}</p>
            </div>
            <FeedbackHistory :reviews="latest.reviews || []" />
        </div>
        <p v-if="!assignment.open" class="muted caption">
            ارسال پاسخ برای این تمرین بسته شده است.
        </p>
        <p v-else-if="latest?.status === 'reviewed'" class="muted caption">
            این پاسخ بررسی شده است. اگر مربی درخواست اصلاح بدهد، ارسال مجدد فعال
            می‌شود.
        </p>
        <button
            v-else
            type="button"
            class="btn secondary"
            @click="toggle"
            :aria-expanded="open"
        >
            <Icon :name="open ? 'X' : 'Plus'" :size="17" />{{
                open ? "بستن فرم" : latest ? "ارسال نسخه جدید" : "پاسخ به تمرین"
            }}
        </button>
        <form
            v-if="open && assignment.open && latest?.status !== 'reviewed'"
            class="form-stack submission-form"
            @submit.prevent="submit"
        >
            <label
                >پاسخ شما<textarea
                    v-model="form.body"
                    rows="5"
                    maxlength="12000"
                    placeholder="پاسخ را بنویسید یا یک فایل پیوست کنید."
                ></textarea></label
            ><label
                >فایل پیوست، اختیاری<input
                    ref="input"
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png,.docx"
                    @change="
                        form.file = $event.target.files[0] || null
                    " /></label
            ><small class="muted"
                >PDF، تصویر یا Word؛ حداکثر ۱۰ مگابایت. پیوست نسخه قبلی جایگزین
                نمی‌شود.</small
            >
            <p
                v-for="(value, key) in form.errors"
                :key="key"
                class="field-error"
                role="alert"
            >
                {{ value }}
            </p>
            <progress
                v-if="form.progress"
                :value="form.progress.percentage"
                max="100"
                aria-label="پیشرفت بارگذاری"
            ></progress
            ><button class="btn primary" :disabled="form.processing">
                <Icon name="Upload" :size="17" />{{
                    form.processing ? "در حال ثبت پاسخ…" : "ثبت پاسخ"
                }}
            </button>
        </form>
        <AttemptHistory :attempts="assignment.attempts.slice(1)" />
    </article>
</template>

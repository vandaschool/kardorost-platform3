<script setup>
import { useForm } from "@inertiajs/vue3";
import ContentMediaThumbnail from "./ContentMediaThumbnail.vue";
import { dateTime, fa } from "../dates";
const props = defineProps({ photo: Object, needsReason: Boolean });
const form = useForm({ revision: props.photo.revision, reason: "" });
function withdraw() {
    form.revision = props.photo.revision;
    form.post(`/coach/profile-photos/${props.photo.id}/withdraw`, {
        preserveScroll: true,
    });
}
</script>
<template>
    <article
        class="glass panel-card consultation-section"
        :data-coach-photo="photo.id"
    >
        <h3>{{ photo.metadata?.title || "عکس با اطلاعات نامعتبر" }}</h3>
        <p>
            {{
                photo.withdrawn_at
                    ? "برداشته‌شده"
                    : photo.public_url
                      ? "در نسخهٔ عمومی پروفایل"
                      : "خصوصی؛ در نسخهٔ عمومی کنونی نیست"
            }}
            ·
            {{ dateTime(photo.consented_at) }}
        </p>
        <template v-if="photo.metadata"
            ><ContentMediaThumbnail
                v-if="!photo.withdrawn_at"
                :source="`/coach/profile-photos/${photo.public_key}/preview`"
                :alt="photo.metadata.title"
                class="photo-preview"
            />
            <p class="caption muted">
                {{ fa(photo.metadata.width) }}×{{
                    fa(photo.metadata.height)
                }}
                پیکسل · {{ fa(Math.ceil(photo.metadata.size / 1024)) }} کیلوبایت
            </p></template
        >
        <p v-if="photo.withdrawal">
            دلیل برداشت: {{ photo.withdrawal.reason }} ·
            {{ dateTime(photo.withdrawal.at) }}
        </p>
        <form
            v-if="!photo.withdrawn_at"
            class="form-stack"
            @submit.prevent="withdraw"
            data-coach-photo-withdraw
        >
            <p class="caption muted">
                برداشتن، دریافت تازهٔ همین عکس را متوقف می‌کند؛ نسخهٔ آماده،
                رضایت و سابقه باقی می‌مانند. کپی‌های قبلاً دریافت‌شده از دستگاه
                دیگران پاک نمی‌شوند.
            </p>
            <label v-if="needsReason"
                >دلیل برداشت توسط مدیریت<textarea
                    v-model="form.reason"
                    required
                    minlength="3"
                    maxlength="2000"
                    :disabled="form.processing"
                />
            </label>
            <p v-for="error in form.errors" class="field-error" role="alert">
                {{ error }}
            </p>
            <button class="btn secondary" :disabled="form.processing">
                برداشتن عکس
            </button>
        </form>
    </article>
</template>
<style scoped>
.photo-preview {
    display: block;
    max-width: 100%;
    max-height: 12rem;
    border-radius: 14px;
}
p {
    overflow-wrap: anywhere;
}
</style>

<script setup>
import { onMounted, ref } from "vue";
import { useForm } from "@inertiajs/vue3";
import { dateTime, fa } from "../dates";
const props = defineProps({ share: Object, own: Boolean });
const revoke = useForm({ revision: props.share.revision, reason: "" });
const input = ref(null);
const copyStatus = ref("");
const absoluteUrl = ref(props.share.url || "");
onMounted(() => {
    if (props.share.url)
        absoluteUrl.value = new URL(
            props.share.url,
            window.location.origin,
        ).href;
});
async function copy() {
    try {
        if (!navigator.clipboard?.writeText)
            throw Error("clipboard unavailable");
        await navigator.clipboard.writeText(absoluteUrl.value);
        copyStatus.value = "لینک کپی شد";
    } catch {
        input.value?.select();
        copyStatus.value = "لینک را از کادر انتخاب‌شده دستی کپی کنید.";
    }
}
</script>
<template>
    <article
        class="share-card consultation-section"
        :data-resume-share="share.id"
    >
        <h3>
            {{
                {
                    active: "مهلت لینک باقی است",
                    expired: "مهلت لینک تمام شده",
                    revoked: "لینک قطع شده",
                }[share.status]
            }}
        </h3>
        <p class="muted">
            ساخته‌شده: {{ dateTime(share.created_at) }} · مهلت:
            {{ dateTime(share.expires_at) }} · {{ fa(share.duration_days) }} روز
        </p>
        <p>
            {{
                share.include_contacts
                    ? "با تلفن، ایمیل و پیوندهای نسخه"
                    : "بدون فیلدهای تلفن، ایمیل و پیوندها"
            }}
        </p>
        <template v-if="share.available && share.url">
            <label
                >لینک این نسخه<input
                    ref="input"
                    :value="absoluteUrl"
                    dir="ltr"
                    readonly
                    aria-label="لینک اشتراک رزومه"
            /></label>
            <div class="action-row">
                <button
                    type="button"
                    class="btn secondary"
                    @click="copy"
                    data-copy-resume-share
                >
                    کپی لینک</button
                ><a
                    :href="share.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="btn secondary"
                    data-open-resume-share
                    >بازکردن نسخه اشتراکی</a
                >
            </div>
            <p v-if="copyStatus" role="status">{{ copyStatus }}</p>
        </template>
        <p v-else-if="share.status === 'active'" class="muted">
            این لینک در حال حاضر در دسترس نیست؛ وضعیت حساب و نسخه منبع نیز در
            دسترسی اثر دارند.
        </p>
        <p v-if="share.revoked_at" class="muted">
            زمان قطع: {{ dateTime(share.revoked_at)
            }}<span v-if="share.reason"> · {{ share.reason }}</span>
        </p>
        <details v-if="!share.revoked_at">
            <summary>قطع این لینک</summary>
            <form
                class="stack-form"
                @submit.prevent="
                    revoke.post('/career/shares/' + share.id + '/revoke')
                "
                data-revoke-resume-share
            >
                <p>
                    گیرندگان قبلی ممکن است متن یا فایل را قبلاً ذخیره کرده
                    باشند.
                </p>
                <label
                    >{{ own ? "دلیل؛ اختیاری" : "دلیل برای صاحب رزومه؛ الزامی"
                    }}<textarea
                        v-model="revoke.reason"
                        name="share_reason"
                        maxlength="1000"
                        rows="3"
                        :required="!own"
                    />
                </label>
                <p
                    v-for="error in revoke.errors"
                    class="field-error"
                    role="alert"
                >
                    {{ error }}
                </p>
                <button class="btn secondary" :disabled="revoke.processing">
                    قطع لینک
                </button>
            </form>
        </details>
    </article>
</template>
<style scoped>
.share-card {
    min-width: 0;
    overflow-wrap: anywhere;
}
.share-card input {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    margin: 0.5rem 0;
}
.share-card .action-row {
    flex-wrap: wrap;
}
.share-card summary {
    cursor: pointer;
    margin: 0.8rem 0;
}
</style>

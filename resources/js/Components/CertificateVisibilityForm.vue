<script setup>
import { watch } from "vue";
import { useForm } from "@inertiajs/vue3";
import { dateTime } from "../dates";
const props = defineProps({ certificate: Object });
const form = useForm({
    revision: props.certificate.revision,
    enabled: props.certificate.public_enabled,
    public_consent: false,
});
watch(
    () => props.certificate.revision,
    () => {
        form.revision = props.certificate.revision;
        form.enabled = props.certificate.public_enabled;
        form.public_consent = false;
    },
);
</script>
<template>
    <form
        class="panel glass consultation-card stack-form consultation-section"
        data-certificate-visibility
        @submit.prevent="
            form.post(`/certificates/${certificate.id}/visibility`, {
                preserveScroll: true,
            })
        "
    >
        <h2>نمایش عمومی گواهی</h2>
        <p>
            وضعیت فعلی:
            {{
                certificate.public_enabled
                    ? "استعلام عمومی فعال است"
                    : "گواهی برای مشاهده خصوصی در حساب شماست"
            }}
        </p>
        <p class="muted">
            با بستن نمایش عمومی، بازدیدهای بعدی از لینک برای دیگران بسته می‌شود.
            سابقه و وضعیت صدور گواهی در حساب شما حفظ می‌شوند.
        </p>
        <label
            ><input
                type="checkbox"
                name="certificate_public_enabled"
                v-model="form.enabled"
            />نمایش نام و اطلاعات گواهی از طریق لینک برای عموم فعال باشد</label
        ><label v-if="form.enabled && !certificate.public_enabled"
            ><input
                type="checkbox"
                name="certificate_public_consent"
                v-model="form.public_consent"
                required
            />با نمایش دوباره نام، عنوان دوره، صادرکننده و وضعیت گواهی برای
            دارندگان همین لینک موافقم.</label
        >
        <p v-if="certificate.public_consent_at" class="muted">
            آخرین رضایت نمایش عمومی:
            {{ dateTime(certificate.public_consent_at) }}
        </p>
        <p
            v-for="(error, key) in form.errors"
            :key="key"
            class="field-error"
            role="alert"
        >
            {{ error }}
        </p>
        <button
            class="btn secondary"
            :disabled="
                form.processing || form.enabled === certificate.public_enabled
            "
        >
            {{ form.enabled ? "فعال‌کردن نمایش عمومی" : "بستن نمایش عمومی" }}
        </button>
    </form>
</template>

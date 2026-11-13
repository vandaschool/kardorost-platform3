<script setup>
import { useForm } from "@inertiajs/vue3";
import { dateTime } from "../dates";
const props = defineProps({ invitation: Object, manager: Boolean });
const form = useForm({
    revision: props.invitation.revision,
    action: "",
    consent: false,
});
const labels = {
    pending: "منتظر پاسخ",
    accepted: "پذیرفته‌شده",
    declined: "ردشده توسط فرد",
    revoked: "لغوشده توسط سازمان",
};
function act(action) {
    form.revision = props.invitation.revision;
    form.action = action;
    form.post(
        props.manager
            ? `/organization/invitations/${props.invitation.id}/change`
            : `/organization-invitations/${props.invitation.id}/respond`,
        { preserveScroll: true },
    );
}
</script>
<template>
    <article
        class="panel glass consultation-card invitation-card"
        :data-invitation-id="invitation.id"
    >
        <span class="status-badge">{{
            invitation.status === "pending" && invitation.expired
                ? "مهلت گذشته"
                : labels[invitation.status]
        }}</span>
        <h2 v-if="manager">
            <bdi>{{ invitation.phone }}</bdi>
        </h2>
        <h2 v-else>{{ invitation.organization.name }}</h2>
        <p>مهلت پاسخ: {{ dateTime(invitation.expires_at) }}</p>
        <p v-if="invitation.responded_at" class="muted">
            زمان پاسخ: {{ dateTime(invitation.responded_at) }}
        </p>
        <template v-if="manager">
            <p class="muted">
                تا پیش از پذیرش، این دعوت عضویت یا دسترسی به گزارش فرد ایجاد
                نمی‌کند.
            </p>
            <div
                class="learning-actions"
                v-if="invitation.status !== 'accepted'"
            >
                <button
                    v-if="invitation.status === 'pending'"
                    class="btn secondary"
                    :disabled="form.processing"
                    @click="act('revoke')"
                >
                    لغو دعوت
                </button>
                <button
                    v-if="invitation.status !== 'pending' || invitation.expired"
                    class="btn primary"
                    :disabled="form.processing"
                    @click="act('renew')"
                >
                    دعوت دوباره برای هفت روز
                </button>
            </div>
        </template>
        <form
            v-else-if="invitation.status === 'pending' && !invitation.expired"
            class="stack-form"
            @submit.prevent="act('accept')"
        >
            <p>
                با پذیرش، مدیران فعلی این سازمان به گزارش‌های فردی شما در
                کاردرست شامل دوره‌ها، پیشرفت، حضور، تکلیف و نتایج نهایی خودشناسی
                دسترسی پیدا می‌کنند. اطلاعات بانکی و بازپرداخت شخصی در این
                دسترسی نیست.
            </p>
            <p class="muted">
                این پذیرش شما را در دوره‌ای ثبت‌نام نمی‌کند و دسترسی مدیریت
                سازمان نمی‌دهد.
            </p>
            <label
                ><input v-model="form.consent" type="checkbox" required />با
                عضویت و دسترسی گزارش‌های توضیح‌داده‌شده موافقم.</label
            >
            <div class="learning-actions">
                <button class="btn primary" :disabled="form.processing">
                    پذیرش عضویت</button
                ><button
                    type="button"
                    class="btn secondary"
                    :disabled="form.processing"
                    @click="act('decline')"
                >
                    رد دعوت
                </button>
            </div>
        </form>
        <p
            v-else-if="!manager && invitation.status === 'accepted'"
            class="muted"
        >
            عضویت ثبت شده است. ثبت‌نام گروهی دوره را سازمان جداگانه انجام
            می‌دهد.
        </p>
        <p
            v-for="(error, key) in form.errors"
            :key="key"
            class="field-error"
            role="alert"
        >
            {{ error }}
        </p>
    </article>
</template>

<script setup>
import { Head } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import OrganizationInvitationCard from "../Components/OrganizationInvitationCard.vue";
import Pagination from "../Components/Pagination.vue";
defineProps({ invitations: Object });
</script>
<template>
    <Head title="دعوت سازمان‌ها" /><Shell title="دعوت سازمان‌ها" role="student">
        <section class="page-intro">
            <div>
                <span class="eyebrow">انتخاب آگاهانه عضویت</span>
                <h1>دعوت سازمان‌ها</h1>
                <p class="muted">
                    فقط دعوت‌های مرتبط با شماره تأییدشده حساب شما نمایش داده
                    می‌شوند. پیش از پذیرش، نام سازمان و دامنه دسترسی گزارش را
                    بخوانید.
                </p>
            </div>
        </section>
        <p v-if="!invitations.data.length" class="muted">
            دعوتی برای شماره شما از سازمان‌های فعال وجود ندارد.
        </p>
        <div class="consultation-grid">
            <OrganizationInvitationCard
                v-for="invitation in invitations.data"
                :key="invitation.id"
                :invitation="invitation"
            />
        </div>
        <Pagination :links="invitations.links" />
    </Shell>
</template>

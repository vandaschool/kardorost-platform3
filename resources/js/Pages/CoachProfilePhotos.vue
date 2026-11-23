<script setup>
import { Head, Link, usePage } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import ProfilePhotoUpload from "../Components/ProfilePhotoUpload.vue";
import CoachProfilePhotoCard from "../Components/CoachProfilePhotoCard.vue";
defineProps({
    photos: Array,
    can_upload: Boolean,
    has_profile: Boolean,
    consent: String,
    role: String,
});
const page = usePage();
</script>
<template>
    <Head title="عکس‌های پروفایل من" /><Shell
        title="عکس‌های پروفایل من"
        :role="page.props.activeRole"
    >
        <section class="page-intro">
            <div>
                <h1>عکس‌های پروفایل من</h1>
                <p>
                    نسخه‌های آمادهٔ عکس برای معرفی مربی؛ بارگذاری به‌تنهایی
                    نمایش عمومی را فعال نمی‌کند.
                </p>
            </div>
            <Link
                v-if="can_upload || has_profile"
                href="/coach/profile"
                class="btn secondary"
                >پروفایل حرفه‌ای مربی</Link
            >
        </section>
        <details
            v-if="can_upload"
            class="glass panel-card consultation-section"
        >
            <summary>عکس تازه</summary>
            <ProfilePhotoUpload :consent="consent" />
        </details>
        <p v-else class="glass panel-card">
            ثبت عکس تازه نیازمند نقش مربی فعال و موبایل تأییدشده است. عکس‌های
            قبلی و کنترل برداشتن آن‌ها متعلق به حساب شماست.
        </p>
        <p v-if="!photos.length" class="glass panel-card">
            هنوز عکسی ثبت نشده است.
        </p>
        <CoachProfilePhotoCard
            v-for="photo in photos"
            :key="photo.id"
            :photo="photo"
        />
    </Shell>
</template>

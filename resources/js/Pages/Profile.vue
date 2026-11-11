<script setup>
import { Head, useForm, usePage, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
const page = usePage();
const form = useForm({ name: page.props.auth.user.name });
const security = useForm({
    current_password: "",
    password: "",
    password_confirmation: "",
});
</script>
<template>
    <Head title="حساب من" /><Shell title="حساب من" :role="page.props.activeRole"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">فضای شخصی شما</span>
                <h1>حساب من</h1>
                <p class="muted">اطلاعات حسابتان را مرور و به‌روز کنید.</p>
            </div>
        </section>
        <section class="glass panel-card narrow">
            <span class="profile-avatar">{{
                page.props.auth.user.name.slice(0, 1)
            }}</span>
            <h2>اطلاعات شخصی</h2>
            <form @submit.prevent="form.patch('/account')" class="form-stack">
                <label
                    >نام و نام خانوادگی<input
                        v-model="form.name"
                        autocomplete="name"
                        required
                        maxlength="100" /></label
                ><label
                    >شماره موبایل تأییدشده<input
                        :value="page.props.auth.user.phone"
                        disabled
                        dir="ltr"
                /></label>
                <p class="caption muted">
                    <Icon name="ShieldCheck" :size="16" /> شماره موبایل شما
                    تأیید شده است.
                </p>
                <button class="btn primary" :disabled="form.processing">
                    ذخیره تغییرات<Icon name="Check" :size="17" /></button
                ><Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="btn secondary"
                    >خروج از حساب</Link
                >
            </form>
        </section>
        <section class="glass panel-card narrow">
            <h2>تغییر رمز عبور</h2>
            <form
                class="form-stack"
                @submit.prevent="
                    security.patch('/account/password', {
                        onSuccess: () => security.reset(),
                    })
                "
            >
                <label
                    >رمز فعلی<input
                        v-model="security.current_password"
                        type="password"
                        dir="ltr"
                        autocomplete="current-password"
                        required /></label
                ><label
                    >رمز جدید<input
                        v-model="security.password"
                        type="password"
                        dir="ltr"
                        autocomplete="new-password"
                        minlength="8"
                        required /></label
                ><label
                    >تکرار رمز جدید<input
                        v-model="security.password_confirmation"
                        type="password"
                        dir="ltr"
                        autocomplete="new-password"
                        minlength="8"
                        required
                /></label>
                <p
                    v-for="(e, k) in security.errors"
                    :key="k"
                    class="field-error"
                    role="alert"
                >
                    {{ e }}
                </p>
                <button class="btn primary" :disabled="security.processing">
                    تغییر رمز<Icon name="ShieldCheck" :size="17" />
                </button>
            </form></section
    ></Shell>
</template>

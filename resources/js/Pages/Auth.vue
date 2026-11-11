<script setup>
import { computed, ref } from "vue";
import { Head, Link, useForm, usePage, router } from "@inertiajs/vue3";
import Icon from "../Components/Icon.vue";
const props = defineProps({ mode: String, step: String, phone: String });
const form = useForm({
    phone: props.phone || "",
    password: "",
    password_confirmation: "",
    name: "",
    mode: props.mode,
    code: "",
});
const visible = ref(false);
const login = computed(() => props.mode === "login");
const title = computed(() =>
    login.value
        ? "خوش برگشتید"
        : props.mode === "register"
          ? "مسیر تازه‌تان از اینجا شروع می‌شود"
          : "بازیابی رمز عبور",
);
const page = usePage();
const latin = (s) =>
    String(s).replace(/[۰-۹٠-٩]/g, (d) =>
        String(
            "۰۱۲۳۴۵۶۷۸۹".includes(d)
                ? "۰۱۲۳۴۵۶۷۸۹".indexOf(d)
                : "٠١٢٣٤٥٦٧٨٩".indexOf(d),
        ),
    );
function submit() {
    form.transform((d) => ({
        ...d,
        phone: latin(d.phone),
        code: latin(d.code),
    })).post(
        login.value
            ? "/login"
            : props.step === "phone"
              ? "/auth/send-code"
              : props.step === "code"
                ? "/auth/verify-code"
                : "/auth/complete",
        {
            onFinish: () => {
                form.password = "";
                form.password_confirmation = "";
            },
        },
    );
}
</script>
<template>
    <Head
        :title="
            login ? 'ورود' : mode === 'register' ? 'ثبت‌نام' : 'بازیابی رمز'
        "
    />
    <div class="auth-page">
        <div class="auth-header">
            <Link href="/login"
                ><img
                    src="/brand/logo.svg"
                    class="logo-light"
                    alt="کاردرست"
                    width="155" /><img
                    src="/brand/logo-reverse.svg"
                    class="logo-dark"
                    alt="کاردرست"
                    width="155" /></Link
            ><span>یک قدم نزدیک‌تر به خودتان</span>
        </div>
        <main class="auth-grid">
            <section class="auth-form glass">
                <span class="eyebrow"
                    ><span class="online-dot"></span>به کاردرست خوش آمدید</span
                >
                <h1>{{ title }}</h1>
                <p class="muted">
                    {{
                        login
                            ? "برای ادامه مسیر، وارد حساب خود شوید."
                            : step === "code"
                              ? "کد پنج‌رقمی پیامک‌شده را وارد کنید."
                              : step === "password"
                                ? "یک رمز شخصی برای ورودهای بعدی انتخاب کنید."
                                : "شماره موبایل خود را وارد کنید تا آن را تأیید کنیم."
                    }}
                </p>
                <div
                    v-if="!login"
                    class="auth-steps"
                    aria-label="مراحل ثبت‌نام"
                >
                    <span :class="{ done: step === 'phone' }"
                        >۱. شماره موبایل</span
                    ><span :class="{ done: step === 'code' }"
                        >۲. تأیید شماره</span
                    ><span :class="{ done: step === 'password' }"
                        >۳. رمز عبور</span
                    >
                </div>
                <div
                    v-if="page.props.flash.success"
                    class="flash success"
                    role="status"
                >
                    {{ page.props.flash.success }}
                </div>
                <form @submit.prevent="submit" class="form-stack">
                    <label v-if="login || step === 'phone'"
                        >شماره موبایل<input
                            v-model="form.phone"
                            name="phone"
                            type="tel"
                            dir="ltr"
                            inputmode="tel"
                            autocomplete="tel"
                            placeholder="۰۹۱۲ ۳۴۵ ۶۷۸۹"
                            required
                            maxlength="30"
                            :aria-invalid="!!form.errors.phone"
                            :autofocus="true"
                    /></label>
                    <div
                        v-if="!login && step !== 'phone'"
                        class="verified-phone"
                    >
                        <Icon name="Smartphone" :size="18" /><b dir="ltr">{{
                            phone
                        }}</b
                        ><button
                            type="button"
                            class="text-link"
                            @click="router.post('/auth/restart')"
                        >
                            تغییر شماره
                        </button>
                    </div>
                    <label v-if="!login && step === 'code'"
                        >کد تأیید<input
                            v-model="form.code"
                            name="code"
                            class="otp-input"
                            dir="ltr"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            minlength="5"
                            maxlength="5"
                            required
                            placeholder="— — — — —"
                    /></label>
                    <label
                        v-if="
                            !login && step === 'password' && mode === 'register'
                        "
                        >نام و نام خانوادگی<input
                            v-model="form.name"
                            autocomplete="name"
                            required
                            maxlength="100"
                            placeholder="دوست داریم شما را به نام بشناسیم"
                    /></label>
                    <label v-if="login || step === 'password'"
                        >رمز عبور<span class="password-field"
                            ><input
                                v-model="form.password"
                                name="password"
                                :type="visible ? 'text' : 'password'"
                                dir="ltr"
                                :autocomplete="
                                    login ? 'current-password' : 'new-password'
                                "
                                :minlength="login ? undefined : 8"
                                maxlength="200"
                                required
                                :placeholder="
                                    login ? 'رمز عبور شما' : 'حداقل ۸ نویسه'
                                " /><button
                                type="button"
                                class="icon-btn"
                                @click="visible = !visible"
                                :aria-label="
                                    visible ? 'پنهان کردن رمز' : 'نمایش رمز'
                                "
                            >
                                <Icon
                                    :name="visible ? 'EyeOff' : 'Eye'"
                                    :size="18"
                                /></button></span
                    ></label>
                    <label v-if="!login && step === 'password'"
                        >تکرار رمز عبور<input
                            v-model="form.password_confirmation"
                            type="password"
                            dir="ltr"
                            autocomplete="new-password"
                            minlength="8"
                            required
                    /></label>
                    <Link
                        v-if="login"
                        href="/forgot-password"
                        class="text-link forgot-link"
                        >رمز عبور را فراموش کرده‌ام</Link
                    >
                    <p
                        v-for="(error, key) in form.errors"
                        :key="key"
                        class="field-error"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <button
                        class="btn primary full"
                        :disabled="form.processing"
                    >
                        {{
                            form.processing
                                ? "کمی صبر کنید…"
                                : login
                                  ? "ورود به حساب"
                                  : step === "phone"
                                    ? "دریافت کد تأیید"
                                    : step === "code"
                                      ? "تأیید و ادامه"
                                      : mode === "register"
                                        ? "ساخت حساب من"
                                        : "ذخیره رمز جدید"
                        }}<Icon name="ArrowLeft" :size="19" />
                    </button>
                </form>
                <p class="auth-switch">
                    {{ login ? "هنوز حساب ندارید؟" : "حساب کاربری دارید؟" }}
                    <Link
                        :href="login ? '/register' : '/login'"
                        class="text-link"
                        >{{
                            login ? "ثبت‌نام در کاردرست" : "ورود به حساب"
                        }}</Link
                    >
                </p>
            </section>
            <section class="auth-story">
                <span class="eyebrow">آگاهانه انتخاب کنید</span>
                <h2>مسیر حرفه‌ای شما،<br /><span>به رنگ خودتان.</span></h2>
                <p>
                    از شناخت خودتان تا یک تصمیم روشن؛<br />در هر قدم، کاردرست
                    کنارتان است.
                </p>
                <div class="journey-art" aria-hidden="true">
                    <div class="orbit orbit-a"></div>
                    <div class="orbit orbit-b"></div>
                    <div class="journey-core">
                        <Icon name="Sprout" :size="62" />
                    </div>
                    <span class="orbit-tag tag-a"
                        ><Icon name="Fingerprint" />خودشناسی</span
                    ><span class="orbit-tag tag-b"
                        ><Icon name="Signpost" />تصمیم‌گیری</span
                    ><span class="orbit-tag tag-c"
                        ><Icon name="RefreshCw" />بازنگری</span
                    ><span class="art-dot art-dot-a"></span
                    ><span class="art-dot art-dot-b"></span>
                </div>
                <span class="story-caption"
                    >شناخت بیشتر. انتخاب بهتر. مسیر روشن‌تر.</span
                >
            </section>
        </main>
        <footer class="auth-footer">
            کاردرست · پلتفرم آموزش، ارزیابی و مشاوره شغلی
        </footer>
    </div>
</template>

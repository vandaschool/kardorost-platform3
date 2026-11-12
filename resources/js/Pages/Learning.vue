<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Empty from "../Components/Empty.vue";
import Icon from "../Components/Icon.vue";
defineProps({ enrollments: Array, paymentEnabled: Boolean, progress: Object });
const form = useForm({});
const status = {
    active: "ثبت‌نام فعال",
    pending_payment: "در انتظار پرداخت",
    cancelled: "لغوشده",
};
const money = (n) => new Intl.NumberFormat("fa-IR").format(n / 10);
</script>
<template>
    <Head title="یادگیری من" /><Shell title="یادگیری من"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">ادامه مسیر شما</span>
                <h1>یادگیری من</h1>
                <p class="muted">دوره‌ها و وضعیت ثبت‌نامتان، در یک جای مشخص.</p>
            </div>
            <Link href="/courses" class="btn secondary"
                ><Icon name="Plus" :size="17" />کشف دوره جدید</Link
            >
        </section>
        <section class="glass panel-card">
            <Empty
                v-if="!enrollments.length"
                icon="BookOpen"
                title="اولین قدم هنوز در انتظار شماست"
                text="با ثبت‌نام در یک دوره، مسیر یادگیری‌تان در این بخش شکل می‌گیرد."
                ><Link href="/courses" class="btn primary"
                    >مشاهده دوره‌ها<Icon name="ArrowLeft" :size="17" /></Link
            ></Empty>
            <article
                v-for="e in enrollments"
                :key="e.id"
                class="enrollment-row"
            >
                <span class="course-mini"
                    ><Icon name="BookOpen" :size="30"
                /></span>
                <div class="grow">
                    <span
                        :class="[
                            'badge',
                            e.status === 'active' ? 'teal' : 'gold',
                        ]"
                        >{{ status[e.status] || e.status }}</span
                    >
                    <h2>{{ e.course.title }}</h2>
                    <p class="muted">{{ e.cohort.title }}</p>
                    <p
                        v-if="e.status === 'pending_payment'"
                        class="caption muted"
                    >
                        مبلغ دوره: {{ money(e.amount) }} تومان · مهلت پرداخت:
                        {{
                            e.expires_at
                                ? new Intl.DateTimeFormat("fa-IR", {
                                      hour: "2-digit",
                                      minute: "2-digit",
                                      timeZone: "Asia/Tehran",
                                  }).format(new Date(e.expires_at))
                                : "—"
                        }}
                    </p>
                    <div
                        v-else-if="e.status === 'active'"
                        class="enrollment-progress"
                    >
                        <p class="caption muted">
                            {{
                                new Intl.NumberFormat("fa-IR").format(
                                    progress[e.id]?.completed || 0,
                                )
                            }}
                            از
                            {{
                                new Intl.NumberFormat("fa-IR").format(
                                    progress[e.id]?.total || 0,
                                )
                            }}
                            درس تکمیل شده
                        </p>
                        <progress
                            :value="progress[e.id]?.percent || 0"
                            max="100"
                            aria-label="پیشرفت دوره"
                        ></progress>
                    </div>
                </div>
                <Link
                    v-if="e.status === 'active'"
                    :href="'/learning/' + e.id"
                    class="btn primary"
                    >ادامه یادگیری<Icon name="ArrowLeft" :size="17"
                /></Link>
                <button
                    v-if="
                        e.status === 'pending_payment' &&
                        paymentEnabled &&
                        new Date(e.expires_at) > new Date()
                    "
                    class="btn primary"
                    :disabled="form.processing"
                    @click="form.post('/enrollments/' + e.id + '/pay')"
                >
                    تکمیل پرداخت<Icon name="ArrowLeft" :size="17" /></button
                ><span
                    v-else-if="e.status === 'pending_payment'"
                    class="caption muted"
                    >{{
                        new Date(e.expires_at) <= new Date()
                            ? "مهلت رزرو تمام شده؛ دوباره از دوره‌ها ثبت‌نام کنید."
                            : "درگاه هنوز فعال نشده است."
                    }}</span
                >
            </article>
        </section></Shell
    >
</template>

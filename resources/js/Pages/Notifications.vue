<script setup>
import { Head, Link, usePage, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
import Empty from "../Components/Empty.vue";
import { dateTime } from "../dates";
defineProps({ notifications: Object });
const page = usePage();
const form = useForm({});
</script>
<template>
    <Head title="اعلان‌ها" /><Shell
        title="اعلان‌ها"
        :role="page.props.activeRole"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">خبرهای مسیر شما</span>
                <h1>اعلان‌ها</h1>
                <p class="muted">
                    بازخورد تمرین‌ها، وضعیت رسیدها و خبرهای حساب شما.
                </p>
            </div>
        </section>
        <section class="glass panel-card">
            <Empty
                v-if="!notifications.data.length"
                icon="Bell"
                title="هنوز اعلانی ندارید"
                text="رویدادهای آموزشی و مالی حساب شما در این بخش نمایش داده می‌شوند."
            />
            <article
                v-for="n in notifications.data"
                :key="n.id"
                :class="['notification-row', { 'is-unread': !n.read_at }]"
            >
                <span class="row-icon teal"><Icon name="Bell" /></span>
                <div class="grow">
                    <h3>{{ n.data.title }}</h3>
                    <p class="muted caption">{{ n.data.text }}</p>
                    <small class="muted">{{ dateTime(n.created_at) }}</small>
                    <div class="learning-actions">
                        <Link
                            v-if="n.data.url"
                            :href="n.data.url"
                            class="text-link"
                            >مشاهده جزئیات<Icon
                                name="ChevronLeft"
                                :size="16" /></Link
                        ><button
                            v-if="!n.read_at"
                            class="text-link"
                            :disabled="form.processing"
                            @click="
                                form.post('/notifications/' + n.id + '/read', {
                                    preserveScroll: true,
                                })
                            "
                        >
                            خوانده شد
                        </button>
                    </div>
                </div>
                <span
                    v-if="!n.read_at"
                    class="online-dot"
                    aria-label="خوانده نشده"
                ></span>
            </article>
            <nav
                v-if="notifications.last_page > 1"
                class="learning-actions"
                aria-label="صفحه‌بندی اعلان‌ها"
            >
                <Link
                    v-if="notifications.prev_page_url"
                    :href="notifications.prev_page_url"
                    class="btn secondary"
                    >صفحه قبل</Link
                ><Link
                    v-if="notifications.next_page_url"
                    :href="notifications.next_page_url"
                    class="btn secondary"
                    >صفحه بعد</Link
                >
            </nav>
        </section></Shell
    >
</template>

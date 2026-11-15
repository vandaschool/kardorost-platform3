<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import MembershipEnd from "../Components/MembershipEnd.vue";
import { dateTime } from "../dates";
defineProps({ memberships: Array, history: Array, role: String });
const actions = {
    "organization_membership.leave": "خروج فرد",
    "organization_membership.remove": "پایان عضویت توسط سازمان",
    "organization_membership.revoke_manager": "پایان دسترسی مدیریتی",
    "organization_membership.manager_assigned": "تعیین مدیر",
};
</script>
<template>
    <Head title="عضویت‌های سازمانی من" /><Shell
        title="عضویت‌های سازمانی من"
        :role="role"
        ><section class="page-intro">
            <div>
                <h1>عضویت‌های سازمانی من</h1>
                <p>
                    عضویت فعال به مدیران همان سازمان اجازه مشاهده گزارش‌های
                    آموزشی و نتایج نهایی خودشناسی شما را می‌دهد.
                </p>
            </div>
            <Link href="/organization-invitations" class="btn secondary"
                >دعوت‌های سازمان‌ها</Link
            >
        </section>
        <p v-if="!memberships.length" class="muted">عضویت سازمانی ندارید.</p>
        <article
            v-for="item in memberships"
            :key="item.id"
            :data-membership="item.id"
            class="glass panel-card consultation-section"
        >
            <h2>{{ item.name }}</h2>
            <p>
                {{ item.kind === "manager" ? "مدیر سازمان" : "عضو سازمان" }} ·
                {{
                    item.status === "active" ? "سازمان فعال" : "سازمان غیرفعال"
                }}
            </p>
            <MembershipEnd
                v-if="item.kind === 'member'"
                :url="'/organization-memberships/' + item.id + '/leave'"
                :membership-key="item.membership_key"
                label="خروج از سازمان"
            />
            <p v-else class="muted">
                تغییر مدیر سازمان از کارتابل ادمین انجام می‌شود.
            </p>
        </article>
        <section class="glass panel-card consultation-section">
            <h2>آخرین تغییرات عضویت من</h2>
            <p v-if="!history.length" class="muted">سابقه‌ای ثبت نشده است.</p>
            <article
                v-for="event in history"
                :key="event.id"
                class="consultation-section"
            >
                <h3>
                    {{ event.organization_name }} · {{ actions[event.action] }}
                </h3>
                <p>{{ event.reason }}</p>
                <small>{{ dateTime(event.created_at) }}</small>
            </article>
        </section></Shell
    >
</template>

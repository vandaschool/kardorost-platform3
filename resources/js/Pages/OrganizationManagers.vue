<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import MembershipEnd from "../Components/MembershipEnd.vue";
import { dateTime } from "../dates";
const props = defineProps({
    organizations: Array,
    organization: Object,
    members: Array,
    history: Array,
});
const form = useForm({ phone: "", expected_key: "" });
function assign() {
    form.post("/admin/organizations/" + props.organization.id + "/managers", {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function promote(member) {
    form.phone = member.phone;
    form.expected_key = member.membership_key;
}
const actions = {
    "organization_membership.leave": "خروج فرد",
    "organization_membership.remove": "پایان عضویت",
    "organization_membership.revoke_manager": "پایان دسترسی مدیریتی",
    "organization_membership.manager_assigned": "تعیین مدیر",
};
</script>
<template>
    <Head title="مدیران و عضویت سازمان" /><Shell
        title="مدیران و عضویت سازمان"
        role="admin"
        ><section class="page-intro">
            <div>
                <h1>مدیران و عضویت سازمان</h1>
                <p>تعیین چند مدیر، پایان عضویت و مشاهده سابقه دسترسی.</p>
            </div>
            <label
                >انتخاب سازمان<select
                    name="organization"
                    :value="organization?.id"
                    @change="
                        router.get('/admin/organization-managers', {
                            organization: $event.target.value,
                        })
                    "
                >
                    <option v-for="item in organizations" :value="item.id">
                        {{ item.name }}
                    </option>
                </select></label
            >
        </section>
        <p v-if="!organization" class="muted">ابتدا سازمان بسازید.</p>
        <template v-else
            ><section class="glass panel-card consultation-section">
                <h2>تعیین مدیر برای {{ organization.name }}</h2>
                <form @submit.prevent="assign" class="stack-form">
                    <label
                        >موبایل حساب فعال و تأییدشده<input
                            v-model="form.phone"
                            name="phone"
                            dir="ltr"
                            required
                            maxlength="30"
                    /></label>
                    <p v-if="form.expected_key" class="muted">
                        عضو انتخاب‌شده از فهرست به مدیر ارتقا می‌یابد.
                    </p>
                    <p>
                        مدیر به گزارش‌های اعضای این سازمان، دعوت‌ها، سهمیه‌ها و
                        صورتحساب‌های آن دسترسی خواهد داشت.
                    </p>
                    <p
                        v-for="error in form.errors"
                        class="field-error"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <button class="btn primary" :disabled="form.processing">
                        تعیین مدیر</button
                    ><button
                        v-if="form.expected_key"
                        type="button"
                        class="btn secondary"
                        @click="form.reset()"
                    >
                        لغو انتخاب عضو
                    </button>
                </form>
            </section>
            <section class="glass panel-card consultation-section">
                <h2>اعضای فعلی</h2>
                <article
                    v-for="member in members"
                    :key="member.id"
                    class="consultation-section"
                    :data-member="member.id"
                >
                    <h3>{{ member.name }}</h3>
                    <p>
                        <bdi>{{ member.phone }}</bdi> ·
                        {{ member.kind === "manager" ? "مدیر" : "عضو" }} ·
                        {{ member.is_active ? "حساب فعال" : "حساب غیرفعال" }}
                    </p>
                    <button
                        v-if="member.kind === 'member'"
                        class="btn secondary"
                        @click="promote(member)"
                    >
                        انتخاب برای مدیریت</button
                    ><MembershipEnd
                        :url="
                            '/admin/organizations/' +
                            organization.id +
                            '/members/' +
                            member.id +
                            '/remove'
                        "
                        :membership-key="member.membership_key"
                        :action="
                            member.kind === 'manager'
                                ? 'revoke_manager'
                                : 'remove'
                        "
                        :label="
                            member.kind === 'manager'
                                ? 'پایان دسترسی مدیریتی'
                                : 'پایان عضویت'
                        "
                    />
                </article>
            </section>
            <section class="glass panel-card consultation-section">
                <h2>آخرین تغییرات</h2>
                <article
                    v-for="event in history"
                    :key="event.id"
                    class="consultation-section"
                >
                    <h3>
                        {{ event.member_name }} · {{ actions[event.action] }}
                    </h3>
                    <p>{{ event.reason }}</p>
                    <small>{{ dateTime(event.created_at) }}</small>
                </article>
            </section></template
        ></Shell
    >
</template>

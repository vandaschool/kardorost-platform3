<script setup>
import { Head, Link, router } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Empty from "../Components/Empty.vue";
import Icon from "../Components/Icon.vue";
defineProps({ organization: Object, organizations: Array, members: Array });
const fa = (n) => new Intl.NumberFormat("fa-IR").format(n);
</script>
<template>
    <Head title="سازمان من" /><Shell title="سازمان من" role="org_admin"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">همراه رشد اعضای مجموعه</span>
                <h1>{{ organization?.name || "پنل سازمان" }}</h1>
                <p class="muted">
                    اعضا و گزارش‌های فردی مجموعه خود را از اینجا دنبال کنید.
                </p>
            </div>
            <select
                v-if="organizations.length > 1"
                aria-label="انتخاب سازمان"
                :value="organization?.id"
                @change="
                    router.post('/organization/switch', {
                        organization_id: $event.target.value,
                    })
                "
            >
                <option v-for="o in organizations" :key="o.id" :value="o.id">
                    {{ o.name }}
                </option>
            </select>
        </section>
        <Empty
            v-if="!organization"
            icon="Building2"
            title="سازمان فعالی به شما متصل نیست"
            text="تعیین مدیر سازمان توسط مدیریت کاردرست انجام می‌شود." /><template
            v-else
            ><Link href="/organization/licenses" class="btn primary"
                >سهمیه‌ها و ثبت‌نام گروهی</Link
            >
            <section class="stats-grid">
                <div class="stat-card glass">
                    <span class="stat-icon teal"><Icon name="Users" /></span>
                    <div>
                        <strong>{{ fa(members.length) }}</strong
                        ><span>عضو مجموعه</span>
                    </div>
                </div>
                <div class="stat-card glass">
                    <span class="stat-icon gold"
                        ><Icon name="Building2"
                    /></span>
                    <div>
                        <strong class="stat-text">{{
                            organization.type === "university"
                                ? "دانشگاه"
                                : "شرکت"
                        }}</strong
                        ><span>نوع مجموعه</span>
                    </div>
                </div>
                <div class="stat-card glass">
                    <span class="stat-icon navy"
                        ><Icon name="ShieldCheck"
                    /></span>
                    <div>
                        <strong class="stat-text">فعال</strong
                        ><span>وضعیت سازمان</span>
                    </div>
                </div>
            </section>
            <section class="glass panel-card">
                <div class="section-heading">
                    <h2>اعضای مجموعه</h2>
                    <span class="muted caption"
                        >دسترسی به گزارش کامل اعضای همین سازمان</span
                    >
                </div>
                <p class="muted">
                    عضو تازه را با دعوت تکی یا فایل CSV اضافه کنید. تا پذیرش
                    فرد، گزارش او در اختیار سازمان قرار نمی‌گیرد.
                </p>
                <Link href="/organization/invitations" class="btn primary"
                    ><Icon name="UserPlus" :size="17" />دعوت تکی یا گروهی
                    اعضا</Link
                >
                <Link
                    v-for="m in members"
                    :key="m.id"
                    :href="'/organization/members/' + m.id"
                    class="simple-row"
                    ><span class="avatar">{{ m.name.slice(0, 1) }}</span>
                    <div class="grow">
                        <strong>{{ m.name }}</strong
                        ><small dir="ltr">{{ m.phone }}</small>
                    </div>
                    <span class="text-link"
                        >گزارش فردی<Icon name="ChevronLeft" :size="17" /></span
                ></Link></section></template
    ></Shell>
</template>

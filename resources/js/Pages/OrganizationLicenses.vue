<script setup>
import { Head, Link } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Pagination from "../Components/Pagination.vue";
import { fa } from "../dates";
import { dateOnly } from "../finance";
defineProps({ organization: Object, licenses: Object });
</script>
<template>
    <Head title="ثبت‌نام گروهی" /><Shell title="ثبت‌نام گروهی" role="org_admin"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">{{ organization.name }}</span>
                <h1>ثبت‌نام گروهی اعضا</h1>
                <p class="muted">
                    ثبت‌نام از سهمیه تأییدشده سازمان انجام می‌شود و بدهی شخصی
                    برای عضو ایجاد نمی‌کند.
                </p>
            </div>
            <Link href="/organization" class="btn secondary"
                >مدیریت اعضا و انتخاب سازمان</Link
            >
        </section>
        <p v-if="!licenses.data.length" class="muted">
            هنوز برای این سازمان سهمیه‌ای تعریف نشده است؛ تخصیص سهمیه از پنل
            ادمین انجام می‌شود.
        </p>
        <div class="consultation-grid">
            <article
                v-for="l in licenses.data"
                :key="l.id"
                class="panel glass consultation-card"
            >
                <h2>{{ l.course_title }}</h2>
                <p>{{ l.cohort_title }}</p>
                <p>
                    {{ fa(l.used) }} از {{ fa(l.seat_limit) }} صندلی مصرف شده ·
                    مانده {{ fa(l.remaining) }}
                </p>
                <p>پایان مهلت ثبت‌نام تازه: {{ dateOnly(l.expires_on) }}</p>
                <p>
                    {{
                        l.can_enroll
                            ? "ثبت‌نام تازه فعال است"
                            : "برای ثبت‌نام تازه سهمیه قابل‌استفاده وجود ندارد"
                    }}
                </p>
                <Link
                    :href="`/organization/licenses/${l.id}`"
                    class="btn primary"
                    >انتخاب اعضا و سوابق ثبت‌نام</Link
                >
            </article>
        </div>
        <Pagination :links="licenses.links"
    /></Shell>
</template>

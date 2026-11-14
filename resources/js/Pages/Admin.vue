<script setup>
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import Shell from "../Layouts/Shell.vue";
import Icon from "../Components/Icon.vue";
import Empty from "../Components/Empty.vue";
import CourseBenefitEditor from "../Components/CourseBenefitEditor.vue";
import BillingPlanEditor from "../Components/BillingPlanEditor.vue";
const props = defineProps({
    section: String,
    courses: Array,
    users: Array,
    organizations: Array,
    events: Array,
    coaches: Array,
});
const labels = {
    courses: "مدیریت دوره‌ها",
    users: "کاربران",
    organizations: "دانشگاه‌ها و شرکت‌ها",
    activity: "سابقه فعالیت",
};
const open = ref(false);
const editUser = ref(null);
const access = useForm({ roles: [], is_active: true });
const roleLabels = {
    student: "دانشجو",
    coach: "مربی",
    org_admin: "مدیر سازمان",
    admin: "ادمین",
};
function startEdit(u) {
    editUser.value = u.id;
    access.roles = u.roles.map((r) => r.key);
    access.is_active = u.is_active;
}
function saveAccess() {
    access.patch("/admin/users/" + editUser.value, {
        onSuccess: () => (editUser.value = null),
    });
}
const assignment = useForm({ coach_id: null });
function assignCoach(c, id) {
    assignment.coach_id = id ? Number(id) : null;
    assignment.patch("/admin/courses/" + c.id + "/coach");
}
const course = useForm({
    title: "",
    description: "",
    stage: 1,
    price: 0,
    capacity: 25,
    format: "hybrid",
    is_published: false,
});
const org = useForm({ name: "", type: "university", phone: "" });
function save() {
    const form = props.section === "courses" ? course : org;
    form.post(
        props.section === "courses" ? "/admin/courses" : "/admin/organizations",
        {
            onSuccess: () => {
                open.value = false;
                form.reset();
            },
        },
    );
}
const fa = (n) => new Intl.NumberFormat("fa-IR").format(n);
const action = {
    "account.registered": "ثبت حساب جدید",
    "password.reset": "بازیابی رمز",
    "password.changed": "تغییر رمز",
    "course.created": "ساخت دوره",
    "enrollment.created": "ثبت درخواست دوره",
    "organization.created": "ساخت سازمان",
    "organization.member_added": "افزودن عضو سازمان",
    "organization.report_viewed": "مشاهده گزارش عضو",
    "payment.paid": "پرداخت تأییدشده",
    "payment.review_required": "پرداخت نیازمند بررسی",
    "role.granted": "اعطای نقش",
    "user.access_updated": "تغییر دسترسی کاربر",
    "course.coach_assigned": "تخصیص مربی دوره",
};
</script>
<template>
    <Head :title="labels[section]" /><Shell
        :title="labels[section]"
        role="admin"
        ><section class="page-intro">
            <div>
                <span class="eyebrow">مدیریت کاردرست</span>
                <h1>{{ labels[section] }}</h1>
                <p class="muted">
                    {{
                        section === "courses"
                            ? "دوره، شیوه برگزاری و ظرفیت ثبت‌نام را مشخص کنید."
                            : section === "organizations"
                              ? "دانشگاه‌ها و شرکت‌ها را با مدیر مستقل تعریف کنید."
                              : section === "users"
                                ? "حساب‌ها و نقش‌های فعلی کاربران را ببینید."
                                : "تاریخچه اقدامات ثبت‌شده در پلتفرم."
                    }}
                </p>
            </div>
            <button
                v-if="['courses', 'organizations'].includes(section)"
                @click="open = !open"
                class="btn primary"
                :aria-expanded="open"
            >
                <Icon :name="open ? 'X' : 'Plus'" :size="18" />{{
                    open
                        ? "بستن فرم"
                        : section === "courses"
                          ? "دوره جدید"
                          : "سازمان جدید"
                }}
            </button>
        </section>
        <section v-if="open" class="glass panel-card">
            <h2>
                {{
                    section === "courses"
                        ? "ساخت دوره و نوبت اول"
                        : "ساخت سازمان"
                }}
            </h2>
            <form
                @submit.prevent="save"
                class="form-grid"
                v-if="section === 'courses'"
            >
                <label
                    >عنوان دوره<input
                        v-model="course.title"
                        required
                        maxlength="150" /></label
                ><label
                    >مرحله<select v-model="course.stage">
                        <option :value="1">خودشناسی شغلی</option>
                        <option :value="2">تصمیم‌گیری شغلی</option>
                        <option :value="3">بازنگری شغلی</option>
                    </select></label
                ><label class="span-all"
                    >توضیح دوره<textarea
                        v-model="course.description"
                        required
                        maxlength="5000"
                        rows="3"
                    ></textarea></label
                ><label
                    >قیمت (تومان)<input
                        type="number"
                        v-model="course.price"
                        min="0"
                        max="1000000000"
                        required /></label
                ><label
                    >ظرفیت نوبت اول<input
                        type="number"
                        v-model="course.capacity"
                        min="1"
                        max="10000"
                        required /></label
                ><label
                    >شیوه برگزاری<select v-model="course.format">
                        <option value="hybrid">ترکیبی</option>
                        <option value="live">زنده</option>
                        <option value="recorded">ضبط‌شده</option>
                    </select></label
                ><label class="check-label"
                    ><input
                        type="checkbox"
                        v-model="course.is_published"
                    />انتشار و بازکردن ثبت‌نام</label
                >
                <div class="span-all">
                    <p
                        v-for="(e, k) in course.errors"
                        :key="k"
                        class="field-error"
                        role="alert"
                    >
                        {{ e }}
                    </p>
                    <button class="btn primary" :disabled="course.processing">
                        ساخت دوره<Icon name="Check" :size="17" />
                    </button>
                </div>
            </form>
            <form v-else @submit.prevent="save" class="form-grid">
                <label
                    >نام مجموعه<input
                        v-model="org.name"
                        required
                        maxlength="150" /></label
                ><label
                    >نوع مجموعه<select v-model="org.type">
                        <option value="university">دانشگاه</option>
                        <option value="company">شرکت</option>
                    </select></label
                ><label
                    >شماره موبایل مدیر<input
                        v-model="org.phone"
                        type="tel"
                        dir="ltr"
                        required
                /></label>
                <p class="muted caption">
                    مدیر باید ابتدا با شماره خودش ثبت‌نام کند. نقش سازمان به
                    همان حساب اضافه می‌شود.
                </p>
                <div class="span-all">
                    <p
                        v-for="(e, k) in org.errors"
                        :key="k"
                        class="field-error"
                        role="alert"
                    >
                        {{ e }}
                    </p>
                    <button class="btn primary" :disabled="org.processing">
                        ساخت سازمان<Icon name="Check" :size="17" />
                    </button>
                </div>
            </form>
        </section>
        <section class="glass panel-card">
            <template v-if="section === 'courses'"
                ><Empty
                    v-if="!courses.length"
                    icon="BookOpen"
                    title="هنوز دوره‌ای ساخته نشده"
                    text="از دکمه دوره جدید، اولین دوره را تعریف کنید." />
                <div v-for="c in courses" :key="c.id" class="simple-row">
                    <span class="row-icon teal"><Icon name="BookOpen" /></span>
                    <div class="grow">
                        <Link :href="'/teaching/' + c.id"
                            ><strong>{{ c.title }}</strong></Link
                        >
                        ><small
                            >{{ fa(c.price / 10) }} تومان ·
                            {{ fa(c.cohorts.length) }} نوبت</small
                        >
                    </div>
                    <Link :href="'/teaching/' + c.id" class="text-link"
                        >مدیریت آموزش<Icon
                            name="ChevronLeft"
                            :size="15" /></Link
                    ><select
                        class="coach-select"
                        aria-label="مربی دوره"
                        :value="c.coach_id || ''"
                        @change="assignCoach(c, $event.target.value)"
                    >
                        <option value="">بدون مربی</option>
                        <option
                            v-for="coach in coaches"
                            :key="coach.id"
                            :value="coach.id"
                        >
                            {{ coach.name }}
                        </option></select
                    ><span
                        class="badge"
                        :class="c.is_published ? 'teal' : 'gold'"
                        >{{ c.is_published ? "منتشرشده" : "پیش‌نویس" }}</span
                    ><BillingPlanEditor
                        v-for="cohort in c.cohorts"
                        :key="cohort.id"
                        :cohort="cohort"
                        :course-price="c.price"
                    /><CourseBenefitEditor
                        v-for="cohort in c.cohorts"
                        :key="'benefit-' + cohort.id"
                        :cohort="cohort"
                        :coaches="coaches"
                        :course-price="c.price"
                    /></div
            ></template>
            <template v-else-if="section === 'users'"
                ><form
                    v-if="editUser"
                    @submit.prevent="saveAccess"
                    class="access-form"
                >
                    <h3>ویرایش نقش‌ها و فعالیت حساب</h3>
                    <div class="access-options">
                        <label
                            v-for="(label, key) in roleLabels"
                            :key="key"
                            class="check-label"
                            ><input
                                type="checkbox"
                                :value="key"
                                v-model="access.roles"
                            />{{ label }}</label
                        ><label class="check-label"
                            ><input
                                type="checkbox"
                                v-model="access.is_active"
                            />حساب فعال</label
                        >
                    </div>
                    <p
                        v-for="(e, k) in access.errors"
                        :key="k"
                        class="field-error"
                    >
                        {{ e }}
                    </p>
                    <button class="btn primary" :disabled="access.processing">
                        ذخیره دسترسی</button
                    ><button
                        class="btn secondary"
                        type="button"
                        @click="editUser = null"
                    >
                        انصراف
                    </button>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>نام</th>
                                <th>شماره موبایل</th>
                                <th>نقش‌ها</th>
                                <th>وضعیت</th>
                                <th>عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in users" :key="u.id">
                                <td>{{ u.name }}</td>
                                <td dir="ltr">{{ u.phone }}</td>
                                <td>
                                    <span
                                        v-for="r in u.roles"
                                        :key="r.key"
                                        class="badge teal"
                                        >{{ r.name }}</span
                                    >
                                </td>
                                <td>{{ u.is_active ? "فعال" : "غیرفعال" }}</td>
                                <td>
                                    <button
                                        class="text-link"
                                        @click="startEdit(u)"
                                    >
                                        ویرایش دسترسی
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Empty
                    v-if="!users.length"
                    icon="Users"
                    title="هنوز کاربری ثبت نشده"
                    text="حساب‌ها پس از ثبت‌نام در این فهرست قرار می‌گیرند."
            /></template>
            <template v-else-if="section === 'organizations'"
                ><Empty
                    v-if="!organizations.length"
                    icon="Building2"
                    title="هنوز سازمانی تعریف نشده"
                    text="دانشگاه یا شرکت را با مدیر مشخص اضافه کنید."
                />
                <div v-for="o in organizations" :key="o.id" class="simple-row">
                    <span class="row-icon gold"><Icon name="Building2" /></span>
                    <div class="grow">
                        <strong>{{ o.name }}</strong
                        ><small
                            >{{
                                o.type === "university" ? "دانشگاه" : "شرکت"
                            }}
                            · {{ fa(o.members_count) }} عضو</small
                        >
                    </div>
                    <span class="badge teal">{{
                        o.status === "active" ? "فعال" : "معلق"
                    }}</span>
                </div></template
            >
            <template v-else
                ><Empty
                    v-if="!events.length"
                    icon="History"
                    title="هنوز رویدادی ثبت نشده"
                    text="فعالیت‌های مهم در این بخش ثبت می‌شوند."
                />
                <div v-for="e in events" :key="e.id" class="simple-row">
                    <Icon name="Activity" />
                    <div class="grow">
                        <strong>{{ action[e.action] || e.action }}</strong
                        ><small>شناسه رویداد {{ fa(e.id) }}</small>
                    </div>
                    <span class="muted caption">{{
                        new Intl.DateTimeFormat("fa-IR", {
                            dateStyle: "short",
                            timeStyle: "short",
                            timeZone: "Asia/Tehran",
                        }).format(new Date(e.created_at))
                    }}</span>
                </div></template
            >
        </section>
    </Shell>
</template>

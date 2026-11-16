<script setup>
import { ref } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import Shell from "../Layouts/Shell.vue";
import Empty from "../Components/Empty.vue";
import Pagination from "../Components/Pagination.vue";
import { fa } from "../dates";
const props = defineProps({ filters: Object, users: Object });
const roleLabels = {
    student: "دانشجو",
    coach: "مربی",
    org_admin: "مدیر سازمان",
    admin: "ادمین",
};
const filters = useForm({ ...props.filters });
const selected = ref(null);
const access = useForm({ roles: [], is_active: true, revision: null });
function apply() {
    filters.get("/admin/users", { preserveScroll: true, preserveState: false });
}
function edit(user) {
    selected.value = user;
    access.roles = user.roles.map((r) => r.key);
    access.is_active = user.is_active;
    access.revision = user.access_revision;
    access.clearErrors();
}
function refresh() {
    router.visit(window.location.href, {
        preserveState: false,
        preserveScroll: true,
    });
}
function save() {
    access.patch("/admin/users/" + selected.value.id, {
        preserveScroll: true,
        onSuccess: () => (selected.value = null),
    });
}
</script>
<template>
    <Head title="کاربران"
        ><meta name="robots" content="noindex, nofollow" /></Head
    ><Shell title="کاربران" role="admin">
        <section class="page-intro">
            <div>
                <span class="eyebrow">مدیریت و عملیات</span>
                <h1>کاربران</h1>
                <p class="muted">
                    پیداکردن حساب و مدیریت نقش‌ها و وضعیت دسترسی.
                </p>
            </div>
        </section>
        <form
            class="panel glass user-filters"
            @submit.prevent="apply"
            data-testid="user-filters"
        >
            <label
                >بخشی از نام<input name="q" v-model="filters.q" maxlength="150"
            /></label>
            <label
                >شماره کامل موبایل<input
                    name="phone"
                    v-model="filters.phone"
                    maxlength="40"
                    inputmode="tel"
                    dir="ltr"
                    placeholder="0912…"
            /></label>
            <label
                >شناسه حساب<input
                    name="user_id"
                    v-model="filters.user_id"
                    type="number"
                    min="1"
                    inputmode="numeric"
            /></label>
            <label
                >نقش<select name="role" v-model="filters.role">
                    <option value="">همه نقش‌ها</option>
                    <option
                        v-for="(label, key) in roleLabels"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            >
            <label
                >وضعیت حساب<select name="status" v-model="filters.status">
                    <option value="all">همه حساب‌ها</option>
                    <option value="active">فعال</option>
                    <option value="inactive">غیرفعال</option>
                </select></label
            >
            <label
                >تأیید موبایل<select
                    name="verification"
                    v-model="filters.verification"
                >
                    <option value="all">همه</option>
                    <option value="verified">تأییدشده</option>
                    <option value="unverified">تأییدنشده</option>
                </select></label
            >
            <p v-for="error in filters.errors" :key="error" class="field-error">
                {{ error }}
            </p>
            <div class="user-actions">
                <button class="btn primary" :disabled="filters.processing">
                    جست‌وجو</button
                ><Link href="/admin/users" class="btn secondary"
                    >پاک‌کردن فیلترها</Link
                >
            </div>
        </form>
        <form
            v-if="selected"
            @submit.prevent="save"
            class="panel glass user-access"
            data-testid="user-access"
        >
            <h2>ویرایش دسترسی {{ selected.name }}</h2>
            <p class="muted">
                شناسه {{ fa(selected.id) }} ·
                <span dir="ltr">{{ selected.phone }}</span>
            </p>
            <fieldset>
                <legend>نقش‌های حساب</legend>
                <label
                    v-for="(label, key) in roleLabels"
                    :key="key"
                    class="check-label"
                    ><input
                        type="checkbox"
                        :name="'access_' + key"
                        :value="key"
                        v-model="access.roles"
                    />{{ label }}</label
                >
            </fieldset>
            <label class="check-label"
                ><input
                    name="access_active"
                    type="checkbox"
                    v-model="access.is_active"
                />حساب فعال</label
            >
            <p class="muted caption">
                مدیر سازمان با نقش حساب مشخص می‌شود؛ دسترسی به هر مجموعه به
                عضویت مدیریتی همان مجموعه نیاز دارد. غیرفعال‌کردن حساب، نشست‌های
                آن را می‌بندد.
            </p>
            <p v-for="error in access.errors" :key="error" class="field-error">
                {{ error }}
            </p>
            <button
                v-if="access.errors.revision"
                type="button"
                class="btn secondary"
                @click="refresh"
                :disabled="access.processing"
                data-testid="refresh-user-access"
            >
                تازه‌سازی فهرست و بررسی دوباره
            </button>
            <div class="user-actions">
                <button class="btn primary" :disabled="access.processing">
                    ذخیره دسترسی</button
                ><button
                    type="button"
                    class="btn secondary"
                    @click="selected = null"
                    :disabled="access.processing"
                >
                    انصراف
                </button>
            </div>
        </form>
        <section class="panel glass consultation-card">
            <h2>
                حساب‌ها
                <span class="caption muted">{{ fa(users.total) }} مورد</span>
            </h2>
            <Empty
                v-if="!users.data.length"
                icon="Users"
                title="حسابی با این شرایط پیدا نشد"
                text="فیلترها را تغییر دهید یا پاک کنید."
            />
            <article
                v-for="user in users.data"
                :key="user.id"
                class="user-card"
                :data-user-id="user.id"
            >
                <div class="user-card-main">
                    <h3>{{ user.name }}</h3>
                    <p class="caption muted">
                        شناسه {{ fa(user.id) }} ·
                        <span dir="ltr">{{ user.phone }}</span>
                    </p>
                    <p class="caption">
                        {{ user.is_active ? "حساب فعال" : "حساب غیرفعال" }} ·
                        {{
                            user.phone_verified
                                ? "موبایل تأییدشده"
                                : "موبایل تأییدنشده"
                        }}
                    </p>
                    <div class="user-roles">
                        <span
                            v-for="role in user.roles"
                            :key="role.key"
                            class="badge teal"
                            >{{ roleLabels[role.key] || role.name }}</span
                        ><span v-if="!user.roles.length" class="caption muted"
                            >بدون نقش</span
                        >
                    </div>
                </div>
                <button
                    class="btn secondary"
                    type="button"
                    @click="edit(user)"
                    :disabled="access.processing"
                >
                    ویرایش دسترسی
                </button>
            </article>
            <Pagination :page="users" />
        </section>
    </Shell>
</template>
<style scoped>
.user-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
    gap: 1rem;
    padding: 1.25rem;
    margin-bottom: 1.25rem;
}
.user-filters > .user-actions,
.user-filters > .field-error {
    grid-column: 1/-1;
}
.user-actions,
.user-roles {
    display: flex;
    gap: 0.6rem;
    flex-wrap: wrap;
}
.user-access {
    padding: 1.25rem;
    margin-bottom: 1.25rem;
    overflow-wrap: anywhere;
}
.user-access fieldset {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}
.user-card {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1rem 0;
    border-bottom: 1px solid var(--line);
}
.user-card-main {
    flex: 1;
    min-width: min(100%, 200px);
    overflow-wrap: anywhere;
}
.user-card h3 {
    font-size: 1rem;
    margin: 0 0 0.35rem;
}
.user-card p {
    margin: 0.35rem 0;
}
</style>

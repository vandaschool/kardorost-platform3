<script setup>
const items = defineModel({ type: Array, required: true });
const props = defineProps({ kind: String, disabled: Boolean });
const definitions = {
    experience: {
        title: "تجربه کاری",
        limit: 20,
        fields: [
            ["employer", "شرکت / مجموعه", 150],
            ["title", "عنوان نقش", 150],
            ["period", "بازه زمانی؛ متن به انتخاب شما", 80],
            [
                "description",
                "مسئولیت‌ها و نتیجه‌های قابل توضیح",
                3000,
                "textarea",
            ],
        ],
    },
    education: {
        title: "تحصیلات",
        limit: 15,
        fields: [
            ["institution", "دانشگاه / مرکز آموزشی", 150],
            ["degree", "رشته / مدرک", 150],
            ["period", "بازه زمانی", 80],
            ["description", "توضیح اختیاری", 1500, "textarea"],
        ],
    },
    projects: {
        title: "پروژه‌ها",
        limit: 15,
        fields: [
            ["title", "نام پروژه", 150],
            ["description", "نقش شما و نتیجه پروژه", 2000, "textarea"],
        ],
    },
    links: {
        title: "پیوندها",
        limit: 10,
        fields: [
            ["label", "عنوان پیوند", 80],
            ["url", "نشانی کامل http یا https", 2000, "url"],
        ],
    },
};
function add() {
    items.value = [
        ...items.value,
        Object.fromEntries(
            definitions[props.kind].fields.map((field) => [field[0], ""]),
        ),
    ];
}
function remove(index) {
    items.value = items.value.filter((_, i) => i !== index);
}
function move(index, direction) {
    const next = [...items.value];
    const to = index + direction;
    [next[index], next[to]] = [next[to], next[index]];
    items.value = next;
}
function update(index, key, value) {
    items.value = items.value.map((entry, i) =>
        i === index ? { ...entry, [key]: value } : entry,
    );
}
</script>
<template>
    <section class="consultation-section" :data-resume-section="kind">
        <h2>{{ definitions[kind].title }}</h2>
        <p v-if="!items.length" class="muted">موردی اضافه نشده است.</p>
        <article
            v-for="(entry, index) in items"
            :key="index"
            class="resume-entry stack-form"
        >
            <label v-for="field in definitions[kind].fields" :key="field[0]"
                >{{ field[1]
                }}<textarea
                    v-if="field[3] === 'textarea'"
                    :value="entry[field[0]]"
                    :maxlength="field[2]"
                    :disabled="disabled"
                    rows="3"
                    :name="kind + '_' + field[0] + '_' + index"
                    @input="
                        update(index, field[0], $event.target.value)
                    " /><input
                    v-else
                    :value="entry[field[0]]"
                    :type="field[3] === 'url' ? 'url' : 'text'"
                    :dir="field[3] === 'url' ? 'ltr' : undefined"
                    :maxlength="field[2]"
                    :disabled="disabled"
                    :required="!['period', 'description'].includes(field[0])"
                    :name="kind + '_' + field[0] + '_' + index"
                    @input="update(index, field[0], $event.target.value)"
            /></label>
            <div class="action-row">
                <button
                    type="button"
                    class="btn secondary"
                    :disabled="disabled || index === 0"
                    @click="move(index, -1)"
                >
                    بالاتر</button
                ><button
                    type="button"
                    class="btn secondary"
                    :disabled="disabled || index === items.length - 1"
                    @click="move(index, 1)"
                >
                    پایین‌تر</button
                ><button
                    type="button"
                    class="btn secondary"
                    :disabled="disabled"
                    @click="remove(index)"
                >
                    حذف این مورد
                </button>
            </div>
        </article>
        <button
            type="button"
            class="btn secondary"
            :disabled="disabled || items.length >= definitions[kind].limit"
            :data-add-entry="kind"
            @click="add"
        >
            افزودن {{ definitions[kind].title }}
        </button>
    </section>
</template>
<style scoped>
.resume-entry {
    padding: 20px;
    border: 1px solid var(--line);
    border-radius: 18px;
    margin-block: 18px;
}
</style>

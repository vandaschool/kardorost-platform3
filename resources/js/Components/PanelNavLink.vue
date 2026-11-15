<script setup>
import { Link } from "@inertiajs/vue3";
import Icon from "./Icon.vue";
defineProps({ item: Array, active: Boolean, invitationCount: Number });
defineEmits(["navigate"]);
</script>
<template>
    <Link
        :href="item[2]"
        :class="['nav-item', { active }]"
        :aria-current="active ? 'page' : undefined"
        @click="$emit('navigate')"
        ><Icon :name="item[1]" /><span
            >{{ item[0]
            }}<small
                v-if="
                    item[2] === '/organization-invitations' && invitationCount
                "
                >{{
                    new Intl.NumberFormat("fa-IR").format(invitationCount)
                }}</small
            ></span
        ><span v-if="active" class="nav-active-dot"></span
    ></Link>
</template>

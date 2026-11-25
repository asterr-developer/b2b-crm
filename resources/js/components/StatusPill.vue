<script setup lang="ts">
withDefaults(defineProps<{tone?:'neutral'|'info'|'success'|'warning'|'danger'}>(), {tone:'neutral'});
</script>

<template>
    <span class="moon-pill" :data-tone="tone"><slot /></span>
</template>

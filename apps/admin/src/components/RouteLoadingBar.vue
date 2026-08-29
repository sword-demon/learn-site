<template>
  <!-- nprogress injects #nprogress into document.body -->
  <span class="sr-only" aria-hidden="true" />
</template>

<script setup lang="ts"></script>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>

<template>
    <div class="sidebar-backdrop" aria-hidden="true">
        <div class="sidebar-backdrop__shade"></div>
    </div>
</template>

<style scoped>
.sidebar-backdrop {
    position: absolute;
    inset: 0;
    z-index: 0;
    overflow: hidden;
    pointer-events: none;
    background: transparent;
}

.sidebar-backdrop::after {
    content: '';
    position: absolute;
    inset: 0;
    box-shadow:
        inset -1px 0 0 rgba(148, 163, 184, 0.12),
        inset 0 0 72px rgba(2, 6, 23, 0.82);
}

.sidebar-backdrop__shade {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(180deg, rgba(2, 6, 23, 0.28) 0%, rgba(2, 6, 23, 0.14) 35%, rgba(2, 6, 23, 0.1) 65%, rgba(2, 6, 23, 0.24) 100%),
        linear-gradient(90deg, rgba(2, 6, 23, 0.3) 0%, rgba(2, 6, 23, 0.08) 60%, rgba(2, 6, 23, 0.2) 100%);
}
</style>

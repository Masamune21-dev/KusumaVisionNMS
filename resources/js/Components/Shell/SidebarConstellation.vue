<template>
    <div class="sidebar-backdrop" aria-hidden="true">
        <img
            class="sidebar-backdrop__image"
            src="/img/sidebar.png"
            width="941"
            height="1672"
            alt=""
            decoding="async"
        />
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
    background: #020617;
}

.sidebar-backdrop::after {
    content: '';
    position: absolute;
    inset: 0;
    box-shadow:
        inset -1px 0 0 rgba(148, 163, 184, 0.12),
        inset 0 0 72px rgba(2, 6, 23, 0.82);
}

.sidebar-backdrop__image {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: cover;
    object-position: 50% 66%;
    opacity: 0.58;
    filter: saturate(1.08) contrast(1.04);
    transform: scale(1.02);
}

.sidebar-backdrop__shade {
    position: absolute;
    inset: 0;
    background:
        linear-gradient(180deg, rgba(2, 6, 23, 0.84) 0%, rgba(2, 6, 23, 0.7) 28%, rgba(2, 6, 23, 0.46) 58%, rgba(2, 6, 23, 0.76) 100%),
        linear-gradient(90deg, rgba(2, 6, 23, 0.74) 0%, rgba(2, 6, 23, 0.36) 54%, rgba(2, 6, 23, 0.58) 100%);
}

@media (max-height: 760px) {
    .sidebar-backdrop__image {
        object-position: 50% 72%;
    }
}
</style>

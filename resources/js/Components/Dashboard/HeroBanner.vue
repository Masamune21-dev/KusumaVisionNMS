<script setup>
defineProps({
    title: { type: String, default: 'Unified FTTH Network Management Platform' },
    subtitle: { type: String, default: 'Monitor dan kelola perangkat OLT dan ONU secara terpusat.' },
});
</script>

<template>
    <div class="hero-header">
        <img
            class="hero-image"
            src="/img/hero.png"
            width="1916"
            height="821"
            alt=""
            decoding="async"
            fetchpriority="high"
            aria-hidden="true"
        />
        <div class="hero-scrim" aria-hidden="true"></div>

        <div class="hero-content">
            <h1 class="title">{{ title }}</h1>
            <p class="subtitle">{{ subtitle }}</p>
        </div>
    </div>
</template>

<style scoped>
.hero-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-height: 132px;
    background: #020617;
    border-radius: 16px;
    padding: clamp(1.35rem, 2vw, 2rem);
    color: white;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, 0.16);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
}

.hero-image {
    position: absolute;
    inset: 0;
    z-index: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: 68% 72%;
    opacity: 0.88;
    transform: scale(1.01);
}

.hero-scrim {
    position: absolute;
    inset: 0;
    z-index: 1;
    background:
        linear-gradient(90deg, rgba(2, 6, 23, 0.88) 0%, rgba(2, 6, 23, 0.72) 32%, rgba(2, 6, 23, 0.24) 68%, rgba(2, 6, 23, 0.1) 100%),
        linear-gradient(180deg, rgba(2, 6, 23, 0.22) 0%, rgba(2, 6, 23, 0.02) 48%, rgba(2, 6, 23, 0.18) 100%);
    pointer-events: none;
}

.hero-content {
    position: relative;
    z-index: 2;
    max-width: min(660px, 58%);
}

.title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    letter-spacing: 0;
    line-height: 1.15;
}

@media (min-width: 640px) {
    .title {
        font-size: 1.875rem;
    }
}

.subtitle {
    color: #cbd5e1;
    font-size: 0.9rem;
    line-height: 1.65;
}

@media (min-width: 640px) {
    .subtitle {
        font-size: 1rem;
    }
}

@media (max-width: 767px) {
    .hero-header {
        min-height: 176px;
    }

    .hero-content {
        max-width: 100%;
        padding-right: 1rem;
    }

    .hero-image {
        object-position: 76% 70%;
        opacity: 0.72;
    }

    .hero-scrim {
        background:
            linear-gradient(90deg, rgba(2, 6, 23, 0.9) 0%, rgba(2, 6, 23, 0.76) 54%, rgba(2, 6, 23, 0.36) 100%),
            linear-gradient(180deg, rgba(2, 6, 23, 0.2) 0%, rgba(2, 6, 23, 0.44) 100%);
    }
}
</style>

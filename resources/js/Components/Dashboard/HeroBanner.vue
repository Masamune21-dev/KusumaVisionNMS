<script setup>
defineProps({
    title: { type: String, default: 'ZTE OLT Management & Provisioning Platform' },
    subtitle: { type: String, default: 'Monitor dan kelola perangkat OLT dan ONU secara terpusat.' },
});
</script>

<template>
    <div class="hero-header">
        <div class="hero-tint" aria-hidden="true"></div>
        <div class="hero-glow" aria-hidden="true"></div>
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
    /* Brand diagonal base: deep slate → sky/cyan tint toward the right. */
    background:
        linear-gradient(115deg,
            rgba(3, 9, 22, 0.92) 0%,
            rgba(8, 24, 48, 0.74) 38%,
            rgba(10, 49, 78, 0.46) 68%,
            rgba(13, 78, 112, 0.40) 100%);
    -webkit-backdrop-filter: blur(14px);
            backdrop-filter: blur(14px);
    border-radius: 16px;
    padding: clamp(1.35rem, 2vw, 2rem);
    color: white;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(56, 189, 248, 0.18);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.06),
        inset 0 0 0 1px rgba(56, 189, 248, 0.04);
}

/* Brand colour wash — keeps the panel from reading as flat grey. */
.hero-tint {
    position: absolute;
    inset: 0;
    z-index: 1;
    background:
        linear-gradient(115deg, rgba(34, 211, 238, 0.10) 0%, transparent 46%),
        linear-gradient(300deg, rgba(99, 102, 241, 0.12) 0%, transparent 42%);
    pointer-events: none;
}

/* Soft accent glow filling the empty right half. */
.hero-glow {
    position: absolute;
    inset: 0;
    z-index: 1;
    background:
        radial-gradient(46% 130% at 96% 8%, rgba(34, 211, 238, 0.26), transparent 60%),
        radial-gradient(40% 120% at 82% 110%, rgba(56, 189, 248, 0.18), transparent 62%);
    pointer-events: none;
}

/* Left scrim only — protects text contrast without dulling the colour. */
.hero-scrim {
    position: absolute;
    inset: 0;
    z-index: 2;
    background:
        linear-gradient(90deg, rgba(2, 6, 23, 0.82) 0%, rgba(2, 6, 23, 0.46) 38%, rgba(2, 6, 23, 0.08) 64%, transparent 82%);
    pointer-events: none;
}

.hero-content {
    position: relative;
    z-index: 3;
    max-width: min(660px, 58%);
}

.title {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    letter-spacing: 0;
    line-height: 1.15;
    background: linear-gradient(92deg, #ffffff 0%, #e0f2fe 52%, #7dd3fc 100%);
    -webkit-background-clip: text;
            background-clip: text;
    -webkit-text-fill-color: transparent;
    color: transparent;
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

    .hero-scrim {
        background:
            linear-gradient(90deg, rgba(2, 6, 23, 0.86) 0%, rgba(2, 6, 23, 0.66) 58%, rgba(2, 6, 23, 0.34) 100%),
            linear-gradient(180deg, transparent 0%, rgba(2, 6, 23, 0.42) 100%);
    }
}
</style>

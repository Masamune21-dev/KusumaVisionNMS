/**
 * Nama interface CLI port PON GPON ZTE untuk rute `smartolt.port.detail` (?interface=…).
 * Sama dengan penamaan di Components/SmartOlt/OltChassis.vue: C300/C320 `gpon-olt_1/s/p`,
 * C600 3-tier `gpon_olt-1/s/p`. Port EPON (kartu ETG) tidak punya halaman detail port → null.
 */
export function zteGponInterface(olt, slot, port, ponType = 'gpon') {
    if (ponType === 'epon' || slot == null || port == null) return null;
    return olt?.capabilities?.is_c600 ? `gpon_olt-1/${slot}/${port}` : `gpon-olt_1/${slot}/${port}`;
}

export function ztePortDetailHref(olt, slot, port, ponType = 'gpon') {
    const iface = zteGponInterface(olt, slot, port, ponType);
    return iface ? `${route('smartolt.port.detail', olt.id)}?interface=${encodeURIComponent(iface)}` : null;
}

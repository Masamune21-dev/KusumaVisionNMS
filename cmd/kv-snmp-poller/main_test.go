package main

import "testing"

// Regression for the slot/port if-index collision: the ZTE ONU-table prefix
// index (0x10000000|slot<<16|port<<8) numerically overlaps with the IF-MIB
// GPON-port if-index of the next slot. A slot-1 ONU prefix equals the IF-MIB
// if-index of gpon_1/2/(port+1); keying portMap by IF-MIB if-index used to
// mis-bind every slot-1 ONU onto a slot-2 port (e.g. 1/1 -> 2/2).
func TestBuildPortMapKeysByOnuPrefixNotIfMib(t *testing.T) {
	ports := []portRow{
		{IfIndex: 268435456, Slot: 1, Port: 1}, // gpon_1/1/1 (IF-MIB if-index)
		{IfIndex: 268501248, Slot: 2, Port: 2}, // gpon_1/2/2 (IF-MIB) — collides with ONU 1/1 prefix
	}

	m := buildPortMap(ports)

	// ONU on slot 1 port 1 -> prefix index 0x10000000|1<<16|1<<8 = 268501248.
	const onu11Prefix = 268501248
	pr, ok := m[onu11Prefix]
	if !ok {
		t.Fatalf("ONU 1/1 prefix %d did not match any port", onu11Prefix)
	}
	if pr.Slot != 1 || pr.Port != 1 {
		t.Fatalf("ONU 1/1 mis-bound to slot %d port %d, want 1/1", pr.Slot, pr.Port)
	}
}

func TestDecodeIfIndexOnuPrefix(t *testing.T) {
	cases := []struct {
		idx, slot, port int
	}{
		{268501248, 1, 1},  // ONU 1/1
		{268503040, 1, 8},  // ONU 1/8
		{268505088, 1, 16}, // ONU 1/16
		{268566784, 2, 1},  // ONU 2/1
		{268567040, 2, 2},  // ONU 2/2
	}

	for _, c := range cases {
		if s, p := decodeIfIndex(c.idx); s != c.slot || p != c.port {
			t.Errorf("decodeIfIndex(%d) = %d/%d, want %d/%d", c.idx, s, p, c.slot, c.port)
		}
	}
}

func TestOnuPortPrefixIndexRoundTrip(t *testing.T) {
	for slot := 1; slot <= 2; slot++ {
		for port := 1; port <= 16; port++ {
			idx := onuPortPrefixIndex(slot, port)
			if s, p := decodeIfIndex(idx); s != slot || p != port {
				t.Errorf("round-trip slot/port %d/%d -> idx %d -> %d/%d", slot, port, idx, s, p)
			}
		}
	}
}

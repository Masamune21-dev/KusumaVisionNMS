package main

import (
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"net"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/gosnmp/gosnmp"
)

const (
	sysDescr    = "1.3.6.1.2.1.1.1.0"
	sysObjectID = "1.3.6.1.2.1.1.2.0"
	sysUptime   = "1.3.6.1.2.1.1.3.0"
	sysName     = "1.3.6.1.2.1.1.5.0"

	ifDescr      = "1.3.6.1.2.1.2.2.1.2"
	ifOperStatus = "1.3.6.1.2.1.2.2.1.8"
	ifName       = "1.3.6.1.2.1.31.1.1.1.1"

	zteOnuType          = "1.3.6.1.4.1.3902.1012.3.28.1.1.1"
	zteOnuName          = "1.3.6.1.4.1.3902.1012.3.28.1.1.2"
	zteOnuDescription   = "1.3.6.1.4.1.3902.1012.3.28.1.1.3"
	zteOnuSN            = "1.3.6.1.4.1.3902.1012.3.28.1.1.5"
	zteOnuAdminState    = "1.3.6.1.4.1.3902.1012.3.28.1.1.17"
	zteOnuPhaseState    = "1.3.6.1.4.1.3902.1012.3.28.2.1.4"
	zteOnuLastDownCause = "1.3.6.1.4.1.3902.1012.3.28.2.1.7"
	zteOnuRXPower       = "1.3.6.1.4.1.3902.1012.3.50.12.1.1.10"

	// ZTE TITAN C600 uses the .1082 subtree. ONU table .20.2.1.2.1 (type .8, SN .3 as 8-byte octet);
	// name/description in a SEPARATE table .10.2.3.3.1 (.2 name, .3 SmartOLT metadata); admin & phase
	// in .10.2.3.8.1 (.1 admin 1=enable/2=disable, .4 phase 2=LOS/4=Working/5=DyingGasp/7=OffLine);
	// RX .20.2.2.2.1.10 (same index/scale as the C300 RX). All verified live — see
	// docs/ZTE_C600_Configured_ONU_Name_SNMP_Discovery.md.
	c600OnuType    = "1.3.6.1.4.1.3902.1082.500.20.2.1.2.1.8"
	c600OnuSN      = "1.3.6.1.4.1.3902.1082.500.20.2.1.2.1.3"
	c600OnuName    = "1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.2"
	c600OnuDesc    = "1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.3"
	c600OnuAdmin   = "1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.1"
	c600OnuPhase   = "1.3.6.1.4.1.3902.1082.500.10.2.3.8.1.4"
	c600OnuRXPower = "1.3.6.1.4.1.3902.1082.500.20.2.2.2.1.10"
)

type systemInfo struct {
	SysDescr    string `json:"sys_descr,omitempty"`
	SysObjectID string `json:"sys_object_id,omitempty"`
	SysUptime   string `json:"sys_uptime,omitempty"`
	SysName     string `json:"sys_name,omitempty"`
}

type portRow struct {
	IfIndex        int    `json:"if_index"`
	Name           string `json:"name"`
	IfName         string `json:"if_name,omitempty"`
	IfDescr        string `json:"if_descr,omitempty"`
	Slot           int    `json:"slot"`
	Port           int    `json:"port"`
	OperStatusCode *int   `json:"oper_status_code"`
	OperStatus     string `json:"oper_status"`
}

type onuRow struct {
	IfIndex           int      `json:"if_index"`
	OnuID             int      `json:"onu_id"`
	Slot              int      `json:"slot"`
	Port              int      `json:"port"`
	Interface         string   `json:"interface"`
	TypeName          string   `json:"type_name,omitempty"`
	Name              string   `json:"name,omitempty"`
	Description       string   `json:"description,omitempty"`
	SerialNumber      string   `json:"serial_number,omitempty"`
	AdminStateCode    *int     `json:"admin_state_code"`
	AdminState        string   `json:"admin_state"`
	PhaseStateCode    *int     `json:"phase_state_code"`
	PhaseState        string   `json:"phase_state"`
	Online            bool     `json:"online"`
	LastDownCauseCode *int     `json:"last_down_cause_code"`
	LastDownCause     string   `json:"last_down_cause"`
	RXPowerPort       *int     `json:"rx_power_port,omitempty"`
	RawRXPower        *int     `json:"raw_rx_power,omitempty"`
	RXPowerDBM        *float64 `json:"rx_power_dbm,omitempty"`
	RXPowerLabel      string   `json:"rx_power_label,omitempty"`
	RXPowerSource     string   `json:"rx_power_source,omitempty"`
}

type rxPowerRow struct {
	IfIndex       int
	OnuID         int
	OnuPort       int
	RawRXPower    int
	RXPowerDBM    float64
	RXPowerLabel  string
	RXPowerSource string
}

type rxPowerMeta struct {
	OK     bool    `json:"ok"`
	Source string  `json:"source"`
	Count  int     `json:"count"`
	Error  *string `json:"error"`
}

type pollResult struct {
	OK        bool        `json:"ok"`
	Driver    string      `json:"driver"`
	LatencyMS int64       `json:"latency_ms"`
	System    systemInfo  `json:"system"`
	Ports     []portRow   `json:"ports"`
	Onus      []onuRow    `json:"onus"`
	RXPower   rxPowerMeta `json:"rx_power"`
	Error     *string     `json:"error"`
}

type collector struct {
	snmp     *gosnmp.GoSNMP
	walkMode string
}

func main() {
	host := flag.String("host", "", "SNMP target host")
	port := flag.Int("port", 161, "SNMP UDP port")
	version := flag.String("version", "v2c", "SNMP version: v1 or v2c")
	includeRX := flag.Bool("include-rx", false, "include ONU RX power table")
	timeout := flag.Duration("timeout", 10*time.Second, "SNMP timeout per request")
	retries := flag.Int("retries", 2, "SNMP retries")
	walkMode := flag.String("walk-mode", "bulk", "SNMP walk mode: auto, bulk, or walk")
	maxRepetitions := flag.Int("max-repetitions", 10, "max repetitions for SNMP bulk walk")
	flag.Parse()

	startedAt := time.Now()
	community := os.Getenv("KV_SNMP_COMMUNITY")
	if *host == "" || community == "" {
		writeError(startedAt, "host and KV_SNMP_COMMUNITY are required")
		os.Exit(2)
	}

	c, err := newCollector(*host, uint16(*port), *version, community, *timeout, *retries, *walkMode, *maxRepetitions)
	if err != nil {
		writeError(startedAt, err.Error())
		os.Exit(1)
	}
	defer c.snmp.Conn.Close()

	result, err := c.poll(*includeRX)
	result.LatencyMS = time.Since(startedAt).Milliseconds()
	if err != nil {
		message := err.Error()
		result.OK = false
		result.Error = &message
	}

	if err := json.NewEncoder(os.Stdout).Encode(result); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}

func newCollector(host string, port uint16, version string, community string, timeout time.Duration, retries int, walkMode string, maxRepetitions int) (*collector, error) {
	if net.ParseIP(host) == nil {
		if _, err := net.LookupHost(host); err != nil {
			return nil, err
		}
	}

	snmpVersion := gosnmp.Version2c
	if version == "v1" {
		snmpVersion = gosnmp.Version1
	}
	if version != "v1" && version != "v2c" {
		return nil, fmt.Errorf("unsupported SNMP version %s", version)
	}
	if walkMode != "auto" && walkMode != "bulk" && walkMode != "walk" {
		return nil, fmt.Errorf("unsupported walk mode %s", walkMode)
	}
	if maxRepetitions < 1 {
		maxRepetitions = 1
	}

	params := &gosnmp.GoSNMP{
		Target:             host,
		Port:               port,
		Community:          community,
		Version:            snmpVersion,
		Timeout:            timeout,
		Retries:            retries,
		MaxRepetitions:     uint32(maxRepetitions),
		ExponentialTimeout: false,
	}

	if err := params.Connect(); err != nil {
		return nil, err
	}

	return &collector{snmp: params, walkMode: walkMode}, nil
}

func (c *collector) poll(includeRX bool) (pollResult, error) {
	system, err := c.system()
	if err != nil {
		return pollResult{Driver: "unknown", System: system, Ports: []portRow{}, Onus: []onuRow{}}, err
	}

	ports, err := c.gponPorts()
	if err != nil {
		return pollResult{Driver: driver(system), System: system, Ports: []portRow{}, Onus: []onuRow{}}, err
	}

	c600 := isC600(system)
	var onus []onuRow
	if c600 {
		onus, err = c.registeredOnusC600(ports)
	} else {
		onus, err = c.registeredOnus(ports)
	}
	if err != nil {
		return pollResult{Driver: driver(system), System: system, Ports: ports, Onus: []onuRow{}}, err
	}

	rxMeta := rxPowerMeta{OK: true, Source: "snmp", Count: 0, Error: nil}
	if includeRX {
		rxOID := zteOnuRXPower
		if c600 {
			rxOID = c600OnuRXPower
		}
		powers, err := c.onuRXPowers(rxOID)
		if err != nil {
			message := err.Error()
			rxMeta = rxPowerMeta{OK: false, Source: "snmp", Count: 0, Error: &message}
		} else {
			rxMeta.Count = mergeRXPowers(onus, powers)
		}
	}

	return pollResult{
		OK:      true,
		Driver:  driver(system),
		System:  system,
		Ports:   ports,
		Onus:    onus,
		RXPower: rxMeta,
		Error:   nil,
	}, nil
}

func (c *collector) system() (systemInfo, error) {
	values, err := c.get([]string{sysDescr, sysObjectID, sysUptime, sysName})
	if err != nil {
		return systemInfo{}, err
	}

	return systemInfo{
		SysDescr:    values[sysDescr],
		SysObjectID: strings.TrimPrefix(values[sysObjectID], "."),
		SysUptime:   values[sysUptime],
		SysName:     values[sysName],
	}, nil
}

func (c *collector) gponPorts() ([]portRow, error) {
	descriptions, err := c.walk(ifDescr)
	if err != nil {
		return nil, err
	}
	names, err := c.walk(ifName)
	if err != nil {
		return nil, err
	}
	statuses, err := c.walk(ifOperStatus)
	if err != nil {
		return nil, err
	}

	ports := make([]portRow, 0)
	seen := map[int]bool{}
	appendFrom := func(rows map[string]string, base string) {
		for oid := range rows {
			ifIndex, ok := extractIndex(oid, base)
			if !ok || seen[ifIndex] {
				continue
			}

			description := descriptions[joinOID(ifDescr, strconv.Itoa(ifIndex))]
			name := names[joinOID(ifName, strconv.Itoa(ifIndex))]
			label, ok := resolvePortLabel(name, description)
			if !ok {
				continue
			}

			seen[ifIndex] = true
			slot, port := parseSlotPort(label, ifIndex)
			operCode := intPointerFromString(statuses[joinOID(ifOperStatus, strconv.Itoa(ifIndex))])
			ports = append(ports, portRow{
				IfIndex:        ifIndex,
				Name:           label,
				IfName:         name,
				IfDescr:        description,
				Slot:           slot,
				Port:           port,
				OperStatusCode: operCode,
				OperStatus:     decodeOperStatus(operCode),
			})
		}
	}

	appendFrom(names, ifName)
	appendFrom(descriptions, ifDescr)
	sort.Slice(ports, func(i, j int) bool {
		if ports[i].Slot != ports[j].Slot {
			return ports[i].Slot < ports[j].Slot
		}
		if ports[i].Port != ports[j].Port {
			return ports[i].Port < ports[j].Port
		}
		return ports[i].IfIndex < ports[j].IfIndex
	})

	return ports, nil
}

func (c *collector) registeredOnus(ports []portRow) ([]onuRow, error) {
	types, err := c.walk(zteOnuType)
	if err != nil {
		return nil, err
	}
	if len(types) == 0 {
		return []onuRow{}, nil
	}

	names, err := c.walk(zteOnuName)
	if err != nil {
		return nil, err
	}
	descriptions, err := c.walk(zteOnuDescription)
	if err != nil {
		return nil, err
	}
	serials, err := c.walk(zteOnuSN)
	if err != nil {
		return nil, err
	}
	adminStates, err := c.walk(zteOnuAdminState)
	if err != nil {
		return nil, err
	}
	phaseStates, err := c.walk(zteOnuPhaseState)
	if err != nil {
		return nil, err
	}
	lastDownCauses, err := c.walk(zteOnuLastDownCause)
	if err != nil {
		return nil, err
	}

	portMap := buildPortMap(ports)
	onus := make([]onuRow, 0, len(types))
	for oid, typeName := range types {
		ifIndex, onuID, ok := extractOnuIndex(oid, zteOnuType)
		if !ok {
			continue
		}

		suffix := fmt.Sprintf("%d.%d", ifIndex, onuID)
		// ifIndex here is the ONU-table prefix index, which self-encodes slot/port.
		// portMap is keyed by that same prefix index (see buildPortMap), so this
		// binds the ONU to its real parent port and inherits the port's
		// name-derived slot/port — without colliding across slots.
		slot, port := decodeIfIndex(ifIndex)
		if portRow, ok := portMap[ifIndex]; ok {
			slot = portRow.Slot
			port = portRow.Port
		}

		phaseRaw := intPointerFromString(phaseStates[joinOID(zteOnuPhaseState, suffix)])
		adminRaw := intPointerFromString(adminStates[joinOID(zteOnuAdminState, suffix)])
		lastDownRaw := intPointerFromString(lastDownCauses[joinOID(zteOnuLastDownCause, suffix)])
		online := phaseRaw != nil && *phaseRaw == 3

		onus = append(onus, onuRow{
			IfIndex:           ifIndex,
			OnuID:             onuID,
			Slot:              slot,
			Port:              port,
			Interface:         fmt.Sprintf("gpon-onu_1/%d/%d:%d", slot, port, onuID),
			TypeName:          typeName,
			Name:              names[joinOID(zteOnuName, suffix)],
			Description:       descriptions[joinOID(zteOnuDescription, suffix)],
			SerialNumber:      decodeOnuSN(serials[joinOID(zteOnuSN, suffix)]),
			AdminStateCode:    adminRaw,
			AdminState:        decodeAdminState(adminRaw),
			PhaseStateCode:    phaseRaw,
			PhaseState:        decodePhaseState(phaseRaw),
			Online:            online,
			LastDownCauseCode: lastDownRaw,
			LastDownCause:     decodeLastDownCause(lastDownRaw),
		})
	}

	sortOnus(onus)

	return onus, nil
}

// sortOnus orders ONU rows by slot, port, then ONU id — shared by both ONU-table collectors.
func sortOnus(onus []onuRow) {
	sort.Slice(onus, func(i, j int) bool {
		if onus[i].Slot != onus[j].Slot {
			return onus[i].Slot < onus[j].Slot
		}
		if onus[i].Port != onus[j].Port {
			return onus[i].Port < onus[j].Port
		}
		return onus[i].OnuID < onus[j].OnuID
	})
}

// registeredOnusC600 is the C600 (.1082 subtree) twin of registeredOnus. The ONU type/SN live in the
// ONU table (.20.2.1.2.1), but name/description and admin/phase live in SEPARATE tables — all keyed by
// the same {ifIndex}.{onuId}. The C600 ONU-table ifIndex IS the real IF-MIB port if-index, so slot/port
// come straight from the port map / decodeIfIndexC600 (no prefix-index collision like C300/C320).
func (c *collector) registeredOnusC600(ports []portRow) ([]onuRow, error) {
	types, err := c.walk(c600OnuType)
	if err != nil {
		return nil, err
	}
	if len(types) == 0 {
		return []onuRow{}, nil
	}

	// Optional columns (separate tables) — a walk failure shouldn't drop the whole poll.
	walkOpt := func(oid string) map[string]string {
		rows, err := c.walk(oid)
		if err != nil {
			return map[string]string{}
		}
		return rows
	}
	serials := walkOpt(c600OnuSN)
	names := walkOpt(c600OnuName)
	descriptions := walkOpt(c600OnuDesc)
	adminStates := walkOpt(c600OnuAdmin)
	phaseStates := walkOpt(c600OnuPhase)

	portByIfIndex := map[int]portRow{}
	for _, p := range ports {
		portByIfIndex[p.IfIndex] = p
	}

	onus := make([]onuRow, 0, len(types))
	for oid, typeName := range types {
		ifIndex, onuID, ok := extractOnuIndex(oid, c600OnuType)
		if !ok {
			continue
		}

		suffix := fmt.Sprintf("%d.%d", ifIndex, onuID)
		slot, port := decodeIfIndexC600(ifIndex)
		if pr, ok := portByIfIndex[ifIndex]; ok {
			slot = pr.Slot
			port = pr.Port
		}

		phaseRaw := intPointerFromString(phaseStates[joinOID(c600OnuPhase, suffix)])
		adminRaw := intPointerFromString(adminStates[joinOID(c600OnuAdmin, suffix)])
		online := phaseRaw != nil && *phaseRaw == 4 // 4 = Working

		onus = append(onus, onuRow{
			IfIndex:           ifIndex,
			OnuID:             onuID,
			Slot:              slot,
			Port:              port,
			Interface:         fmt.Sprintf("gpon_onu-1/%d/%d:%d", slot, port, onuID),
			TypeName:          typeName,
			Name:              names[joinOID(c600OnuName, suffix)],
			Description:       descriptions[joinOID(c600OnuDesc, suffix)],
			SerialNumber:      decodeOnuSN(serials[joinOID(c600OnuSN, suffix)]),
			AdminStateCode:    adminRaw,
			AdminState:        decodeAdminState(adminRaw),
			PhaseStateCode:    phaseRaw,
			PhaseState:        decodePhaseStateC600(phaseRaw),
			Online:            online,
			LastDownCauseCode: nil,
			LastDownCause:     decodeLastDownCause(nil),
		})
	}

	sortOnus(onus)

	return onus, nil
}

func (c *collector) onuRXPowers(rxOID string) (map[string]rxPowerRow, error) {
	rows, err := c.walk(rxOID)
	if err != nil {
		return nil, err
	}

	powers := map[string]rxPowerRow{}
	for oid, rawValue := range rows {
		ifIndex, onuID, onuPort, ok := extractOnuPortIndex(oid, rxOID)
		if !ok {
			continue
		}

		raw, ok := intFromString(rawValue)
		if !ok {
			continue
		}

		dbm, ok := convertOnuRXPowerToDBM(raw)
		if !ok {
			continue
		}

		key := onuRXPowerKey(ifIndex, onuID)
		if _, exists := powers[key]; exists && onuPort != 1 {
			continue
		}

		powers[key] = rxPowerRow{
			IfIndex:       ifIndex,
			OnuID:         onuID,
			OnuPort:       onuPort,
			RawRXPower:    raw,
			RXPowerDBM:    dbm,
			RXPowerLabel:  fmt.Sprintf("%.3f dBm", dbm),
			RXPowerSource: "snmp_onu_rx",
		}
	}

	return powers, nil
}

func (c *collector) get(oids []string) (map[string]string, error) {
	result, err := c.snmp.Get(oids)
	if err != nil {
		return nil, err
	}
	if result == nil {
		return nil, errors.New("SNMP get returned no result")
	}

	values := map[string]string{}
	for _, pdu := range result.Variables {
		values[normalizeOID(pdu.Name)] = valueString(pdu.Value)
	}

	return values, nil
}

func (c *collector) walk(oid string) (map[string]string, error) {
	var rows []gosnmp.SnmpPDU
	var err error

	if c.snmp.Version == gosnmp.Version1 || c.walkMode == "walk" {
		rows, err = c.snmp.WalkAll(oid)
	} else if c.walkMode == "bulk" {
		rows, err = c.snmp.BulkWalkAll(oid)
	} else {
		rows, err = c.snmp.BulkWalkAll(oid)
		if err != nil {
			rows, err = c.snmp.WalkAll(oid)
		}
	}
	if err != nil {
		return nil, err
	}

	normalized := make(map[string]string, len(rows))
	for _, pdu := range rows {
		normalized[normalizeOID(pdu.Name)] = valueString(pdu.Value)
	}

	return normalized, nil
}

func writeError(startedAt time.Time, message string) {
	_ = json.NewEncoder(os.Stdout).Encode(pollResult{
		OK:        false,
		Driver:    "unknown",
		LatencyMS: time.Since(startedAt).Milliseconds(),
		System:    systemInfo{},
		Ports:     []portRow{},
		Onus:      []onuRow{},
		RXPower:   rxPowerMeta{OK: false, Source: "snmp", Count: 0, Error: &message},
		Error:     &message,
	})
}

func mergeRXPowers(onus []onuRow, powers map[string]rxPowerRow) int {
	count := 0
	for i := range onus {
		power, ok := powers[onuRXPowerKey(onus[i].IfIndex, onus[i].OnuID)]
		if !ok {
			continue
		}

		onus[i].RXPowerPort = &power.OnuPort
		onus[i].RawRXPower = &power.RawRXPower
		onus[i].RXPowerDBM = &power.RXPowerDBM
		onus[i].RXPowerLabel = power.RXPowerLabel
		onus[i].RXPowerSource = power.RXPowerSource
		count++
	}

	return count
}

func driver(system systemInfo) string {
	value := strings.ToLower(system.SysDescr + " " + system.SysObjectID)
	if strings.Contains(value, "zte") || strings.Contains(value, "zxa10") || strings.Contains(value, "3902") {
		return "zte"
	}

	return "unknown"
}

// isC600 recognises a ZTE TITAN C600 from its sysDescr ("C600") or sysObjectID
// (.3902.1082.1001.600). The C600 answers the .1082 subtree, not the C300/C320 .1012 tables.
func isC600(system systemInfo) bool {
	value := strings.ToLower(system.SysDescr + " " + system.SysObjectID)
	return strings.Contains(value, "c600") || strings.Contains(value, "3902.1082.1001.600")
}

func valueString(value interface{}) string {
	switch v := value.(type) {
	case nil:
		return ""
	case string:
		return strings.Trim(v, "\" \t\r\n")
	case []byte:
		if isPrintable(v) {
			return strings.Trim(string(v), "\" \t\r\n")
		}

		parts := make([]string, len(v))
		for i, b := range v {
			parts[i] = strings.ToUpper(hex.EncodeToString([]byte{b}))
		}
		return strings.Join(parts, " ")
	default:
		return strings.Trim(fmt.Sprint(v), "\" \t\r\n")
	}
}

func isPrintable(bytes []byte) bool {
	for _, b := range bytes {
		if b < 32 || b > 126 {
			return false
		}
	}

	return true
}

func normalizeOID(oid string) string {
	return strings.TrimPrefix(oid, ".")
}

func joinOID(base string, suffix string) string {
	return normalizeOID(base) + "." + strings.TrimPrefix(suffix, ".")
}

func extractIndex(oid string, base string) (int, bool) {
	oid = normalizeOID(oid)
	prefix := normalizeOID(base) + "."
	if !strings.HasPrefix(oid, prefix) {
		return 0, false
	}

	suffix := strings.TrimPrefix(oid, prefix)
	if !digitsOnly(suffix) {
		return 0, false
	}

	value, err := strconv.Atoi(suffix)
	return value, err == nil
}

func extractOnuIndex(oid string, base string) (int, int, bool) {
	oid = normalizeOID(oid)
	prefix := normalizeOID(base) + "."
	if !strings.HasPrefix(oid, prefix) {
		return 0, 0, false
	}

	parts := strings.Split(strings.TrimPrefix(oid, prefix), ".")
	if len(parts) != 2 || !digitsOnly(parts[0]) || !digitsOnly(parts[1]) {
		return 0, 0, false
	}

	ifIndex, errA := strconv.Atoi(parts[0])
	onuID, errB := strconv.Atoi(parts[1])
	return ifIndex, onuID, errA == nil && errB == nil
}

func extractOnuPortIndex(oid string, base string) (int, int, int, bool) {
	oid = normalizeOID(oid)
	prefix := normalizeOID(base) + "."
	if !strings.HasPrefix(oid, prefix) {
		return 0, 0, 0, false
	}

	parts := strings.Split(strings.TrimPrefix(oid, prefix), ".")
	if len(parts) != 3 || !digitsOnly(parts[0]) || !digitsOnly(parts[1]) || !digitsOnly(parts[2]) {
		return 0, 0, 0, false
	}

	ifIndex, errA := strconv.Atoi(parts[0])
	onuID, errB := strconv.Atoi(parts[1])
	onuPort, errC := strconv.Atoi(parts[2])
	return ifIndex, onuID, onuPort, errA == nil && errB == nil && errC == nil
}

func digitsOnly(value string) bool {
	if value == "" {
		return false
	}
	for _, r := range value {
		if r < '0' || r > '9' {
			return false
		}
	}
	return true
}

var portLabelPattern = regexp.MustCompile(`(?i)^gpon(?:[-_]olt)?[-_]?\d+/\d+/\d+$`)
var slotPortPattern = regexp.MustCompile(`(\d+)/(\d+)/(\d+)`)

func resolvePortLabel(name string, description string) (string, bool) {
	for _, candidate := range []string{name, description} {
		if candidate == "" {
			continue
		}

		if portLabelPattern.MatchString(candidate) {
			return candidate, true
		}
	}

	return "", false
}

func parseSlotPort(description string, ifIndex int) (int, int) {
	matches := slotPortPattern.FindStringSubmatch(description)
	if len(matches) == 4 {
		slot, _ := strconv.Atoi(matches[2])
		port, _ := strconv.Atoi(matches[3])
		return slot, port
	}

	return decodeIfIndex(ifIndex)
}

func decodeIfIndex(ifIndex int) (int, int) {
	return (ifIndex >> 16) & 0xFF, (ifIndex >> 8) & 0xFF
}

// decodeIfIndexC600 decodes the C600 IF-MIB index: slot at bits 15-8, port at bits 7-0
// (e.g. 285278977 = 0x11010301 -> 3/1, 285279504 = 0x11010510 -> 5/16). Verified live.
func decodeIfIndexC600(ifIndex int) (int, int) {
	return (ifIndex >> 8) & 0xFF, ifIndex & 0xFF
}

func buildPortMap(ports []portRow) map[int]portRow {
	m := map[int]portRow{}
	for _, port := range ports {
		// Key by the ZTE ONU-table prefix index (0x10000000|slot<<16|port<<8) —
		// the value the ONU table uses to reference its parent port — NOT the raw
		// IF-MIB port if-index. The two numberings overlap: a slot-1 ONU prefix
		// equals the IF-MIB if-index of gpon_1/2/(port+1), so keying by if-index
		// mis-binds every slot-1 ONU onto a slot-2 port.
		m[onuPortPrefixIndex(port.Slot, port.Port)] = port
	}
	return m
}

// onuPortPrefixIndex returns the ZTE C300/C320 ONU-table prefix index for a
// GPON port (matches decodeIfIndex: slot at bits 23-16, port at bits 15-8).
func onuPortPrefixIndex(slot, port int) int {
	return 0x10000000 | (slot << 16) | (port << 8)
}

func intPointerFromString(value string) *int {
	valueInt, ok := intFromString(value)
	if !ok {
		return nil
	}

	return &valueInt
}

var intPattern = regexp.MustCompile(`-?\d+`)

func intFromString(value string) (int, bool) {
	match := intPattern.FindString(value)
	if match == "" {
		return 0, false
	}

	valueInt, err := strconv.Atoi(match)
	return valueInt, err == nil
}

func decodeOnuSN(raw string) string {
	raw = strings.Trim(raw, "\" \t\r\n")
	if raw == "" {
		return ""
	}

	parts := strings.Fields(raw)
	if len(parts) == 8 {
		bytes := make([]byte, 8)
		ok := true
		for i, part := range parts {
			value, err := strconv.ParseUint(part, 16, 8)
			if err != nil {
				ok = false
				break
			}
			bytes[i] = byte(value)
		}

		vendor := string(bytes[:4])
		if ok && regexp.MustCompile(`^[A-Z]{4}$`).MatchString(vendor) {
			return strings.ToUpper(vendor + hex.EncodeToString(bytes[4:]))
		}
	}

	clean := regexp.MustCompile(`[^A-Za-z0-9]`).ReplaceAllString(raw, "")
	return strings.ToUpper(clean)
}

func decodeAdminState(code *int) string {
	if code == nil {
		return "unknown"
	}
	switch *code {
	case 1:
		return "active"
	case 2:
		return "disabled"
	default:
		return "unknown"
	}
}

func decodePhaseState(code *int) string {
	if code == nil {
		return "Unknown"
	}
	switch *code {
	case 0:
		return "Logging"
	case 1:
		return "LOS"
	case 2:
		return "Sync MIB"
	case 3:
		return "Working"
	case 4:
		return "DyingGasp"
	case 5:
		return "Auth Failed"
	case 6:
		return "Offline"
	default:
		return "Unknown"
	}
}

// decodePhaseStateC600 maps the C600 state enum (.10.2.3.8.1.4) — different from the C300/C320 enum.
// Verified live against CLI Phase state: 2=LOS, 4=Working, 5=DyingGasp, 7=OffLine.
func decodePhaseStateC600(code *int) string {
	if code == nil {
		return "Unknown"
	}
	switch *code {
	case 2:
		return "LOS"
	case 4:
		return "Working"
	case 5:
		return "DyingGasp"
	case 7:
		return "OffLine"
	default:
		return "Unknown"
	}
}

func decodeLastDownCause(code *int) string {
	if code == nil {
		return "Unknown"
	}
	switch *code {
	case 0:
		return "Normal"
	case 1:
		return "LOS"
	case 2:
		return "LOSi"
	case 3:
		return "LOFi"
	case 4:
		return "SFi"
	case 5:
		return "LOAi"
	case 6:
		return "LOAMi"
	case 7:
		return "Deactivated"
	case 8:
		return "Manual"
	case 9:
		return "DyingGasp"
	default:
		return "Unknown"
	}
}

func decodeOperStatus(code *int) string {
	if code == nil {
		return "unknown"
	}
	switch *code {
	case 1:
		return "up"
	case 2:
		return "down"
	case 3:
		return "testing"
	case 4:
		return "unknown"
	case 5:
		return "dormant"
	case 6:
		return "notPresent"
	case 7:
		return "lowerLayerDown"
	default:
		return "unknown"
	}
}

func convertOnuRXPowerToDBM(raw int) (float64, bool) {
	// 0xFFFF (65535) = "N/A" sentinel; other hard sentinels for negative-encoded firmwares.
	if raw <= -80000 || raw >= 2147480000 || raw == -32768 || raw == 65535 {
		return 0, false
	}

	if raw >= -50000 && raw <= -3000 {
		return round3(float64(raw) / 1000), true
	}

	if raw >= -500 && raw <= -5 {
		return round3(float64(raw) / 10), true
	}

	if raw > 0 && raw <= 65534 {
		// ZTE C300/C320 ONU-RX: unsigned 16-bit, dBm = signed16(raw) * 0.002 - 30.
		// raw 32768..65534 are negative two's-complement (weak signal),
		// e.g. raw 64032 = -1504 = -33.0 dBm. Window drops impossible/garbage values.
		signed := raw
		if raw > 32767 {
			signed = raw - 65536
		}
		dbm := round3(float64(signed)*0.002 - 30)
		if dbm >= -45 && dbm <= 0 {
			return dbm, true
		}
		return 0, false
	}

	return 0, false
}

func round3(value float64) float64 {
	rounded, _ := strconv.ParseFloat(fmt.Sprintf("%.3f", value), 64)
	return rounded
}

func onuRXPowerKey(ifIndex int, onuID int) string {
	return fmt.Sprintf("%d.%d", ifIndex, onuID)
}

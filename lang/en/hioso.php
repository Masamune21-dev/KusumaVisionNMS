<?php

// HiOSO OLT backend messages (CLI telnet/SNMP). ID: lang/id/hioso.php.
return [
    'onu_name_required' => 'HiOSO ONU name cannot be empty (letters/digits/_-. only).',
    'cli_telnet_only' => 'HiOSO CLI only supports Telnet. Set the OLT CLI transport to telnet.',
    'telnet_connect_failed' => 'Telnet connection failed: :message (:code)',
    'snmp_set_name_rejected' => 'The OLT rejected the SNMP SET of the ONU name (check the write community / OID).',
    'snmp_write_community_missing' => 'The OLT SNMP write community is not set — cannot write via SNMP.',
    'snmp_walk_failed' => 'SNMP walk failed for :oid',
    'snmp_v3_unsupported' => 'HiOSO only supports SNMP v1/v2c.',
    'scan_retry_on_open' => ') — it will be retried when the page is opened.',
    'action_unsupported' => 'This action is not supported for this OLT.',
];

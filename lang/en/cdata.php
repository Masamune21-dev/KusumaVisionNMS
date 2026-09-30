<?php

// C-Data OLT backend messages (CLI/SNMP/VLAN/port detail). ID: lang/id/cdata.php.
return [
    'cli_telnet_only' => 'C-Data CLI only supports Telnet for now. Set the OLT CLI transport to telnet.',
    'telnet_connect_failed' => 'Telnet connection failed: :message (:code)',
    'cli_rejected' => 'CLI rejected \':command\': :error',
    'unknown_interface' => 'Unknown C-Data interface: :interface',
    'remote_access_gpon_only' => 'Remote ONT security-mgmt is only available on C-Data GPON.',
    'snmp_oid_unavailable' => 'SNMP walk: OID :oid is not available on the device',
    'snmp_walk_failed' => 'SNMP walk failed for :oid',
    'snmp_v3_unsupported' => 'C-Data only supports SNMP v1/v2c.',
    'vlan_exists' => 'VLAN :vlan already exists on the OLT.',
    'vlan_not_created' => 'VLAN :vlan did not appear in \'show vlan\' after it was created.',
    'tag_kind_unsupported' => 'Tagging VLANs from NMS is only for GE/XGE/EPON ports — GPON ports join new VLANs automatically.',
    'vlan_missing' => 'VLAN :vlan does not exist on the OLT yet. Create it first on the VLAN page.',
    'port_vlan_unreadable' => 'Unable to read the VLANs of port :port.',
    'port_mode_untaggable' => 'Port :port is in :mode mode. Tagging VLANs from NMS is only for Trunk or Hybrid ports — change the mode via CLI first.',
    'vlan_already_untagged' => 'VLAN :vlan is already untagged on :port; NMS will not change it to tagged.',
    'vlan_list_too_long' => 'The VLAN list of :port is too long to send safely from NMS. Tag it via CLI.',
    'vlan_not_on_port' => 'VLAN :vlan is not yet visible on port :port after the command was sent.',
    'vlans_lost' => 'Verification: VLAN :vlans missing from :port. Check the port via CLI immediately.',
    'enter_interface_failed' => 'Failed to enter \'interface :kind 0/:slot\'',
    'unknown_port_kind' => 'Unknown C-Data port type: :kind',
    'vlan_id_range' => 'VLAN ID must be 1-4094.',
    'scan_retry_on_open' => ') — it will be retried when the page is opened.',
    'action_unsupported' => 'This action is not supported for this OLT.',
    'vlan_write_forbidden' => 'Only head-office staff or the OLT owner can change VLANs on this OLT.',
];

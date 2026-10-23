<?php

// ZTE OLT (C300/C320/C600) backend messages. ID: lang/id/zte.php.
return [
    'invalid_interface_param' => 'Invalid interface parameter.',
    'config_save_partial' => 'Saving the configuration finished with errors: ',
    'onu_reboot_partial' => 'The ONU reboot finished with errors: ',
    'onu_deleted' => 'ONU :onu deleted from :interface.',
    'onu_delete_partial' => 'Deleting the ONU finished with errors: ',
    'onus_deleted' => ':count ONU(s) deleted from :interface.',
    'onus_delete_partial' => ':deleted ONU(s) deleted, :failed failed — :failures. Refresh the ONUs to confirm the port state.',
    'copy_same_port' => 'The destination port must be different from the source port.',
    'mgmt_ip_c600_only' => 'Auto mgmt-IP is only available for C600 OLTs.',
    'mgmt_ip_pool_unavailable' => 'The mgmt-IP pool could not be read from the OLT or is already full.',
    'preview_fill_required' => '! Fill in the required fields (VLAN, TCONT profile, mgmt-ip, ACS) to see the script.',
    'c600_toggle_unsupported' => 'Enabling/disabling an ONU is not supported on C600 OLTs yet: the admin-state OID is not mapped.',
    'c600_rename_unsupported' => 'Renaming an ONU is not supported on C600 OLTs yet: the name OID is not mapped.',
    'card_table_empty_c600' => 'The C600 SNMP card table (zxAnCardTable) is empty or unreadable.',
    'show_card_unparseable' => 'The show card output contains no parseable card data',
    'no_active_uplink_card' => 'There is no active uplink card for interface discovery yet. Refresh the hardware first.',
    'no_interface_detail_parsed' => 'No interface details could be parsed',
    'invalid_gpon_interface' => 'Invalid GPON interface.',
    'invalid_uplink_interface' => 'Invalid uplink interface.',
    'interface_output_unparseable' => 'The output of :interface could not be parsed',
    'vlan_refresh_failed' => 'VLAN refresh failed: ',
    'backup_zte_only' => 'Config backup is currently only available for ZTE OLTs.',
    'backup_saved' => 'Config backup of OLT :name saved (new version).',
    'backup_unchanged' => 'The config has not changed since the last backup — no new version was created.',
    'backup_daily_enabled' => 'Daily config backup enabled for OLT :name.',
    'backup_daily_disabled' => 'Daily config backup disabled for OLT :name.',

    // Pre-Register ONU (OnuPreregisterService)
    'prereg_serial_format' => 'Invalid SN format — a GPON SN is 4 vendor characters + 8 hex digits (e.g. ZTEGC1234567). Check the label on the box/ONU.',
    'prereg_serial_registered' => 'This SN is already registered on this OLT (:interface).',
    'prereg_onu_id_used' => 'ONU ID :onu_id is already used on this port — pick another ID.',

    // ZTE C300/C320 EPON cards (ZteEpon, ZTE_EPON_ENABLED switch) — experimental
    'epon_cli_unreadable' => 'The card list (show card) could not be read over CLI — the last EPON data was kept.',
    'epon_state_unreadable' => 'EPON ONU status (show epon onu state) could not be read — the last EPON data was kept.',
    'epon_rx_unreadable' => 'EPON ONU Rx could not be read (show pon power onu-rx was rejected or empty for the EPON port).',
    'epon_mac_invalid' => 'Invalid EPON ONU MAC — enter 12 hex digits, e.g. 1c18.4a0d.d30f or 1C:18:4A:0D:D3:0F.',
    'epon_action_unsupported' => 'This action is not available for EPON ports (ETG cards) yet.',
    'epon_use_epon_form' => 'This is an EPON port (ETG card) — register its ONUs with the EPON registration form.',
    'epon_port_unknown' => 'This slot/port is not an EPON port known to the NMS. Poll/refresh the OLT first.',
    'epon_registered_ok' => 'EPON ONU :interface registered (EPON syntax is unverified — check the CLI output in the registration history).',
];

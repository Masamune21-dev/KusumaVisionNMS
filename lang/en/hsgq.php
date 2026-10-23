<?php

// Backend messages for genuine HSGQ OLTs (HS Fiber 50224 — SNMP + JSON web API). ID: lang/id/hsgq.php.
return [
    'snmp_walk_failed' => 'SNMP walk failed for :oid',
    'snmp_v3_unsupported' => 'HSGQ supports SNMP v1/v2c only.',
    'snmp_no_answer' => 'The OLT did not answer SNMP (empty sysObjectID & sysDescr).',
    'scan_retry_on_open' => ') — will retry when the page is opened.',
    'action_unsupported' => 'This action is not supported for this HSGQ OLT.',
    'olt_updated' => 'HSGQ OLT updated.',
    'olt_deleted' => 'HSGQ OLT deleted.',
    'test_ok' => 'SNMP OK. Family: :family. Latency: :latency ms. ONUs read: :onus. The diagnostic report is on this page.',
    'web_credentials_missing' => 'The OLT web username/password is empty (OLT form → Web & CLI access).',
    'web_target_blocked' => 'This OLT address may not be contacted from the server (loopback/link-local/multicast/reserved).',
    'demo_device' => 'Demo OLTs are not connected to a device.',
    'web_port_missing' => 'The OLT web port is empty — HSGQ ONU actions over the web need the HTTP port (usually 80).',
    'web_captcha_required' => 'The OLT web login asks for a captcha, so the NMS cannot log in automatically. Disable the login captcha on the OLT web UI.',
    'web_login_failed' => 'OLT web login failed: :message',
    'web_session_expired' => 'The OLT web session keeps expiring (Token Check Failed).',
    'web_unreachable' => 'Cannot reach the OLT web UI: :message',
    'web_bad_response' => 'Unrecognised OLT web response (:detail).',
    'web_rejected' => 'The OLT rejected the command without a message.',
    'onu_name_required' => 'The ONU name cannot be empty (letters, digits, space, _ - . @ # ( ) /).',
    'onu_not_in_cache' => 'The ONU is not in the NMS cache — refresh the port first.',
    'onu_not_on_web' => 'ONU :identity was not found in the OLT web ONU table.',
    'onu_identifier_missing' => 'The OLT web ONU table has no ONU identifier — action cancelled.',
    'onu_renamed_save_hint' => 'ONU renamed. Press Save Config so it survives an OLT restart.',
    'config_saved' => 'Configuration of OLT :name saved.',
];

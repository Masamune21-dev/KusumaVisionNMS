<?php

// General backend messages (mobile API, middleware, users, settings). ID: lang/id/common.php.
return [
    'token_revoked' => 'Token revoked.',
    'page_forbidden' => 'You do not have access to this page.',
    'fcm_device_not_registered' => 'This device is not registered for notifications yet. Try signing out and back in, and allow notifications.',
    'fcm_test_title' => '🔔 Test Notification',
    'fcm_test_body' => 'KusumaVision NMS FCM push received successfully.',
    'fcm_test_sent' => 'Test notification sent to :count device(s). Check the notification bar.',
    'onu_reboot_sent' => 'Reboot command sent. The ONU restarts in 30-60 seconds.',
    'onu_reboot_warn' => 'Reboot finished with error indications.',
    'onu_name_only' => 'This OLT only supports renaming the ONU.',
    'onu_info_updated' => 'ONU info updated.',
    'onu_deleted' => 'ONU :onu deleted from the OLT.',
    'onu_delete_warn' => 'ONU deletion finished with error indications.',
    'onu_not_in_snapshot' => 'ONU not found in this OLT\'s snapshot.',
    'action_unsupported_driver' => 'This action is not supported for this OLT driver.',
    'live_refresh_zte_only' => 'Live refresh via this endpoint is only available for ZTE OLTs.',
    'app_description' => 'FTTH/GPON Network Management System for ZTE OLTs & ONU provisioning.',
    'invalid_credentials' => 'Incorrect email or password.',
];

<?php
/**
 * Audit-log redaction (Simple_MCP_Audit::redact / redact_command): secrets never reach the log.
 */

smcp_test('audit: wp_cli secrets are redacted in every common form', function () {
    smcp_require_method('Simple_MCP_Audit', 'redact_command');
    $cases = [
        'user create bob bob@x.io --user_pass=Hunter2'                       => 'user create bob bob@x.io --user_pass=[redacted]',
        'user update 1 --user_pass Hunter2'                                  => 'user update 1 --user_pass [redacted]',
        "config set DB_PASSWORD 'Hunter2'"                                    => 'config set DB_PASSWORD [redacted]',
        'config set NONCE_KEY Hunter2 --raw'                                 => 'config set NONCE_KEY [redacted] --raw',
        'option update stripe_secret_key Hunter2'                            => 'option update stripe_secret_key [redacted]',
        'site option update smtp_password Hunter2'                           => 'site option update smtp_password [redacted]',
        'user meta update 1 api_token Hunter2'                               => 'user meta update 1 api_token [redacted]',
        'post meta add 5 _stripe_secret Hunter2'                             => 'post meta add 5 _stripe_secret [redacted]',
        'option patch update wp_mail_smtp smtp pass Hunter2'                 => 'option patch update wp_mail_smtp smtp pass [redacted]',
        'post meta patch insert 5 settings api_key Hunter2'                  => 'post meta patch insert 5 settings api_key [redacted]',
        "option update wp_mail_smtp '{\"smtp\":{\"pass\":\"Hunter2\"}}' --format=json" => "option update wp_mail_smtp '{\"smtp\":{\"pass\":\"[redacted]\"}}' --format=json",
    ];
    foreach ($cases as $in => $want) {
        smcp_assert_same($want, Simple_MCP_Audit::redact_command($in), $in);
    }
});

smcp_test('audit: ordinary wp_cli commands are logged unchanged', function () {
    smcp_require_method('Simple_MCP_Audit', 'redact_command');
    foreach ([
        'option update blogname "My site"',
        'option get smtp_password',
        'post meta update 5 color red',
        'post list --post_type=page --format=json',
        'user application-password create 1 x --porcelain',
        'option patch delete wp_mail_smtp smtp pass',
    ] as $cmd) {
        smcp_assert_same($cmd, Simple_MCP_Audit::redact_command($cmd));
    }
});

smcp_test('audit: URLs lose credentials and signed query strings', function () {
    smcp_require_method('Simple_MCP_Audit', 'redact');
    $out = Simple_MCP_Audit::redact(['url' => 'https://user:Hunter2@cdn.example.com/a.jpg?X-Amz-Signature=Hunter2&token=Hunter2'], 'upload_media');
    smcp_assert_same('https://[redacted]@cdn.example.com/a.jpg?[redacted]', $out['url']);
    smcp_assert_same('https://cdn.example.com/a.jpg', Simple_MCP_Audit::redact(['url' => 'https://cdn.example.com/a.jpg'])['url']);
    smcp_assert_same('see ftp://[redacted]@host/x', Simple_MCP_Audit::redact_text('see ftp://me:pw@host/x'));
});

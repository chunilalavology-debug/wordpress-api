<?php
/**
 * Plugin Name: Offers API Hub
 * Description: Multi-section offers plugin (IMM + KACU APIs) via shortcodes.
 * Version: 0.3.0
 *
 * Install:
 * - Create folder: wp-content/plugins/imm-sections/
 * - Put this file at: wp-content/plugins/imm-sections/imm-sections.php
 * - Activate "Offers API Hub" in WP Admin -> Plugins
 *
 * Featured Digital Offers (CollectionOffers; shopperID from SSO / user meta):
 *   GET …/Offers/CollectionOffers?shopperID={id}&Collection=DigitalOffers
 *   [imm_featured_digital_offers] — optional: shopper_id="" collection="DigitalOffers"
 *
 * Notes:
 * - Configure API credentials in WP Admin -> Settings -> Offers API Hub
 * - Token is fetched/refreshed server-side and cached automatically.
 */
if (!defined('ABSPATH')) exit;

define('IMM_SECTIONS_OPT', 'imm_sections_settings');
define('IMM_KACU_SECTIONS_OPT', 'imm_sections_kacu_settings');

function imm_sections_clean_base_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) return $url;
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
    $host = $parts['host'];
    $port = isset($parts['port']) ? (':' . $parts['port']) : '';
    $path = isset($parts['path']) ? $parts['path'] : '';
    // Ensure path has no trailing slash unless it's root
    if ($path !== '/' && $path !== '') $path = rtrim($path, '/');
    return $scheme . '://' . $host . $port . $path;
}

function imm_sections_get_settings() {
    $defaults = [
        'token_url'     => 'https://prt-ridstaging.immapi.com/Api/V3.0/token',
        // Default ClientID for prt-ridstaging / Coupon Click parity with Postman (IMM uses 1).
        'client_id'     => '1',
        // SSO iframe domain configuration (used for iframe src + postMessage origin validation).
        'sso_env'       => 'staging',
        'sso_subdomain_staging' => 'ridstaging.immdemo.net',
        'sso_subdomain_production' => 'rperks.shopridleys.com',
        'sso_login_path' => '/sso-login',
        // Keep credentials out of code; set them in WP Admin -> Settings -> Offers API Hub
        'username'      => '',
        'password'      => '',
        'grant_type'    => 'password',
        'offers_base'   => 'https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/Offers',
        'header_client' => 'ClientID', // header name many IMM setups use
        // How token endpoint expects credentials:
        // - "body": username/password/client field sent in x-www-form-urlencoded body
        // - "headers": username/password/client header sent as HTTP headers; body includes grant_type only (per your curl)
        'token_auth_mode' => 'body',
        // Token form field names vary by vendor; keep configurable.
        'token_field_grant'    => 'grant_type',
        // Postman success for this API uses "ClientID" in the body.
        'token_field_clientid' => 'ClientID',
        'token_field_username' => 'username',
        'token_field_password' => 'password',
        // Featured Digital Offers: "Activate Now" -> Clip/Coupon click endpoint.
        // If empty, we will derive it from offers_base.
        'coupon_click_url' => 'https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/Coupon/Click',
    ];
    $saved = get_option(IMM_SECTIONS_OPT, []);
    if (!is_array($saved)) $saved = [];
    $out = array_merge($defaults, $saved);
    // client_id is used to request the access token.
    // We DO NOT hardcode it here; default to 1 only when config is empty/missing.
    $effective = apply_filters('imm_sections_effective_client_id', (string)($out['client_id'] ?? '1'));
    $out['client_id'] = preg_replace('/[^0-9]/', '', $effective);
    if ($out['client_id'] === '') {
        $out['client_id'] = '1';
    }
    return $out;
}

/**
 * ClientID on Coupon/Click headers. Defaults to the same Client ID used to mint the Bearer token
 * (pass the normalized ID from settings). IMM returns 400 if ClientID does not match the token’s client.
 *
 * @param string|null $token_client_id Normalized digits-only Client ID from Offers API Hub; if null, read from settings.
 * @return string Digits-only ClientID for clip request headers.
 */
function imm_sections_get_clip_client_id($token_client_id = null) {
    if ($token_client_id === null || $token_client_id === '') {
        $s = imm_sections_get_settings();
        $same_as_token = preg_replace('/[^0-9]/', '', (string) ($s['client_id'] ?? ''));
    } else {
        $same_as_token = preg_replace('/[^0-9]/', '', (string) $token_client_id);
    }
    if ($same_as_token === '') {
        $same_as_token = '1';
    }
    $cid = preg_replace('/[^0-9]/', '', (string) apply_filters('imm_sections_clip_client_id', $same_as_token));
    return $cid !== '' ? $cid : $same_as_token;
}

/**
 * Best-effort extraction of ClientID from a JWT access token.
 * If the token isn't a JWT (opaque string), returns ''.
 *
 * This helps keep Coupon/Click ClientID in sync with whatever the token was issued for.
 */
function imm_sections_extract_client_id_from_access_token($token) {
    if (!is_string($token)) return '';
    $token = trim($token);
    if ($token === '') return '';

    // JWT format: header.payload.signature
    $parts = explode('.', $token);
    if (count($parts) < 2) return '';
    // If it's not 3 parts, still attempt with the "middle" as payload for robustness.
    $payload_b64u = $parts[1] ?? '';
    if ($payload_b64u === '') return '';

    $payload_b64 = strtr($payload_b64u, '-_', '+/');
    $pad = strlen($payload_b64) % 4;
    if ($pad) $payload_b64 .= str_repeat('=', 4 - $pad);

    $json = base64_decode($payload_b64, true);
    if ($json === false || $json === '') return '';
    $claims = json_decode($json, true);
    if (!is_array($claims)) return '';

    foreach (['ClientID', 'ClientId', 'client_id', 'clientid', 'clientId', 'Client'] as $k) {
        if (isset($claims[$k]) && is_scalar($claims[$k])) {
            $cid = preg_replace('/[^0-9]/', '', (string)$claims[$k]);
            if ($cid !== '') return $cid;
        }
    }
    return '';
}

function imm_sections_save_settings_sanitized($input) {
    $out = imm_sections_get_settings();
    if (!is_array($input)) return $out;

    $out['token_url']   = esc_url_raw($input['token_url'] ?? $out['token_url']);
    $out['client_id']   = preg_replace('/[^0-9]/', '', (string)($input['client_id'] ?? $out['client_id']));
    $out['username']    = sanitize_text_field($input['username'] ?? $out['username']);
    $out['password']    = (string)($input['password'] ?? $out['password']); // keep as-is (can contain symbols)
    $out['grant_type']  = sanitize_text_field($input['grant_type'] ?? $out['grant_type']);
    // Strip any query params to keep it as a true base endpoint.
    $out['offers_base'] = imm_sections_clean_base_url(esc_url_raw($input['offers_base'] ?? $out['offers_base']));
    $out['header_client'] = sanitize_text_field($input['header_client'] ?? $out['header_client']);
    $out['token_auth_mode'] = sanitize_text_field($input['token_auth_mode'] ?? $out['token_auth_mode']);
    $out['token_field_grant'] = sanitize_text_field($input['token_field_grant'] ?? $out['token_field_grant']);
    $out['token_field_clientid'] = sanitize_text_field($input['token_field_clientid'] ?? $out['token_field_clientid']);
    $out['token_field_username'] = sanitize_text_field($input['token_field_username'] ?? $out['token_field_username']);
    $out['token_field_password'] = sanitize_text_field($input['token_field_password'] ?? $out['token_field_password']);

    $out['coupon_click_url'] = esc_url_raw($input['coupon_click_url'] ?? $out['coupon_click_url']);

    // SSO iframe origin configuration
    $out['sso_env'] = sanitize_text_field((string)($input['sso_env'] ?? $out['sso_env']));
    $out['sso_subdomain_staging'] = sanitize_text_field((string)($input['sso_subdomain_staging'] ?? $out['sso_subdomain_staging']));
    $out['sso_subdomain_production'] = sanitize_text_field((string)($input['sso_subdomain_production'] ?? $out['sso_subdomain_production']));
    $out['sso_login_path'] = sanitize_text_field((string)($input['sso_login_path'] ?? $out['sso_login_path']));

    return $out;
}

add_action('admin_init', function () {
    register_setting('imm_sections_group', IMM_SECTIONS_OPT, [
        'type'              => 'array',
        'sanitize_callback' => 'imm_sections_save_settings_sanitized',
        'default'           => imm_sections_get_settings(),
    ]);
});

/**
 * When true, IMM offer / find-store shortcodes render nothing for guests.
 * SSO shortcode stays visible when logged out; when logged in it shows a short note + logout link.
 */
function imm_sections_require_login_for_imm_content() {
    return (bool) apply_filters('imm_sections_require_login_for_imm_content', true);
}

function imm_sections_is_wp_login_request() {
    if (!isset($GLOBALS['pagenow'])) return false;
    return $GLOBALS['pagenow'] === 'wp-login.php';
}

function imm_sections_shortcode_guest_returns_empty() {
    // Never gate content in wp-admin or the core login screen.
    if (is_admin() || imm_sections_is_wp_login_request()) return false;
    return imm_sections_require_login_for_imm_content() && !is_user_logged_in();
}

// ------------------------------
// SSO (iframe + postMessage)
// ------------------------------

function imm_sections_jwt_base64url_decode($b64url) {
    $b64url = str_replace(['-', '_'], ['+', '/'], (string)$b64url);
    $pad = strlen($b64url) % 4;
    if ($pad) $b64url .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode($b64url, true);
    return $decoded === false ? null : $decoded;
}

function imm_sections_sso_decode_jwt_payload($jwt) {
    $jwt = trim((string)$jwt);
    // Sometimes servers send "Bearer <token>".
    $jwt = preg_replace('/^Bearer\s+/i', '', $jwt);

    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;

    // Decode all JSON-object-like segments, then score them to pick the real payload
    // (avoid picking the JWT header that usually contains only {alg, typ}).
    $candidates = [];
    foreach ($parts as $i => $seg) {
        $segment_json = imm_sections_jwt_base64url_decode($seg);
        if (!$segment_json) continue;
        $obj = json_decode($segment_json, true);
        if (!is_array($obj)) continue;

        $candidates[] = ['index' => $i, 'obj' => $obj];
    }

    if ($candidates === []) return null;

    $identityKeys = [
        'email', 'Email', 'mail', 'Mail', 'upn', 'UPN',
        'sub', 'Sub', 'subject', 'Subject',
        'preferred_username', 'preferredEmail', 'preferred_email',
        'given_name', 'family_name', 'name'
    ];

    $best = null;
    $bestScore = -9999;

    foreach ($candidates as $cand) {
        $obj = $cand['obj'];
        $keys = array_keys($obj);

        // Strongly penalize header-like objects.
        $isHeaderLike = (in_array('alg', $keys, true) && in_array('typ', $keys, true) && count($keys) <= 3);
        $score = $isHeaderLike ? -1000 : 0;

        // Payload time claims are very common.
        if (isset($obj['nbf']) || isset($obj['exp']) || isset($obj['iat'])) $score += 20;

        // Identity-like claims get higher score.
        foreach ($identityKeys as $k) {
            if (array_key_exists($k, $obj)) {
                $score += 50;
                break;
            }
        }

        // If JWT has 3 parts, prefer the middle segment strongly.
        if (count($parts) === 3 && $cand['index'] === 1) $score += 30;

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $obj;
        }
    }

    return is_array($best) ? $best : null;
}

add_action('wp_ajax_nopriv_imm_sections_sso_exchange_jwt_ajax', function () {
    do_action('wp_ajax_imm_sections_sso_exchange_jwt_ajax');
});

add_action('wp_ajax_imm_sections_sso_exchange_jwt_ajax', function () {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)$_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'imm_sections_sso_exchange_nonce')) {
        wp_send_json_error(['message' => 'Invalid request nonce.'], 403);
    }

    $jwt = isset($_POST['jwt']) ? trim((string)$_POST['jwt']) : '';
    if ($jwt === '') {
        wp_send_json_error(['message' => 'Missing JWT.'], 400);
    }

    // NOTE: For full security you should verify the JWT signature (RS256).
    // We decode payload for now to map the user in WordPress.
    $payload = imm_sections_sso_decode_jwt_payload($jwt);
    if (!$payload) {
        wp_send_json_error(['message' => 'Unable to decode JWT payload.'], 400);
    }

    // Mapping:
    // Your JWT example includes: FName, LName, ShopperID, LoyaltyCard, StoreId, etc.
    // It may NOT include an `email` claim. WordPress still needs an email value.
    //
    // Strategy:
    // 1) If a real email claim exists -> use it.
    // 2) Otherwise synthesize a stable email from ShopperID/LoyaltyCard.
    $email = '';
    $shopperId = '';
    $loyaltyCard = '';
    $storeId = '';
    $storeName = '';

    $shopperIdCandidates = ['ShopperID', 'ShopperId', 'shopperId', 'shopperID'];
    $loyaltyCardCandidates = ['LoyaltyCard', 'loyaltyCard', 'Loyaltycard', 'loyaltycard'];

    foreach ($shopperIdCandidates as $k) {
        if (!array_key_exists($k, $payload)) continue;
        $v = $payload[$k];
        if (is_scalar($v)) {
            $s = preg_replace('/[^0-9]/', '', (string)$v);
            if ($s !== '') {
                $shopperId = $s;
                break;
            }
        }
    }

    foreach ($loyaltyCardCandidates as $k) {
        if (!array_key_exists($k, $payload)) continue;
        $v = $payload[$k];
        if (is_scalar($v)) {
            $s = trim((string)$v);
            if ($s !== '') {
                $loyaltyCard = $s;
                break;
            }
        }
    }

    // Final robustness: if ShopperID key name casing/shape varies, scan all claims.
    if ($shopperId === '') {
        foreach ($payload as $k => $v) {
            $kl = strtolower(trim((string)$k));
            if (strpos($kl, 'shopper') !== false && strpos($kl, 'id') !== false && is_scalar($v)) {
                $s = preg_replace('/[^0-9]/', '', (string)$v);
                if ($s !== '') {
                    $shopperId = $s;
                    break;
                }
            }
        }
    }

    // Store identity (used for header display; comes from JWT).
    $storeIdCandidates = ['StoreId', 'StoreID', 'storeId', 'storeID', 'storeid', 'store_id'];
    foreach ($storeIdCandidates as $k) {
        if (!array_key_exists($k, $payload)) continue;
        $v = $payload[$k];
        if (is_scalar($v)) {
            $s = preg_replace('/[^0-9]/', '', (string)$v);
            if ($s !== '') {
                $storeId = $s;
                break;
            }
        }
    }

    $storeNameCandidates = ['StoreName', 'storeName', 'StoreDisplayName', 'storeDisplayName', 'store_name', 'StoreDisplay', 'Store'];
    foreach ($storeNameCandidates as $k) {
        if (!array_key_exists($k, $payload)) continue;
        $v = $payload[$k];
        if (is_scalar($v)) {
            $s = sanitize_text_field((string)$v);
            if ($s !== '') {
                $storeName = $s;
                break;
            }
        }
    }

    // Case-insensitive fallback (some IdPs vary claim casing).
    if ($storeId === '' || $storeName === '') {
        foreach ($payload as $k => $v) {
            $kl = strtolower(trim((string)$k));
            if ($storeId === '' && ($kl === 'storeid' || $kl === 'store_id')) {
                if (is_scalar($v)) {
                    $s = preg_replace('/[^0-9]/', '', (string)$v);
                    if ($s !== '') $storeId = $s;
                }
            }
            if ($storeName === '' && ($kl === 'storename' || $kl === 'store_name' || $kl === 'storedisplayname' || $kl === 'store_display_name')) {
                if (is_scalar($v)) {
                    $s = sanitize_text_field((string)$v);
                    if ($s !== '') $storeName = $s;
                }
            }
            if ($storeId !== '' && $storeName !== '') break;
        }
    }

    // Final robustness: scan for any key that "looks like" store id/name.
    if ($storeId === '' || $storeName === '') {
        foreach ($payload as $k => $v) {
            $kl = strtolower(trim((string)$k));
            if ($storeId === '' && (strpos($kl, 'store') !== false) && (strpos($kl, 'id') !== false) && is_scalar($v)) {
                $s = preg_replace('/[^0-9]/', '', (string)$v);
                if ($s !== '') $storeId = $s;
            }
            if ($storeName === '' && (strpos($kl, 'store') !== false) && (strpos($kl, 'name') !== false) && is_scalar($v)) {
                $s = sanitize_text_field((string)$v);
                if ($s !== '') $storeName = $s;
            }
            if ($storeId !== '' && $storeName !== '') break;
        }
    }

    // Try multiple common email claim keys and only accept values that are real emails.
    $email_candidates = [
        'email',
        'Email',
        'mail',
        'Mail',
        'upn',
        'UPN',
        'email_address',
        'EmailAddress',
        'preferred_username',
        'preferredEmail',
        'preferred_email',
        'sub',
        'Sub',
    ];

    foreach ($email_candidates as $k) {
        if (!array_key_exists($k, $payload)) continue;
        $v = $payload[$k];
        if (is_array($v)) {
            // Common pattern: emails: ["a@b.com", ...]
            if (!empty($v)) {
                $first = reset($v);
                if (is_string($first) && $first !== '' && is_email($first)) {
                    $email = $first;
                    break;
                }
            }
            continue;
        }
        if (is_string($v) && $v !== '' && is_email($v)) {
            $email = $v;
            break;
        }
    }

    // Special case: some providers use `emails: [...]` specifically.
    if ($email === '' && !empty($payload['emails']) && is_array($payload['emails'])) {
        foreach ($payload['emails'] as $item) {
            if (is_string($item) && $item !== '' && is_email($item)) {
                $email = $item;
                break;
            }
        }
    }

    // If email missing, synthesize one so we can create/lookup WP user.
    if ($email === '') {
        if ($shopperId !== '') {
            $email = 'sso_shopper_' . $shopperId . '@sso.local';
        } elseif ($loyaltyCard !== '') {
            $safe = preg_replace('/[^0-9A-Za-z]/', '', $loyaltyCard);
            $email = 'sso_loyalty_' . $safe . '@sso.local';
        } else {
            wp_send_json_error([
                'message' => 'JWT does not contain email and no usable ShopperID/LoyaltyCard was found.',
                'keys' => array_keys($payload),
            ], 400);
        }
    }

    if (!is_email($email)) {
        wp_send_json_error([
            'message' => 'Synthesized/parsed email is not a valid email format.',
            'email' => $email,
            'keys' => array_keys($payload),
        ], 400);
    }

    // Build stable WP login for lookup.
    $user_login = '';
    if ($shopperId !== '') {
        $user_login = 'sso_shopper_' . $shopperId;
    } else {
        $user_login = sanitize_user(str_replace('@', '_', $email), true);
    }
    if ($user_login === '') {
        $user_login = 'sso_user_' . wp_generate_password(6, false, false);
    }

    $user = get_user_by('login', $user_login);
    if (!$user) {
        $user = get_user_by('email', $email);
    }
    if (!$user) {
        // Auto-create user (common for SSO)
        $user_id = wp_insert_user([
            'user_login' => $user_login,
            'user_email' => $email,
            'role' => 'subscriber',
            'user_pass' => wp_generate_password(24, true, true),
        ]);

        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => 'Unable to create WP user: ' . $user_id->get_error_message()], 500);
        }
        $user = get_user_by('id', (int)$user_id);
    }

    if (!$user) {
        wp_send_json_error(['message' => 'Unable to resolve WP user.'], 400);
    }

    if ($shopperId !== '') {
        update_user_meta($user->ID, 'imm_shopper_id', $shopperId);
    }
    if ($loyaltyCard !== '') {
        update_user_meta($user->ID, 'imm_loyalty_card', sanitize_text_field($loyaltyCard));
    }
    if ($storeId !== '') {
        update_user_meta($user->ID, 'imm_store_id', $storeId);
    }
    if ($storeName !== '') {
        update_user_meta($user->ID, 'imm_store_name', $storeName);
    }

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    wp_send_json_success([
        'message' => 'Login successful.',
        'redirect' => isset($_POST['redirect']) ? esc_url_raw((string)$_POST['redirect']) : '',
    ]);
});

add_shortcode('imm_sso_login', function ($atts) {
    if (is_user_logged_in()) {
        $out = '<p class="imm-sso-logged-in" style="margin:0;font-size:14px;line-height:1.4;">'
            . esc_html__('You are signed in.', 'imm-sections')
            . ' <a href="' . esc_url(imm_sections_direct_logout_url(home_url('/'))) . '">'
            . esc_html__('Log out', 'imm-sections')
            . '</a></p>';
        return $out;
    }
    $default_redirect = home_url('/');
    $atts = shortcode_atts([
        'title' => 'Sign In',
        'id' => '',
        // User asked to show the login directly on page load.
        // Default: hide the button and auto-open the iframe modal.
        'show_button' => '0',
        'auto_open' => '1',
        'redirect' => $default_redirect,
    ], $atts, 'imm_sso_login');

    $title = sanitize_text_field((string)$atts['title']);
    $extra_id = trim((string)$atts['id']);
    $show_button = ((string)$atts['show_button'] !== '0');
    $auto_open = ((string)($atts['auto_open'] ?? '0') === '1');
    $redirect = esc_url_raw((string)$atts['redirect']);
    if ($redirect === '') $redirect = $default_redirect;

    $nonce = wp_create_nonce('imm_sections_sso_exchange_nonce');

    $sso_env = sanitize_text_field((string)(imm_sections_get_settings()['sso_env'] ?? 'staging'));
    $k = imm_sections_get_settings();
    $staging_sub = sanitize_text_field((string)($k['sso_subdomain_staging'] ?? ''));
    $prod_sub = sanitize_text_field((string)($k['sso_subdomain_production'] ?? ''));
    $login_path = sanitize_text_field((string)($k['sso_login_path'] ?? '/sso-login'));

    // Normalize subdomain to an origin (scheme + host).
    $to_origin = function (string $sub) : string {
        $sub = trim($sub);
        if ($sub === '') return '';
        if (stripos($sub, 'http://') === 0 || stripos($sub, 'https://') === 0) {
            $u = wp_parse_url($sub);
            if (!is_array($u) || empty($u['host'])) return $sub;
            $scheme = isset($u['scheme']) ? $u['scheme'] : 'https';
            return $scheme . '://' . $u['host'];
        }
        return 'https://' . $sub;
    };

    $staging_origin = $to_origin($staging_sub);
    $prod_origin = $to_origin($prod_sub);

    $use_origin = ($sso_env === 'production' && $prod_origin !== '') ? $prod_origin : $staging_origin;
    $iframe_src = $use_origin . '/' . ltrim($login_path, '/');

    // Must match window.parent.postMessage(..., "*") — event.origin is the iframe URL origin.
    $allowed_origins = array_values(array_filter([$staging_origin, $prod_origin]));
    if ($iframe_src === '/' || $iframe_src === 'https:///') {
        // Fallback to staging_origin if env config is missing.
        $iframe_src = $staging_origin . '/' . ltrim($login_path, '/');
    }

    $wrap_id = $extra_id !== '' ? sanitize_key($extra_id) : 'imm_sso_' . wp_generate_password(8, false, false);

    ob_start();
    ?>
    <div class="imm-sso-inline-wrap" id="<?php echo esc_attr($wrap_id); ?>" data-imm-sso-redirect="<?php echo esc_attr($redirect); ?>">
      <?php if ($show_button) { ?>
        <button type="button" class="imm-sso-open-btn" style="margin:0 0 12px 0"><?php echo esc_html($title); ?></button>
      <?php } ?>

      <div class="imm-sso-iframe-holder" style="<?php echo $show_button ? 'display:none;' : ''; ?>">
        <iframe
          id="imm-sso-iframe-<?php echo esc_attr($wrap_id); ?>"
          src="<?php echo esc_url($iframe_src); ?>"
          style="width:100%;height:720px;max-height:90vh;border:0;display:block;overflow:hidden"
          allow="clipboard-read; clipboard-write"
        ></iframe>
      </div>
      <div
        id="imm-sso-status-<?php echo esc_attr($wrap_id); ?>"
        style="font-size:13px;color:#0b3557;margin-top:10px;line-height:1.4;word-break:break-word;background:#f8fafc;border:1px solid #dbe3ee;border-radius:8px;padding:10px 12px;min-height:44px"
      >SSO: initializing...</div>
    </div>
    <script>
      (function() {
        var wrap = document.getElementById('<?php echo esc_js($wrap_id); ?>');
        if (!wrap) return;
        var openBtn = wrap.querySelector('.imm-sso-open-btn');
        var holder = wrap.querySelector('.imm-sso-iframe-holder');
        var iframe = document.getElementById('imm-sso-iframe-<?php echo esc_js($wrap_id); ?>');
        if (!iframe) return;

        var allowedOrigins = <?php echo wp_json_encode($allowed_origins); ?>;
        var exchangeNonce = <?php echo wp_json_encode($nonce); ?>;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var redirect = wrap.getAttribute('data-imm-sso-redirect') || '';
        var statusEl = document.getElementById('imm-sso-status-<?php echo esc_js($wrap_id); ?>');
        var ssoHomeUrl = <?php echo wp_json_encode(home_url('/')); ?>;
        // Provider contract: only accept postMessage from iframe + allowed origins (see Rperks SSO doc).
        var DEBUG_RELAX_ORIGIN_SOURCE = false;
        var WP_AJAX_ATTEMPTS = 0;
        var WP_AJAX_MAX_ATTEMPTS = 10;
        var NO_JWT_DEBUG_LOGS_LEFT = 3; // avoid flooding console
        var FROM_IFRAME_DEBUG_LOGS_LEFT = 5; // capture actual iframe payloads

        if (statusEl) statusEl.textContent = 'SSO: waiting for iframe to load...';

        function tokenPreview(token) {
          if (typeof token !== 'string') return '';
          var t = token.trim();
          if (t.length <= 24) return t;
          return t.slice(0, 16) + '...' + t.slice(-8);
        }

        function isProbablyJwtString(s) {
          if (typeof s !== 'string') return false;
          var str = s.trim();
          // Normalize common "Bearer <token>" wrapper (server-side already does this too).
          str = str.replace(/^Bearer\s+/i, '');
          // Some providers wrap tokens in URL-encoded strings or include whitespace.
          str = str.replace(/\s+/g, '');
          if (str.indexOf('%') !== -1) {
            try {
              str = decodeURIComponent(str);
            } catch (e) {
              // If decoding fails, keep original string.
            }
          }
          // If token is accidentally wrapped in quotes, strip them.
          if ((str[0] === '"' && str[str.length - 1] === '"') || (str[0] === "'" && str[str.length - 1] === "'")) {
            str = str.slice(1, -1).trim();
          }
          if (str.length < 10) return false;
          // JWT/JWS compact tokens normally have 3 dot-separated segments.
          // Be tolerant: we only require 2 dots + base64url-ish characters per segment.
          var parts = str.split('.');
          if (parts.length < 3) return false;
          if ((str.match(/\./g) || []).length < 2) return false;
          for (var i = 0; i < parts.length; i++) {
            // Allow both base64url and base64-ish segments (some providers differ).
            // base64url: [A-Za-z0-9_-], base64: [A-Za-z0-9+\/]
            if (!/^[A-Za-z0-9_\-+\/]+={0,2}$/.test(parts[i])) return false;
          }
          return true;
        }

        // Robustly extract JWT-like string from nested message objects.
        function extractJwtFromAnyShape(obj, maxDepth) {
          maxDepth = (typeof maxDepth === 'number') ? maxDepth : 5;
          var seen = 0;
          function walk(x, depth) {
            if (seen > 500) return null;
            seen++;
            if (depth > maxDepth) return null;
            if (!x) return null;
            if (typeof x === 'string') {
              // Return a token candidate as soon as it looks compact-token-like.
              // We still validate it later before sending to WordPress.
              var s = x.trim();
              s = s.replace(/^Bearer\s+/i, '');
              s = s.replace(/\s+/g, '');
              if (s.indexOf('%') !== -1) {
                try {
                  s = decodeURIComponent(s);
                } catch (e) {
                  // keep original
                }
              }
              if ((s[0] === '"' && s[s.length - 1] === '"') || (s[0] === "'" && s[s.length - 1] === "'")) {
                s = s.slice(1, -1).trim();
              }
              var parts = s.split('.');
              if (s.length > 20 && parts.length >= 3 && (s.match(/\./g) || []).length >= 2) {
                return s;
              }
              return null;
            }
            if (Array.isArray(x)) {
              for (var i = 0; i < x.length; i++) {
                var r = walk(x[i], depth + 1);
                if (r) return r;
              }
              return null;
            }
            if (typeof x === 'object') {
              var keys = Object.keys(x);
              for (var j = 0; j < keys.length; j++) {
                var k = keys[j];
                var r2 = walk(x[k], depth + 1);
                if (r2) return r2;
              }
              return null;
            }
            return null;
          }
          return walk(obj, 0);
        }

        // Loose extractor: find a string value for keys that look like token/jwt.
        function extractTokenLikeFromAnyKey(obj, maxDepth) {
          maxDepth = (typeof maxDepth === 'number') ? maxDepth : 5;
          var seen = 0;
          var tokenKeyRe = /(jwt|token|access_token|id_token|authorization)/i;
          function walk(x, depth) {
            if (seen > 500) return null;
            seen++;
            if (depth > maxDepth) return null;
            if (!x) return null;

            if (typeof x === 'object') {
              // Arrays: walk items (no key name to match).
              if (Array.isArray(x)) {
                for (var i = 0; i < x.length; i++) {
                  var rArr = walk(x[i], depth + 1);
                  if (rArr) return rArr;
                }
                return null;
              }

              var keys = Object.keys(x);
              for (var j = 0; j < keys.length; j++) {
                var k = keys[j];
                var v = x[k];
                if (tokenKeyRe.test(k) && typeof v === 'string' && v.trim().length > 20) {
                  return v.trim();
                }
                var rObj = walk(v, depth + 1);
                if (rObj) return rObj;
              }
              return null;
            }

            return null;
          }

          return walk(obj, 0);
        }

        function openHolder() {
          if (!holder) return;
          holder.style.display = 'block';
        }

        var autoOpen = <?php echo $auto_open ? 'true' : 'false'; ?>;
        if (autoOpen) openHolder();

        // Listener must be on the parent window before login completes (messages use event.origin from iframe).
        iframe.addEventListener('load', function() {
          console.log('[imm-sso] iframe loaded — ready for postMessage from', '<?php echo esc_js($iframe_src); ?>');
          if (statusEl) statusEl.textContent = 'SSO: sign-in form ready. Please complete login in iframe.';
        });

        function isAllowed(event) {
          if (!event || !event.origin) return false;
          return allowedOrigins.indexOf(event.origin) !== -1 && event.source === iframe.contentWindow;
        }

        window.addEventListener('message', function(event) {
          try {
            var data = event.data || {};
            var type = data && (data.type || data.messageType || data.eventType);
            var jwt = null;
            var tokenWasLoose = false;
            var fromIframe = (iframe && event.source === iframe.contentWindow);
            var originAllowed = allowedOrigins.indexOf(event.origin) !== -1;

            // Ignore same-page / extension noise: real SSO messages use iframe origin (e.g. ridstaging.immdemo.net).
            if (!DEBUG_RELAX_ORIGIN_SOURCE && !isAllowed(event)) {
              return;
            }

            // Provider: window.parent.postMessage({ type: "AUTH_SUCCESS", jwt: jwtToken }, "*");
            if (data && data.type === 'AUTH_SUCCESS' && typeof data.jwt === 'string') {
              jwt = data.jwt;
            }

            // Fast-path: common direct shapes.
            if (!jwt && data && typeof data.jwt === 'string') jwt = data.jwt;
            if (!jwt && data && typeof data.token === 'string') jwt = data.token;
            if (!jwt && data && typeof data.access_token === 'string') jwt = data.access_token;
            if (!jwt && data && data.data && typeof data.data.jwt === 'string') jwt = data.data.jwt;
            if (!jwt && data && data.payload && typeof data.payload.jwt === 'string') jwt = data.payload.jwt;

            // Fallback: scan nested object for JWT-like string.
            if (!jwt) jwt = extractJwtFromAnyShape(data, 12);
            if (!jwt) {
              // If it's not a strict JWT-like string, try to capture an opaque token anyway.
              var tokenCandidate = extractTokenLikeFromAnyKey(data, 12);
              if (tokenCandidate) {
                jwt = tokenCandidate;
                tokenWasLoose = true;
              }
            }

            console.log('[imm-sso] message', {
              origin: event.origin,
              type: type,
              typeFields: {
                type: data && data.type,
                messageType: data && data.messageType,
                eventType: data && data.eventType,
              },
              hasJwt: !!jwt,
              fromIframe: fromIframe,
              originAllowed: originAllowed,
              tokenWasLoose: tokenWasLoose,
              dataKeys: (data && typeof data === 'object') ? Object.keys(data) : [],
            });

            // If we truly receive the provider message from inside the iframe,
            // log the raw payload so we can update the extractor.
            if (fromIframe && FROM_IFRAME_DEBUG_LOGS_LEFT > 0) {
              FROM_IFRAME_DEBUG_LOGS_LEFT--;
              console.log('[imm-sso] from iframe postMessage data', data);
            }

            if (!jwt && NO_JWT_DEBUG_LOGS_LEFT > 0 && (fromIframe || originAllowed)) {
              NO_JWT_DEBUG_LOGS_LEFT--;
              console.log('[imm-sso] raw postMessage data (no jwt) [fromIframe/originAllowed]', data);
            }

            // Always show what we received (helps debug even if jwt extraction fails).
            if (statusEl) {
              statusEl.textContent =
                'SSO msg: origin=' + (event.origin || '') +
                ', type=' + (type || 'unknown') +
                ', hasJwt=' + (!!jwt);
            }

            if (typeof jwt !== 'string') return;
            jwt = jwt.trim();
            jwt = jwt.replace(/^Bearer\s+/i, '');
            jwt = jwt.replace(/\s+/g, '');
            if (jwt.indexOf('%') !== -1) {
              try { jwt = decodeURIComponent(jwt); } catch (e) {}
            }
            // If we found it via loose token extraction, still attempt exchange,
            // because backend can tell us "not a JWT format" explicitly.
            if (!tokenWasLoose && !isProbablyJwtString(jwt)) return;

            if (statusEl) {
              statusEl.textContent =
                'SSO: JWT received. type=' + (type || 'unknown') +
                ', len=' + jwt.length +
                ', preview=' + tokenPreview(jwt);
            }

            if (WP_AJAX_ATTEMPTS >= WP_AJAX_MAX_ATTEMPTS) {
              // Avoid infinite retries if provider keeps sending the same payload.
              return;
            }
          } catch (e) {
            console.error('[imm-sso] message handler error', e);
            return;
          }

          // Exchange JWT with WordPress to create login session.
          var body = new URLSearchParams({
            action: 'imm_sections_sso_exchange_jwt_ajax',
            nonce: exchangeNonce,
            jwt: jwt,
            redirect: redirect
          });
          WP_AJAX_ATTEMPTS++;
          console.log('[imm-sso] sending WP ajax', {
            action: 'imm_sections_sso_exchange_jwt_ajax',
            jwtLen: (jwt && typeof jwt === 'string') ? jwt.length : null,
            attempt: WP_AJAX_ATTEMPTS
          });
          if (statusEl) {
            statusEl.textContent =
              'SSO: sending token to WP AJAX... attempt=' + WP_AJAX_ATTEMPTS +
              ', tokenPreview=' + tokenPreview(jwt);
          }
          fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
          })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res && res.success) {
              if (statusEl) statusEl.textContent = 'SSO complete: WordPress login success.';
              // Always redirect to homepage on success.
              if (ssoHomeUrl) window.location.href = ssoHomeUrl;
              return;
            }
            console.error('SSO exchange failed:', res);
            if (statusEl) {
              statusEl.textContent =
                'SSO failed: ' + (res && res.data && res.data.message ? res.data.message : 'unknown error');
            }
          })
          .catch(function(err) {
            console.error('SSO exchange error:', err);
            if (statusEl) statusEl.textContent = 'SSO failed: ajax/network error';
          });
        });
      })();
    </script>
    <?php
    return ob_get_clean();
});

class IMM_SSO_Login_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'imm_sso_login_widget',
            'IMM SSO Login',
            ['description' => 'SSO login via iframe + postMessage']
        );
    }

    public function form($instance) {
        $title = isset($instance['title']) ? (string)$instance['title'] : 'Sign In';
        ?>
        <p>
          <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">Title</label>
          <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field((string)($new_instance['title'] ?? 'Sign In'));
        return $instance;
    }

    public function widget($args, $instance) {
        $title = isset($instance['title']) ? (string)$instance['title'] : 'Sign In';
        $redirect = home_url('/');
        echo $args['before_widget'];
        echo do_shortcode('[imm_sso_login title="' . esc_attr($title) . '" redirect="' . esc_attr($redirect) . '" show_button="0" auto_open="1"]');
        echo $args['after_widget'];
    }
}

add_action('widgets_init', function () {
    register_widget('IMM_SSO_Login_Widget');
});

/**
 * Menu locations that receive automatic Sign in / Log out links.
 * Filter: imm_sections_login_logout_menu_locations — pass array of theme_location slugs, or [] to disable.
 * Filter: imm_sections_sign_in_url — URL for guests (default /login/).
 * Filter: imm_sections_login_logout_all_menus — when true, inject for all frontend menus.
 */
function imm_sections_login_logout_menu_locations() {
    $default = ['Header-desk-menus'];
    return apply_filters('imm_sections_login_logout_menu_locations', $default);
}

function imm_sections_sign_in_url() {
    return apply_filters('imm_sections_sign_in_url', home_url('/login/'));
}

/**
 * Logout URL that completes immediately (no wp-login.php "Are you sure?" screen).
 * Uses a front-end handler with a fresh nonce on every page load.
 */
function imm_sections_direct_logout_url($redirect_to = '') {
    $url = add_query_arg('imm_logout', '1', home_url('/'));
    // Build raw nonce param (do not use wp_nonce_url here because it HTML-escapes & to &amp;).
    return add_query_arg('_wpnonce', wp_create_nonce('imm_sections_logout'), $url);
}

add_action('template_redirect', function () {
    if (!isset($_GET['imm_logout']) || (string) $_GET['imm_logout'] !== '1') {
        return;
    }
    if (is_admin() || imm_sections_is_wp_login_request()) {
        return;
    }
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field((string) $_GET['_wpnonce']) : '';
    if (!wp_verify_nonce($nonce, 'imm_sections_logout')) {
        wp_die(esc_html__('Invalid logout link.', 'imm-sections'), '', ['response' => 403]);
    }
    // Always send users to the homepage after logout (ignores redirect_to).
    $redirect = home_url('/');
    if (!is_user_logged_in()) {
        wp_safe_redirect($redirect);
        exit;
    }
    wp_logout();
    wp_safe_redirect($redirect);
    exit;
}, 1);

function imm_sections_login_logout_all_menus() {
    // Keep this disabled by default to avoid duplicate auth tabs in multiple menus.
    return (bool) apply_filters('imm_sections_login_logout_all_menus', false);
}

function imm_sections_menu_should_inject($args) {
    if (imm_sections_login_logout_all_menus()) return true;
    $locations = imm_sections_login_logout_menu_locations();
    if ($locations === []) return false;
    $normalized_locations = array_map(function ($v) {
        return sanitize_title((string)$v);
    }, $locations);
    if (!empty($args->theme_location)) {
        $theme_loc = (string)$args->theme_location;
        if (in_array($theme_loc, $locations, true) || in_array(sanitize_title($theme_loc), $normalized_locations, true)) {
            return true;
        }
    }
    // Fallback: many themes pass menu name/slug instead of expected location slug.
    if (!empty($args->menu)) {
        $menu_obj = is_object($args->menu) ? $args->menu : wp_get_nav_menu_object($args->menu);
        $menu_name = is_object($menu_obj) ? (string)($menu_obj->name ?? '') : (string)$args->menu;
        $menu_slug = is_object($menu_obj) ? (string)($menu_obj->slug ?? '') : sanitize_title((string)$args->menu);
        foreach ($locations as $loc) {
            if (strcasecmp((string)$loc, $menu_name) === 0 || sanitize_title((string)$loc) === sanitize_title($menu_slug)) {
                return true;
            }
        }
    }
    return false;
}

function imm_sections_is_auth_menu_item($item) {
    $title = strtolower(trim(wp_strip_all_tags((string)($item->title ?? ''))));
    $url = strtolower(trim((string)($item->url ?? '')));
    $auth_titles = ['sign in', 'signin', 'login', 'log in', 'log out', 'logout'];
    if (in_array($title, $auth_titles, true)) {
        return true;
    }
    if (strpos($url, '/login') !== false || strpos($url, 'wp-login.php') !== false || strpos($url, 'action=logout') !== false) {
        return true;
    }
    return false;
}

function imm_sections_is_header_quicklinks_html($items_html) {
    $html = strtolower(wp_strip_all_tags((string)$items_html));
    $needles = ['rperks', 'specials', 'pharmacy', 'recipes'];
    $hits = 0;
    foreach ($needles as $n) {
        if (strpos($html, $n) !== false) $hits++;
    }
    return $hits >= 2;
}

function imm_sections_is_header_quicklinks_objects($sorted_menu_items) {
    if (!is_array($sorted_menu_items)) return false;
    $needles = ['rperks', 'specials', 'pharmacy', 'recipes'];
    $hits = 0;
    foreach ($sorted_menu_items as $item) {
        $title = strtolower(trim(wp_strip_all_tags((string)($item->title ?? ''))));
        if ($title === '') continue;
        foreach ($needles as $n) {
            if (strpos($title, $n) !== false) {
                $hits++;
                break;
            }
        }
    }
    return $hits >= 2;
}

add_filter('wp_nav_menu_items', function ($items, $args) {
    // Do not affect wp-admin or wp-login.php behavior.
    if (is_admin() || imm_sections_is_wp_login_request()) {
        return $items;
    }
    $should_inject = imm_sections_menu_should_inject($args) || imm_sections_is_header_quicklinks_html($items);
    if (!$should_inject) {
        return $items;
    }
    // If our auth item already exists in this menu output, do not append another one.
    if (stripos((string)$items, 'imm-sso-menu-login') !== false || stripos((string)$items, 'imm-sso-menu-logout') !== false) {
        return $items;
    }
    if (is_user_logged_in()) {
        $items .= '<li class="menu-item imm-sso-menu-logout"><a href="' . esc_url(imm_sections_direct_logout_url(home_url('/'))) . '">' . esc_html__('Log out', 'imm-sections') . '</a></li>';
    } else {
        $items .= '<li class="menu-item imm-sso-menu-login"><a href="' . esc_url(imm_sections_sign_in_url()) . '">' . esc_html__('Sign in', 'imm-sections') . '</a></li>';
    }
    return $items;
}, 10, 2);

// Replace existing "Sign In/Login" item with "Log out" in the same menu position.
add_filter('wp_nav_menu_objects', function ($sorted_menu_items, $args) {
    if (is_admin() || imm_sections_is_wp_login_request()) return $sorted_menu_items;
    $should_inject = imm_sections_menu_should_inject($args) || imm_sections_is_header_quicklinks_objects($sorted_menu_items);
    if (!$should_inject) return $sorted_menu_items;

    $found_auth_item = false;
    foreach ($sorted_menu_items as $item) {
        if (!imm_sections_is_auth_menu_item($item)) {
            continue;
        }
        $found_auth_item = true;
        if (is_user_logged_in()) {
            $item->title = esc_html__('Log out', 'imm-sections');
            $item->url = esc_url(imm_sections_direct_logout_url(home_url('/')));
            $item->classes = is_array($item->classes) ? array_merge($item->classes, ['imm-sso-menu-logout']) : ['imm-sso-menu-logout'];
        } else {
            $item->title = esc_html__('Sign in', 'imm-sections');
            $item->url = esc_url(imm_sections_sign_in_url());
            $item->classes = is_array($item->classes) ? array_merge($item->classes, ['imm-sso-menu-login']) : ['imm-sso-menu-login'];
        }
        break;
    }

    // Do not synthesize object items here; HTML-level append is handled in wp_nav_menu_items.
    // Some custom walkers/themes can skip synthetic objects intermittently (e.g. query-string variants).
    return $sorted_menu_items;
}, 20, 2);

add_action('wp_head', function () {
    if (is_admin() || imm_sections_is_wp_login_request()) return;
    echo '<style id="imm-sso-menu-auth-style">'
        . '.imm-sso-menu-login > a,.imm-sso-menu-logout > a{font-size:13px !important;color:#fff !important;display:inline-flex !important;align-items:center !important;height:100% !important;line-height:50px !important;font-family:"DM Sans",Sans-serif !important;font-weight:normal !important;padding:0 !important;opacity:1 !important;visibility:visible !important;text-indent:0 !important;white-space:nowrap !important;}'
        . '.imm-sso-menu-login > a:empty::before{content:"Sign in";}'
        . '.imm-sso-menu-login > a:hover,.imm-sso-menu-logout > a:hover{color:#fff !important;}'
        . '@media (max-width: 767px){.imm-sso-menu-login > a,.imm-sso-menu-logout > a{color:#6a7283 !important;}.imm-sso-menu-login > a:hover,.imm-sso-menu-logout > a:hover{color:#6a7283 !important;}}'
        . '</style>';
}, 99);

add_action('wp_ajax_nopriv_imm_sections_auth_state_ajax', function () {
    do_action('wp_ajax_imm_sections_auth_state_ajax');
});

add_action('wp_ajax_imm_sections_auth_state_ajax', function () {
    wp_send_json_success([
        'logged_in' => is_user_logged_in(),
        'sign_in_url' => esc_url_raw(imm_sections_sign_in_url()),
        'log_out_url' => esc_url_raw(imm_sections_direct_logout_url(home_url('/'))),
        'label' => is_user_logged_in() ? 'Log out' : 'Sign in',
    ]);
});

add_action('wp_footer', function () {
    if (is_admin() || imm_sections_is_wp_login_request()) return;
    $ajax_url = esc_url_raw(admin_url('admin-ajax.php'));
    ?>
    <script>
    (function () {
      var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
      var authItemSelector = '.imm-sso-menu-login,.imm-sso-menu-logout';
      var quickLabels = ['rperks', 'specials', 'pharmacy', 'recipes'];

      function getQuickLinksMenus() {
        var candidates = document.querySelectorAll('nav ul, .elementor-nav-menu, .menu');
        var out = [];
        for (var i = 0; i < candidates.length; i++) {
          var el = candidates[i];
          var txt = (el.textContent || '').toLowerCase();
          var hits = 0;
          for (var j = 0; j < quickLabels.length; j++) {
            if (txt.indexOf(quickLabels[j]) !== -1) hits++;
          }
          if (hits >= 2) out.push(el);
        }
        return out;
      }

      function ensureAuthItem(menuEl, state) {
        if (!menuEl || !state) return;
        var item = menuEl.querySelector(authItemSelector);
        if (!item) {
          item = document.createElement('li');
          item.className = 'menu-item';
          var a = document.createElement('a');
          item.appendChild(a);
          menuEl.appendChild(item);
        }
        item.classList.remove('imm-sso-menu-login', 'imm-sso-menu-logout');
        item.classList.add(state.logged_in ? 'imm-sso-menu-logout' : 'imm-sso-menu-login');
        var link = item.querySelector('a');
        if (!link) {
          link = document.createElement('a');
          item.appendChild(link);
        }
        link.textContent = state.logged_in ? 'Log out' : 'Sign in';
        link.setAttribute('href', state.logged_in ? state.log_out_url : state.sign_in_url);
      }

      function syncAuthMenu() {
        var url = ajaxUrl + '?action=imm_sections_auth_state_ajax&_=' + Date.now();
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (json) {
            if (!json || !json.success || !json.data) return;
            var menus = getQuickLinksMenus();
            for (var i = 0; i < menus.length; i++) {
              ensureAuthItem(menus[i], json.data);
            }
          })
          .catch(function () {});
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncAuthMenu);
      } else {
        syncAuthMenu();
      }
      window.addEventListener('load', syncAuthMenu);
      document.addEventListener('visibilitychange', function () {
        if (!document.hidden) syncAuthMenu();
      });
      setInterval(syncAuthMenu, 30000);
    })();
    </script>
    <?php
}, 100);

function imm_sections_current_shopper_id() {
    if (!is_user_logged_in()) {
        return '';
    }
    $uid = (int) get_current_user_id();
    // SSO exchange stores the real IMM id here — prefer it over parsing user_login
    // (users resolved by email may not use the sso_shopper_* login pattern).
    $meta_id = get_user_meta($uid, 'imm_shopper_id', true);
    $meta_id = preg_replace('/[^0-9]/', '', (string)$meta_id);
    if ($meta_id !== '') {
        return $meta_id;
    }
    $user = wp_get_current_user();
    if ($user && !empty($user->user_login)) {
        if (preg_match('/sso_shopper_(\d+)/', (string)$user->user_login, $m)) {
            return preg_replace('/[^0-9]/', '', (string)$m[1]);
        }
    }
    return '';
}

/**
 * Full-page caches (e.g. reverse proxies) must not serve one shopper's HTML to another.
 */
function imm_sections_mark_page_non_cacheable_for_personalized_content() {
    if (!is_user_logged_in()) {
        return;
    }
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
}

/**
 * Headers for IMM Offers GET (CollectionOffers / Offers). Some gateways respect ShopperID only in headers.
 *
 * @param array<string,string> $base Existing headers (Accept, Authorization, Client header, ...).
 * @param string $shopper_id Numeric shopper id or ''.
 * @return array<string,string>
 */
function imm_sections_imm_offers_merge_shopper_header(array $base, $shopper_id) {
    $sid = preg_replace('/[^0-9]/', '', (string)$shopper_id);
    if ($sid !== '') {
        $base['ShopperID'] = $sid;
    }
    return $base;
}

/**
 * Segment offer transients per logged-in user / shopper so cached HTML is not shared across shoppers.
 */
function imm_sections_imm_offer_cache_scope() {
    $sid = imm_sections_current_shopper_id();
    if ($sid !== '') {
        return 'sid:' . $sid;
    }
    if (is_user_logged_in()) {
        return 'uid:' . (int) get_current_user_id();
    }
    return 'guest';
}

function imm_kacu_save_settings_sanitized($input) {
    $out = imm_kacu_get_settings();
    if (!is_array($input)) return $out;

    $out['base_url']     = esc_url_raw($input['base_url'] ?? $out['base_url']);
    $out['token_url']    = esc_url_raw($input['token_url'] ?? $out['token_url']);
    $out['offers_url']   = esc_url_raw($input['offers_url'] ?? $out['offers_url']);
    $out['activate_url'] = esc_url_raw($input['activate_url'] ?? $out['activate_url']);

    $out['apikey']    = sanitize_text_field($input['apikey'] ?? $out['apikey']);
    $out['apisecret'] = sanitize_text_field($input['apisecret'] ?? $out['apisecret']);
    $out['clientid']  = sanitize_text_field($input['clientid'] ?? $out['clientid']);

    $out['companyid'] = preg_replace('/[^0-9]/', '', (string)($input['companyid'] ?? $out['companyid']));
    $out['storeid']   = preg_replace('/[^0-9]/', '', (string)($input['storeid'] ?? $out['storeid']));
    $out['loyaltycard'] = preg_replace('/[^0-9]/', '', (string)($input['loyaltycard'] ?? $out['loyaltycard']));

    $out['shopperprofileid'] = preg_replace('/[^0-9]/', '', (string)($input['shopperprofileid'] ?? $out['shopperprofileid']));
    // ShopperID is never configured here — it comes from SSO JWT at login only.
    $out['shopperid'] = '';

    $out['stores_lookup_url'] = esc_url_raw($input['stores_lookup_url'] ?? $out['stores_lookup_url']);
    $out['shopper_update_store_url'] = esc_url_raw($input['shopper_update_store_url'] ?? $out['shopper_update_store_url']);
    $out['stores_bearer_token'] = trim((string)($input['stores_bearer_token'] ?? $out['stores_bearer_token']));
    $out['shopper_update_bearer_token'] = trim((string)($input['shopper_update_bearer_token'] ?? $out['shopper_update_bearer_token']));
    $out['google_geocode_api_key'] = trim((string)($input['google_geocode_api_key'] ?? $out['google_geocode_api_key']));
    $out['default_geocode_address'] = sanitize_text_field($input['default_geocode_address'] ?? $out['default_geocode_address']);

    return $out;
}

add_action('admin_init', function () {
    register_setting('imm_sections_kacu_group', IMM_KACU_SECTIONS_OPT, [
        'type'              => 'array',
        'sanitize_callback' => 'imm_kacu_save_settings_sanitized',
        'default'           => imm_kacu_get_settings(),
    ]);
});

add_action('admin_menu', function () {
    add_options_page(
        'Offers API Hub',
        'Offers API Hub',
        'manage_options',
        'imm-sections',
        function () {
            if (!current_user_can('manage_options')) return;
            $s = imm_sections_get_settings();
            ?>
            <div class="wrap">
              <h1>Offers API Hub</h1>
              <p>These credentials are stored server-side and used to fetch/refresh the bearer token automatically.</p>
              <form method="post" action="options.php">
                <?php settings_fields('imm_sections_group'); ?>
                <table class="form-table" role="presentation">
                  <tr>
                    <th scope="row"><label for="imm_sso_env">SSO Environment</label></th>
                    <td>
                      <select id="imm_sso_env" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[sso_env]">
                        <option value="staging" <?php selected($s['sso_env'] ?? 'staging', 'staging'); ?>>Staging</option>
                        <option value="production" <?php selected($s['sso_env'] ?? 'staging', 'production'); ?>>Production</option>
                      </select>
                      <p class="description">Used to pick the SSO iframe domain for login + postMessage origin checks.</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_sso_subdomain_staging">SubDomain (Staging)</label></th>
                    <td>
                      <input id="imm_sso_subdomain_staging" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[sso_subdomain_staging]" type="text" class="regular-text" value="<?php echo esc_attr($s['sso_subdomain_staging'] ?? ''); ?>" />
                      <p class="description">Example: <code>ridstaging.immdemo.net</code></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_sso_subdomain_production">SubDomain (Production)</label></th>
                    <td>
                      <input id="imm_sso_subdomain_production" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[sso_subdomain_production]" type="text" class="regular-text" value="<?php echo esc_attr($s['sso_subdomain_production'] ?? ''); ?>" />
                      <p class="description">Example: <code>rperks.shopridleys.com</code></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_sso_login_path">SSO Login Path</label></th>
                    <td>
                      <input id="imm_sso_login_path" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[sso_login_path]" type="text" class="regular-text" value="<?php echo esc_attr($s['sso_login_path'] ?? '/sso-login'); ?>" />
                      <p class="description">Default: <code>/sso-login</code></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_token_url">Token URL</label></th>
                    <td><input id="imm_token_url" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[token_url]" type="url" class="regular-text" value="<?php echo esc_attr($s['token_url']); ?>" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_grant_type">Grant type</label></th>
                    <td><input id="imm_grant_type" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[grant_type]" type="text" class="regular-text" value="<?php echo esc_attr($s['grant_type']); ?>" />
                      <p class="description">Common values: <code>password</code> or <code>client_credentials</code></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_client_id">Client ID</label></th>
                    <td><input id="imm_client_id" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[client_id]" type="text" class="regular-text" value="<?php echo esc_attr($s['client_id']); ?>" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_username">Username</label></th>
                    <td><input id="imm_username" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[username]" type="text" class="regular-text" value="<?php echo esc_attr($s['username']); ?>" autocomplete="off" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_password">Password</label></th>
                    <td><input id="imm_password" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[password]" type="password" class="regular-text" value="<?php echo esc_attr($s['password']); ?>" autocomplete="new-password" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_offers_base">Offers base URL</label></th>
                    <td><input id="imm_offers_base" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[offers_base]" type="url" class="regular-text" value="<?php echo esc_attr($s['offers_base']); ?>" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_coupon_click_url">Coupon Click URL</label></th>
                    <td>
                      <input id="imm_coupon_click_url" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[coupon_click_url]" type="url" class="regular-text" value="<?php echo esc_attr($s['coupon_click_url']); ?>" />
                      <p class="description">Clip Offer POST: Bearer + <code>ShopperID</code>, <code>ClientID</code>, <code>ClickType</code> headers; JSON body <code>{&quot;Items&quot;:[{&quot;CouponID&quot;:&quot;…&quot;,&quot;ClippedDate&quot;:…}]}</code>. Leave blank to derive from Offers base (…/Offers → …/Coupon/Click). The clip request uses the same <strong>Client ID</strong> as the token (above) unless you override with filter <code>imm_sections_clip_client_id</code>.</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_header_client">Client header name</label></th>
                    <td><input id="imm_header_client" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[header_client]" type="text" class="regular-text" value="<?php echo esc_attr($s['header_client']); ?>" />
                      <p class="description">If your API requires ClientID header, keep as <code>ClientID</code>. Otherwise set to blank.</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_token_auth_mode">Token auth mode</label></th>
                    <td>
                      <select id="imm_token_auth_mode" name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[token_auth_mode]">
                        <option value="body" <?php selected($s['token_auth_mode'], 'body'); ?>>Send credentials in Body</option>
                        <option value="headers" <?php selected($s['token_auth_mode'], 'headers'); ?>>Send credentials in Headers (per curl)</option>
                      </select>
                      <p class="description">Choose <b>Headers</b> if your working curl uses <code>--header 'username: ...'</code>, <code>--header 'password: ...'</code>, <code>--header 'ClientID: ...'</code>.</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row">Token body field names</th>
                    <td>
                      <p class="description">Only change these if the token endpoint expects different field names.</p>
                      <label>grant type field<br/>
                        <input name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[token_field_grant]" type="text" class="regular-text" value="<?php echo esc_attr($s['token_field_grant']); ?>" />
                      </label><br/><br/>
                      <label>client id field<br/>
                        <input name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[token_field_clientid]" type="text" class="regular-text" value="<?php echo esc_attr($s['token_field_clientid']); ?>" />
                      </label><br/><br/>
                      <label>username field<br/>
                        <input name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[token_field_username]" type="text" class="regular-text" value="<?php echo esc_attr($s['token_field_username']); ?>" />
                      </label><br/><br/>
                      <label>password field<br/>
                        <input name="<?php echo esc_attr(IMM_SECTIONS_OPT); ?>[token_field_password]" type="text" class="regular-text" value="<?php echo esc_attr($s['token_field_password']); ?>" />
                      </label>
                    </td>
                  </tr>
                </table>
                <?php submit_button(); ?>
              </form>

              <hr/>

              <h2>Cash Back (KACU) Coupon Settings</h2>
              <p>Fill your KACU credentials + default params for the <code>Cash Back</code> shortcode. <strong>Token</strong> uses <code>Apikey</code>, <code>ApiSecret</code>, and <code>ClientID</code> headers (not the same flow as IMM OAuth). <strong>Activate</strong> uses the bearer from that token and posts <code>OfferID</code> + <code>ShopperID</code> to Offers/Activate.</p>
              <form method="post" action="options.php">
                <?php settings_fields('imm_sections_kacu_group'); ?>
                <?php $k = imm_kacu_get_settings(); ?>
                <table class="form-table" role="presentation">
                  <tr>
                    <th scope="row"><label for="imm_kacu_apikey">API Key</label></th>
                    <td><input id="imm_kacu_apikey" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[apikey]" type="text" class="regular-text" value="<?php echo esc_attr($k['apikey']); ?>" autocomplete="off" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_apisecret">API Secret</label></th>
                    <td><input id="imm_kacu_apisecret" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[apisecret]" type="password" class="regular-text" value="<?php echo esc_attr($k['apisecret']); ?>" autocomplete="new-password" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_token_url">KACU Token URL</label></th>
                    <td><input id="imm_kacu_token_url" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[token_url]" type="url" class="regular-text" value="<?php echo esc_attr($k['token_url']); ?>" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_offers_url">KACU Offers URL</label></th>
                    <td><input id="imm_kacu_offers_url" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[offers_url]" type="url" class="regular-text" value="<?php echo esc_attr($k['offers_url']); ?>" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_activate_url">KACU Offers Activate URL</label></th>
                    <td>
                      <input id="imm_kacu_activate_url" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[activate_url]" type="url" class="regular-text" value="<?php echo esc_attr($k['activate_url'] ?? ''); ?>" />
                      <p class="description">Cash Back <strong>Activate Now</strong> button: <code>POST</code> with <code>Authorization: Bearer …</code> (from Token API) and form fields <code>OfferID</code>, <code>ShopperID</code>. Example: <code>https://stagingclientapi.kacu.app/API/V1.0/Offers/Activate</code></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_clientid">ClientID</label></th>
                    <td><input id="imm_kacu_clientid" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[clientid]" type="text" class="regular-text" value="<?php echo esc_attr($k['clientid']); ?>" autocomplete="off" /></td>
                  </tr>

                  <tr>
                    <th scope="row"><label for="imm_kacu_companyid">CompanyID</label></th>
                    <td><input id="imm_kacu_companyid" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[companyid]" type="text" class="regular-text" value="<?php echo esc_attr($k['companyid']); ?>" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_storeid">StoreID</label></th>
                    <td><input id="imm_kacu_storeid" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[storeid]" type="text" class="regular-text" value="<?php echo esc_attr($k['storeid']); ?>" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_loyaltycard">LoyaltyCard</label></th>
                    <td><input id="imm_kacu_loyaltycard" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[loyaltycard]" type="text" class="regular-text" value="<?php echo esc_attr($k['loyaltycard']); ?>" /></td>
                  </tr>

                  <tr>
                    <th scope="row"><label for="imm_kacu_shopperprofileid">ShopperProfileID (optional)</label></th>
                    <td><input id="imm_kacu_shopperprofileid" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[shopperprofileid]" type="text" class="regular-text" value="<?php echo esc_attr($k['shopperprofileid']); ?>" /></td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_stores_lookup_url">Stores lookup URL</label></th>
                    <td>
                      <input id="imm_kacu_stores_lookup_url" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[stores_lookup_url]" type="url" class="large-text code" value="<?php echo esc_attr($k['stores_lookup_url']); ?>" />
                      <p class="description">Example: <code>https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/StoresByCurrentLocation</code></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_stores_bearer">Stores API Bearer token</label></th>
                    <td>
                      <input id="imm_kacu_stores_bearer" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[stores_bearer_token]" type="password" class="large-text code" value="<?php echo esc_attr($k['stores_bearer_token']); ?>" autocomplete="new-password" />
                      <p class="description">Sent as <code>Authorization: Bearer …</code> for store list + update if update token is empty.</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_shopper_update_url">Shopper update store URL</label></th>
                    <td>
                      <input id="imm_kacu_shopper_update_url" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[shopper_update_store_url]" type="url" class="large-text code" value="<?php echo esc_attr($k['shopper_update_store_url']); ?>" />
                      <p class="description">Example: <code>https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/ShopperUpdateStore</code></p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_shopper_update_bearer">Shopper update Bearer (optional)</label></th>
                    <td>
                      <input id="imm_kacu_shopper_update_bearer" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[shopper_update_bearer_token]" type="password" class="large-text code" value="<?php echo esc_attr($k['shopper_update_bearer_token']); ?>" autocomplete="new-password" />
                      <p class="description">If empty, the Stores API Bearer token above is used.</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_google_geocode_key">Google Geocoding API key</label></th>
                    <td>
                      <input id="imm_kacu_google_geocode_key" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[google_geocode_api_key]" type="password" class="large-text code" value="<?php echo esc_attr($k['google_geocode_api_key']); ?>" autocomplete="new-password" />
                      <p class="description">Used server-side only. Typing a <strong>zip</strong>, city, or state and clicking search runs Google Geocoding first; the returned latitude/longitude (and state code for <code>City</code>) are sent to <code>StoresByCurrentLocation</code>.</p>
                    </td>
                  </tr>
                  <tr>
                    <th scope="row"><label for="imm_kacu_default_geocode">Default geocode search</label></th>
                    <td>
                      <input id="imm_kacu_default_geocode" name="<?php echo esc_attr(IMM_KACU_SECTIONS_OPT); ?>[default_geocode_address]" type="text" class="regular-text" value="<?php echo esc_attr($k['default_geocode_address']); ?>" />
                      <p class="description">Example: <code>arizona</code> — used when opening the store popup if no search text yet.</p>
                    </td>
                  </tr>
                </table>
                <?php submit_button(); ?>
              </form>
            </div>
            <?php
        }
    );
});

function imm_sections_get_access_token($force_refresh = false, $client_id_override = null, $cache_prefix = 'imm_sections_access_token_v2_', $settings_override = null) {
    $s = imm_sections_get_settings();
    if (is_array($settings_override) && !empty($settings_override)) {
        $s = array_merge($s, $settings_override);
    }
    if ($client_id_override !== null) {
        // Allow callers to explicitly request token for a specific ClientID.
        $s['client_id'] = preg_replace('/[^0-9]/', '', (string) $client_id_override);
    }
    // Cache token per (ClientID + token URL + credentials) but allow a custom cache prefix
    // so the Coupon/Click button can use its own dedicated token cache.
    // Also scope by username so changing WP token credentials doesn't reuse a previously cached token.
    $cache_key = $cache_prefix . md5(
        (string) ($s['client_id'] ?? '') . '|' .
        (string) ($s['token_url'] ?? '') . '|' .
        (string) ($s['username'] ?? '') . '|' .
        (string) ($s['grant_type'] ?? '') . '|' .
        strtolower((string) ($s['token_auth_mode'] ?? 'body'))
    );

    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
    }

    if (empty($s['token_url'])) {
        return new WP_Error('imm_no_token_url', 'Token URL not configured.');
    }
    if (empty($s['client_id'])) return new WP_Error('imm_no_client_id', 'Client ID not configured.');
    if ($s['grant_type'] === 'password' && (empty($s['username']) || empty($s['password']))) {
        return new WP_Error('imm_no_creds', 'Username/password not configured for password grant.');
    }

    // Token endpoint can require credentials in body or headers depending on deployment.
    $grant_field = $s['token_field_grant'] ?: 'grant_type';
    $client_field = $s['token_field_clientid'] ?: 'ClientID';
    $user_field = $s['token_field_username'] ?: 'username';
    $pass_field = $s['token_field_password'] ?: 'password';

    $body = [
        $grant_field  => $s['grant_type'],
    ];

    $headers = [
        'Accept'       => 'application/json',
        'Content-Type' => 'application/x-www-form-urlencoded',
        // Some servers break on "Expect: 100-continue" (commonly sent by cURL for larger posts)
        'Expect'       => '',
        // Set an explicit UA (some gateways/WAFs treat empty UA differently than Postman/browser)
        'User-Agent'   => 'WordPress/IMM-Sections',
    ];

    $token_auth_mode = strtolower((string)($s['token_auth_mode'] ?? 'body'));
    if ($token_auth_mode !== 'headers') $token_auth_mode = 'body';

    if ($token_auth_mode === 'headers') {
        // Match your curl: credentials in headers, body includes grant_type only.
        if (!empty($s['username'])) $headers[$user_field] = $s['username'];
        if (!empty($s['password'])) $headers[$pass_field] = $s['password'];
        if (!empty($s['client_id'])) {
            // Set whatever header name you configured, AND also the common IMM name.
            if (!empty($s['header_client'])) {
                $headers[$s['header_client']] = $s['client_id'];
            }
            $headers['ClientID'] = $s['client_id'];
        }
    } else {
        // Credentials in body (classic OAuth-style form post)
        if (!empty($s['client_id'])) {
            // Match your working Postman: send ONLY ClientID (not alternate client_id field),
            // because token shape differences can lead to a token that doesn't work for Coupon/Click.
            $body['ClientID'] = $s['client_id'];
            // Note: we intentionally do NOT add $client_field here when it differs from "ClientID".
            // If your IMM token endpoint truly requires the alternate name, adjust via token_field_clientid.
        }
        if (!empty($s['username'])) $body[$user_field] = $s['username'];
        if (!empty($s['password'])) $body[$pass_field] = $s['password'];
        // IMPORTANT: In Postman working calls, ClientID is provided in the body.
        // Do NOT also send it as a header in body mode, otherwise token generation
        // can differ from the Postman request shape.
    }

    // Build body as a query string to match Postman/x-www-form-urlencoded precisely.
    $body_string = http_build_query($body, '', '&', PHP_QUERY_RFC3986);

    $do_post = function(array $body_arr, array $headers_override = null) use ($s, $headers) {
        $body_string = http_build_query($body_arr, '', '&', PHP_QUERY_RFC3986);
        $use_headers = is_array($headers_override) ? $headers_override : $headers;
        return wp_remote_post($s['token_url'], [
            'timeout' => 15,
            'headers' => $use_headers,
            'body' => $body_string,
            'redirection' => 0,
        ]);
    };

    $res = $do_post($body);

    if (is_wp_error($res)) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);

    // Retry logic: try alternate parameter styles that match known working clients.
    $retry_info = '';
    if (($code === 500 || $code === 400) && strcasecmp($client_field, 'ClientID') !== 0) {
        $retry_body = $body;
        unset($retry_body[$client_field]);
        $retry_body['ClientID'] = $s['client_id'];
        $res_retry = $do_post($retry_body);
        if (!is_wp_error($res_retry)) {
            $code_retry = wp_remote_retrieve_response_code($res_retry);
            $raw_retry  = wp_remote_retrieve_body($res_retry);
            $retry_info = ' RetryHTTP=' . $code_retry . ' RetryFields=[' . implode(', ', array_keys($retry_body)) . '].';
            if ($code_retry >= 200 && $code_retry < 300) {
                $json_retry = json_decode($raw_retry, true);
                if (is_array($json_retry) && !empty($json_retry['access_token'])) {
                    $token = (string)$json_retry['access_token'];
                    $expires_in = isset($json_retry['expires_in']) ? intval($json_retry['expires_in']) : 3600;
                    $ttl = max(30, $expires_in - 120);
                    set_transient($cache_key, $token, $ttl);
                    return $token;
                }
            }
        }
    }

    // If still failing and we were in body mode, try "credentials in headers" mode (matches your curl).
    if (($code === 500 || $code === 400) && $token_auth_mode === 'body') {
        $hdr_headers = $headers;
        // Remove any accidental auth fields from body; keep only grant_type
        $hdr_body = [ $grant_field => $s['grant_type'] ];

        if (!empty($s['username'])) $hdr_headers[$user_field] = $s['username'];
        if (!empty($s['password'])) $hdr_headers[$pass_field] = $s['password'];
        if (!empty($s['header_client']) && !empty($s['client_id'])) {
            $hdr_headers[$s['header_client']] = $s['client_id'];
        }

        $res_hdr = $do_post($hdr_body, $hdr_headers);
        if (!is_wp_error($res_hdr)) {
            $code_hdr = wp_remote_retrieve_response_code($res_hdr);
            $raw_hdr  = wp_remote_retrieve_body($res_hdr);
            $retry_info .= ' HeaderModeHTTP=' . $code_hdr . ' HeaderModeFields=[' . implode(', ', array_keys($hdr_body)) . '].';
            if ($code_hdr >= 200 && $code_hdr < 300) {
                $json_hdr = json_decode($raw_hdr, true);
                if (is_array($json_hdr) && !empty($json_hdr['access_token'])) {
                    $token = (string)$json_hdr['access_token'];
                    $expires_in = isset($json_hdr['expires_in']) ? intval($json_hdr['expires_in']) : 3600;
                    $ttl = max(30, $expires_in - 120);
                    set_transient($cache_key, $token, $ttl);
                    return $token;
                }
            }
        }
    }

    if ($code < 200 || $code >= 300) {
        // Don't leak server internals to frontend; keep detail for debug views.
        $preview = '';
        if (is_string($raw) && $raw !== '') {
            $preview = wp_strip_all_tags($raw);
            $preview = preg_replace('/\s+/', ' ', $preview);
            $preview = substr($preview, 0, 300);
        }
        $field_list = implode(', ', array_keys($body));
        $has_user = array_key_exists($user_field, $body) ? 'yes' : 'no';
        $has_pass = array_key_exists($pass_field, $body) ? 'yes' : 'no';
        $has_user_hdr = array_key_exists($user_field, $headers) ? 'yes' : 'no';
        $has_pass_hdr = array_key_exists($pass_field, $headers) ? 'yes' : 'no';
        $client_hdr = (!empty($s['header_client']) && !empty($s['client_id'])) ? ($s['header_client'] . ': ' . $s['client_id']) : '(none)';

        $msg = 'Token request failed (HTTP ' . $code . ').';
        if ($preview) $msg .= ' ' . $preview;
        // Include request shape hints for debugging (no secrets).
        $msg .= ' url=' . $s['token_url'] . ' mode=' . $token_auth_mode . ' Fields=[' . $field_list . '], username_in_body=' . $has_user . ', password_in_body=' . $has_pass . ', username_in_headers=' . $has_user_hdr . ', password_in_headers=' . $has_pass_hdr . ', client_header=' . $client_hdr . '.' . $retry_info;
        return new WP_Error('imm_token_http', $msg);
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['access_token'])) {
        return new WP_Error('imm_token_bad_json', 'Token response missing access_token.');
    }

    $token = (string)$json['access_token'];
    $expires_in = isset($json['expires_in']) ? intval($json['expires_in']) : 3600;
    // Refresh a bit early
    $ttl = max(30, $expires_in - 120);
    set_transient($cache_key, $token, $ttl);

    return $token;
}

function imm_sections_last_good_offers_option_key($cache_key) {
    return 'imm_last_good_' . md5((string)$cache_key);
}

function imm_sections_filter_meaningful_offers($offers) {
    if (!is_array($offers) || empty($offers)) return [];
    $out = [];
    foreach ($offers as $row) {
        if (!is_array($row)) continue;
        $title = '';
        foreach (['OfferTitle','offerTitle','Title','title','OfferName','Name','ProductName','productName'] as $k) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') { $title = trim((string)$row[$k]); break; }
        }
        $img = '';
        foreach (['ImageUrl','imageUrl','Image','image','ThumbnailUrl','thumbnailUrl','ProductImageUrl','productImageUrl'] as $k) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') { $img = trim((string)$row[$k]); break; }
        }
        $oid = '';
        foreach (['OfferId','offerId','ExternalReferenceId','externalReferenceId'] as $k) {
            if (isset($row[$k]) && trim((string)$row[$k]) !== '') { $oid = trim((string)$row[$k]); break; }
        }
        if ($title !== '' || $img !== '' || ($oid !== '' && $oid !== '0')) {
            $out[] = $row;
        }
    }
    return $out;
}

function imm_sections_save_last_good_offers($cache_key, $offers) {
    $clean = imm_sections_filter_meaningful_offers($offers);
    if (empty($clean)) return;
    update_option(imm_sections_last_good_offers_option_key($cache_key), $clean, false);
}

function imm_sections_get_last_good_offers($cache_key) {
    $v = get_option(imm_sections_last_good_offers_option_key($cache_key), []);
    return imm_sections_filter_meaningful_offers($v);
}

function imm_sections_get_fallback_offers($stale_key, $cache_key) {
    $stale = get_transient($stale_key);
    if (is_array($stale) && !empty($stale)) return $stale;
    return imm_sections_get_last_good_offers($cache_key);
}

/**
 * Inline carousel CSS once per page. Shortcodes run after wp_head, so styles are appended to the first
 * rendered section output (subsequent shortcodes get an empty string).
 */
function imm_sections_get_carousel_styles_once() {
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    ob_start();
    ?>
<style id="imm-sections-carousel-css">
      @import url('https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap');
      @import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&display=swap');

      /* Scope ALL offer-slider styles under .featured-digital-offer so theme/header/footer are never affected */
      .featured-digital-offer{background:#f2f5fd;padding:64px 0}
      .featured-digital-offer.cashback-offers{background:transparent}
      .featured-digital-offer .container{width:100%;max-width:1320px;margin:0 auto;padding:0 15px}
      .featured-digital-offer .featured-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
      .featured-digital-offer .featured-heading h2{color:#11385c;text-align:left;font-size:32px;line-height:32px;font-family:"Poppins",sans-serif;font-weight:600}
      .featured-digital-offer .featured-offer-btn a{color:#11385c;text-align:center;font-size:16px;line-height:16px;letter-spacing:.8px;font-weight:700;text-decoration:none;font-family:"DM Sans",sans-serif;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
      .featured-digital-offer .featured-offer-btn a span{display:inline-flex;align-items:center}
      .featured-digital-offer.cashback-offers .featured-offer-btn a{
        background:transparent !important;
        color:#10395d !important;
        padding:13px 18px !important;
        border-radius:100px !important;
        border:0 !important;
      }
      .featured-digital-offer.cashback-offers .featured-offer-btn a svg path{fill:#10395d !important}
      .featured-digital-offer.cashback-offers .featured-offer-btn a:hover{
        background:transparent !important;
        color:#10395d !important;
      }
      .featured-digital-offer.cashback-offers .featured-offer-btn a:hover svg path{fill:#10395d !important}
      .featured-digital-offer .featured-slider{display:flex;flex-direction:column;gap:20px;position:relative;width:100%}
      .featured-digital-offer .featured-digital-grid{
        display:flex;
        align-items:stretch;
        --imm-card-gap:clamp(14px,3vw,24px);
        gap:var(--imm-card-gap);
        overflow-x:auto;
        overflow-y:hidden;
        scroll-snap-type:x mandatory;
        scroll-behavior:smooth;
        padding:4px 2px 8px;
        -webkit-overflow-scrolling:touch;
        width:100%;
        box-sizing:border-box;
      }
      .featured-digital-offer .featured-digital-grid::-webkit-scrollbar{display:none}
      .featured-digital-offer .featured-digital-grid{scrollbar-width:none}

      .featured-digital-offer .featured-digital-card{
        align-self:stretch;
        height:auto;
        min-height:0;
        display:flex;
        flex-direction:column;
        scroll-snap-align:start;
      }
      /* Desktop: exactly 4 full cards visible (no “half” fifth peeking) */
      @media (min-width: 901px){
        .featured-digital-offer .featured-digital-grid{--imm-card-gap:24px}
        .featured-digital-offer .featured-digital-card{
          flex:0 0 calc((100% - 3 * var(--imm-card-gap)) / 4);
          width:calc((100% - 3 * var(--imm-card-gap)) / 4);
          max-width:none;
        }
      }
      .featured-digital-offer .featured-digital-box{height:100%;flex:1;display:flex;flex-direction:column}
      .featured-digital-offer .bg-white-box{display:flex}
      .featured-digital-offer .bg-white-box .featured-digital-box{flex:1}

      .featured-digital-offer .featured-img{position:relative;height:100%;min-height:clamp(240px,50vw,340px);flex:1;display:flex}
      .featured-digital-offer .featured-img img{height:100%;width:100%;object-fit:cover;border-radius:16px}
      .featured-digital-offer .featured-content-over{position:absolute;bottom:36px;left:50%;transform:translateX(-50%);text-align:center;width:100%;padding:0 12px}
      .featured-digital-offer .featured-content-over h3{color:#feffff;font-family:"Poppins",sans-serif;font-size:18px;line-height:118.7%;font-weight:700;margin-top:10px}
      .featured-digital-offer .featured-content-over img{max-width:140px;height:auto;display:block;margin:0 auto}

      .featured-digital-offer .bg-white-box{background:#fff;border-radius:12px;border:1px solid rgba(17,57,93,.2);padding:18px 13px}
      .featured-digital-offer .coupon-box{display:flex;align-items:center;justify-content:space-between}
      .featured-digital-offer .coupon-box .label{background:#b86028;border-radius:32px;color:#fff;text-align:left;padding:5px 15px;font-size:13px;line-height:19.2px;font-weight:400;font-family:"DM Sans",sans-serif}
      .featured-digital-offer.cashback-offers .coupon-box .label{background:#797951}

      .featured-digital-offer .featured-digitalimg{display:flex;justify-content:center;margin:10px 0 6px}
      .featured-digital-offer .featured-digitalimg img{border-radius:16px;max-width:270px;height:156px;object-fit:contain;width:100%}
      @media (min-width: 901px){
        .featured-digital-offer .featured-digitalimg img{max-width:100%}
      }

      .featured-digital-offer .featured-digitalimg-content .items-text{color:#6c6c6c;text-align:left;font-family:"Poppins",sans-serif;font-size:16px;font-weight:400;margin-bottom:11px;display:inline-block}
      .featured-digital-offer .featured-digitalimg-content{display:flex;flex-direction:column;flex:1}
      .featured-digital-offer .featured-digitalimg-content h3{color:#11385c;text-align:left;font-family:"Poppins",sans-serif;font-size:18px;line-height:24px;font-weight:600;min-height:48px}

      .featured-digital-offer .price-container{display:flex;align-items:center;justify-content:space-between;margin-top:0;gap:10px}
      .featured-digital-offer .price-main{display:flex;align-items:flex-start}
      .featured-digital-offer .price-main .currency{color:#11385c;font-family:"Poppins",sans-serif;font-size:26px;font-weight:700;line-height:1;margin-top:5px}
      .featured-digital-offer .price-dollars{color:#11385c;text-align:center;font-family:"Poppins",sans-serif;font-size:42px;font-weight:700;line-height:1}
      .featured-digital-offer .price-cents{color:#11385c;text-align:center;font-family:"Poppins",sans-serif;font-size:26px;font-weight:700;line-height:1;margin-top:5px}
      .featured-digital-offer .price-discount span{display:block;font-family:"DM Sans",sans-serif;font-size:19px;line-height:32px;font-weight:400}
      .featured-digital-offer .original-price{color:#6a7283}
      .featured-digital-offer .discount-amount{color:#b86028}
      .featured-digital-offer .imm-featured-expiry{
        color:#b86028;
        text-align:right;
        font-family:"DM Sans",sans-serif;
        font-size:22px;
        line-height:1;
        font-weight:500;
        white-space:nowrap;
      }

      /* First section buttons (Activate Now / Activated) */
      .featured-digital-offer .imm-featured-activate-btn{
        display:block;
        border:1px dashed transparent;
        background:#11385c;
        padding:13px 65px;
        color:#ffffff;
        text-align:center;
        font-family:"DM Sans",sans-serif;
        font-size:16px;
        line-height:16px;
        font-weight:500;
        text-decoration:none;
        border-radius:100px;
        position:relative;
        overflow:hidden;
        z-index:1;
        transition:all .3s;
        margin-top:auto;
        margin-bottom:8px;
      }
      .featured-digital-offer .imm-featured-activate-btn .icon{margin-right:8px}
      .featured-digital-offer .imm-featured-activate-btn::before{
        content:"";
        position:absolute;
        inset:0;
        background:#1f3b5b;
        border-radius:30px;
        transition:all .4s ease;
        z-index:-1;
      }
      .featured-digital-offer .imm-featured-activate-btn::after{
        content:"";
        position:absolute;
        top:0;
        right:0;
        height:100%;
        width:60px;
        background:#fff;
        clip-path:polygon(30% 0,100% 0,100% 100%,0% 100%);
        transition:all .4s ease;
        z-index:-1;
      }
      .featured-digital-offer .imm-featured-activate-btn:hover::after{width:100%;clip-path:none}
      .featured-digital-offer .imm-featured-activate-btn:hover{
        color:#11385c;
        border-color:#11385c;
      }
      .featured-digital-offer span.imm-featured-activate-btn{
        cursor:default;
        user-select:none;
      }
      .featured-digital-offer a.imm-featured-activate-btn{cursor:pointer}

      /* Cashback section buttons */
      .featured-digital-offer.cashback-offers .imm-kacu-activate-btn{
        display:block;
        border:1px dashed #11385c;
        background:transparent;
        padding:13px 65px;
        color:#11385c;
        text-align:center;
        font-family:"DM Sans",sans-serif;
        font-size:16px;
        line-height:16px;
        font-weight:500;
        text-decoration:none;
        border-radius:100px;
        position:relative;
        overflow:hidden;
        z-index:1;
        transition:all .3s;
        margin-top:auto;
        margin-bottom:8px;
      }
      .featured-digital-offer.cashback-offers .imm-kacu-activate-btn .icon{margin-right:8px}
      /* Disable pseudo-elements so hover fill is 100% controlled by background/color. */
      .featured-digital-offer.cashback-offers .imm-kacu-activate-btn::before,
      .featured-digital-offer.cashback-offers .imm-kacu-activate-btn::after{
        display:none;
        content:"";
      }
      .featured-digital-offer.cashback-offers .imm-kacu-activate-btn:hover,
      .featured-digital-offer.cashback-offers .imm-kacu-activate-btn:focus,
      .featured-digital-offer.cashback-offers .imm-kacu-activate-btn:active{
        color:#ffffff !important;
        border-color:#11385c !important;
        background:#11385c !important;
      }

      /* Activated state (Featured Digital Offers): white pill, no hover animation, no click */
      .featured-digital-offer .imm-featured-activate-btn--activated{
        background:#ffffff !important;
        color:#10395d !important;
        border:1px solid #10395d !important;
        border-style:solid !important;
        transition:none !important;
        cursor:default !important;
        pointer-events:none !important;
      }
      .featured-digital-offer .imm-featured-activate-btn--activated::before,
      .featured-digital-offer .imm-featured-activate-btn--activated::after{
        display:none !important;
      }
      .featured-digital-offer .imm-featured-activate-btn--activated:hover{
        color:#10395d !important;
        border-color:#10395d !important;
      }

      .featured-digital-offer .price-main.imm-price-is-free .currency,
      .featured-digital-offer .price-main.imm-price-is-free .price-dollars,
      .featured-digital-offer .price-main.imm-price-is-free .price-cents{display:none !important}
      .featured-digital-offer .price-main.imm-price-is-free .imm-price-free{
        display:inline;font-size:42px;line-height:1;font-weight:700;color:#11385c;font-family:"Poppins",sans-serif
      }

      /* First section only: match requested card typography/size exactly */
      .featured-digital-offer:not(.cashback-offers) .featured-digitalimg-content .items-text{
        color:#6c6c6c;
        text-align:left;
        font-family:"Poppins",sans-serif;
        font-size:16px;
        font-weight:400;
        display:inline-block;
        line-height:100%;
        margin-bottom:0;
      }
      .featured-digital-offer:not(.cashback-offers) .featured-digitalimg-content h3{
        color:#11385c;
        text-align:left;
        font-family:"Poppins",sans-serif;
        font-size:18px;
        line-height:24px;
        font-weight:600;
        margin-bottom:0;
      }
      .featured-digital-offer:not(.cashback-offers) .imm-featured-mustbuy-limit{
        color:#11385c;
        text-align:left;
        font-family:"DM Sans",sans-serif;
        font-size:15px;
        line-height:1.25;
        font-weight:500;
        margin-top:4px;
        margin-bottom:8px;
      }
      .featured-digital-offer:not(.cashback-offers) .imm-featured-mustbuy-limit.is-empty{
        visibility:hidden;
      }
      .featured-digital-offer:not(.cashback-offers) .price-container{
        margin-top:4px;
        margin-bottom:16px;
      }
      .featured-digital-offer:not(.cashback-offers) .price-exp{
        color:#b86028;
        text-align:right;
        font-family:"DM Sans",sans-serif;
        font-size:20px;
        line-height:1.1;
        font-weight:500;
        white-space:nowrap;
      }
      .featured-digital-offer:not(.cashback-offers) .price-main .currency{
        color:#11385c;
        font-family:"Poppins",sans-serif;
        font-size:26px;
        font-weight:700;
        line-height:1;
        margin-top:5px;
        align-self:flex-start;
      }
      .featured-digital-offer:not(.cashback-offers) .price-dollars{
        color:#11385c;
        text-align:center;
        font-family:"Poppins",sans-serif;
        font-size:42px;
        font-weight:700;
        line-height:1;
      }
      .featured-digital-offer:not(.cashback-offers) .price-cents{
        color:#11385c;
        text-align:center;
        font-family:"Poppins",sans-serif;
        font-size:26px;
        font-weight:700;
        line-height:1;
        margin-top:5px;
        align-self:flex-start;
      }
      .featured-digital-offer:not(.cashback-offers) .price-discount{
        display:none !important;
      }
      .featured-digital-offer:not(.cashback-offers) .price-main{
        align-items:flex-start;
        gap:1px;
      }
      .featured-digital-offer:not(.cashback-offers) .featured-digitalimg{
        display:flex;
        justify-content:center;
      }
      .featured-digital-offer:not(.cashback-offers) .bg-white-box{
        background:#fff;
        border-radius:12px;
        border:1px solid rgba(17,57,93,.2);
        padding:18px 13px;
        width:100%;
        /* height:423px; */
      }
      .featured-digital-offer.cashback-offers .featured-digitalimg-content .items-text{
        color:#6c6c6c;
        text-align:left;
        font-family:"Poppins",sans-serif;
        font-size:16px;
        font-weight:400;
        display:inline-block;
        line-height:100%;
      }
      .featured-digital-offer.cashback-offers .featured-digitalimg-content h3{
        color:#11385c;
        text-align:left;
        font-family:"Poppins",sans-serif;
        font-size:18px;
        line-height:24px;
        font-weight:600;
        margin-bottom:0;
      }
      .featured-digital-offer.cashback-offers .price-main .currency{
        color:#11385c;
        font-family:"Poppins",sans-serif;
        font-size:26px;
        font-weight:700;
        line-height:1;
      }
      .featured-digital-offer.cashback-offers .price-dollars{
        color:#11385c;
        text-align:center;
        font-family:"Poppins",sans-serif;
        font-size:42px;
        font-weight:700;
        line-height:100%;
      }
      .featured-digital-offer.cashback-offers .price-cents{
        color:#11385c;
        text-align:center;
        font-family:"Poppins",sans-serif;
        font-size:26px;
        font-weight:700;
        line-height:1;
      }
      .featured-digital-offer.cashback-offers .featured-digitalimg{
        display:flex;
        justify-content:center;
      }
      .featured-digital-offer.cashback-offers .bg-white-box{
        background:#fff;
        border-radius:12px;
        border:1px solid rgba(17,57,93,.2);
        padding:18px 13px 28px 13px;
        width:100%;
        height:423px;
      }
      .featured-digital-offer.cashback-offers .imm-cashback-line{
        margin:6px 0 10px 0;
        padding:0;
        color:#11385c;
        font-family:"Poppins",sans-serif;
        font-size:17px;
        line-height:1.35;
        font-weight:600;
        text-align:left;
      }
      .featured-digital-offer.cashback-offers .featured-digitalimg-content p:not(.imm-cashback-line){
        margin:0 0 12px 0;
        color:#6a7283;
        font-family:"DM Sans",sans-serif;
        font-size:15px;
        line-height:1.4;
        font-weight:400;
      }
      .everyday-savings .featured-digitalimg-content .items-text{
        color:#6c6c6c;
        text-align:left;
        font-family:"Poppins",sans-serif;
        font-size:16px;
        font-weight:400;
        display:inline-block;
        line-height:100%;
      }
      .everyday-savings .featured-digitalimg-content h3{
        color:#11385c;
        text-align:left;
        font-family:"Poppins",sans-serif;
        font-size:18px;
        line-height:24px;
        font-weight:600;
        margin-bottom:0;
      }
      .everyday-savings .price-main .currency{
        color:#11385c;
        font-family:"Poppins",sans-serif;
        font-size:26px;
        font-weight:700;
        line-height:1;
      }
      .everyday-savings .price-dollars{
        color:#11385c;
        text-align:center;
        font-family:"Poppins",sans-serif;
        font-size:42px;
        font-weight:700;
        line-height:100%;
      }
      .everyday-savings .price-cents{
        color:#11385c;
        text-align:center;
        font-family:"Poppins",sans-serif;
        font-size:26px;
        font-weight:700;
        line-height:1;
      }
      .everyday-savings .featured-digitalimg{
        display:flex;
        justify-content:center;
      }
      .everyday-savings .bg-white-box{
        background:#fff;
        border-radius:12px;
        border:1px solid rgba(17,57,93,.2);
        padding:18px 13px;
        width:100%;
        height:423px;
      }

      .featured-digital-offer .featured-slider-footer,
      .everyday-savings .featured-slider-footer{
        display:flex;
        justify-content:center;
        align-items:center;
        gap:16px;
        margin-top:4px;
        padding:8px 0 4px;
      }
      .featured-digital-offer .featured-slider-nav,
      .everyday-savings .featured-slider-nav{
        position:static;
        transform:none;
        width:44px;
        height:44px;
        border-radius:50%;
        cursor:pointer;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        flex-shrink:0;
        line-height:0;
        transition:opacity .2s ease, transform .15s ease;
      }
      .featured-digital-offer .featured-slider-nav:focus-visible,
      .everyday-savings .featured-slider-nav:focus-visible{outline:2px solid #11385c;outline-offset:2px}
      .featured-digital-offer .featured-slider-nav--prev,
      .everyday-savings .featured-slider-nav--prev{
        background:#fff;
        border:2px solid #11385c;
        color:#11385c;
        box-shadow:0 2px 8px rgba(17,56,92,.12);
      }
      .featured-digital-offer .featured-slider-nav--next,
      .everyday-savings .featured-slider-nav--next{
        background:#11385c;
        border:2px solid #11385c;
        color:#fff;
        box-shadow:0 2px 8px rgba(17,56,92,.2);
      }
      .featured-digital-offer .featured-slider-nav:hover,
      .everyday-savings .featured-slider-nav:hover{opacity:.92}
      .featured-digital-offer .featured-slider-nav:active,
      .everyday-savings .featured-slider-nav:active{transform:scale(.96)}

      @media (max-width: 900px){
        .featured-digital-offer{padding:44px 0}
        .featured-digital-offer .featured-digital-grid{--imm-card-gap:16px;gap:16px;padding-left:4px;padding-right:4px}
        .featured-digital-offer .featured-digital-card{
          flex:0 0 min(78vw,270px);
          flex-basis:min(78vw,270px);
          max-width:270px;
          width:min(78vw,270px);
        }
        .featured-digital-offer .featured-heading h2{font-size:26px;line-height:28px}
        .featured-digital-offer .featured-img{min-height:clamp(200px,46vw,290px)}
      }
      @media (max-width: 520px){
        .featured-digital-offer .featured-header{gap:10px;align-items:flex-end}
        .featured-digital-offer .featured-offer-btn a{font-size:14px}
        .featured-digital-offer .featured-slider-footer,
        .everyday-savings .featured-slider-footer{gap:12px}
        .featured-digital-offer .featured-slider-nav,
        .everyday-savings .featured-slider-nav{width:48px;height:48px}
        .featured-digital-offer .featured-slider-nav svg,
        .everyday-savings .featured-slider-nav svg{width:14px;height:14px}
        .featured-digital-offer .featured-digital-grid{gap:12px}
        .featured-digital-offer .featured-digital-card{flex-basis:82vw;max-width:82vw;width:82vw}
        .featured-digital-offer .featured-img{min-height:200px}
      }

      /* Third section: Everyday Savings / Advantage Rewards (scoped overrides) */
      .everyday-savings {
        padding: 65px 0;
        background: #ffffff;
      }

      .everyday-savings .container{width:100%;max-width:1320px;margin:0 auto;padding:0 15px}
      .everyday-savings .featured-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:22px}
      .everyday-savings .featured-heading h2{color:#11385c;text-align:left;font-size:32px;line-height:32px;font-family:"Poppins",sans-serif;font-weight:600}
      .everyday-savings .featured-offer-btn a{color:#11385c;text-align:center;font-size:16px;line-height:16px;letter-spacing:.8px;font-weight:700;text-decoration:none;font-family:"DM Sans",sans-serif;display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
      .everyday-savings .featured-offer-btn a span{display:inline-flex;align-items:center}
      .everyday-savings .bg-white-box{background:#fff;border-radius:12px;border:1px solid rgba(17,57,93,.2);padding:18px 13px;display:flex}
      .everyday-savings .bg-white-box .featured-digital-box{flex:1}
      .everyday-savings .featured-digitalimg{display:flex;justify-content:center;margin:10px 0 6px}
      .everyday-savings .featured-digitalimg img{border-radius:16px;max-width:270px;height:156px;object-fit:contain;width:100%}
      @media (min-width: 901px){
        .everyday-savings .featured-digitalimg img{max-width:100%}
      }
      .everyday-savings .featured-digitalimg-content{display:flex;flex-direction:column;flex:1}
      .everyday-savings .featured-digitalimg-content h3{color:#11385c;text-align:left;font-family:"Poppins",sans-serif;font-size:18px;line-height:24px;font-weight:600;min-height:48px}

      .everyday-savings .coupon-box .label {
        background-color: #797951;
      }

      .everyday-savings .featured-slider{display:flex;flex-direction:column;gap:20px;position:relative;width:100%}
      .everyday-savings .featured-digital-grid{
        display:flex;
        align-items:stretch;
        --imm-card-gap:clamp(14px,3vw,24px);
        gap:var(--imm-card-gap);
        overflow-x:auto;
        overflow-y:hidden;
        scroll-snap-type:x mandatory;
        scroll-behavior:smooth;
        padding:4px 2px 8px;
        -webkit-overflow-scrolling:touch;
        width:100%;
        box-sizing:border-box;
      }
      .everyday-savings .featured-digital-grid::-webkit-scrollbar{display:none}
      .everyday-savings .featured-digital-grid{scrollbar-width:none}

      .everyday-savings .featured-digital-card{
        align-self:stretch;
        height:auto;
        min-height:0;
        display:flex;
        flex-direction:column;
        scroll-snap-align:start;
      }
      @media (min-width: 901px){
        .everyday-savings .featured-digital-grid{--imm-card-gap:24px}
        .everyday-savings .featured-digital-card{
          flex:0 0 calc((100% - 3 * var(--imm-card-gap)) / 4);
          width:calc((100% - 3 * var(--imm-card-gap)) / 4);
          max-width:none;
        }
      }
      .everyday-savings .featured-digital-box{height:100%;flex:1;display:flex;flex-direction:column;min-height:0}
      .everyday-savings .featured-img{
        position:relative;
        flex:1;
        min-height:clamp(240px,50vw,340px);
        display:flex;
        border-radius:16px;
        overflow:hidden;
      }
      .everyday-savings .featured-img a{display:flex;flex:1;min-height:0;width:100%}
      .everyday-savings .featured-img img{width:100%;height:100%;object-fit:cover;border-radius:16px}
      .everyday-savings.advantage-rewards .featured-digital-card.promo-tile .featured-img{
        background:#f1f3f7;
        border:1px solid #e5e8ef;
        border-radius:10px;
      }
      .everyday-savings.advantage-rewards .featured-digital-card.promo-tile .featured-img a{
        align-items:center;
        justify-content:center;
        padding:18px;
      }
      .everyday-savings.advantage-rewards .featured-digital-card.promo-tile .featured-img img{
        object-fit:contain;
        border-radius:0;
      }

      .everyday-savings .imm-reward-activate-btn::after,
      .everyday-savings .imm-reward-activate-btn::before {
        display: none;
      }

      .everyday-savings .imm-reward-activate-btn {
        border-radius: 100px;
        border-style: dashed;
        border-color: #11385c;
        border-width: 1px;
        background: transparent;
        color: #11385c;
      }

      .everyday-savings.advantage-rewards .imm-reward-redeemed-pill{
        display:inline-block !important;
        padding:13px 18px !important;
        background:#6b7280 !important;
        color:#ffffff !important;
        border-radius:100px !important;
        font-family:"DM Sans",sans-serif !important;
        font-size:14px !important;
        font-weight:700 !important;
        cursor:default !important;
      }

      .everyday-savings .imm-reward-activate-btn:hover {
        background: #11385c;
        color: #ffffff;
      }

      .everyday-savings .featured-digitalimg-content p {
        color: #6a7283;
        text-align: left;
        font-size: 19px;
        font-weight: 400;
        margin-top: 4px;
        margin-bottom: 11px;
        font-family: "DM Sans", sans-serif;
      }

      .everyday-savings.advantage-rewards .featured-digitalimg-content p {
        color: #6a7283;
        text-align: left;
        font-size: 17px;
        font-weight: 400;
        margin-top: 4px;
        margin-bottom: 11px;
        font-family: "DM Sans", sans-serif;
      }

      .everyday-savings.advantage-rewards .dozen-btn {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top:0;
        padding-top:8px;
      }

      .everyday-savings.advantage-rewards .imm-reward-bottom-bar{
        margin-top:auto;
        width:100%;
        background:#6b7a3b;
        color:#ffffff;
        text-align:center;
        font-family:"DM Sans",sans-serif;
        font-size:14px;
        line-height:1.2;
        font-weight:700;
        padding:12px 10px;
        border-radius:8px;
      }

      .everyday-savings.advantage-rewards .dozen-btn span {
        color: #11385c;
        font-size: 18px;
        line-height: 24px;
        font-weight: 600;
        font-family: "Poppins", sans-serif;
      }

      .everyday-savings.advantage-rewards .dozen-btn .imm-reward-activate-btn {
        width: auto !important;
        display: inline-block !important;
        padding: 13px 18px;
        background: #11385c;
        color: #ffffff;
      }

      .everyday-savings.advantage-rewards .dozen-btn .imm-reward-activate-btn:hover {
        background: transparent;
        color: #11385c;
      }

      @media (max-width: 900px){
        .everyday-savings{padding:48px 0}
        .everyday-savings .featured-digital-grid{--imm-card-gap:16px;gap:16px;padding-left:4px;padding-right:4px}
        .everyday-savings .featured-digital-card{
          flex:0 0 min(78vw,270px);
          flex-basis:min(78vw,270px);
          max-width:270px;
          width:min(78vw,270px);
        }
        .everyday-savings .featured-img{min-height:clamp(200px,46vw,290px)}
      }
      @media (max-width: 520px){
        .everyday-savings .featured-digital-grid{gap:12px}
        .everyday-savings .featured-digital-card{flex-basis:82vw;max-width:82vw;width:82vw}
        .everyday-savings .featured-img{min-height:200px}
      }
</style>
    <?php
    return ob_get_clean();
}

/**
 * Register footer init script once (runs after all shortcode HTML exists in the DOM).
 */
function imm_sections_request_carousel_assets() {
    static $hooked = false;
    if ($hooked) {
        return;
    }
    $hooked = true;

    add_action('wp_footer', static function () {
        static $once = false;
        if ($once) {
            return;
        }
        $once = true;
        ?>
<script id="imm-sections-carousel-js">
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-featured-slider]').forEach(function (section) {
    var track = section.querySelector('[data-slider-track]');
    if (!track) return;
    var prevBtn = section.querySelector('[data-slider-nav="prev"]');
    var nextBtn = section.querySelector('[data-slider-nav="next"]');
    if (!prevBtn || !nextBtn) return;
    function scrollOne(dir) {
      var firstCard = track.querySelector('.featured-digital-card');
      if (!firstCard) return;
      var cardW = firstCard.getBoundingClientRect().width;
      var cs = window.getComputedStyle(track);
      var gap = parseFloat(cs.gap || cs.columnGap || '24');
      var step = cardW + (isFinite(gap) ? gap : 24);
      track.scrollBy({ left: dir === 'next' ? step : -step, behavior: 'smooth' });
    }
    prevBtn.addEventListener('click', function () { scrollOne('prev'); });
    nextBtn.addEventListener('click', function () { scrollOne('next'); });
  });
});
</script>
        <?php
    }, 25);
}

add_shortcode('imm_featured_digital_offers', function ($atts) {
    if (imm_sections_shortcode_guest_returns_empty()) {
        return '';
    }
    imm_sections_request_carousel_assets();
    $fdo_uid = wp_unique_id('imm-fdo-');
    $clip_nonce = wp_create_nonce('imm_sections_clip_offer_nonce');
    $atts = shortcode_atts([
        'title'       => 'Featured Digital Offers',
        'see_all_url' => 'https://ridstaging.immdemo.net/pages/collections/collection/DigitalOffers',
        'shopper_id'  => '',
        // If collection is set, we use CollectionOffers (recommended for "Featured" sections).
        // Example: collection="DigitalOffers"
        'collection'  => 'DigitalOffers',
        // Fallback when collection is empty.
        'offertype'   => '2',
        'limit'       => 12,
        'cache_ttl'   => 30,
        'debug'       => '0',
        'promo_logo_url' => 'https://ridleysfamistg.wpenginepowered.com/wp-content/uploads/2026/03/Group-1000002704.png',
        'promo_cta'      => 'Activate Digital Offers to Save Even More!',
    ], $atts, 'imm_featured_digital_offers');

    $shopper_id = imm_sections_imm_resolve_shopper_id($atts['shopper_id'] ?? '');
    $debug      = ((string)$atts['debug'] === '1');
    imm_sections_mark_page_non_cacheable_for_personalized_content();
    if (apply_filters('imm_sections_require_shopper_id_for_imm_offers', is_user_logged_in()) && $shopper_id === '') {
        return $debug ? '<p>ShopperID is required for personalized offers.</p>' : '';
    }
    $offertype  = sanitize_text_field($atts['offertype']);
    $collection = sanitize_text_field($atts['collection']);
    $limit      = max(1, min(50, intval($atts['limit'])));
    // Keep offers fresh; short TTL prevents "stuck" Activated/Clipped state.
    $cache_ttl  = max(10, min(120, intval($atts['cache_ttl'])));

    $s = imm_sections_get_settings();
    $offers_base = !empty($s['offers_base']) ? $s['offers_base'] : 'https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/Offers';
    $offers_base = imm_sections_clean_base_url($offers_base);

    if ($collection !== '') {
        $collection_base = rtrim($offers_base, '/') . '/CollectionOffers';
        $url = add_query_arg([
            'shopperID'   => $shopper_id,
            'Collection'  => $collection,
        ], $collection_base);
    } else {
        $url = add_query_arg([
            'shopperID' => $shopper_id,
            'offertype' => $offertype,
        ], $offers_base);
    }

    $cache_key = 'imm_fdo_v2_' . md5($url . '|' . imm_sections_imm_offer_cache_scope());
    $stale_key = $cache_key . '_stale';
    // Avoid stale transients for logged-in shoppers: ensures correct clipped/activated UI after reload.
    $disable_offers_cache = is_user_logged_in();
    $offers = $disable_offers_cache ? [] : imm_sections_filter_meaningful_offers(get_transient($cache_key));

    if (!is_array($offers) || empty($offers)) {
        $headers = ['Accept' => 'application/json'];
        $token = imm_sections_get_access_token(false);
        if (is_wp_error($token)) {
                // Serve stale/last-good offers if available to keep section live 24/7.
                $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                if (!empty($fallback)) {
                    $offers = $fallback;
                } else {
                    return $debug ? '<p>' . esc_html($token->get_error_message()) . '</p>' : '';
                }
        }
        if (!is_wp_error($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if (!empty($s['header_client']) && !empty($s['client_id'])) {
            $headers[$s['header_client']] = $s['client_id'];
        }
        $headers = imm_sections_imm_offers_merge_shopper_header($headers, $shopper_id);

        // Only call Offers API if we have a valid token; otherwise we're using stale cache.
        if (!is_wp_error($token)) {
            $res = wp_remote_get($url, [
                'timeout' => 15,
                'headers' => $headers,
            ]);

            if (is_wp_error($res)) {
                $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                if (!empty($fallback)) {
                    $offers = $fallback;
                } else {
                    return $debug ? '<p>API error: ' . esc_html($res->get_error_message()) . '</p>' : '';
                }
            } else {

                $code = wp_remote_retrieve_response_code($res);
                $body = wp_remote_retrieve_body($res);
                if ($code < 200 || $code >= 300) {
                    $body_preview = '';
                    if (is_string($body) && $body !== '') {
                        $body_preview = wp_strip_all_tags($body);
                        $body_preview = preg_replace('/\s+/', ' ', $body_preview);
                        $body_preview = substr($body_preview, 0, 300);
                    }

                    // If token expired/revoked unexpectedly, try one forced refresh then retry once.
                    if ($code === 401) {
                        $fresh = imm_sections_get_access_token(true);
                        if (!is_wp_error($fresh)) {
                            $headers['Authorization'] = 'Bearer ' . $fresh;
                            $headers = imm_sections_imm_offers_merge_shopper_header($headers, $shopper_id);
                            $res2 = wp_remote_get($url, [
                                'timeout' => 15,
                                'headers' => $headers,
                            ]);
                            if (!is_wp_error($res2)) {
                                $code2 = wp_remote_retrieve_response_code($res2);
                                $body2 = wp_remote_retrieve_body($res2);
                                if ($code2 >= 200 && $code2 < 300) {
                                    $json2 = json_decode($body2, true);
                                    $offers = imm_sections_imm_parse_offers_response(is_array($json2) ? $json2 : []);
                                    if (empty($offers)) {
                                        // API returned 200 but no offers; do not render an empty widget.
                                        $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                                        if (!empty($fallback)) {
                                            $offers = $fallback;
                                        }
                                    }
                                    if (!empty($offers)) {
                                        // Always update last-good so logged-in users can recover on later empty responses.
                                        imm_sections_save_last_good_offers($cache_key, $offers);
                                    }
                                    if (!$disable_offers_cache && !empty($offers)) {
                                        set_transient($cache_key, $offers, $cache_ttl);
                                        set_transient($stale_key, $offers, 120);
                                    }
                                } else {
                                    $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                                    if (!empty($fallback)) {
                                        $offers = $fallback;
                                    } else {
                                        $body_preview = '';
                                        if (is_string($body2) && $body2 !== '') {
                                            $body_preview = wp_strip_all_tags($body2);
                                            $body_preview = preg_replace('/\s+/', ' ', $body_preview);
                                            $body_preview = substr($body_preview, 0, 300);
                                        }
                                        return $debug
                                            ? '<p>API HTTP ' . esc_html((string)$code2) . ($body_preview ? (' — ' . esc_html($body_preview)) : '') . '</p>'
                                            : '';
                                    }
                                }
                            }
                        }
                    } else {
                        $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                        if (!empty($fallback)) {
                            $offers = $fallback;
                        } else {
                            return $debug
                                ? '<p>API HTTP ' . esc_html((string)$code) . ($body_preview ? (' — ' . esc_html($body_preview)) : '') . ($debug ? ('<br/><small>' . esc_html($url) . '</small>') : '') . '</p>'
                                : '';
                        }
                    }
                } else {
                    $json = json_decode($body, true);
                    $offers = imm_sections_imm_parse_offers_response(is_array($json) ? $json : []);
                    if (empty($offers)) {
                        // API returned 200 but no offers; keep the last-good offers visible.
                        $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                        if (!empty($fallback)) {
                            $offers = $fallback;
                        }
                    }
                    if (!empty($offers)) {
                        // Always update last-good so cache-bypass mode can recover later.
                        imm_sections_save_last_good_offers($cache_key, $offers);
                    }
                    if (!$disable_offers_cache && !empty($offers)) {
                        set_transient($cache_key, $offers, $cache_ttl);
                        set_transient($stale_key, $offers, 120);
                    }
                }
            }
        }
    }

    $money = function ($v) {
        if ($v === null || $v === '' || $v === 'N/A') return '';
        $n = floatval($v);
        $s = preg_replace('/\.00$/', '', number_format($n, 2, '.', ''));
        return '$' . $s;
    };
    $img_fallback = 'https://via.placeholder.com/600x400?text=Offer';
    $normalize_img = function ($url) use ($img_fallback) {
        $u = trim((string)$url);
        if ($u === '') return $img_fallback;
        // Common "no image" placeholder from IMM demo environment
        if (stripos($u, 'GEnoimage.jpg') !== false) return $img_fallback;
        // Avoid mixed-content blocks and prefer https when available
        if (stripos($u, 'http://') === 0) {
            $u = 'https://' . substr($u, 7);
        }
        return $u;
    };

    $title = esc_html(sanitize_text_field($atts['title']));
    $see_all_url = esc_url($atts['see_all_url']);
    $promo_image_url = isset($atts['promo_image_url']) ? esc_url($atts['promo_image_url']) : '';
    $promo_logo_url  = isset($atts['promo_logo_url']) ? esc_url($atts['promo_logo_url']) : '';
    $promo_cta       = isset($atts['promo_cta']) ? sanitize_text_field($atts['promo_cta']) : 'Activate Digital Offers to Save Even More!';
    if ($promo_image_url === '') {
        $promo_image_url = 'https://ridleysfamistg.wpenginepowered.com/wp-content/uploads/2026/03/BackgroundBorder-2.png';
    }
    if ($promo_logo_url === '') {
        $promo_logo_url = 'https://ridleysfamistg.wpenginepowered.com/wp-content/uploads/2026/03/Group-1000002704.png';
    }

    ob_start();
    ?>
    <section class="featured-digital-offer" data-imm-featured-offers-root="<?php echo esc_attr($fdo_uid); ?>" data-imm-shopper-id="<?php echo esc_attr((string) $shopper_id); ?>" aria-label="<?php echo $title; ?>">
      <div class="container">
        <div class="featured-header">
          <div class="featured-heading">
            <h2><?php echo $title; ?></h2>
          </div>
          <div class="featured-offer-btn">
            <a href="<?php echo $see_all_url; ?>">See All Deals
              <span>
                <svg width="14" height="12" viewBox="0 0 14 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                  <path d="M8.272 0.200309L13.776 5.44831C13.9253 5.59764 14 5.77898 14 5.99231C14 6.20564 13.9253 6.38698 13.776 6.53631L8.272 11.7843C8.12267 11.923 7.944 11.9923 7.736 11.9923C7.528 11.9923 7.352 11.9176 7.208 11.7683C7.064 11.619 6.99467 11.4376 7 11.2243C7.00533 11.011 7.08267 10.835 7.232 10.6963L11.376 6.74431H0.752C0.549333 6.74431 0.373333 6.66964 0.224 6.52031C0.0746667 6.37098 0 6.19231 0 5.98431C0 5.77631 0.0746667 5.60031 0.224 5.45631C0.373333 5.31231 0.549333 5.24031 0.752 5.24031H11.376L7.232 1.28831C7.08267 1.14964 7.00533 0.976309 7 0.768309C6.99467 0.560309 7.064 0.381642 7.208 0.232309C7.352 0.0829757 7.52267 0.00564233 7.72 0.000309001C7.91733 -0.00502433 8.10133 0.0616423 8.272 0.200309Z" fill="#11385C"/>
                </svg>
              </span>
            </a>
          </div>
        </div>

        <div class="featured-slider" data-featured-slider>
          <div class="featured-digital-grid featured-slider-track" data-slider-track>
          <div class="featured-digital-card">
            <div class="featured-digital-box">
              <div class="featured-img">
                <img src="<?php echo esc_url($promo_image_url); ?>" alt="Featured promo" loading="lazy" />
                <div class="featured-content-over">
                  <?php if ($promo_logo_url !== '') { ?>
                    <img src="<?php echo esc_url($promo_logo_url); ?>" alt="Promo logo" loading="lazy" />
                  <?php } ?>
                  <?php if ($promo_cta !== '') { ?>
                    <h3><?php echo esc_html($promo_cta); ?></h3>
                  <?php } ?>
                </div>
              </div>
            </div>
          </div>

          <?php
          $count = 0;
          foreach ($offers as $offer) {
              if ($count >= $limit) break;
              if (!is_array($offer)) continue;
              $offer = imm_sections_imm_normalize_offer_row($offer);

              $count++;

              $offerTitle = imm_sections_imm_offer_pick($offer, [
                  'OfferTitle', 'offerTitle', 'Title', 'title', 'OfferName', 'Name', 'ProductName', 'productName',
                  'ItemDescription', 'itemDescription', 'ShortDescription', 'shortDescription',
              ]);
              if ($offerTitle === '') {
                  $oid = imm_sections_imm_offer_pick($offer, ['OfferId', 'offerId', 'ExternalReferenceId', 'externalReferenceId']);
                  $typeName = imm_sections_imm_offer_pick($offer, ['OfferTypeDisplayName', 'offerTypeDisplayName']);
                  if ($oid !== '' && $oid !== '0') {
                      $offerTitle = trim(($typeName !== '' ? $typeName : 'Offer') . ' ' . $oid);
                  }
              }

              $badge = 'Digital Coupon';

              // API: clipped "Yes" = activated (see CollectionOffers payload).
              $clipped = imm_sections_imm_offer_is_clipped($offer);

              // Coupon ID needed for "Activate Now" (Coupon/Click).
              // Use strict coupon extraction to avoid sending OfferId by mistake.
              $coupon_id = imm_sections_imm_pick_click_coupon_id($offer);

              $sizeText = imm_sections_imm_offer_pick($offer, ['PackagingSize', 'packagingSize']);
              if ($sizeText === '') {
                  $sizeText = imm_sections_imm_offer_pick($offer, ['MinPurchaseQuantity', 'minPurchaseQuantity']);
                  if ($sizeText !== '' && strtoupper($sizeText) !== 'N/A') {
                      $sizeText .= ' qty';
                  } else {
                      $sizeText = '';
                  }
              }

              // "Must Buy X, Limit Y" row from CollectionOffers response.
              // Payload can vary (top-level, nested Offer, nested UPCList rows). Scan recursively.
              // Use key *priority* per node: a loose scan can pick MinQuantity before MinPurchaseQuantity
              // when both exist, which hides the correct "Must Buy" value.
              $pick_numeric_priority = function ($root, array $keys): int {
                  if (!is_array($root) || empty($keys)) return 0;
                  $wanted = [];
                  foreach ($keys as $k) {
                      $wanted[] = strtolower(trim((string)$k));
                  }
                  $queue = [$root];
                  $guard = 0;
                  while (!empty($queue) && $guard < 40) {
                      $guard++;
                      $cur = array_shift($queue);
                      if (!is_array($cur)) continue;

                      foreach ($wanted as $wk) {
                          foreach ($cur as $k => $v) {
                              if (!is_string($k)) continue;
                              if (strtolower(trim($k)) !== $wk) continue;
                              if (!is_scalar($v)) continue;
                              $s = trim((string)$v);
                              if ($s === '' || strtoupper($s) === 'N/A' || !is_numeric($s)) continue;
                              return (int) round((float) $s);
                          }
                      }

                      if (isset($cur['Offer']) && is_array($cur['Offer'])) $queue[] = $cur['Offer'];
                      if (isset($cur['offer']) && is_array($cur['offer'])) $queue[] = $cur['offer'];
                      if (isset($cur['UPCList']) && is_array($cur['UPCList'])) $queue[] = $cur['UPCList'];
                      if (isset($cur['upcList']) && is_array($cur['upcList'])) $queue[] = $cur['upcList'];

                      foreach ($cur as $v) {
                          if (is_array($v)) $queue[] = $v;
                      }
                  }
                  return 0;
              };

              $minQtyNum = $pick_numeric_priority($offer, [
                  'MinPurchaseQuantity', 'minPurchaseQuantity',
                  'MustBuyQuantity', 'mustBuyQuantity',
                  'MinimumPurchaseQuantity', 'minimumPurchaseQuantity',
                  'MinQuantity', 'minQuantity',
                  'MinimumQuantity', 'minimumQuantity',
              ]);
              $lifeLimitNum = $pick_numeric_priority($offer, [
                  'LifeTimeRedemptionLimit', 'lifeTimeRedemptionLimit',
                  'LifetimeRedemptionLimit', 'lifetimeRedemptionLimit',
                  'LifetimeLimit', 'lifetimeLimit',
              ]);
              $limitTxnNum = $pick_numeric_priority($offer, [
                  'LimitPerTransaction', 'limitPerTransaction',
                  'PerTransactionLimit', 'perTransactionLimit',
                  'TransactionLimit', 'transactionLimit',
              ]);
              // Card copy matches in-store phrasing: prefer per-transaction limit when present.
              $limitNum = ($limitTxnNum > 0) ? $limitTxnNum : $lifeLimitNum;
              // UX: hide "Must Buy 1" (show Must Buy only when quantity is > 1).
              $mustBuyQty = ($minQtyNum > 1) ? $minQtyNum : 0;
              $mustBuyLimitText = '';
              if ($mustBuyQty > 0 && $limitNum > 0) {
                  $mustBuyLimitText = 'Must Buy ' . $mustBuyQty . ', Limit ' . $limitNum;
              } elseif ($mustBuyQty > 0) {
                  $mustBuyLimitText = 'Must Buy ' . $mustBuyQty;
              } elseif ($limitNum > 0) {
                  $mustBuyLimitText = 'Limit ' . $limitNum;
              }

              $rewardType = imm_sections_imm_offer_pick($offer, ['OfferRewardType', 'offerRewardType']);
              $rewardVal  = imm_sections_imm_offer_pick($offer, ['OfferRewardValue', 'offerRewardValue']);
              $regRaw     = imm_sections_imm_offer_pick($offer, ['RegularUnitPrice', 'regularUnitPrice']);
              $reg    = $money($regRaw);

              // Mapping from API -> UI:
              // - OfferRewardType=2 means the user pays OfferRewardValue (fixed/final price)
              // - OfferRewardType=1 means OfferRewardValue is the amount-off
              // UI fields:
              // - original-price = RegularUnitPrice
              // - price-main     = final price
              // - discount-amount = negative savings (shown like -$1.00)
              $regularNum = is_numeric($regRaw) ? (float) $regRaw : 0.0;
              $rewardNum  = is_numeric($rewardVal) ? (float) $rewardVal : 0.0;

              $finalNum = 0.0;
              $discountNum = 0.0;
              if ($rewardType === '2') {
                  $finalNum = $rewardNum;
                  $discountNum = $regularNum - $finalNum;
              } elseif ($rewardType === '1') {
                  $discountNum = $rewardNum;
                  $finalNum = $regularNum - $discountNum;
              } else {
                  // Fallback: show regular unit price as final if reward type is unknown.
                  $finalNum = $regularNum;
                  $discountNum = 0.0;
              }

              // Main price: 0 or below => FREE (per UX spec).
              $finalFormatted = number_format((float) $finalNum, 2, '.', '');
              $priceDollars = '';
              $priceCents = '';
              $showFree = ((float) $finalNum <= 0);
              if (!$showFree && is_numeric($finalFormatted)) {
                  $parts = explode('.', $finalFormatted);
                  $priceDollars = $parts[0] ?? '';
                  $priceCents = $parts[1] ?? '00';
              }

              $discount = '';
              if ($discountNum > 0.00001) {
                  $discount = '-' . $money((string)$discountNum);
              }

              // Original / unit price line: same FREE rule when 0 or negative.
              $regLabel = ((float) $regularNum <= 0) ? 'FREE' : $reg;

              $expiryKeys = [
                  'Validity', 'validity',
                  'ExpirationDate', 'expirationDate',
                  'ExpireDate', 'expireDate',
                  'EndDate', 'endDate',
                  'ValidUntil', 'validUntil',
                  'ExpiresOn', 'expiresOn',
                  'OfferExpirationDate', 'offerExpirationDate',
                  'RedemptionEndDate', 'redemptionEndDate',
              ];
              $validityRaw = imm_sections_imm_offer_pick($offer, $expiryKeys, '');
              if ($validityRaw === '') {
                  $validityRaw = imm_sections_imm_offer_pick_deep($offer, $expiryKeys, '');
              }
              $expText = '';
              if ($validityRaw !== '') {
                  $ts = imm_sections_imm_parse_expiry_timestamp($validityRaw);
                  if ($ts !== false) {
                      $expText = 'Exp ' . date('n/j', $ts);
                  } else {
                      $v = trim((string) $validityRaw);
                      if ($v !== '' && strtoupper($v) !== 'N/A') {
                          $expText = 'Exp ' . $v;
                      }
                  }
              }

              $img = imm_sections_imm_offer_pick($offer, [
                  'ImageUrl', 'imageUrl', 'Image', 'image', 'ThumbnailUrl', 'thumbnailUrl',
                  'ProductImageUrl', 'productImageUrl', 'LargeImageUrl', 'largeImageUrl',
              ]);
              $img = $normalize_img($img);
              ?>
              <div class="featured-digital-card bg-white-box<?php echo $clipped ? ' is-clipped' : ''; ?>">
                <div class="featured-digital-box">
                  <div class="coupon-box">
                    <span class="label"><?php echo esc_html($badge ?: 'Digital Coupon'); ?></span>
                  </div>

                  <div class="featured-digitalimg">
                    <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($offerTitle ?: 'Offer'); ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo esc_url($img_fallback); ?>';" />
                  </div>

                  <div class="featured-digitalimg-content">
                    <?php if ($sizeText !== '') { ?>
                      <span class="items-text"><?php echo esc_html($sizeText); ?></span>
                    <?php } ?>
                    <h3><?php echo esc_html($offerTitle); ?></h3>
                    <div class="imm-featured-mustbuy-limit<?php echo ($mustBuyLimitText === '') ? ' is-empty' : ''; ?>">
                      <?php echo ($mustBuyLimitText !== '') ? esc_html($mustBuyLimitText) : '&nbsp;'; ?>
                    </div>

                    <div class="price-container">
                      <div class="price-main<?php echo $showFree ? ' imm-price-is-free' : ''; ?>">
                        <?php if ($showFree) { ?>
                          <span class="imm-price-free">FREE</span>
                        <?php } else { ?>
                          <span class="currency">$</span>
                          <span class="price-dollars"><?php echo esc_html($priceDollars); ?></span>
                          <span class="price-cents"><?php echo esc_html($priceCents); ?></span>
                        <?php } ?>
                      </div>
                      <?php if ($expText !== '') { ?>
                        <div class="price-exp"><?php echo esc_html($expText); ?></div>
                      <?php } ?>
                    </div>

                    <?php if ($clipped) { ?>
                      <span class="imm-featured-activate-btn imm-featured-activate-btn--state imm-featured-activate-btn--activated" aria-current="true">
                        <span class="icon">✓</span> Activated
                      </span>
                    <?php } else { ?>
                      <a href="#" class="imm-featured-activate-btn imm-featured-activate-btn--state" data-coupon-id="<?php echo esc_attr((string)$coupon_id); ?>">
                        <span class="icon">✓</span> Activate Now
                      </a>
                    <?php } ?>
                  </div>
                </div>
              </div>
              <?php
          }
          ?>
          </div>

          <div class="featured-slider-footer" role="group" aria-label="Offer carousel">
            <button class="featured-slider-nav featured-slider-nav--prev" type="button" aria-label="Previous offers" data-slider-nav="prev">
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7.5 10.5L3 6l4.5-4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button class="featured-slider-nav featured-slider-nav--next" type="button" aria-label="Next offers" data-slider-nav="next">
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4.5 1.5L9 6l-4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
      </div>
    </section>
    <script>
      (function () {
        var root = document.querySelector('[data-imm-featured-offers-root="<?php echo esc_js($fdo_uid); ?>"]');
        if (!root) return;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var clipNonce = <?php echo wp_json_encode($clip_nonce); ?>;
        var shopperId = (root.getAttribute('data-imm-shopper-id') || '').replace(/\D+/g, '');

        root.querySelectorAll('a.imm-featured-activate-btn').forEach(function (btn) {
          if (!btn || btn.dataset.immClipBound === '1') return;
          btn.dataset.immClipBound = '1';

          btn.addEventListener('click', function (e) {
            e.preventDefault();
            var card = btn.closest('.featured-digital-card');
            if (card && card.classList.contains('is-clipped')) return;

            var couponId = (btn.getAttribute('data-coupon-id') || '').trim();
            if (!couponId) {
              console.warn('IMM Featured Offers: missing coupon_id for click', btn);
              btn.innerHTML = '<span class="icon">!</span> Unavailable';
              btn.style.pointerEvents = '';
              return;
            }

            var prevHtml = btn.innerHTML;
            btn.style.pointerEvents = 'none';
            btn.innerHTML = '<span class="icon">✓</span> Activating…';

            var body = new URLSearchParams({
              action: 'imm_sections_clip_offer_ajax',
              nonce: clipNonce,
              coupon_id: couponId,
              shopper_id: shopperId
            });

            fetch(ajaxUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
              body: body.toString()
            })
              .then(function (r) {
                return r.text().then(function (t) {
                  try { return JSON.parse(t); } catch (e) {
                    return { success: false, data: { message: 'Invalid server response' } };
                  }
                });
              })
              .then(function (json) {
                if (json && json.success) {
                  if (card) card.classList.add('is-clipped');
                  var sp = document.createElement('span');
                  sp.className = 'imm-featured-activate-btn imm-featured-activate-btn--state imm-featured-activate-btn--activated';
                  sp.setAttribute('aria-current', 'true');
                  sp.innerHTML = '<span class="icon">✓</span> Activated';
                  if (btn.parentNode) btn.parentNode.replaceChild(sp, btn);
                } else {
                  var errMsg = '';
                  if (json && json.data) {
                    if (typeof json.data === 'string') errMsg = json.data;
                    else if (json.data.message) errMsg = String(json.data.message);
                  }
                  btn.innerHTML = prevHtml;
                }
              })
              .catch(function () {
                btn.innerHTML = prevHtml;
              })
              .finally(function () {
                btn.style.pointerEvents = '';
              });
          });
        });
      })();
    </script>
    <?php
    return ob_get_clean() . imm_sections_get_carousel_styles_once();
});

// ------------------------------
// Cashback section (KACU)
// ------------------------------

function imm_kacu_get_settings() {
    // Allow secret values to be provided via environment variables (recommended)
    // rather than hardcoding them into the codebase.
    //
    // Example:
    //   IMM_GOOGLE_GEOCODE_API_KEY="AIza..."
    //   IMM_STORES_BEARER_TOKEN="..."
    //   IMM_SHOPPER_UPDATE_BEARER_TOKEN="..."
    $imm_env = function (string $key): string {
        $v = '';
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) $v = $_ENV[$key];
        if ($v === '' && function_exists('getenv')) {
            $ev = getenv($key);
            if (is_string($ev)) $v = $ev;
        }
        $v = trim($v);
        return $v;
    };

    $defaults = [
        'base_url' => 'https://stagingclientapi.kacu.app',
        'token_url' => 'https://stagingclientapi.kacu.app/API/V1.0/Token',
        'offers_url' => 'https://stagingclientapi.kacu.app/API/V1.0/Offers/All',
        /** POST bearer + form body OfferID & ShopperID — separate from token credentials (Bearer comes from Token API). */
        'activate_url' => 'https://stagingclientapi.kacu.app/API/V1.0/Offers/Activate',
        'apikey' => '',
        'apisecret' => '',
        'clientid' => '',
        // Default request params for the Cash Back shortcode
        // IMPORTANT: environment-specific IDs must be configured in WP Admin -> Settings.
        'companyid' => '',
        'storeid' => '115',
        'loyaltycard' => '49008470134',
        'shopperprofileid' => '',
        'shopperid' => '',
        // Store finder (IMM) — server-side proxy used by Change Store popup
        'stores_lookup_url' => 'https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/StoresByCurrentLocation',
        'stores_bearer_token' => '',
        'shopper_update_store_url' => 'https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/ShopperUpdateStore',
        'shopper_update_bearer_token' => '',
        // Google Geocoding (server-side) → lat/lng for StoresByCurrentLocation
        'google_geocode_api_key' => '',
        'default_geocode_address' => 'arizona',
    ];
    $opt = get_option('imm_sections_kacu_settings', []);
    if (!is_array($opt)) $opt = [];

    $merged = array_merge($defaults, $opt);

    // Env overrides only when WP settings are empty.
    $env_overrides = [
        'google_geocode_api_key' => 'IMM_GOOGLE_GEOCODE_API_KEY',
        'stores_bearer_token' => 'IMM_STORES_BEARER_TOKEN',
        'shopper_update_bearer_token' => 'IMM_SHOPPER_UPDATE_BEARER_TOKEN',
    ];
    foreach ($env_overrides as $setting_key => $env_key) {
        $current = isset($merged[$setting_key]) ? (string)$merged[$setting_key] : '';
        if (trim($current) === '') {
            $from_env = $imm_env($env_key);
            if ($from_env !== '') $merged[$setting_key] = $from_env;
        }
    }

    // ShopperID is never sourced from saved settings (SSO JWT only). Ignore legacy DB value.
    $merged['shopperid'] = '';

    return $merged;
}

function imm_kacu_get_token($force_refresh = false) {
    $s = imm_kacu_get_settings();
    if (empty($s['token_url'])) return new WP_Error('kacu_no_token_url', 'KACU token URL missing.');
    if (empty($s['apikey']) || empty($s['apisecret']) || empty($s['clientid'])) {
        return new WP_Error('kacu_missing_creds', 'KACU credentials missing in WP settings.');
    }

    // Separate cache per token URL + ClientID + Apikey so credential changes do not reuse old tokens.
    $cache_key = 'imm_kacu_at_' . md5((string)$s['token_url'] . '|' . (string)$s['clientid'] . '|' . (string)$s['apikey']);
    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') return $cached;
    }

    /**
     * KACU token (Cash Back only — different from IMM OAuth):
     * POST token_url
     * Headers: Apikey, ApiSecret, ClientID, Content-Type: application/x-www-form-urlencoded
     * Body: grant_type=password
     */
    $req = [
        'timeout' => 15,
        'headers' => [
            'Apikey' => (string)$s['apikey'],
            'ApiSecret' => (string)$s['apisecret'],
            'ClientID' => (string)$s['clientid'],
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'WordPress/IMM-Sections',
        ],
        'body' => [
            'grant_type' => 'password',
        ],
        'redirection' => 0,
    ];
    $req = apply_filters('imm_kacu_token_request', $req, $s);
    $res = wp_remote_post($s['token_url'], $req);

    if (is_wp_error($res)) return $res;
    $code = wp_remote_retrieve_response_code($res);
    $raw  = wp_remote_retrieve_body($res);
    if ($code < 200 || $code >= 300) {
        $preview = '';
        if (is_string($raw) && $raw !== '') {
            $preview = wp_strip_all_tags($raw);
            $preview = preg_replace('/\s+/', ' ', $preview);
            $preview = substr($preview, 0, 300);
        }
        return new WP_Error('kacu_token_http', 'KACU token failed HTTP ' . esc_html((string)$code) . ($preview ? (' — ' . $preview) : ''));
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || empty($json['access_token'])) return new WP_Error('kacu_token_bad_json', 'KACU token response missing access_token.');

    $token = (string)$json['access_token'];
    $expires_in = isset($json['expires_in']) ? intval($json['expires_in']) : 3600;
    $ttl = max(30, $expires_in - 120);
    set_transient($cache_key, $token, $ttl);
    return $token;
}

/**
 * Resolve KACU Offers/Activate URL (Cash Back activate button — uses Bearer from Token API).
 */
function imm_kacu_activate_endpoint_url() {
    $k = imm_kacu_get_settings();
    $u = isset($k['activate_url']) ? trim((string)$k['activate_url']) : '';
    if ($u !== '') {
        return esc_url_raw($u);
    }
    if (!empty($k['base_url'])) {
        return esc_url_raw(rtrim((string)$k['base_url'], '/') . '/API/V1.0/Offers/Activate');
    }
    return 'https://stagingclientapi.kacu.app/API/V1.0/Offers/Activate';
}

add_shortcode('imm_cashback_offers', function ($atts) {
    if (imm_sections_shortcode_guest_returns_empty()) {
        return '';
    }
    imm_sections_mark_page_non_cacheable_for_personalized_content();
    imm_sections_request_carousel_assets();
    $atts = shortcode_atts([
        'title' => 'Cash Back',
        'see_all_url' => 'https://ridstaging.immdemo.net/pages/cashback',
        'limit' => 12,
        'cache_ttl' => 30,
        'debug' => '0',

        // KACU required params (from your curl)
        'companyid' => '',
        'storeid' => '',
        'loyaltycard' => '',
        // Optional (from Postman example)
        'shopperprofileid' => '',
        'shopperid' => '',

        // Optional: if you have a working bearer token, you can pass it directly.
        // This matches your curl for immediate testing. For 24/7, we should switch
        // to auto-fetch (token refresh) using KACU credentials.
        'kacu_bearer_token' => '',

        // Promo tile
        'promo_image_url' => '',
        'promo_logo_url' => '',
        'promo_cta' => 'Start Your Online Order',
    ], $atts, 'imm_cashback_offers');

    $title = esc_html(sanitize_text_field($atts['title']));
    $see_all_url = esc_url($atts['see_all_url']);
    $clip_nonce = wp_create_nonce('imm_sections_clip_offer_nonce');

    $limit = max(1, min(50, intval($atts['limit'])));
    // Keep offers fresh; short TTL prevents "stuck" Activated/Clipped state.
    $cache_ttl = max(10, min(120, intval($atts['cache_ttl'])));

    $companyid = preg_replace('/[^0-9]/', '', (string)$atts['companyid']);
    $storeid = preg_replace('/[^0-9]/', '', (string)$atts['storeid']);
    $loyaltycard = preg_replace('/[^0-9]/', '', (string)$atts['loyaltycard']);
    $shopperprofileid = preg_replace('/[^0-9]/', '', (string)$atts['shopperprofileid']);
    $shopperid = preg_replace('/[^0-9]/', '', (string)$atts['shopperid']);

    // If these aren't provided via shortcode, pull them from WP Settings.
    $kacu = imm_kacu_get_settings();
    if ($companyid === '' && !empty($kacu['companyid'])) $companyid = preg_replace('/[^0-9]/', '', (string)$kacu['companyid']);
    if ($storeid === '' && !empty($kacu['storeid'])) $storeid = preg_replace('/[^0-9]/', '', (string)$kacu['storeid']);
    if ($loyaltycard === '' && !empty($kacu['loyaltycard'])) $loyaltycard = preg_replace('/[^0-9]/', '', (string)$kacu['loyaltycard']);
    if ($shopperprofileid === '' && !empty($kacu['shopperprofileid'])) $shopperprofileid = preg_replace('/[^0-9]/', '', (string)$kacu['shopperprofileid']);
    if ($shopperid === '') {
        $shopperid = imm_sections_current_shopper_id();
    }
    // Per-user identity from SSO (avoid one global loyalty/store in settings for every shopper).
    if (is_user_logged_in()) {
        $uid = (int) get_current_user_id();
        if ($loyaltycard === '') {
            $lc_meta = get_user_meta($uid, 'imm_loyalty_card', true);
            if (is_string($lc_meta) && $lc_meta !== '') {
                $loyaltycard = preg_replace('/[^0-9]/', '', $lc_meta);
            }
        }
        if ($storeid === '') {
            $st_meta = preg_replace('/[^0-9]/', '', (string)get_user_meta($uid, 'imm_store_id', true));
            if ($st_meta !== '') {
                $storeid = $st_meta;
            }
        }
    }

    if ($companyid === '' || $storeid === '' || $loyaltycard === '') {
        return '<p>KACU params missing (companyid/storeid/loyaltycard).</p>';
    }

    $promo_image_url = trim((string)$atts['promo_image_url']);
    $promo_logo_url  = trim((string)$atts['promo_logo_url']);
    $promo_cta = sanitize_text_field((string)$atts['promo_cta']);
    if ($promo_image_url === '') {
        $promo_image_url = 'https://ridleysfamistg.wpenginepowered.com/wp-content/uploads/2026/03/Rectangle-240651011-1.png';
    }

    $offers_url = $kacu['offers_url'];
    if (empty($offers_url)) return '<p>KACU offers URL missing.</p>';

    $cache_key = 'imm_kacu_offers_' . md5($companyid . '|' . $storeid . '|' . $loyaltycard . '|' . $shopperprofileid . '|' . $shopperid);
    $stale_key = $cache_key . '_stale';
    // Avoid cached offers for logged-in shoppers to prevent mismatched clipped/activated UI after changes.
    $disable_offers_cache = is_user_logged_in();
    $offers = $disable_offers_cache ? [] : imm_sections_filter_meaningful_offers(get_transient($cache_key));
    if (!is_array($offers) || empty($offers)) {
        $debug = ((string)$atts['debug'] === '1');

        $bearer = trim((string)$atts['kacu_bearer_token']);
        $used_bearer_token = ($bearer !== '');
        if ($bearer !== '') {
            $token = $bearer;
        } else {
            $token = imm_kacu_get_token(false);
            if (is_wp_error($token)) {
                $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                if (!empty($fallback)) {
                    $offers = $fallback;
                } else {
                    return $debug
                        ? '<p>Cash Back is temporarily unavailable.</p><p><small>' . esc_html($token->get_error_message()) . '</small></p>'
                        : '';
                }
            }
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'WordPress/IMM-Sections',
        ];
        if (!is_wp_error($token)) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $body = [
            'CompanyID' => $companyid,
            'StoreID' => $storeid,
            'LoyaltyCard' => $loyaltycard,
        ];
        if ($shopperprofileid !== '') $body['ShopperProfileID'] = $shopperprofileid;
        if ($shopperid !== '') $body['ShopperID'] = $shopperid;

        $res = null;
        if (!is_wp_error($token)) {
            $res = wp_remote_post($offers_url, [
                'timeout' => 20,
                'headers' => $headers,
                'body' => $body,
                'redirection' => 0,
            ]);
        }

        $res_ok = ($res !== null) && !is_wp_error($res);
        $code = 0;
        $raw  = '';
        if (!is_wp_error($res)) {
            $code = wp_remote_retrieve_response_code($res);
            $raw  = wp_remote_retrieve_body($res);
            if ($code < 200 || $code >= 300) $res_ok = false;
        }

        // If a bearer token was passed manually and it's expired, try auto-refresh once.
        if (!$res_ok && $used_bearer_token) {
            $token2 = imm_kacu_get_token(true);
            if (!is_wp_error($token2)) {
                $headers['Authorization'] = 'Bearer ' . $token2;
                $res = wp_remote_post($offers_url, [
                    'timeout' => 20,
                    'headers' => $headers,
                    'body' => $body,
                    'redirection' => 0,
                ]);
                $res_ok = !is_wp_error($res);
                $code = 0;
                $raw  = '';
                if (!is_wp_error($res)) {
                    $code = wp_remote_retrieve_response_code($res);
                    $raw  = wp_remote_retrieve_body($res);
                    if ($code < 200 || $code >= 300) $res_ok = false;
                }
            }
        }

        if (!$res_ok) {
            $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
            if (!empty($fallback)) {
                $offers = $fallback;
            } else {
            if ($debug) {
                $preview = '';
                if (is_string($raw) && $raw !== '') {
                    $preview = wp_strip_all_tags($raw);
                    $preview = preg_replace('/\s+/', ' ', $preview);
                    $preview = substr($preview, 0, 300);
                }
                return '<p>Cash Back is temporarily unavailable.</p><p><small>HTTP=' . esc_html((string)$code) . ($preview ? (' — ' . esc_html($preview)) : '') . '</small></p>';
            }
            return '';
            }
        }

        $json = json_decode($raw, true);
        $offers = [];
        if (is_array($json)) {
            if (isset($json['Message']) && is_array($json['Message'])) {
                $offers = $json['Message'];
            } elseif (isset($json['message']) && is_array($json['message'])) {
                $offers = $json['message'];
            }
        }

        if (!$disable_offers_cache) {
            set_transient($cache_key, $offers, $cache_ttl);
            set_transient($stale_key, $offers, 120);
            imm_sections_save_last_good_offers($cache_key, $offers);
        }
    }

    $money = function ($v) {
        if ($v === null || $v === '' || $v === 'N/A') return '';
        $n = floatval($v);
        $s = preg_replace('/\.00$/', '', number_format($n, 2, '.', ''));
        return '$' . $s;
    };

    $img_fallback = 'https://via.placeholder.com/600x400?text=Offer';
    $normalize_img = function ($url) use ($img_fallback) {
        $u = trim((string)$url);
        if ($u === '') return $img_fallback;
        if (stripos($u, 'GEnoimage.jpg') !== false) return $img_fallback;
        if (stripos($u, 'http://') === 0) $u = 'https://' . substr($u, 7);
        return $u;
    };

    ob_start();
    ?>
    <section class="featured-digital-offer cashback-offers" data-imm-cashback-root="<?php echo esc_attr($cashback_uid); ?>" data-imm-shopper-id="<?php echo esc_attr((string) $shopperid); ?>" aria-label="<?php echo $title; ?>">
      <div class="container">
        <div class="featured-header">
          <div class="featured-heading">
            <h2><?php echo $title; ?></h2>
          </div>
          <div class="featured-offer-btn">
            <a href="<?php echo $see_all_url; ?>" style="background:transparent !important;color:#10395d !important;border:0 !important;border-radius:100px !important;padding:13px 18px !important;display:inline-flex !important;align-items:center !important;gap:6px !important;white-space:nowrap !important;text-decoration:none !important;">
              See All Deals
              <span>
                <svg width="14" height="12" viewBox="0 0 14 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                  <path d="M8.272 0.200309L13.776 5.44831C13.9253 5.59764 14 5.77898 14 5.99231C14 6.20564 13.9253 6.38698 13.776 6.53631L8.272 11.7843C8.12267 11.923 7.944 11.9923 7.736 11.9923C7.528 11.9923 7.352 11.9176 7.208 11.7683C7.064 11.619 6.99467 11.4376 7 11.2243C7.00533 11.011 7.08267 10.835 7.232 10.6963L11.376 6.74431H0.752C0.549333 6.74431 0.373333 6.66964 0.224 6.52031C0.0746667 6.37098 0 6.19231 0 5.98431C0 5.77631 0.0746667 5.60031 0.224 5.45631C0.373333 5.31231 0.549333 5.24031 0.752 5.24031H11.376L7.232 1.28831C7.08267 1.14964 7.00533 0.976309 7 0.768309C6.99467 0.560309 7.064 0.381642 7.208 0.232309C7.352 0.0829757 7.52267 0.00564233 7.72 0.000309001C7.91733 -0.00502433 8.10133 0.0616423 8.272 0.200309Z" fill="#ffffff"/>
                </svg>
              </span>
            </a>
          </div>
        </div>

        <div class="featured-slider" data-featured-slider>
          <div class="featured-digital-grid featured-slider-track" data-slider-track>
            <div class="featured-digital-card">
              <div class="featured-digital-box">
                <div class="featured-img">
                  <img src="<?php echo esc_url($promo_image_url); ?>" alt="Promo" loading="lazy" />
                  <div class="featured-content-over">
                    <?php if ($promo_logo_url !== '') { ?>
                      <img src="<?php echo esc_url($promo_logo_url); ?>" alt="Promo logo" loading="lazy" />
                    <?php } ?>
                  </div>
                </div>
              </div>
            </div>

            <?php
          $count = 0;
          foreach ($offers as $offer) {
              if ($count >= $limit) break;
              if (!is_array($offer)) continue;
              $offer = imm_sections_imm_normalize_offer_row($offer);
              $count++;

              // Flexible field mapping for KACU + IMM-like payload variants.
              $offerTitle = imm_sections_imm_offer_pick($offer, [
                  'OfferTitle', 'offerTitle', 'Title', 'title', 'OfferName', 'offerName',
                  'ProductName', 'productName', 'ItemDescription', 'itemDescription',
              ]);
              $offerDesc = imm_sections_imm_offer_pick($offer, [
                  'OfferDescription', 'offerDescription', 'ShortDescription', 'shortDescription',
                  'LongDescription', 'longDescription', 'Description', 'description',
                  'SubTitle', 'subtitle', 'OfferText', 'offerText',
              ]);
              $img = imm_sections_imm_offer_pick($offer, [
                  'Image', 'image', 'ImageUrl', 'imageUrl', 'ThumbnailUrl', 'thumbnailUrl',
                  'ProductImageUrl', 'productImageUrl', 'LargeImageUrl', 'largeImageUrl',
              ]);
              $img = $normalize_img($img);

              // KACU has an "activated/clipped" flag, but field casing may vary.
              $isActivated = false;
              $parseBoolish = function ($v): bool {
                  if ($v === true || $v === 1) return true;
                  $s = strtolower(trim((string)$v));
                  return $s === 'true' || $s === '1' || $s === 'yes';
              };

              $isActivatedRaw = imm_sections_imm_offer_pick($offer, ['IsActivated', 'isActivated', 'isactivated', 'Is_Activated'], '');
              $clippedRaw = imm_sections_imm_offer_pick($offer, ['Clipped', 'clipped', 'isClipped', 'is_clipped', 'IsClipped'], '');

              if ($isActivatedRaw !== '') {
                  $isActivated = $parseBoolish($isActivatedRaw);
              }
              if ($clippedRaw !== '') {
                  // Clipped overrides if it's explicitly true.
                  $isActivated = $isActivated || $parseBoolish($clippedRaw);
              }

              // Coupon ID needed for "Activate Now" (Coupon/Click).
              $coupon_id = imm_sections_imm_pick_click_coupon_id($offer);

              $badge = imm_sections_imm_offer_pick($offer, [
                  'Badge', 'badge', 'OfferTypeDisplayName', 'offerTypeDisplayName', 'OfferCategory', 'offerCategory',
              ], 'Cash Back');
              $qty = imm_sections_imm_offer_pick($offer, ['MinQuantity', 'minQuantity', 'MinPurchaseQuantity', 'minPurchaseQuantity']);
              $rewardType = imm_sections_imm_offer_pick($offer, ['OfferRewardType', 'offerRewardType']);
              $rewardValue = imm_sections_imm_offer_pick($offer, ['OfferRewardValue', 'offerRewardValue', 'Cashback', 'cashback']);
              $brandName = imm_sections_imm_offer_pick($offer, ['BrandName', 'brandName', 'Brand', 'brand']);
              $saveText = '';
              if ($rewardType === '1' && $rewardValue !== '' && is_numeric($rewardValue) && (float)$rewardValue > 0) {
                  $saveText = 'Save ' . $money($rewardValue);
              } elseif ($rewardType === '3' && $rewardValue !== '' && is_numeric($rewardValue) && (float)$rewardValue > 0) {
                  $saveText = rtrim(rtrim(number_format((float)$rewardValue, 2, '.', ''), '0'), '.') . '% Off';
              } elseif ($rewardValue !== '' && is_numeric($rewardValue) && (float)$rewardValue > 0) {
                  $saveText = 'Save ' . $money($rewardValue);
              }

              if ($offerDesc === '' || (strlen(trim($offerDesc)) <= 6 && preg_match('/^[0-9.\-]+$/', trim($offerDesc)))) {
                  $parts = [];
                  if ($qty !== '' && strtoupper($qty) !== 'N/A') $parts[] = 'When you buy ' . $qty;
                  if ($saveText !== '') $parts[] = $saveText;
                  $offerDesc = implode(' - ', $parts);
              }
              if ($offerTitle === '') {
                  $fallbackName = imm_sections_imm_offer_pick($offer, ['BrandName', 'brandName', 'DepartmentName', 'departmentName']);
                  if ($fallbackName !== '') $offerTitle = $fallbackName;
              }
              $cashbackLine = '';
              if ($rewardValue !== '' && is_numeric($rewardValue) && (float)$rewardValue > 0) {
                  $cashbackLine = 'Get ' . $money($rewardValue) . ' Cash back';
              } elseif ($saveText !== '') {
                  $cashbackLine = 'Get ' . $saveText;
              }
              $displayTitle = ($brandName !== '') ? $brandName : $offerTitle;
              if ($badge === '' || stripos($badge, 'weekly') !== false) {
                  $badge = 'Cash Back';
              }
              if ($offerTitle === '' && $offerDesc === '') {
                  continue;
              }
              ?>
              <div class="featured-digital-card bg-white-box<?php echo $isActivated ? ' is-clipped' : ''; ?>">
                <div class="featured-digital-box">
                  <div class="coupon-box">
                    <span class="label"><?php echo esc_html($badge); ?></span>
                  </div>

                  <div class="featured-digitalimg">
                    <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($offerTitle ?: 'Cash Back'); ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo esc_url($img_fallback); ?>';" />
                  </div>

                  <div class="featured-digitalimg-content">
                    <h3><?php echo esc_html($displayTitle); ?></h3>
                    <?php if ($cashbackLine !== '') { ?>
                      <p class="imm-cashback-line"><?php echo esc_html($cashbackLine); ?></p>
                    <?php } ?>
                    <?php if ($offerTitle !== '' && $offerTitle !== $displayTitle) { ?>
                      <p><?php echo esc_html($offerTitle); ?></p>
                    <?php } elseif ($offerDesc !== '') { ?>
                      <p><?php echo esc_html($offerDesc); ?></p>
                    <?php } ?>

                    <a href="#"
                      class="imm-kacu-activate-btn"
                      data-coupon-id="<?php echo esc_attr((string)$coupon_id); ?>"
                    >
                      <span class="icon">✓</span> <?php echo $isActivated ? 'Activated' : 'Activate Now'; ?>
                    </a>
                  </div>
                </div>
              </div>
              <?php
          }
            ?>

          </div>

          <div class="featured-slider-footer" role="group" aria-label="Offer carousel">
            <button class="featured-slider-nav featured-slider-nav--prev" type="button" aria-label="Previous offers" data-slider-nav="prev">
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7.5 10.5L3 6l4.5-4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button class="featured-slider-nav featured-slider-nav--next" type="button" aria-label="Next offers" data-slider-nav="next">
              <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4.5 1.5L9 6l-4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
          </div>
        </div>
      </div>
    </section>
    <script>
      (function () {
        var root = document.querySelector('[data-imm-cashback-root="<?php echo esc_js($cashback_uid); ?>"]');
        if (!root) return;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var clipNonce = <?php echo wp_json_encode($clip_nonce); ?>;
        var shopperId = (root.getAttribute('data-imm-shopper-id') || '').replace(/\D+/g, '');

        root.querySelectorAll('.imm-kacu-activate-btn').forEach(function (btn) {
          if (!btn || btn.dataset.immCashbackClipBound === '1') return;
          btn.dataset.immCashbackClipBound = '1';

          btn.addEventListener('click', function (e) {
            e.preventDefault();

            var card = btn.closest('.featured-digital-card');
            if (card && card.classList.contains('is-clipped')) return;

            var couponId = (btn.getAttribute('data-coupon-id') || '').trim();
            if (!couponId) {
              console.warn('IMM Cash Back: missing coupon_id for click', btn);
              btn.innerHTML = '<span class="icon">!</span> Unavailable';
              btn.style.pointerEvents = '';
              return;
            }

            var prevHtml = btn.innerHTML;
            btn.style.pointerEvents = 'none';
            btn.innerHTML = '<span class="icon">✓</span> Activating…';

            var body = new URLSearchParams({
              action: 'imm_kacu_activate_offer_ajax',
              nonce: clipNonce,
              coupon_id: couponId,
              shopper_id: shopperId
            });

            fetch(ajaxUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
              body: body.toString()
            })
              .then(function (r) { return r.json(); })
              .then(function (json) {
                if (json && json.success) {
                  if (card) card.classList.add('is-clipped');
                  btn.innerHTML = '<span class="icon">✓</span> Activated';
                } else {
                  btn.innerHTML = prevHtml;
                }
              })
              .catch(function () {
                btn.innerHTML = prevHtml;
              })
              .finally(function () {
                btn.style.pointerEvents = '';
              });
          });
        });
      })();
    </script>
    <?php
    return ob_get_clean() . imm_sections_get_carousel_styles_once();
});

/**
 * Flatten IMM offer row when details live under nested "Offer".
 */
function imm_sections_imm_normalize_offer_row($row) {
    if (!is_array($row)) return [];
    if (!empty($row['Offer']) && is_array($row['Offer'])) {
        return array_merge($row, $row['Offer']);
    }
    return $row;
}

/**
 * Whether a CollectionOffers row is already clipped/activated.
 * Supports clipped / Clipped = "Yes" | "No", booleans, and 0/1 (and common activated flags).
 */
function imm_sections_imm_offer_is_clipped(array $offer) {
    $eval = static function ($v) {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return ((int)$v) === 1;
        }
        $s = strtolower(trim((string)$v));
        if ($s === 'yes' || $s === 'y' || $s === 'true' || $s === '1') {
            return true;
        }
        if ($s === 'no' || $s === 'n' || $s === 'false' || $s === '0') {
            return false;
        }
        return null;
    };

    $keys = ['Clipped', 'clipped', 'IsClipped', 'isClipped', 'is_clipped', 'IsActivated', 'isActivated', 'Activated', 'activated'];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $offer)) {
            continue;
        }
        $r = $eval($offer[$k]);
        if ($r !== null) {
            return $r;
        }
    }
    // Some payloads use different casing (e.g. CLIPPED) — scan keys.
    foreach ($offer as $k => $v) {
        if (!is_string($k)) {
            continue;
        }
        $kl = strtolower($k);
        if ($kl === 'clipped' || $kl === 'isclipped' || $kl === 'is_clipped' || $kl === 'isactivated' || $kl === 'activated') {
            $r = $eval($v);
            if ($r !== null) {
                return $r;
            }
        }
    }
    return false;
}

/**
 * First non-empty scalar from an offer (tries multiple API key spellings).
 */
function imm_sections_imm_offer_pick($offer, array $keys, $default = '') {
    if (!is_array($offer)) return $default;
    foreach ($keys as $k) {
        if (!array_key_exists($k, $offer)) continue;
        $v = $offer[$k];
        if ($v === null || $v === '') continue;
        if (is_scalar($v)) {
            $s = trim((string)$v);
            if ($s !== '' && strtoupper($s) !== 'N/A') return $s;
        }
    }
    return $default;
}

/**
 * Like imm_sections_imm_offer_pick but scans nested Offer / UPCList (BFS) with key priority order.
 */
function imm_sections_imm_offer_pick_deep($offer, array $keys, $default = '') {
    if (!is_array($offer) || empty($keys)) {
        return $default;
    }
    $wanted = [];
    foreach ($keys as $k) {
        $wanted[] = strtolower(trim((string) $k));
    }
    $queue = [$offer];
    $guard = 0;
    while (!empty($queue) && $guard < 40) {
        $guard++;
        $cur = array_shift($queue);
        if (!is_array($cur)) {
            continue;
        }

        foreach ($wanted as $wk) {
            foreach ($cur as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                if (strtolower(trim($k)) !== $wk) {
                    continue;
                }
                if ($v === null || $v === '') {
                    continue;
                }
                if (is_scalar($v)) {
                    $s = trim((string) $v);
                    if ($s !== '' && strtoupper($s) !== 'N/A') {
                        return $s;
                    }
                }
            }
        }

        if (isset($cur['Offer']) && is_array($cur['Offer'])) {
            $queue[] = $cur['Offer'];
        }
        if (isset($cur['offer']) && is_array($cur['offer'])) {
            $queue[] = $cur['offer'];
        }
        if (isset($cur['UPCList']) && is_array($cur['UPCList'])) {
            $queue[] = $cur['UPCList'];
        }
        if (isset($cur['upcList']) && is_array($cur['upcList'])) {
            $queue[] = $cur['upcList'];
        }

        foreach ($cur as $v) {
            if (is_array($v)) {
                $queue[] = $v;
            }
        }
    }
    return $default;
}

/**
 * Best-effort CouponID extractor for Coupon/Click calls.
 * IMPORTANT: Prefer explicit coupon keys only (avoid OfferId/ExternalReferenceId where possible),
 * because passing a non-coupon identifier to Coupon/Click causes API 400.
 */
function imm_sections_imm_pick_click_coupon_id($offer) {
    if (!is_array($offer)) {
        return '';
    }
    $coupon = imm_sections_imm_offer_pick_deep($offer, [
        'CouponID', 'CouponId', 'couponID', 'couponId',
        'CouponCode', 'couponCode',
    ], '');
    if ($coupon !== '') {
        return preg_replace('/[^0-9]/', '', (string) $coupon);
    }

    // Common nested wrappers.
    foreach (['ClippedOffer', 'clippedOffer', 'Offer', 'offer'] as $k) {
        if (!isset($offer[$k]) || !is_array($offer[$k])) continue;
        $v = imm_sections_imm_offer_pick_deep($offer[$k], [
            'CouponID', 'CouponId', 'couponID', 'couponId',
            'CouponCode', 'couponCode',
        ], '');
        if ($v !== '') {
            return preg_replace('/[^0-9]/', '', (string) $v);
        }
    }

    // Final fallback only when explicit coupon keys are absent.
    return imm_sections_imm_pick_coupon_id($offer);
}

/**
 * Parse API expiry into a Unix timestamp for display, or false if unknown.
 */
function imm_sections_imm_parse_expiry_timestamp($raw) {
    $raw = trim((string) $raw);
    if ($raw === '' || strtoupper($raw) === 'N/A') {
        return false;
    }
    if (preg_match('/^\d+$/', $raw)) {
        $n = (int) $raw;
        if (strlen($raw) >= 13) {
            $n = (int) round($n / 1000);
        }
        if ($n > 0) {
            return $n;
        }
        return false;
    }
    // IMM API often returns US-style strings, e.g. "12/13/2027 11:59:59 PM" (Validity).
    $usFormats = [
        'm/d/Y g:i:s A',
        'm/d/Y g:i A',
        'm/d/Y H:i:s',
        'm/d/Y',
    ];
    foreach ($usFormats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $raw);
        if ($dt instanceof DateTimeImmutable) {
            $err = DateTimeImmutable::getLastErrors();
            $ok = ($err === false)
                || ((($err['error_count'] ?? 0) === 0) && (($err['warning_count'] ?? 0) === 0));
            if ($ok) {
                return $dt->getTimestamp();
            }
        }
    }
    $ts = strtotime($raw);
    if ($ts !== false && $ts > 0) {
        return $ts;
    }
    return false;
}

/**
 * Best-effort numeric coupon identifier extractor.
 * This helps when gateway payload field names vary (CouponID vs OfferId vs ExternalReferenceId, etc).
 */
function imm_sections_imm_pick_coupon_id($offer) {
    if (!is_array($offer)) return '';

    $preferKey = function ($key) {
        $lk = strtolower((string)$key);
        // Prefer keys that strongly indicate "coupon/reward/offer id" rather than store/session ids.
        if (strpos($lk, 'coupon') !== false) return 3;
        if (strpos($lk, 'externalreference') !== false) return 2;
        if (strpos($lk, 'offerid') !== false || strpos($lk, 'offer_id') !== false) return 2;
        if (strpos($lk, 'reward') !== false || strpos($lk, 'redemption') !== false) return 1;
        if (strpos($lk, 'id') !== false) return 0;
        return -1;
    };

    $best = '';
    $bestScore = -999;

    foreach ($offer as $k => $v) {
        if (!is_scalar($v)) continue;
        $digits = preg_replace('/[^0-9]/', '', (string)$v);
        if ($digits === '') continue;

        $score = $preferKey($k);
        if ($score > $bestScore) {
            $best = $digits;
            $bestScore = $score;
        }
        // Early exit for very strong matches.
        if ($bestScore >= 3) break;
    }

    return $best;
}

/**
 * Whether an array is a 0..n-1 list (JSON array).
 */
function imm_sections_imm_is_numeric_list($arr) {
    if (!is_array($arr) || $arr === []) {
        return false;
    }
    $i = 0;
    foreach ($arr as $k => $_) {
        if ((string)(int)$k !== (string)$k || (int)$k !== $i) {
            return false;
        }
        $i++;
    }
    return true;
}

/**
 * True if this row looks like one IMM/KACU offer object.
 */
function imm_sections_imm_row_looks_like_offer($row) {
    if (!is_array($row)) {
        return false;
    }
    $keys = [
        'OfferId', 'offerId', 'ExternalReferenceId', 'externalReferenceId',
        'OfferTitle', 'offerTitle', 'Title', 'title', 'OfferName', 'Name',
        'Image', 'image', 'ImageUrl', 'imageUrl', 'ThumbnailUrl', 'thumbnailUrl',
        'Offer', 'offer', 'BrandName', 'brandName',
    ];
    foreach ($keys as $k) {
        if (array_key_exists($k, $row)) {
            return true;
        }
    }
    return false;
}

/**
 * Extract offer rows from IMM Offers JSON (supports message.OfferList, message as array, etc.).
 */
function imm_sections_imm_parse_offers_response($json) {
    if (!is_array($json)) {
        return [];
    }
    $paths = [
        ['message', 'OfferList'],
        ['message', 'offerList'],
        ['Message', 'OfferList'],
        ['Message', 'offerList'],
        ['message', 'Offerlist'],
        ['OfferList'],
        ['offerList'],
    ];
    foreach ($paths as $path) {
        $cur = $json;
        $ok = true;
        foreach ($path as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                $ok = false;
                break;
            }
            $cur = $cur[$p];
        }
        if (!$ok || !is_array($cur) || $cur === []) {
            continue;
        }
        if (imm_sections_imm_row_looks_like_offer($cur) && !imm_sections_imm_is_numeric_list($cur)) {
            return [$cur];
        }
        $first = reset($cur);
        if (is_array($first) && imm_sections_imm_row_looks_like_offer($first)) {
            return array_values($cur);
        }
    }

    foreach (['message', 'Message'] as $mk) {
        if (!isset($json[$mk]) || !is_array($json[$mk])) {
            continue;
        }
        $m = $json[$mk];
        if ($m === []) {
            continue;
        }
        if (imm_sections_imm_is_numeric_list($m)) {
            $first = reset($m);
            if (is_array($first) && imm_sections_imm_row_looks_like_offer($first)) {
                return array_values($m);
            }
        }
    }

    // Fallback: recursively scan payload for the first array of offer-like objects.
    // This is important because IMM reward payloads can vary their nesting keys.
    $maxDepth = 6;
    $scan = function ($node, $depth) use (&$scan, $maxDepth) {
        if ($depth > $maxDepth) return null;

        if (!is_array($node)) {
            return null;
        }

        // Direct offer object.
        if (imm_sections_imm_row_looks_like_offer($node) && !imm_sections_imm_is_numeric_list($node)) {
            return [$node];
        }

        // Numeric list of offers.
        if (imm_sections_imm_is_numeric_list($node)) {
            $first = reset($node);
            if (is_array($first) && imm_sections_imm_row_looks_like_offer($first)) {
                return array_values($node);
            }
        }

        // Associative/unknown structure: scan children values.
        foreach ($node as $v) {
            $res = $scan($v, $depth + 1);
            if (is_array($res) && !empty($res)) {
                return $res;
            }
        }

        return null;
    };

    $scanRes = $scan($json, 0);
    if (is_array($scanRes) && !empty($scanRes)) {
        return $scanRes;
    }

    return [];
}

/**
 * IMM shopper ID for Offers APIs: optional shortcode override, else current SSO user (JWT → user meta).
 * ShopperID is identity from login — it is not read from plugin settings.
 */
function imm_sections_imm_resolve_shopper_id($from_shortcode) {
    $id = preg_replace('/[^0-9]/', '', (string)$from_shortcode);
    if ($id !== '') {
        return $id;
    }
    $id = imm_sections_current_shopper_id();
    if ($id !== '') {
        return $id;
    }
    return '';
}

// ------------------------------
// Third section: Advantage Rewards / Reward Coupon (IMM Offers, offertype=6)
// ------------------------------
add_shortcode('imm_advantage_rewards', function ($atts) {
    if (imm_sections_shortcode_guest_returns_empty()) {
        return '';
    }
    imm_sections_request_carousel_assets();
    $adv_uid = wp_unique_id('imm-adv-rewards-');
    $clip_nonce = wp_create_nonce('imm_sections_clip_offer_nonce');
    $atts = shortcode_atts([
        'title' => 'Reward Coupon', // change heading here
        'see_all_url' => 'https://ridstaging.immdemo.net/pages/loyalty-reward',
        'shopper_id' => '',
        'offertype' => '6',
        'limit' => 12, // max reward cards (promo tile still counts as first slide if set)
        'promo_image_url' => '', // optional: if provided, renders the first "View Rewards" tile
        'promo_alt' => 'ViewRewards',
        'promo_link_url' => '#',
        'debug' => '0',
    ], $atts, 'imm_advantage_rewards');

    $title = esc_html(sanitize_text_field((string)$atts['title']));
    $see_all_url = esc_url((string)$atts['see_all_url']);
    if ($see_all_url === '' || $see_all_url === '#') {
        $see_all_url = esc_url('https://ridstaging.immdemo.net/pages/loyalty-reward');
    }
    $promo_image_url = trim((string)$atts['promo_image_url']);
    $promo_alt = esc_attr(sanitize_text_field((string)$atts['promo_alt']));
    $promo_link_url = esc_url((string)$atts['promo_link_url']);
    if ($promo_image_url === '') {
        $promo_image_url = 'https://ridleysfamistg.wpenginepowered.com/wp-content/uploads/2026/03/Group-1000003480.png';
    }

    $shopper_id = imm_sections_imm_resolve_shopper_id($atts['shopper_id'] ?? '');
    $debug      = ((string)$atts['debug'] === '1');
    imm_sections_mark_page_non_cacheable_for_personalized_content();
    if (apply_filters('imm_sections_require_shopper_id_for_imm_offers', is_user_logged_in()) && $shopper_id === '') {
        return $debug ? '<p>ShopperID is required for personalized rewards.</p>' : '';
    }
    // Reward coupons always use OfferType=6 (per API contract).
    $offertype  = '6';
    $limit      = max(1, min(12, intval($atts['limit'])));

    $s = imm_sections_get_settings();
    $offers_base = !empty($s['offers_base']) ? $s['offers_base'] : 'https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/Offers';
    $offers_base = imm_sections_clean_base_url($offers_base);

    // Endpoint example (from you): /Offers?shopperID=...&offertype=6
    $url = add_query_arg([
        'shopperID' => $shopper_id,
        'offertype' => $offertype,
    ], $offers_base);

    $cache_key = 'imm_adv_rewards_v5_' . md5($url . '|' . imm_sections_imm_offer_cache_scope());
    $stale_key = $cache_key . '_stale';
    // Avoid stale transients for logged-in shoppers so clipped/activated UI matches current API state.
    $disable_offers_cache = is_user_logged_in();
    $offers = $disable_offers_cache ? [] : imm_sections_filter_meaningful_offers(get_transient($cache_key));
    $debug_meta = [
        'shopper_id' => $shopper_id,
        'offertype' => $offertype,
        'url' => $url,
        'http_code' => '',
        'source' => 'cache',
        'offers_count' => is_array($offers) ? count($offers) : 0,
        'note' => '',
    ];

    if (!is_array($offers) || empty($offers)) {
        $headers = ['Accept' => 'application/json'];
        $token = imm_sections_get_access_token(false);
        $debug_meta['source'] = 'api';

        if (is_wp_error($token)) {
            $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
            if (!empty($fallback)) {
                $offers = $fallback;
                $debug_meta['source'] = 'stale_fallback';
                $debug_meta['offers_count'] = count($offers);
                $debug_meta['note'] = 'Token error; served stale fallback.';
            } else {
                $offers = [];
                $debug_meta['note'] = 'Token error and no stale fallback: ' . $token->get_error_message();
            }
        } else {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        if (!empty($s['header_client']) && !empty($s['client_id'])) {
            $headers[$s['header_client']] = $s['client_id'];
        }
        $headers = imm_sections_imm_offers_merge_shopper_header($headers, $shopper_id);

        $res = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => $headers,
        ]);

        if (is_wp_error($res)) {
            $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
            if (!empty($fallback)) {
                $offers = $fallback;
            } else {
                $offers = [];
            }
        } else {
            $code = wp_remote_retrieve_response_code($res);
            $body = wp_remote_retrieve_body($res);
            $debug_meta['http_code'] = (string)$code;

            if ($code < 200 || $code >= 300) {
                $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                if (!empty($fallback)) {
                    $offers = $fallback;
                    $debug_meta['source'] = 'stale_fallback';
                    $debug_meta['offers_count'] = count($offers);
                    $debug_meta['note'] = 'Offers API HTTP ' . $code . '; served stale fallback.';
                } else {
                    $offers = [];
                    $debug_meta['note'] = 'Offers API HTTP ' . $code . ' and no stale fallback.';
                }
            } else {
                $json = json_decode($body, true);
                $offers = imm_sections_imm_parse_offers_response(is_array($json) ? $json : []);
                $debug_meta['offers_count'] = is_array($offers) ? count($offers) : 0;
                if (empty($offers)) {
                    $debug_meta['note'] = 'Parsed 0 offers from response payload.';

                    // API returned 200 but empty offers; keep last-good visible.
                    $fallback = imm_sections_get_fallback_offers($stale_key, $cache_key);
                    if (!empty($fallback)) {
                        $offers = $fallback;
                        $debug_meta['note'] = 'Parsed 0 offers; served stale/last-good fallback.';
                        $debug_meta['offers_count'] = is_array($offers) ? count($offers) : 0;
                    }
                }
                // Always update last-good so logged-in cache-bypass mode can recover later.
                if (!empty($offers)) {
                    imm_sections_save_last_good_offers($cache_key, $offers);
                }
                // Cache + keep a stale copy to avoid downtime (only when caching is enabled).
                if (!$disable_offers_cache && !empty($offers)) {
                    set_transient($cache_key, $offers, 60);
                    set_transient($stale_key, $offers, 120);
                }
            }
        }
    }

    $img_fallback = 'https://via.placeholder.com/270x172?text=Reward';
    $normalize_img = function ($url) use ($img_fallback) {
        $u = trim((string)$url);
        if ($u === '') return $img_fallback;
        if (stripos($u, 'GEnoimage.jpg') !== false) return $img_fallback;
        if (stripos($u, 'http://') === 0) $u = 'https://' . substr($u, 7);
        return $u;
    };

    $money = function ($v) {
        if ($v === null || $v === '' || $v === 'N/A') return '';
        $n = floatval($v);
        $s = preg_replace('/\.00$/', '', number_format($n, 2, '.', ''));
        return (string)$s;
    };
    $fmt_dollar = function ($v) {
        if ($v === null || $v === '' || $v === 'N/A') return '';
        $n = floatval($v);
        $s = preg_replace('/\.00$/', '', number_format($n, 2, '.', ''));
        return '$' . $s;
    };

    ob_start();
    ?>
    <section class="everyday-savings advantage-rewards" data-imm-adv-rewards-root="<?php echo esc_attr($adv_uid); ?>" data-imm-shopper-id="<?php echo esc_attr((string) $shopper_id); ?>" aria-label="<?php echo $title; ?>">
      <div class="container">
        <div class="featured-header">
          <div class="featured-heading">
            <h2><?php echo $title; ?></h2>
          </div>
          <div class="featured-offer-btn">
            <a href="<?php echo esc_url($see_all_url); ?>">
              View Rewards
              <span>
                <svg width="14" height="12" viewBox="0 0 14 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                  <path d="M8.272 0.200309L13.776 5.44831C13.9253 5.59764 14 5.77898 14 5.99231C14 6.20564 13.9253 6.38698 13.776 6.53631L8.272 11.7843C8.12267 11.923 7.944 11.9923 7.736 11.9923C7.528 11.9923 7.352 11.9176 7.208 11.7683C7.064 11.619 6.99467 11.4376 7 11.2243C7.00533 11.011 7.08267 10.835 7.232 10.6963L11.376 6.74431H0.752C0.549333 6.74431 0.373333 6.66964 0.224 6.52031C0.0746667 6.37098 0 6.19231 0 5.98431C0 5.77631 0.0746667 5.60031 0.224 5.45631C0.373333 5.31231 0.549333 5.24031 0.752 5.24031H11.376L7.232 1.28831C7.08267 1.14964 7.00533 0.976309 7 0.768309C6.99467 0.560309 7.064 0.381642 7.208 0.232309C7.352 0.0829757 7.52267 0.00564233 7.72 0.000309001C7.91733 -0.00502433 8.10133 0.0616423 8.272 0.200309Z" fill="#11385C" />
                </svg>
              </span>
            </a>
          </div>
        </div>

        <div class="featured-slider" data-featured-slider>
        <?php if ($debug) { ?>
          <div style="margin:0 0 12px 0;padding:10px 12px;background:#f8fafc;border:1px solid #dbe3ee;border-radius:8px;color:#11385c;font:12px/1.4 monospace;">
            <?php echo 'shopperID=' . esc_html($debug_meta['shopper_id']); ?> |
            <?php echo 'offertype=' . esc_html($debug_meta['offertype']); ?> |
            <?php echo 'source=' . esc_html($debug_meta['source']); ?> |
            <?php echo 'http=' . esc_html($debug_meta['http_code'] !== '' ? $debug_meta['http_code'] : 'n/a'); ?> |
            <?php echo 'offers=' . esc_html((string)$debug_meta['offers_count']); ?>
            <?php if ($debug_meta['note'] !== '') { ?>
              <br/><?php echo esc_html($debug_meta['note']); ?>
            <?php } ?>
            <br/><?php echo esc_html($debug_meta['url']); ?>
          </div>
        <?php } ?>
        <div class="featured-digital-grid featured-slider-track" data-slider-track>
          <?php if ($promo_image_url !== '') { ?>
            <div class="featured-digital-card promo-tile">
              <div class="featured-digital-box">
                <div class="featured-img">
                  <a href="<?php echo esc_url($promo_link_url); ?>">
                    <img src="<?php echo esc_url($normalize_img($promo_image_url)); ?>" alt="<?php echo $promo_alt; ?>" loading="lazy" />
                  </a>
                </div>
              </div>
            </div>
          <?php } ?>

          <?php
          $count = 0;
          foreach ($offers as $offer) {
              if ($count >= $limit) break;
              if (!is_array($offer)) continue;
              $offer = imm_sections_imm_normalize_offer_row($offer);

              $offerTitle = imm_sections_imm_offer_pick($offer, [
                  'OfferTitle', 'offerTitle', 'Title', 'title', 'OfferName', 'Name', 'ProductName', 'productName',
              ]);
              if ($offerTitle === '') {
                  $oid = imm_sections_imm_offer_pick($offer, ['OfferId', 'offerId', 'ExternalReferenceId', 'externalReferenceId']);
                  if ($oid !== '' && $oid !== '0') $offerTitle = 'Offer #' . $oid;
              }

              // Clipped may vary by gateway version/casing.
              $clippedVal = imm_sections_imm_offer_pick($offer, ['Clipped', 'clipped', 'IsClipped', 'is_clipped'], '');
              $clipped = (
                strtolower((string)$clippedVal) === 'yes' ||
                strtolower((string)$clippedVal) === 'true' ||
                (string)$clippedVal === '1'
              );

              // Coupon ID needed for the clip/coupon click endpoint.
              $coupon_id = imm_sections_imm_pick_click_coupon_id($offer);

              $img = imm_sections_imm_offer_pick($offer, [
                  'ImageUrl', 'imageUrl', 'Image', 'image', 'ThumbnailUrl', 'thumbnailUrl',
                  'ProductImageUrl', 'productImageUrl', 'LargeImageUrl', 'largeImageUrl',
              ]);
              $img = $normalize_img($img);

              $rewardType = imm_sections_imm_offer_pick($offer, ['OfferRewardType', 'offerRewardType']);
              $rewardValue = imm_sections_imm_offer_pick($offer, ['OfferRewardValue', 'offerRewardValue']);

              // Show points ONLY when API provides explicit points fields.
              $pointsRaw = imm_sections_imm_offer_pick($offer, [
                  'Points', 'points', 'PointCost', 'pointCost', 'RewardPoints', 'rewardPoints',
                  'LoyaltyPoints', 'loyaltyPoints', 'RedemptionPoints', 'redemptionPoints',
                  'OfferPoints', 'offerPoints', 'PointsRequired', 'pointsRequired',
              ]);
              $pointsNum = ($pointsRaw !== '' && is_numeric($pointsRaw)) ? (float)$pointsRaw : 0.0;

              $highlightText = '';
              if ($pointsRaw !== '' && $pointsNum > 0.00001) {
                  // Points display: show plain number (no $ sign) to match UI spec.
                  $pointsDisplay = preg_replace('/\.00$/', '', number_format((float)$pointsNum, 2, '.', ''));
                  $pointsDisplay = rtrim(rtrim($pointsDisplay, '0'), '.');
                  $highlightText = $pointsDisplay . ' Points';
              } elseif ($rewardType === '2') {
                  // Fixed price coupon: reward value is the final price.
                  if ($rewardValue !== '' && is_numeric($rewardValue) && (float)$rewardValue > 0) {
                      $highlightText = $fmt_dollar($rewardValue);
                  }
              } elseif ($rewardType === '1') {
                  if ($rewardValue !== '' && is_numeric($rewardValue) && (float)$rewardValue > 0) {
                      $highlightText = 'Save ' . $fmt_dollar($rewardValue);
                  }
              } elseif ($rewardType === '3') {
                  // Percentage discount.
                  if ($rewardValue !== '' && is_numeric($rewardValue) && (float)$rewardValue > 0) {
                      $highlightText = rtrim(rtrim(number_format((float)$rewardValue, 2, '.', ''), '0'), '.') . '% Off';
                  }
              }

              $desc = imm_sections_imm_offer_pick($offer, [
                  'Longdescription', 'longdescription',
                  'LongDescription', 'longDescription',
                  'OfferDescription', 'offerDescription',
                  'ShortDescription', 'shortDescription',
                  'OfferRewardDescription', 'OfferTitleDescription',
                  'OfferRewardMessage', 'OfferMessage', 'offerMessage', 'Description', 'description',
                  'RewardDescription', 'RewardMessage', 'rewardMessage', 'RewardText', 'rewardText',
              ]);
              if ($desc !== '' && strlen($desc) <= 6 && preg_match('/^[0-9.\-]+$/', trim($desc))) {
                  $desc = '';
              }

              if ($desc === '') {
                  $parts = [];
                  $pk = imm_sections_imm_offer_pick($offer, ['PackagingSize', 'packagingSize']);
                  if ($pk !== '') $parts[] = $pk;
                  $mq = imm_sections_imm_offer_pick($offer, ['MinPurchaseQuantity', 'minPurchaseQuantity']);
                  if ($mq !== '' && strtoupper($mq) !== 'N/A') $parts[] = 'Min qty: ' . $mq;
                  $ma = imm_sections_imm_offer_pick($offer, ['MinPurchaseAmount', 'minPurchaseAmount']);
                  if ($ma !== '' && strtoupper($ma) !== 'N/A' && is_numeric($ma) && (float)$ma > 0) {
                      $parts[] = 'Min spend: ' . $fmt_dollar($ma);
                  }
                  $regp = imm_sections_imm_offer_pick($offer, ['RegularUnitPrice', 'regularUnitPrice']);
                  if ($regp !== '' && is_numeric($regp) && (float)$regp > 0) {
                      $parts[] = 'Reg. ' . $fmt_dollar($regp);
                  }
                  $val = imm_sections_imm_offer_pick($offer, ['Validity', 'validity']);
                  if ($val !== '') $parts[] = $val;
                  $desc = implode(' • ', array_filter($parts));
              }
              // Avoid API numeric codes as description text.
              if ($desc !== '' && strlen($desc) <= 3 && ctype_digit(trim($desc))) {
                  $desc = '';
                  $parts = [];
                  $pk = imm_sections_imm_offer_pick($offer, ['PackagingSize', 'packagingSize']);
                  if ($pk !== '') $parts[] = $pk;
                  $mq = imm_sections_imm_offer_pick($offer, ['MinPurchaseQuantity', 'minPurchaseQuantity']);
                  if ($mq !== '' && strtoupper($mq) !== 'N/A') $parts[] = 'Min qty: ' . $mq;
                  $ma = imm_sections_imm_offer_pick($offer, ['MinPurchaseAmount', 'minPurchaseAmount']);
                  if ($ma !== '' && strtoupper($ma) !== 'N/A' && is_numeric($ma) && (float)$ma > 0) {
                      $parts[] = 'Min spend: ' . $fmt_dollar($ma);
                  }
                  $regp = imm_sections_imm_offer_pick($offer, ['RegularUnitPrice', 'regularUnitPrice']);
                  if ($regp !== '' && is_numeric($regp) && (float)$regp > 0) {
                      $parts[] = 'Reg. ' . $fmt_dollar($regp);
                  }
                  $val = imm_sections_imm_offer_pick($offer, ['Validity', 'validity']);
                  if ($val !== '') $parts[] = $val;
                  $desc = implode(' • ', array_filter($parts));
              }

              // Skip malformed rows (common when API returns partial placeholders).
              if ($offerTitle === '' && $highlightText === '' && $desc === '') {
                  continue;
              }

              $count++;
              ?>
              <div class="featured-digital-card bg-white-box<?php echo $clipped ? ' is-clipped' : ''; ?>">
                <div class="featured-digital-box">
                  <div class="featured-digitalimg">
                    <img
                      src="<?php echo esc_url($img); ?>"
                      alt="<?php echo esc_attr($offerTitle ?: 'Reward'); ?>"
                      loading="lazy"
                      onerror="this.onerror=null;this.src='<?php echo esc_url($img_fallback); ?>';"
                    />
                  </div>

                  <div class="featured-digitalimg-content">
                    <h3><?php echo esc_html($offerTitle); ?></h3>
                    <p><?php echo esc_html($desc); ?></p>

                    <div class="dozen-btn">
                      <span><?php echo esc_html($highlightText); ?></span>
                    </div>

                    <div class="imm-reward-bottom-bar">Loyalty Rewards</div>
                  </div>
                </div>
              </div>
              <?php
          }
          ?>
        </div>

        <div class="featured-slider-footer" role="group" aria-label="Rewards carousel">
          <button class="featured-slider-nav featured-slider-nav--prev" type="button" aria-label="Previous rewards" data-slider-nav="prev">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M7.5 10.5L3 6l4.5-4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
          <button class="featured-slider-nav featured-slider-nav--next" type="button" aria-label="Next rewards" data-slider-nav="next">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M4.5 1.5L9 6l-4.5 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
        </div>
        </div>
      </div>
    </section>
    <script>
      (function () {
        var root = document.querySelector('[data-imm-adv-rewards-root="<?php echo esc_js($adv_uid); ?>"]');
        if (!root) return;
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var clipNonce = <?php echo wp_json_encode($clip_nonce); ?>;
        var shopperId = (root.getAttribute('data-imm-shopper-id') || '').replace(/\D+/g, '');

        root.querySelectorAll('.imm-reward-activate-btn[data-coupon-id]').forEach(function (btn) {
          if (!btn || btn.dataset.immAdvRewardClipBound === '1') return;
          btn.dataset.immAdvRewardClipBound = '1';

          btn.addEventListener('click', function (e) {
            e.preventDefault();

            var card = btn.closest('.featured-digital-card');
            if (card && card.classList.contains('is-clipped')) return;

            var couponId = (btn.getAttribute('data-coupon-id') || '').trim();
            if (!couponId) {
              console.warn('IMM Reward Coupons: missing coupon_id for click', btn);
              btn.innerHTML = '<span class="icon">!</span> Unavailable';
              btn.style.pointerEvents = '';
              return;
            }

            var prevHtml = btn.innerHTML;
            btn.style.pointerEvents = 'none';
            btn.textContent = 'Redeeming...';

            var body = new URLSearchParams({
              action: 'imm_sections_clip_offer_ajax',
              nonce: clipNonce,
              coupon_id: couponId,
              shopper_id: shopperId
            });

            fetch(ajaxUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
              body: body.toString()
            })
              .then(function (r) { return r.json(); })
              .then(function (json) {
                if (json && json.success) {
                  if (card) card.classList.add('is-clipped');
                  // Requirement: hide Redeemed/redeem button completely after success.
                  // (Button may not exist in fresh HTML, but keep this safe for cached pages.)
                  if (btn && btn.parentNode) btn.remove();
                } else {
                  btn.innerHTML = prevHtml;
                }
              })
              .catch(function () {
                btn.innerHTML = prevHtml;
              })
              .finally(function () {
                btn.style.pointerEvents = '';
              });
          });
        });
      })();
    </script>
    <?php
    return ob_get_clean() . imm_sections_get_carousel_styles_once();
});

/**
 * Embedded Locations page shortcode (map + search + store cards).
 * Usage: [imm_locations_page]
 */
add_shortcode('imm_locations_page', function ($atts) {
    if (imm_sections_shortcode_guest_returns_empty()) {
        return '';
    }

    $atts = shortcode_atts([
        'title' => 'Locations',
        'search_placeholder' => 'Enter a City, State, or Zip Code',
    ], $atts, 'imm_locations_page');

    $title = sanitize_text_field((string)$atts['title']);
    $search_placeholder = sanitize_text_field((string)$atts['search_placeholder']);

    $kacu = imm_kacu_get_settings();
    $default_shopper = imm_sections_current_shopper_id();
    $nonce = wp_create_nonce('imm_sections_find_store_nonce');
    $has_google_geocode = !empty(trim((string)($kacu['google_geocode_api_key'] ?? '')));
    $default_geocode_address = isset($kacu['default_geocode_address']) && (string)$kacu['default_geocode_address'] !== ''
        ? (string)$kacu['default_geocode_address']
        : 'arizona';

    $uid = wp_unique_id('imm-locations-');

    ob_start();
    ?>
    <section class="imm-locations-page" id="<?php echo esc_attr($uid); ?>">
      <style>
        .imm-locations-page{padding:28px 0 48px;background:#fff}
        .imm-locations-page .imm-locations-wrap{max-width:1320px;margin:0 auto;padding:0 15px}
        .imm-locations-page .imm-locations-grid{display:grid;grid-template-columns:minmax(280px,1fr) minmax(320px,460px);gap:24px;align-items:start}
        .imm-locations-page .imm-locations-map-card{border:1px solid #dbe3ee;border-radius:12px;overflow:hidden;background:#f8fafc}
        .imm-locations-page .imm-locations-map-frame{width:100%;height:380px;border:0;display:block}
        .imm-locations-page .imm-locations-panel h2{margin:0 0 14px;color:#10395d;font-family:"Poppins",sans-serif;font-size:36px;line-height:1.1;font-weight:600}
        .imm-locations-page .imm-locations-search{position:relative;margin-bottom:10px}
        .imm-locations-page .imm-locations-search input{width:100%;height:44px;border:1px solid #cdd6e4;border-radius:22px;padding:0 46px 0 14px;font-size:14px;color:#10395d;background:#fff}
        .imm-locations-page .imm-locations-search button{position:absolute;right:6px;top:6px;width:32px;height:32px;border:0;border-radius:50%;background:transparent;cursor:pointer;color:#9ca3af}
        .imm-locations-page .imm-locations-status{font-size:13px;color:#475569;min-height:1.2em;margin:8px 0}
        .imm-locations-page .imm-locations-status.is-error{color:#b91c1c}
        .imm-locations-page .imm-locations-list{display:flex;flex-direction:column;gap:10px;max-height:460px;overflow:auto;padding-right:4px}
        .imm-locations-page .imm-location-card{border:1px solid #dbe3ee;border-radius:10px;padding:10px 12px;background:#fff}
        .imm-locations-page .imm-location-head{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}
        .imm-locations-page .imm-location-name{color:#10395d;font-size:15px;line-height:1.25;font-weight:700}
        .imm-locations-page .imm-location-distance{font-size:11px;color:#64748b;border:1px solid #e2e8f0;border-radius:8px;padding:2px 6px;white-space:nowrap}
        .imm-locations-page .imm-location-address{color:#334155;font-size:13px;line-height:1.35;margin-top:4px}
        .imm-locations-page .imm-location-open{color:#166534;font-size:12px;margin-top:5px}
        .imm-locations-page .imm-location-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;align-items:center}
        .imm-locations-page .imm-choose-store-btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 14px;border-radius:6px;border:1px solid #10395d;background:#10395d;color:#fff;font-size:12px;font-weight:600;cursor:pointer}
        .imm-locations-page .imm-link{font-size:12px;color:#10395d;text-decoration:underline}
        .imm-locations-page .imm-link:hover{opacity:.9}
        .imm-locations-page .imm-location-empty{border:1px dashed #cbd5e1;border-radius:10px;padding:14px;font-size:13px;color:#475569;background:#f8fafc}
        @media (max-width: 960px){
          .imm-locations-page .imm-locations-grid{grid-template-columns:1fr;gap:16px}
          .imm-locations-page .imm-locations-map-frame{height:260px}
          .imm-locations-page .imm-locations-panel h2{font-size:32px}
        }
      </style>

      <div class="imm-locations-wrap">
        <div class="imm-locations-grid">
          <div class="imm-locations-map-card">
            <iframe class="imm-locations-map-frame" title="Store map" loading="lazy"></iframe>
          </div>

          <div class="imm-locations-panel">
            <h2><?php echo esc_html($title); ?></h2>
            <div class="imm-locations-search">
              <input type="text" class="imm-locations-input" placeholder="<?php echo esc_attr($search_placeholder); ?>" autocomplete="off" />
              <button type="button" class="imm-locations-search-btn" aria-label="Search locations">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
              </button>
            </div>
            <div class="imm-locations-status" role="status"></div>
            <div class="imm-locations-list"></div>
          </div>
        </div>
      </div>

      <script>
        (function () {
          var root = document.getElementById(<?php echo wp_json_encode($uid); ?>);
          if (!root) return;
          if (root.dataset.immLocationsInit === '1') return;
          root.dataset.immLocationsInit = '1';

          var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
          var nonce = <?php echo wp_json_encode($nonce); ?>;
          var shopperId = <?php echo wp_json_encode($default_shopper); ?>;
          var hasGoogleGeocode = <?php echo $has_google_geocode ? 'true' : 'false'; ?>;
          var defaultGeocodeAddress = <?php echo wp_json_encode($default_geocode_address); ?>;
          var defaultLat = 41.3080357;
          var defaultLng = -105.5557724;
          var defaultCity = 'AZ';

          var input = root.querySelector('.imm-locations-input');
          var searchBtn = root.querySelector('.imm-locations-search-btn');
          var statusEl = root.querySelector('.imm-locations-status');
          var listEl = root.querySelector('.imm-locations-list');
          var mapFrame = root.querySelector('.imm-locations-map-frame');
          var storesCache = [];

          function setStatus(msg, isError) {
            statusEl.textContent = msg || '';
            statusEl.classList.toggle('is-error', !!isError);
          }

          function esc(s) {
            return String(s == null ? '' : s)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
          }

          function toNum(v, fallback) {
            var n = Number(v);
            return Number.isFinite(n) ? n : fallback;
          }

          function buildOsmEmbed(lat, lng) {
            var delta = 0.08;
            var left = (lng - delta).toFixed(6);
            var right = (lng + delta).toFixed(6);
            var top = (lat + delta).toFixed(6);
            var bottom = (lat - delta).toFixed(6);
            return 'https://www.openstreetmap.org/export/embed.html?bbox=' + left + '%2C' + bottom + '%2C' + right + '%2C' + top + '&layer=mapnik&marker=' + lat.toFixed(6) + '%2C' + lng.toFixed(6);
          }

          function storeName(s) {
            return s.StoreName || s.storeName || s.Name || s.name || 'Store';
          }
          function storeId(s) {
            return String(s.StoreId || s.storeId || s.StoreID || s.storeID || s.Id || s.id || '');
          }
          function storeAddress(s) {
            var addr = s.Address || s.address || s.StoreAddress || s.storeAddress || '';
            var city = s.City || s.city || '';
            var st = s.State || s.state || '';
            var zip = s.Zip || s.zip || s.ZipCode || s.zipCode || '';
            var line2 = [city, st, zip].filter(Boolean).join(', ').replace(', ,', ',');
            if (!addr && !line2) return '';
            return [addr, line2].filter(Boolean).join(', ');
          }
          function storeDistance(s) {
            var d = s.Distance || s.distance || s.Miles || s.miles || '';
            if (d === '' || d == null) return '';
            var n = Number(d);
            if (!Number.isFinite(n)) return String(d);
            return n.toFixed(1) + ' miles';
          }
          function storeLat(s) {
            return toNum(s.Latitude || s.latitude || s.lat || s.UserCurrentLatitude, NaN);
          }
          function storeLng(s) {
            return toNum(s.Longitude || s.longitude || s.lng || s.lon || s.UserCurrentLongitude, NaN);
          }
          function extractStores(payload) {
            if (!payload) return [];
            var data = payload.data && payload.data.data ? payload.data.data : (payload.data || payload);
            if (Array.isArray(data)) return data;
            if (Array.isArray(data.storeList)) return data.storeList;
            if (Array.isArray(data.StoreList)) return data.StoreList;
            if (Array.isArray(data.stores)) return data.stores;
            if (Array.isArray(data.message)) return data.message;
            if (data.message && Array.isArray(data.message.storeList)) return data.message.storeList;
            return [];
          }

          function renderStores(stores) {
            storesCache = stores || [];
            if (!storesCache.length) {
              listEl.innerHTML = '<div class="imm-location-empty">No stores found for this search.</div>';
              return;
            }
            var html = storesCache.map(function (s, idx) {
              var name = esc(storeName(s));
              var sid = esc(storeId(s));
              var addr = esc(storeAddress(s));
              var dist = esc(storeDistance(s));
              var lat = storeLat(s);
              var lng = storeLng(s);
              var mapHref = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent((Number.isFinite(lat) && Number.isFinite(lng)) ? (lat + ',' + lng) : (storeAddress(s) || name));
              var detailsHref = s.StoreUrl || s.storeUrl || s.Url || s.url || '#';
              var openLine = s.OpenStatus || s.openStatus || s.StoreHours || s.storeHours || 'Open';
              return '' +
                '<article class="imm-location-card" data-store-idx="' + idx + '">' +
                  '<div class="imm-location-head">' +
                    '<div class="imm-location-name">' + name + '</div>' +
                    (dist ? '<div class="imm-location-distance">' + dist + '</div>' : '') +
                  '</div>' +
                  (addr ? '<div class="imm-location-address">' + addr + '</div>' : '') +
                  '<div class="imm-location-open">• ' + esc(openLine) + '</div>' +
                  '<div class="imm-location-actions">' +
                    '<button class="imm-choose-store-btn" type="button" data-store-id="' + sid + '">Choose store</button>' +
                    '<a class="imm-link" href="' + esc(detailsHref) + '" ' + (detailsHref === '#' ? '' : 'target="_blank" rel="noopener"') + '>Store details</a>' +
                    '<a class="imm-link" href="' + esc(mapHref) + '" target="_blank" rel="noopener">Get directions</a>' +
                  '</div>' +
                '</article>';
            }).join('');
            listEl.innerHTML = html;
          }

          function updateMapForStore(store) {
            if (!store) return;
            var lat = storeLat(store);
            var lng = storeLng(store);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
            mapFrame.src = buildOsmEmbed(lat, lng);
          }

          function fetchStores(lat, lng, city) {
            setStatus('Loading stores...', false);
            return fetch(ajaxUrl, {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
              body: new URLSearchParams({
                action: 'imm_sections_get_stores_ajax',
                nonce: nonce,
                lat: String(lat),
                lng: String(lng),
                city: city || defaultCity
              })
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
              if (!json || !json.success) {
                throw new Error((json && json.data && json.data.message) ? json.data.message : 'Store lookup failed.');
              }
              var stores = extractStores(json);
              renderStores(stores);
              if (stores[0]) updateMapForStore(stores[0]);
              setStatus(stores.length ? (stores.length + ' stores found') : 'No stores found.', false);
            })
            .catch(function (err) {
              setStatus(err && err.message ? err.message : 'Could not load stores.', true);
              listEl.innerHTML = '<div class="imm-location-empty">Could not load stores right now.</div>';
            });
          }

          function geocodeAndSearch(address) {
            if (!hasGoogleGeocode) {
              return fetchStores(defaultLat, defaultLng, defaultCity);
            }
            setStatus('Finding location...', false);
            return fetch(ajaxUrl, {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
              body: new URLSearchParams({
                action: 'imm_sections_geocode_ajax',
                nonce: nonce,
                address: address
              })
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
              if (!json || !json.success || !json.data) {
                throw new Error((json && json.data && json.data.message) ? json.data.message : 'Location search failed.');
              }
              var lat = toNum(json.data.lat, defaultLat);
              var lng = toNum(json.data.lng, defaultLng);
              var city = json.data.city || defaultCity;
              mapFrame.src = buildOsmEmbed(lat, lng);
              return fetchStores(lat, lng, city);
            })
            .catch(function (err) {
              setStatus(err && err.message ? err.message : 'Location search failed.', true);
            });
          }

          function chooseStore(storeId, btn) {
            if (!storeId) return;
            var old = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Saving...';
            fetch(ajaxUrl, {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
              body: new URLSearchParams({
                action: 'imm_sections_update_store_ajax',
                nonce: nonce,
                store_id: storeId,
                shopper_id: shopperId
              })
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
              if (!json || !json.success) {
                throw new Error((json && json.data && json.data.message) ? json.data.message : 'Store update failed.');
              }
              setStatus('Store selected successfully.', false);
            })
            .catch(function (err) {
              setStatus(err && err.message ? err.message : 'Store update failed.', true);
            })
            .finally(function () {
              btn.disabled = false;
              btn.textContent = old;
            });
          }

          searchBtn.addEventListener('click', function () {
            var q = (input.value || '').trim();
            if (!q) q = defaultGeocodeAddress || 'arizona';
            geocodeAndSearch(q);
          });

          input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              searchBtn.click();
            }
          });

          listEl.addEventListener('click', function (e) {
            var chooseBtn = e.target.closest('.imm-choose-store-btn');
            var card = e.target.closest('.imm-location-card');
            if (card) {
              var idx = Number(card.getAttribute('data-store-idx'));
              if (Number.isFinite(idx) && storesCache[idx]) {
                updateMapForStore(storesCache[idx]);
              }
            }
            if (chooseBtn) {
              e.preventDefault();
              chooseStore(chooseBtn.getAttribute('data-store-id') || '', chooseBtn);
            }
          });

          mapFrame.src = buildOsmEmbed(defaultLat, defaultLng);
          fetchStores(defaultLat, defaultLng, defaultCity);
        })();
      </script>
    </section>
    <?php
    return ob_get_clean();
});

/**
 * Find Store trigger shortcode.
 * Usage: [imm_find_store_trigger text="Find a Store"]
 */
add_shortcode('imm_find_store_trigger', function ($atts) {
    if (imm_sections_shortcode_guest_returns_empty()) {
        return '';
    }
    $atts = shortcode_atts([
        'text' => 'Find a Store',
        'class' => '',
        'id' => '',
        'show_icon' => '1',
    ], $atts, 'imm_find_store_trigger');

    $text = sanitize_text_field((string)$atts['text']);
    $extra_class = trim(sanitize_text_field((string)$atts['class']));
    $el_id = trim(sanitize_key((string)$atts['id']));
    $show_icon = ((string)$atts['show_icon'] !== '0');

    $classes = 'imm-find-store-trigger-btn';
    if ($extra_class !== '') $classes .= ' ' . $extra_class;

    ob_start();
    ?>
    <a<?php echo $el_id !== '' ? ' id="' . esc_attr($el_id) . '"' : ''; ?>
      href="#change-store"
      class="<?php echo esc_attr($classes); ?>"
      data-imm-change-store
      onclick="if(window.immOpenFindStoreModal){window.immOpenFindStoreModal();return false;}return true;">
      <?php if ($show_icon) { ?>
        <span class="imm-find-store-trigger-btn__icon" aria-hidden="true">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
            <circle cx="12" cy="10" r="2.8" stroke="currentColor" stroke-width="2"/>
          </svg>
        </span>
      <?php } ?>
      <span class="imm-find-store-trigger-btn__text" data-imm-my-store-display><?php echo esc_html($text); ?></span>
    </a>
    <?php
    return ob_get_clean();
});

/**
 * From Google Geocode address_components, pick City query param for IMM (state short_name preferred, e.g. AZ).
 *
 * @param array<int, array<string, mixed>> $components
 */
function imm_sections_geocode_pick_city_for_imm(array $components) {
    // IMM's "City" param (per your Postman samples) is expected to be the state short code (e.g. AZ/WY),
    // not a locality name like "Phoenix" or "Laramie".
    $state = '';
    foreach ($components as $c) {
        if (!is_array($c)) {
            continue;
        }
        $types = isset($c['types']) && is_array($c['types']) ? $c['types'] : [];
        if (in_array('administrative_area_level_1', $types, true) && !empty($c['short_name'])) {
            $state = (string)$c['short_name'];
        }
    }
    if ($state !== '') {
        return strtoupper($state);
    }
    return '';
}

add_action('wp_ajax_imm_sections_geocode_ajax', function () {
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field((string)$_REQUEST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'imm_sections_find_store_nonce')) {
        wp_send_json_error(['message' => 'Invalid request.'], 403);
    }

    $address = isset($_REQUEST['address']) ? sanitize_text_field((string)$_REQUEST['address']) : '';
    if ($address === '') {
        wp_send_json_error(['message' => 'Address is required.'], 400);
    }

    $k = imm_kacu_get_settings();
    $api_key = trim((string)($k['google_geocode_api_key'] ?? ''));
    if ($api_key === '') {
        wp_send_json_error(['message' => 'Google Geocoding API key is not configured in Offers API Hub.'], 400);
    }

    // region=us biases results (helps 5-digit US zip codes resolve correctly).
    $url = add_query_arg(
        [
            'address' => $address,
            'region' => 'us',
            'key' => $api_key,
        ],
        'https://maps.googleapis.com/maps/api/geocode/json'
    );

    $res = wp_remote_get($url, [
        'timeout' => 15,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($res)) {
        wp_send_json_error(['message' => $res->get_error_message()], 500);
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($json)) {
        wp_send_json_error(['message' => 'Geocoding request failed.'], $code >= 400 ? $code : 500);
    }

    if (!isset($json['status']) || (string)$json['status'] !== 'OK') {
        $msg = 'Geocoding failed.';
        if (!empty($json['error_message']) && is_string($json['error_message'])) {
            $msg = $json['error_message'];
        } elseif (!empty($json['status'])) {
            $msg = 'Geocoding status: ' . (string)$json['status'];
        }
        wp_send_json_error(['message' => $msg], 400);
    }

    $first = isset($json['results'][0]) && is_array($json['results'][0]) ? $json['results'][0] : null;
    if (!$first || empty($first['geometry']['location'])) {
        wp_send_json_error(['message' => 'No location found for that address.'], 404);
    }

    $loc = $first['geometry']['location'];
    $lat = isset($loc['lat']) ? (string)$loc['lat'] : '';
    $lng = isset($loc['lng']) ? (string)$loc['lng'] : '';
    if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
        wp_send_json_error(['message' => 'Invalid coordinates from geocoder.'], 500);
    }

    $comps = isset($first['address_components']) && is_array($first['address_components']) ? $first['address_components'] : [];
    $city = imm_sections_geocode_pick_city_for_imm($comps);
    if ($city === '') {
        $city = 'AZ';
    }

    $formatted = '';
    if (!empty($first['formatted_address']) && is_string($first['formatted_address'])) {
        $formatted = $first['formatted_address'];
    }

    wp_send_json_success([
        'lat' => $lat,
        'lng' => $lng,
        'city' => $city,
        'formatted_address' => $formatted,
    ]);
});
add_action('wp_ajax_nopriv_imm_sections_geocode_ajax', function () {
    do_action('wp_ajax_imm_sections_geocode_ajax');
});

/**
 * Find Store popup + IMM store lookup/update proxy endpoints.
 */
add_action('wp_ajax_imm_sections_get_stores_ajax', function () {
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field((string)$_REQUEST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'imm_sections_find_store_nonce')) {
        wp_send_json_error(['message' => 'Invalid request.'], 403);
    }

    $lat = isset($_REQUEST['lat']) ? trim((string)$_REQUEST['lat']) : '41.3080357';
    $lng = isset($_REQUEST['lng']) ? trim((string)$_REQUEST['lng']) : '-105.5557724';
    $city = isset($_REQUEST['city']) ? sanitize_text_field((string)$_REQUEST['city']) : 'AZ';

    if (!is_numeric($lat) || !is_numeric($lng)) {
        wp_send_json_error(['message' => 'Latitude/Longitude must be numeric.'], 400);
    }

    $k = imm_kacu_get_settings();
    $stores_base = trim((string)($k['stores_lookup_url'] ?? ''));
    if ($stores_base === '') {
        $stores_base = 'https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/StoresByCurrentLocation';
    }

    $url = add_query_arg([
        'UserCurrentLatitude' => (string)$lat,
        'UserCurrentLongitude' => (string)$lng,
        'City' => $city,
    ], $stores_base);

    $headers = [
        'Accept' => 'application/json',
    ];
    $imm = imm_sections_get_settings();
    $bearer = trim((string)($k['stores_bearer_token'] ?? ''));
    if ($bearer === '') {
        $token = imm_sections_get_access_token(false);
        if (!is_wp_error($token)) {
            $bearer = trim((string)$token);
        }
    }
    if ($bearer !== '') {
        $headers['Authorization'] = (stripos($bearer, 'bearer ') === 0) ? $bearer : ('Bearer ' . $bearer);
    } else {
        wp_send_json_error([
            'message' => 'IMM Authorization missing. Configure IMM token credentials in Offers API Hub (username/password + token auth mode) or set Stores API Bearer token in KACU settings.',
            'imm_header_client' => $imm['header_client'] ?? '',
            'imm_client_id' => $imm['client_id'] ?? '',
            'token_url' => $imm['token_url'] ?? '',
        ], 401);
    }
    if (!empty($imm['header_client']) && !empty($imm['client_id'])) {
        $headers[$imm['header_client']] = (string)$imm['client_id'];
    }

    $res = wp_remote_get($url, [
        'timeout' => 20,
        'headers' => $headers,
    ]);
    if (is_wp_error($res)) {
        wp_send_json_error(['message' => $res->get_error_message()], 500);
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300) {
        $msg = 'Store lookup failed.';
        if (is_array($json) && !empty($json['message']) && is_string($json['message'])) {
            $msg = $json['message'];
        }
        wp_send_json_error(['message' => $msg, 'status' => $code], $code);
    }

    if (is_array($json) && array_key_exists('errorcode', $json) && (string)$json['errorcode'] !== '0') {
        $msg = 'Store lookup failed.';
        if (!empty($json['message']) && is_string($json['message'])) {
            $msg = $json['message'];
        }
        wp_send_json_error(['message' => $msg, 'errorcode' => $json['errorcode']], 400);
    }

    wp_send_json_success([
        'status' => $code,
        'data' => is_array($json) ? $json : $body,
    ]);
});
add_action('wp_ajax_nopriv_imm_sections_get_stores_ajax', function () {
    do_action('wp_ajax_imm_sections_get_stores_ajax');
});

add_action('wp_ajax_imm_sections_update_store_ajax', function () {
    $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field((string)$_REQUEST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'imm_sections_find_store_nonce')) {
        wp_send_json_error(['message' => 'Invalid request.'], 403);
    }

    $store_id = isset($_REQUEST['store_id']) ? preg_replace('/[^0-9]/', '', (string)$_REQUEST['store_id']) : '';
    $shopper_id = isset($_REQUEST['shopper_id']) ? preg_replace('/[^0-9]/', '', (string)$_REQUEST['shopper_id']) : '';

    if ($store_id === '') {
        wp_send_json_error(['message' => 'StoreId is required.'], 400);
    }

    if ($shopper_id === '') {
        $shopper_id = imm_sections_current_shopper_id();
    }
    if ($shopper_id === '') {
        wp_send_json_error(['message' => 'ShopperId is required. Sign in to set your shopper identity.'], 400);
    }

    $k = imm_kacu_get_settings();
    $update_base = trim((string)($k['shopper_update_store_url'] ?? ''));
    if ($update_base === '') {
        $update_base = 'https://prt-ridstaging.immapi.com/Api/V3.0/api/v4.0/ShopperUpdateStore';
    }

    $url = add_query_arg([
        'StoreId' => $store_id,
        'ShopperId' => $shopper_id,
    ], $update_base);

    $headers = [
        'Accept' => 'application/json',
    ];
    $bearer = trim((string)($k['shopper_update_bearer_token'] ?? ''));
    if ($bearer === '') {
        $bearer = trim((string)($k['stores_bearer_token'] ?? ''));
    }
    if ($bearer === '') {
        $token = imm_sections_get_access_token(false);
        if (!is_wp_error($token)) {
            $bearer = trim((string)$token);
        }

    }
    if ($bearer !== '') {
        $headers['Authorization'] = (stripos($bearer, 'bearer ') === 0) ? $bearer : ('Bearer ' . $bearer);
    }
    $imm = imm_sections_get_settings();
    if (!empty($imm['header_client']) && !empty($imm['client_id'])) {
        $headers[$imm['header_client']] = (string)$imm['client_id'];
    }

    $res = wp_remote_post($url, [
        'timeout' => 20,
        'headers' => $headers,
    ]);
    if (is_wp_error($res)) {
        wp_send_json_error(['message' => $res->get_error_message()], 500);
    }

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300) {
        $msg = 'Store update failed.';
        if (is_array($json) && !empty($json['message']) && is_string($json['message'])) {
            $msg = $json['message'];
        }
        wp_send_json_error(['message' => $msg, 'status' => $code], $code);
    }

    if (is_array($json)) {
        // Some APIs return `errorCode` vs `errorcode` (casing differs). Treat only non-zero as failure.
        $errorCodeVal = null;
        foreach ($json as $k => $v) {
            if (strtolower((string)$k) === 'errorcode') {
                $errorCodeVal = $v;
                break;
            }
        }

        if ($errorCodeVal !== null && (string)$errorCodeVal !== '0') {
            $msg = 'Store update failed.';

            // Try to extract a useful message from API payload.
            if (!empty($json['message']) && is_string($json['message'])) {
                $msg = $json['message'];
            } elseif (!empty($json['message']) && is_array($json['message'])) {
                $first = reset($json['message']);
                if (is_array($first) && !empty($first['ErrorMessage']) && is_string($first['ErrorMessage'])) {
                    $msg = $first['ErrorMessage'];
                } elseif (is_array($first) && !empty($first['errorMessage']) && is_string($first['errorMessage'])) {
                    $msg = $first['errorMessage'];
                }
            } elseif (!empty($json['ErrorsMessage']) && is_string($json['ErrorsMessage'])) {
                $msg = $json['ErrorsMessage'];
            }

            wp_send_json_error(['message' => $msg, 'errorcode' => $errorCodeVal], 400);
        }
    }

    update_user_meta(get_current_user_id(), 'imm_store_id', $store_id);

    wp_send_json_success([
        'status' => $code,
        'data' => is_array($json) ? $json : $body,
    ]);
});
add_action('wp_ajax_nopriv_imm_sections_update_store_ajax', function () {
    do_action('wp_ajax_imm_sections_update_store_ajax');
});

/**
 * Featured Digital Offers: "Activate Now" -> Clip/Coupon click.
 * Calls IMM endpoint (Coupon/Click) using server-side access token.
 */
add_action('wp_ajax_nopriv_imm_sections_clip_offer_ajax', function () {
    do_action('wp_ajax_imm_sections_clip_offer_ajax');
});

add_action('wp_ajax_imm_sections_clip_offer_ajax', function () {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)$_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'imm_sections_clip_offer_nonce')) {
        wp_send_json_error(['message' => 'Invalid request nonce.'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Login required.'], 401);
    }

    $coupon_id = isset($_POST['coupon_id']) ? preg_replace('/[^0-9]/', '', (string)$_POST['coupon_id']) : '';
    if ($coupon_id === '') {
        wp_send_json_error(['message' => 'CouponID is required.'], 400);
    }

    $shopper_id = imm_sections_current_shopper_id();
    if ($shopper_id === '' && isset($_POST['shopper_id'])) {
        $shopper_id = preg_replace('/[^0-9]/', '', (string) $_POST['shopper_id']);
    }
    if ($shopper_id === '') {
        wp_send_json_error(['message' => 'ShopperID is required from your SSO login.'], 400);
    }

    $s = imm_sections_get_settings();
    $token_client_id = preg_replace('/[^0-9]/', '', (string) ($s['client_id'] ?? ''));
    if ($token_client_id === '') {
        wp_send_json_error(['message' => 'ClientID is missing in Offers API Hub settings.'], 500);
    }

    $clip_token_overrides = [
        'username' => 'tanuja.sharma@usa.com',
        'password' => 'tanuja@987',
        'client_id' => '2',
        'token_auth_mode' => 'body',
        'token_field_clientid' => 'ClientID',
        'token_field_grant' => 'grant_type',
    ];
    $token = imm_sections_get_access_token(true, null, 'imm_sections_clip_access_token_v3_', $clip_token_overrides);
    if (is_wp_error($token)) {
        wp_send_json_error(['message' => 'Unable to fetch access token.'], 500);
    }

    $token_str = (string) $token;
    $token_client_id_from_jwt = imm_sections_extract_client_id_from_access_token($token_str);

    $client_id = ($token_client_id_from_jwt !== '')
        ? $token_client_id_from_jwt
        : imm_sections_get_clip_client_id($token_client_id);
    $client_id_override = isset($_REQUEST['imm_clip_client_id']) ? preg_replace('/[^0-9]/', '', (string)$_REQUEST['imm_clip_client_id']) : '';
    if ($client_id_override !== '') {
        $client_id = $client_id_override;
    }
    $client_id = preg_replace('/[^0-9]/', '', (string) apply_filters('imm_sections_coupon_click_client_id', $client_id, $token_client_id, $shopper_id, $coupon_id));
    if ($client_id === '') {
        $client_id = '100';
    } else {
        $client_id = '100';
    }

    $click_url = trim((string)($s['coupon_click_url'] ?? ''));
    if ($click_url === '') {
        $offers_base = trim((string)($s['offers_base'] ?? ''));
        $offers_base = imm_sections_clean_base_url($offers_base);
        $click_url = preg_replace('#/Offers(?:/All)?/?$#', '/Coupon/Click', $offers_base);
        if ($click_url === null || $click_url === '') {
            $click_url = rtrim($offers_base, '/') . '/Coupon/Click';
        }
    }

    if ($click_url === '') {
        wp_send_json_error(['message' => 'Coupon Click URL missing in settings.'], 400);
    }

    $clipped_date_local = date('m/d/Y h:i:s A');
    $clipped_date_utc = gmdate('m/d/Y h:i:s A');
    $clipped_date_override = '';
    if (isset($_REQUEST['imm_clip_date'])) {
        $clipped_date_override = sanitize_text_field((string)$_REQUEST['imm_clip_date']);
    }
    $clipped_date_override = (string) apply_filters('imm_sections_clip_date_override', $clipped_date_override, $coupon_id, $shopper_id);
    if ($clipped_date_override === '') {
        $clipped_date_override = '03/05/2026 10:53:31 PM';
    }
    $clipped_date = $clipped_date_override !== '' ? $clipped_date_override : $clipped_date_local;
    $click_type = '1';

    $payload = [
        'Items' => [
            [
                'CouponID' => (string) $coupon_id,
                'ClippedDate' => $clipped_date,
            ],
        ],
    ];

    $body_json = wp_json_encode($payload);

    $blank_contact_mode = 'semicolon_blank_contact_headers';
    $headers_postman = [
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . (string) $token,
        'ShopperID' => (string) $shopper_id,
        'Email;' => '',
        'ClientID' => (string) $client_id,
        'LoyaltyCard;' => '',
        'Phone;' => '',
        'ClickType' => $click_type,
    ];
    $headers_postman = apply_filters('imm_sections_coupon_click_headers', $headers_postman, $shopper_id, $coupon_id, $s);

    $do_click = function (array $hdr) use ($click_url, $body_json) {
        $use_raw_curl = (bool) apply_filters('imm_sections_coupon_click_use_raw_curl', true, $click_url, $hdr, $body_json);
        if ($use_raw_curl && function_exists('curl_init')) {
            $ch = curl_init($click_url);
            if ($ch === false) {
                return new WP_Error('imm_click_curl_init_failed', 'Unable to initialize cURL.');
            }

            $header_lines = [];
            foreach ($hdr as $k => $v) {
                if (!is_string($k)) {
                    continue;
                }
                if (substr($k, -1) === ';' && (string)$v === '') {
                    $header_lines[] = $k;
                } else {
                    $header_lines[] = $k . ': ' . (string)$v;
                }
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body_json);
            curl_setopt($ch, CURLOPT_TIMEOUT, 20);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            $resp_body = curl_exec($ch);
            if ($resp_body === false) {
                $err = curl_error($ch);
                curl_close($ch);
                return new WP_Error('imm_click_curl_exec_failed', $err ?: 'Coupon click cURL failed.');
            }
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'response' => ['code' => $code, 'message' => ''],
                'body' => (string)$resp_body,
                'headers' => [],
                'cookies' => [],
                'filename' => null,
            ];
        }

        return wp_remote_post($click_url, [
            'timeout' => 15,
            'headers' => $hdr,
            'body' => $body_json,
        ]);
    };

    $headers_used = $headers_postman;
    $res = $do_click($headers_postman);

    if (is_wp_error($res)) {
        wp_send_json_error(['message' => $res->get_error_message()], 500);
    }

    $code = wp_remote_retrieve_response_code($res);
    $raw_body = wp_remote_retrieve_body($res);
    $json = json_decode($raw_body, true);

    if (($code < 200 || $code >= 300) && $click_type === '1' && (bool) apply_filters('imm_sections_enable_clicktype2_retry', false)) {
        $headers_alt = $headers_postman;
        $headers_alt['ClickType'] = '2';
        $headers_used = $headers_alt;
        $res2 = $do_click($headers_alt);
        if (!is_wp_error($res2)) {
            $res = $res2;
            $code = wp_remote_retrieve_response_code($res);
            $raw_body = wp_remote_retrieve_body($res);
            $json = json_decode($raw_body, true);
        }
    }

    if (($code < 200 || $code >= 300) && $blank_contact_mode === 'single_space_wp_transport') {
        $headers_contact_empty = $headers_used;
        $headers_contact_empty['Email'] = '';
        $headers_contact_empty['LoyaltyCard'] = '';
        $headers_contact_empty['Phone'] = '';
        $blank_contact_mode = 'empty_string_wp_transport';

        $resA = $do_click($headers_contact_empty);
        if (!is_wp_error($resA)) {
            $codeA = wp_remote_retrieve_response_code($resA);
            $raw_bodyA = wp_remote_retrieve_body($resA);
            $jsonA = json_decode($raw_bodyA, true);

            if ($codeA >= 200 && $codeA < 300) {
                $res = $resA;
                $code = $codeA;
                $raw_body = $raw_bodyA;
                $json = $jsonA;
                $headers_used = $headers_contact_empty;
            } else {
                $headers_contact_semicolon = $headers_used;
                unset($headers_contact_semicolon['Email'], $headers_contact_semicolon['LoyaltyCard'], $headers_contact_semicolon['Phone']);
                $headers_contact_semicolon['Email;'] = '';
                $headers_contact_semicolon['LoyaltyCard;'] = '';
                $headers_contact_semicolon['Phone;'] = '';
                $blank_contact_mode = 'semicolon_blank_contact_headers';

                $resB = $do_click($headers_contact_semicolon);
                if (!is_wp_error($resB)) {
                    $codeB = wp_remote_retrieve_response_code($resB);
                    $raw_bodyB = wp_remote_retrieve_body($resB);
                    $jsonB = json_decode($raw_bodyB, true);

                    if ($codeB >= 200 && $codeB < 300) {
                        $res = $resB;
                        $code = $codeB;
                        $raw_body = $raw_bodyB;
                        $json = $jsonB;
                        $headers_used = $headers_contact_semicolon;
                    } else {
                        $headers_contact_omit = $headers_used;
                        unset($headers_contact_omit['Email'], $headers_contact_omit['LoyaltyCard'], $headers_contact_omit['Phone']);
                        unset($headers_contact_omit['Email;'], $headers_contact_omit['LoyaltyCard;'], $headers_contact_omit['Phone;']);
                        $blank_contact_mode = 'omit_blank_contact_headers';

                        $resC = $do_click($headers_contact_omit);
                        if (!is_wp_error($resC)) {
                            $codeC = wp_remote_retrieve_response_code($resC);
                            $raw_bodyC = wp_remote_retrieve_body($resC);
                            $jsonC = json_decode($raw_bodyC, true);

                            $res = $resC;
                            $code = $codeC;
                            $raw_body = $raw_bodyC;
                            $json = $jsonC;
                            $headers_used = $headers_contact_omit;
                        }
                    }
                }
            }
        }
    }

    if (($code < 200 || $code >= 300) && isset($clipped_date_utc) && $clipped_date_utc !== $clipped_date_local) {
        $payload_utc = [
            'Items' => [
                [
                    'CouponID' => (string) $coupon_id,
                    'ClippedDate' => $clipped_date_utc,
                ],
            ],
        ];
        $body_json_utc = wp_json_encode($payload_utc);

        $resUTC = wp_remote_post($click_url, [
            'timeout' => 15,
            'headers' => $headers_used,
            'body' => $body_json_utc,
        ]);

        if (!is_wp_error($resUTC)) {
            $codeUTC = wp_remote_retrieve_response_code($resUTC);
            $rawUTC = wp_remote_retrieve_body($resUTC);
            $jsonUTC = json_decode($rawUTC, true);

            $res = $resUTC;
            $code = $codeUTC;
            $raw_body = $rawUTC;
            $json = $jsonUTC;
            $body_json = $body_json_utc;
        }
    }

    $extract_api_msg = function ($jsonBody, $raw, $default = 'Coupon click failed.') {
        $msg = $default;
        if (is_array($jsonBody)) {
            foreach (['message', 'Message', 'error', 'Error', 'error_description', 'ErrorDescription', 'detail', 'Detail'] as $mk) {
                if (!empty($jsonBody[$mk]) && is_scalar($jsonBody[$mk])) {
                    return (string) $jsonBody[$mk];
                }
            }
            foreach (['errors', 'Errors'] as $ek) {
                if (!empty($jsonBody[$ek]) && is_array($jsonBody[$ek])) {
                    $first = reset($jsonBody[$ek]);
                    if (is_array($first)) {
                        foreach (['message', 'Message', 'error', 'Error', 'description', 'Description'] as $mk) {
                            if (!empty($first[$mk]) && is_scalar($first[$mk])) {
                                return (string) $first[$mk];
                            }
                        }
                    } elseif (is_scalar($first)) {
                        return (string) $first;
                    }
                }
            }
        } elseif (is_string($raw) && trim($raw) !== '') {
            $msg = trim($raw);
        }
        return $msg;
    };
    $looks_already_clipped = function ($msg) {
        $m = strtolower(trim((string) $msg));
        if ($m === '') {
            return false;
        }
        return (strpos($m, 'already') !== false && (strpos($m, 'clip') !== false || strpos($m, 'activ') !== false || strpos($m, 'redeem') !== false))
            || (strpos($m, 'previously clipped') !== false)
            || (strpos($m, 'already saved') !== false);
    };

    $alt_client_id = apply_filters('imm_sections_clip_alt_client_id', '');
    if (($code < 200 || $code >= 300) && $alt_client_id !== '') {
        $alt_client_id = preg_replace('/[^0-9]/', '', (string)$alt_client_id);
        if ($alt_client_id !== '' && (string)$token_client_id !== (string)$alt_client_id) {
            $token_alt = imm_sections_get_access_token(true, $alt_client_id);
            if (!is_wp_error($token_alt)) {
                $headers_postman_retry = $headers_postman;
                $headers_postman_retry['Authorization'] = 'Bearer ' . (string)$token_alt;
                $headers_postman_retry['ClientID'] = $alt_client_id;

                $res_retry = $do_click($headers_postman_retry);
                if (!is_wp_error($res_retry)) {
                    $code_retry = wp_remote_retrieve_response_code($res_retry);
                    $raw_body_retry = wp_remote_retrieve_body($res_retry);
                    $json_retry = json_decode($raw_body_retry, true);

                    if ($code_retry >= 200 && $code_retry < 300) {
                        $res = $res_retry;
                        $code = $code_retry;
                        $raw_body = $raw_body_retry;
                        $json = $json_retry;
                        $headers_used = $headers_postman_retry;
                    }
                }
            }
        }
    }

    if ($code < 200 || $code >= 300) {
        $msg = $extract_api_msg($json, $raw_body, 'Coupon click failed.');
        if ($looks_already_clipped($msg)) {
            wp_send_json_success([
                'status' => $code,
                'data' => is_array($json) ? $json : $raw_body,
                'message' => $msg,
            ]);
        }
        wp_send_json_error(['message' => $msg, 'status' => $code], $code);
    }

    if (is_array($json)) {
        $errorCodeVal = null;
        foreach ($json as $k => $v) {
            if (strtolower((string) $k) === 'errorcode') {
                $errorCodeVal = $v;
                break;
            }
        }
        if ($errorCodeVal !== null && (string) $errorCodeVal !== '0') {
            $msg = $extract_api_msg($json, $raw_body, 'Coupon click failed.');
            if ($looks_already_clipped($msg)) {
                wp_send_json_success([
                    'status' => $code,
                    'data' => $json,
                    'message' => $msg,
                ]);
            }
            wp_send_json_error(['message' => $msg, 'errorcode' => $errorCodeVal], 400);
        }
    }

    wp_send_json_success([
        'status' => $code,
        'data' => is_array($json) ? $json : $raw_body,
        'message' => 'Coupon clipped successfully.',
    ]);
});


/**
 * Cashback (KACU) Activate: call KACU Offers/Activate endpoint.
 * This is separate from IMM Coupon/Click used by the Featured Offers buttons.
 */
add_action('wp_ajax_nopriv_imm_kacu_activate_offer_ajax', function () {
    do_action('wp_ajax_imm_kacu_activate_offer_ajax');
});

add_action('wp_ajax_imm_kacu_activate_offer_ajax', function () {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)$_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'imm_sections_clip_offer_nonce')) {
        wp_send_json_error(['message' => 'Invalid request nonce.'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Login required.'], 401);
    }

    $coupon_id = isset($_POST['coupon_id']) ? preg_replace('/[^0-9]/', '', (string)$_POST['coupon_id']) : '';
    if ($coupon_id === '') {
        wp_send_json_error(['message' => 'CouponID is required.'], 400);
    }

    $shopper_id = isset($_POST['shopper_id']) ? preg_replace('/[^0-9]/', '', (string)$_POST['shopper_id']) : '';
    if ($shopper_id === '') {
        wp_send_json_error(['message' => 'ShopperID is required.'], 400);
    }

    $kacu = imm_kacu_get_settings();
    $token = imm_kacu_get_token(false);
    if (is_wp_error($token)) {
        wp_send_json_error(['message' => 'Unable to fetch KACU token.'], 500);
    }

    $endpoint = imm_kacu_activate_endpoint_url();

    $body_arr = [
        'OfferID' => (string)$coupon_id,
        'ShopperID' => (string)$shopper_id,
    ];

    $body_arr = apply_filters('imm_kacu_activate_body', $body_arr, $coupon_id, $shopper_id, $kacu);

    $activate_req = [
        'timeout' => 20,
        'headers' => [
            'Authorization' => 'Bearer ' . (string)$token,
            'Accept-Language' => 'en-us',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'WordPress/IMM-Sections',
        ],
        'body' => http_build_query($body_arr, '', '&', PHP_QUERY_RFC3986),
        'redirection' => 0,
    ];
    $activate_req = apply_filters('imm_kacu_activate_request', $activate_req, $endpoint, $coupon_id, $shopper_id, $kacu);
    $res = wp_remote_post($endpoint, $activate_req);

    if (is_wp_error($res)) {
        wp_send_json_error(['message' => $res->get_error_message()], 500);
    }

    $status = wp_remote_retrieve_response_code($res);
    $raw = wp_remote_retrieve_body($res);
    $json = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
        wp_send_json_error(['message' => 'KACU Offers/Activate failed.'], $status);
    }

    wp_send_json_success([
        'status' => $status,
        'data' => is_array($json) ? $json : $raw,
    ]);
});

add_action('wp_footer', function () {
    if (is_admin()) return;

    $kacu = imm_kacu_get_settings();
    $default_shopper = imm_sections_current_shopper_id();
    $default_store_id = '';
    $default_store_name = '';
    if (is_user_logged_in()) {
        $cu = get_current_user_id();
        $default_store_id = preg_replace('/[^0-9]/', '', (string)get_user_meta($cu, 'imm_store_id', true));
        $default_store_name = sanitize_text_field((string)get_user_meta($cu, 'imm_store_name', true));
    }
    $nonce = wp_create_nonce('imm_sections_find_store_nonce');
    $has_google_geocode = !empty(trim((string)($kacu['google_geocode_api_key'] ?? '')));
    $default_geocode_address = isset($kacu['default_geocode_address']) && (string)$kacu['default_geocode_address'] !== ''
        ? (string)$kacu['default_geocode_address']
        : 'arizona';
    ?>
    <style>
      .imm-find-store-trigger-btn{display:inline-flex;align-items:center;flex-wrap:nowrap;gap:10px;max-width:100%;background:transparent !important;color:#fff;text-decoration:none;font-family:"DM Sans",sans-serif;font-size:13px;line-height:1.2;padding:10px 14px;border-radius:0}
      .imm-find-store-trigger-btn:hover,.imm-find-store-trigger-btn:focus{color:#fff;opacity:.95}
      .imm-find-store-trigger-btn__icon{display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
      .imm-find-store-trigger-btn__icon svg{display:block}
      .imm-find-store-trigger-btn__text{font-size:13px;font-weight:700;letter-spacing:.2px;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .imm-find-store-modal{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:999999;padding:16px}
      .imm-find-store-modal.is-open{display:flex}
      .imm-find-store-dialog{width:min(96vw,480px);max-height:90vh;overflow:hidden;display:flex;flex-direction:column;background:#fff;border-radius:4px;box-shadow:0 12px 40px rgba(0,0,0,.35)}
      .imm-find-store-dialog-top{background:#c75f1e;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:14px 16px;gap:12px}
      .imm-find-store-dialog-top h2{margin:0;font-size:18px;font-weight:700;line-height:1.2;font-family:inherit}
      .imm-find-store-close{border:0;background:transparent;color:#fff;font-size:28px;line-height:1;cursor:pointer;padding:0 4px}
      .imm-find-store-body{padding:18px 16px 12px;flex:1;overflow:auto}
      .imm-find-store-search-row{display:flex;gap:8px;margin-bottom:14px}
      .imm-find-store-search-row input{flex:1;border:1px solid #ccc;border-radius:4px;padding:10px 12px;font-size:14px}
      .imm-find-store-search-btn{flex:0 0 44px;border:1px solid #ccc;border-radius:4px;background:#f3f4f6;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;color:#fbbf24}
      .imm-find-store-search-btn svg{width:20px;height:20px;opacity:1}
      .imm-find-store-search-btn:hover{background:#fbbf24;border-color:#fbbf24;color:#fff}
      .imm-find-store-search-btn:focus-visible{outline:none !important;box-shadow:none !important;background:#fbbf24;border-color:#fbbf24;color:#fff}
      .imm-find-store-search-btn:focus{outline:none !important;box-shadow:none !important;background:#fbbf24;border-color:#fbbf24;color:#fff}
      .imm-find-store-select-wrap{margin-bottom:10px}
      .imm-find-store-select-wrap select{width:100%;border:1px solid #ccc;border-radius:4px;padding:10px 12px;font-size:14px;background:#fff}
      .imm-find-store-status{font-size:13px;color:#475569;min-height:1.2em;margin-top:6px;background:transparent !important;border:0 !important;padding:0 !important}
      .imm-find-store-status.is-error{color:#b91c1c;background:transparent !important;border:0 !important;padding:0 !important}
      .imm-find-store-debugline{margin-top:8px;font:12px/1.35 monospace;color:#334155;white-space:pre-wrap;display:none}
      .imm-find-store-debug{margin-top:10px;background:#f8fafc;border:1px solid #dbe3ee;border-radius:8px;padding:10px 12px;color:#0f172a;font:12px/1.4 monospace;white-space:pre-wrap;max-height:130px;overflow:auto}
      .imm-find-store-actions{display:flex;gap:10px;padding:12px 16px 18px;justify-content:flex-end;flex-wrap:wrap}
      .imm-store-btn{padding:10px 22px;border-radius:4px;font-size:14px;font-weight:600;cursor:pointer;border:1px solid transparent;-webkit-appearance:none;appearance:none;background-image:none}
      .imm-store-btn-update{background:#10395d;color:#fff;border-color:#10395d}
      .imm-store-btn-update:hover:not(:disabled){background:#0d304f;color:#fff;border-color:#0d304f}
      .imm-store-btn-update:focus-visible{outline:2px solid #10395d;outline-offset:2px}
      .imm-store-btn-update:disabled{opacity:.55;cursor:not-allowed;background:#10395d;color:#fff;border-color:#10395d}
      .imm-store-btn-cancel{background:#4b5563;color:#fff}
    </style>
    <div class="imm-find-store-modal" id="imm-find-store-modal" aria-hidden="true">
      <div class="imm-find-store-dialog" role="dialog" aria-modal="true" aria-labelledby="imm-find-store-dialog-title">
        <div class="imm-find-store-dialog-top">
          <h2 id="imm-find-store-dialog-title">Change Store</h2>
          <button type="button" class="imm-find-store-close" data-imm-find-store-close aria-label="Close">&times;</button>
        </div>
        <div class="imm-find-store-body">
          <div class="imm-find-store-search-row">
            <input type="text" id="imm-store-search-input" placeholder="Zip, city, or state (geocoded, then nearby stores)" autocomplete="off" />
            <button type="button" class="imm-find-store-search-btn" id="imm-store-search-btn" aria-label="Search stores">
              <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
          </div>
          <div class="imm-find-store-select-wrap">
            <label class="screen-reader-text" for="imm-store-select">Select Your Preferred Store</label>
            <select id="imm-store-select">
              <option value="">-Select Preferred Store-</option>
            </select>
          </div>
          <div class="imm-find-store-status" id="imm-find-store-status" role="status"></div>
          <div class="imm-find-store-debugline" id="imm-find-store-debugline" role="note" aria-label="Store debug"></div>
          <div class="imm-find-store-debug" id="imm-find-store-debug" role="note" aria-label="Store lookup debug" style="display:none"></div>
        </div>
        <div class="imm-find-store-actions">
          <button type="button" class="imm-store-btn imm-store-btn-update" id="imm-store-update-btn" disabled>Update</button>
          <button type="button" class="imm-store-btn imm-store-btn-cancel" id="imm-store-cancel-btn" data-imm-find-store-close>Cancel</button>
        </div>
      </div>
    </div>
    <style>.screen-reader-text{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}</style>
    <script>
      (function() {
        if (window.__IMM_FIND_STORE_INIT__) return;
        window.__IMM_FIND_STORE_INIT__ = true;

        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var shopperId = <?php echo wp_json_encode($default_shopper); ?>;
        var jwtStoreId = <?php echo wp_json_encode($default_store_id); ?>;
        var jwtStoreName = <?php echo wp_json_encode($default_store_name); ?>;
        var defaultLat = '41.3080357';
        var defaultLng = '-105.5557724';
        var defaultCity = 'AZ';
        var hasGoogleGeocode = <?php echo $has_google_geocode ? 'true' : 'false'; ?>;
        var defaultGeocodeAddress = <?php echo wp_json_encode($default_geocode_address); ?>;
        // Store header label must be per-shopper to avoid reusing another shopper's saved line.
        var LS_DISPLAY = 'imm_my_store_header_line_' + (shopperId ? shopperId : 'guest');
        var storesCache = [];
        var didCenterOnJwtStore = false;

        var modal = document.getElementById('imm-find-store-modal');
        var searchInput = document.getElementById('imm-store-search-input');
        var searchBtn = document.getElementById('imm-store-search-btn');
        var storeSelect = document.getElementById('imm-store-select');
        var selectWrap = modal ? modal.querySelector('.imm-find-store-select-wrap') : null;
        var statusEl = document.getElementById('imm-find-store-status');
        var debugEl = document.getElementById('imm-find-store-debug');
        var debugLineEl = document.getElementById('imm-find-store-debugline');
        var updateBtn = document.getElementById('imm-store-update-btn');
        var debugHistory = [];

        function setStatus(msg, isError) {
          statusEl.textContent = msg || '';
          statusEl.classList.toggle('is-error', !!isError);
        }

        function clearDebug() {
          if (debugEl) debugEl.style.display = 'none';
        }

        function setDebugLine(text) {
          if (!debugLineEl) return;
          debugLineEl.textContent = text || '';
          // Keep it hidden; we only want status text (no extra boxes) in the UI.
          debugLineEl.style.display = 'none';
        }

        // Popup debug UI is hidden. We keep console logs so you can still troubleshoot.
        function setDebug(obj) {
          try { if (debugEl) debugEl.style.display = 'none'; } catch (e) {}
          try { console.log('IMM store debug:', obj); } catch (e2) {}
        }

        function hideSelect() {
          if (selectWrap) selectWrap.style.display = 'none';
        }

        function showSelect() {
          if (selectWrap) selectWrap.style.display = '';
        }

        function normalizeStores(payload) {
          // If response payload is stringified JSON, parse it.
          if (typeof payload === 'string') {
            try { payload = JSON.parse(payload); } catch (e) {}
          }

          // IMM can return different wrapper shapes depending on gateway/version.
          // We'll do a "deep" search for a StoreList/storeList array, then fall back to
          // a few common shapes for performance/clarity.
          function deepFindStoreList(o, maxDepth) {
            if (maxDepth < 0) return null;
            if (!o || typeof o !== 'object') return null;

            // Direct key hits
            if (Array.isArray(o.StoreList)) return o.StoreList;
            if (Array.isArray(o.storeList)) return o.storeList;

            // Case-insensitive key scan (rare, but cheap at this depth)
            for (var k in o) {
              if (!Object.prototype.hasOwnProperty.call(o, k)) continue;
              var kl = String(k).toLowerCase();
              if ((kl === 'storelist' || kl === 'store_list') && Array.isArray(o[k])) return o[k];
            }

            if (maxDepth === 0) return null;

            // Recurse into children objects
            for (var k2 in o) {
              if (!Object.prototype.hasOwnProperty.call(o, k2)) continue;
              var v = o[k2];
              if (v && typeof v === 'object') {
                var found = deepFindStoreList(v, maxDepth - 1);
                if (found !== null) return found; // may be empty array too
              }
            }
            return null;
          }

          if (!payload) return [];
          if (Array.isArray(payload)) return payload;

          // Some IMM responses return stores directly as `message: [ ... ]`
          if (payload.message && Array.isArray(payload.message)) return payload.message;
          if (payload.Message && Array.isArray(payload.Message)) return payload.Message;

          var deep = deepFindStoreList(payload, 6);
          if (Array.isArray(deep)) return deep;

          // Fallbacks (older/known shapes)
          if (payload.stores && Array.isArray(payload.stores)) return payload.stores;
          if (payload.message && Array.isArray(payload.message.StoreList)) return payload.message.StoreList;
          if (payload.data && payload.data.message && Array.isArray(payload.data.message.StoreList)) return payload.data.message.StoreList;
          if (payload.data && payload.data.message && Array.isArray(payload.data.message.storeList)) return payload.data.message.storeList;
          if (payload.data && Array.isArray(payload.data.stores)) return payload.data.stores;
          if (payload.data && Array.isArray(payload.data.StoreList)) return payload.data.StoreList;

          return [];
        }

        function storeName(s) {
          return s.StoreName || s.storeName || s.Name || s.name || 'Store';
        }

        function storeId(s) {
          return (s.StoreId || s.storeId || s.StoreID || s.storeID || s.Id || s.id || '').toString();
        }

        function storeAddress(s) {
          var parts = [
            s.Address || s.address || s.StoreAddress || s.storeAddress || '',
            s.City || s.city || s.StoreCity || s.storeCity || '',
            s.State || s.state || s.StoreState || s.storeState || '',
            s.Zip || s.zip || s.PostalCode || s.postalCode || s.StoreZip || s.storeZip || ''
          ].filter(Boolean);
          return parts.join(', ');
        }

        function storeLat(s) {
          var v = s.Latitude || s.latitude || s.lat || s.Lat || s.UserCurrentLatitude || s.userCurrentLatitude || '';
          var n = parseFloat(v);
          return isFinite(n) ? n : '';
        }

        function storeLng(s) {
          var v = s.Longitude || s.longitude || s.lng || s.lon || s.Lng || s.UserCurrentLongitude || s.userCurrentLongitude || '';
          var n = parseFloat(v);
          return isFinite(n) ? n : '';
        }

        function toMiles(s) {
          var v = s.Distance || s.distance || s.DistanceInMiles || s.distanceInMiles || s.Miles || s.miles || '';
          if (v === '' || v === null || typeof v === 'undefined') return '';
          var n = parseFloat(v);
          if (!isFinite(n)) return '';
          return n.toFixed(2) + ' miles';
        }

        function storeLineForDisplay(s) {
          var addr = storeAddress(s);
          if (addr) return addr;
          return storeName(s);
        }

        function storeDropdownLabel(s) {
          var name = storeName(s);
          var sid = storeId(s);
          if (sid) {
            name = name + ' - ' + sid;
          }
          var num = s.StoreNumber;
          if (num === undefined || num === null || num === '') {
            num = s.storeNumber || s.StoreNo || s.storeNo || '';
          }
          if (num !== '' && num !== null && String(num) !== '') {
            name = name + ' #' + String(num);
          }

          var addr = storeAddress(s);
          var miles = toMiles(s);

          var main = addr ? (name + ' - ' + addr) : name;
          return miles ? (main + ' (' + miles + ')') : main;
        }

        function pickDefaultStore(stores) {
          if (!stores || !stores.length) return null;
          var bestI = 0;
          var bestD = Infinity;
          for (var i = 0; i < stores.length; i++) {
            var d = parseFloat(stores[i].Distance || stores[i].distance || stores[i].DistanceInMiles || '');
            if (isFinite(d) && d < bestD) {
              bestD = d;
              bestI = i;
            }
          }
          return bestD < Infinity ? stores[bestI] : stores[0];
        }

        function updateHeaderDisplay(line) {
          if (!line) return;
          try { localStorage.setItem(LS_DISPLAY, line); } catch (e) {}
          var full = line.indexOf('My Store:') === 0 ? line : ('My Store: ' + line);
          var compact = String(line).replace(/^My Store:\s*/i, '').trim();
          document.querySelectorAll('[data-imm-my-store-display]').forEach(function(el) {
            if (el.classList.contains('imm-find-store-trigger-btn__text')) {
              el.textContent = compact || line;
            } else {
              el.textContent = full;
            }
          });
          document.querySelectorAll('#imm-my-store-display').forEach(function(el) {
            el.textContent = full;
          });
        }

        function applySavedHeader() {
          var saved = '';
          try { saved = localStorage.getItem(LS_DISPLAY) || ''; } catch (e) {}
          if (saved) updateHeaderDisplay(saved);
        }

        function runStoresLookup(lat, lng, city, onDone) {
          var lookupBody = new URLSearchParams({
            action: 'imm_sections_get_stores_ajax',
            nonce: nonce,
            lat: String(lat),
            lng: String(lng),
            city: city || defaultCity
          });
          return fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: lookupBody.toString()
          })
            .then(function(r) { return r.json(); })
            .then(function(res) {
              if (onDone) onDone(res);
              return res;
            });
        }

        function hydrateFindStoreTriggerLabel() {
          var triggers = document.querySelectorAll('.imm-find-store-trigger-btn .imm-find-store-trigger-btn__text');
          if (!triggers.length) return;
          var saved = '';
          try { saved = localStorage.getItem(LS_DISPLAY) || ''; } catch (e) {}
          if (saved) return;

          function applyStoresRes(res) {
            if (!res || !res.success) {
              try { console.log('IMM store lookup failed (hydrate label):', res); } catch (e) {}
              return;
            }

            var storesPayload = res;
            if (res && res.data) {
              storesPayload = (res.data.data !== undefined) ? res.data.data : res.data;
            }

            var stores = normalizeStores(storesPayload);
            if (!stores.length) {
              try { console.log('IMM hydrate label: no stores found', { storesPayload: storesPayload, raw: res }); } catch (e) {}
              return;
            }
            var s = pickDefaultStore(stores);
            if (!s) return;
            var label = storeDropdownLabel(s);
            triggers.forEach(function(el) { el.textContent = label; });
          }

          runStoresLookup(defaultLat, defaultLng, defaultCity, applyStoresRes).catch(function() {});
        }

        // Prefer JWT store identity (StoreId/StoreName) over any previously saved localStorage value.
        // This fixes the bug where the header keeps showing the previous shopper's store.
        if (jwtStoreId || jwtStoreName) {
          var jwtLine = '';
          if (jwtStoreName && jwtStoreId) jwtLine = jwtStoreName + ' - ' + jwtStoreId;
          else if (jwtStoreName) jwtLine = jwtStoreName;
          else if (jwtStoreId) jwtLine = 'Store ' + jwtStoreId;

          if (jwtLine) {
            try { localStorage.setItem(LS_DISPLAY, jwtLine); } catch (e) {}
            updateHeaderDisplay(jwtLine);
          }
        }

        applySavedHeader();
        hydrateFindStoreTriggerLabel();

        if (!modal || !searchInput || !searchBtn || !storeSelect || !statusEl || !updateBtn) {
          window.immOpenFindStoreModal = function() {};
          return;
        }

        function resetSelect() {
          storeSelect.innerHTML = '';
          var ph = document.createElement('option');
          ph.value = '';
          ph.textContent = '-Select Preferred Store-';
          storeSelect.appendChild(ph);
          updateBtn.disabled = true;
        }

        function fillSelect(stores) {
          storesCache = stores.slice();
          resetSelect();
          storesCache.forEach(function(s, idx) {
            var sid = storeId(s);
            if (!sid) return;
            var opt = document.createElement('option');
            opt.value = sid;
            opt.textContent = storeDropdownLabel(s);
            opt.setAttribute('data-store-idx', String(idx));
            storeSelect.appendChild(opt);
          });
          showSelect();
        }

        function fetchStoresWithLatLng(lat, lng, city, geoKeyMissingNote, didRetryCity) {
          hideSelect();
          setStatus('Searching stores…', false);
          // IMM integration expects City=AZ (your working Postman setup). Use defaultCity consistently.
          var requestCity = defaultCity;
          setDebug({ step: 'StoresByCurrentLocation', lat: lat, lng: lng, city: requestCity });
          setDebugLine('Stores request: lat=' + lat + ', lng=' + lng + ', city=' + requestCity);
          updateBtn.disabled = true;
          resetSelect();

          runStoresLookup(lat, lng, requestCity)
          .then(function(res) {
            if (!res || !res.success) {
              var msg = (res && res.data && res.data.message) ? res.data.message : 'Unable to fetch stores.';
              setDebug({ step: 'StoresByCurrentLocation FAILED', request: { lat: lat, lng: lng, city: requestCity }, response: res });
              showSelect();
              setDebugLine('Stores ERROR: ' + msg);

              setStatus(msg + ' (lat=' + lat + ', lng=' + lng + ', city=' + requestCity + ')', true);

              // Fallback: load known default store list so dropdown isn't empty.
              runStoresLookup(defaultLat, defaultLng, defaultCity).then(function(defRes) {
                if (!defRes || !defRes.success) return;
                var defPayload = defRes;
                if (defRes && defRes.data) {
                  defPayload = (defRes.data.data !== undefined) ? defRes.data.data : defRes.data;
                }
                var defStores = normalizeStores(defPayload);
                if (!defStores.length) return;
                fillSelect(defStores);
                setDebugLine('Fallback loaded: ' + defStores.length + ' store(s).');
              }).catch(function(){});
              return;
            }
            var storesPayload = res;
            if (res && res.data) {
              storesPayload = (res.data.data !== undefined) ? res.data.data : res.data;
            }
            var stores = normalizeStores(storesPayload);
            if (!stores.length) {
              var dbgKeys = '';
              try {
                var p = storesPayload;
                if (p && typeof p === 'object' && !Array.isArray(p)) {
                  dbgKeys = Object.keys(p).slice(0, 8).join(', ');
                }
              } catch (e) {}
              setDebug({
                step: 'StoresByCurrentLocation OK but empty',
                request: { lat: lat, lng: lng, city: requestCity },
                storeCountNormalized: 0,
                responseKeys: dbgKeys,
                storesPayload: storesPayload
              });
              // Keep the dropdown container visible (with the placeholder) and show a clear message.
              showSelect();
              setDebugLine('Stores result: 0 stores for lat=' + lat + ', lng=' + lng + ', city=' + requestCity);
              setStatus('No store found at the given location. (lat=' + lat + ', lng=' + lng + ', city=' + requestCity + ')', true);

              // Fallback: load known default store list so dropdown isn't empty.
              runStoresLookup(defaultLat, defaultLng, defaultCity).then(function(defRes) {
                if (!defRes || !defRes.success) return;
                var defPayload = defRes;
                if (defRes && defRes.data) {
                  defPayload = (defRes.data.data !== undefined) ? defRes.data.data : defRes.data;
                }
                var defStores = normalizeStores(defPayload);
                if (!defStores.length) return;
                fillSelect(defStores);
                setDebugLine('Fallback loaded: ' + defStores.length + ' store(s).');
              }).catch(function(){});
              return;
            }
            fillSelect(stores);

            // If we can find the current JWT store inside the nearby list, auto-select it.
            // Also re-center around its lat/lng so the popup shows nearby stores for the shopper's current store.
            if (jwtStoreId) {
              for (var i = 0; i < stores.length; i++) {
                if (storeId(stores[i]) !== String(jwtStoreId)) continue;
                try { storeSelect.value = String(jwtStoreId); } catch (e) {}
                updateBtn.disabled = false;

                if (!didCenterOnJwtStore) {
                  var slat = storeLat(stores[i]);
                  var slng = storeLng(stores[i]);
                  if (slat !== '' && slng !== '') {
                    didCenterOnJwtStore = true;
                    fetchStoresWithLatLng(slat, slng, defaultCity);
                  }
                }
                break;
              }
            }

            setDebug({
              step: 'StoresByCurrentLocation OK',
              request: { lat: lat, lng: lng, city: requestCity },
              storeCountNormalized: stores.length,
              firstStore: stores && stores.length ? stores[0] : null
            });
            setDebugLine('Stores result: ' + stores.length + ' stores.');
            var okMsg = 'Found ' + stores.length + ' stores. Select your preferred store, then click Update.';
            if (geoKeyMissingNote) {
              okMsg += ' (Add Google Geocoding API key in Offers API Hub to use zip/city coordinates.)';
            }
            setStatus(okMsg, false);
          })
          .catch(function() {
            setStatus('Unable to fetch stores right now.', true);
            setDebug({ step: 'StoresByCurrentLocation ERROR (network)' });
            setDebugLine('Stores request failed (network).');
          });
        }

        /**
         * Client flow: Geocode address (Google, server-side) → StoresByCurrentLocation(lat,lng,City).
         * @param {string} addressQuery zip, city, state, or empty (uses default geocode string / AZ fallback)
         */
        function fetchStores(addressQuery) {
          var qInput = (addressQuery !== undefined && addressQuery !== null) ? String(addressQuery).trim() : '';
          // If the user hasn't typed anything yet (popup opened), show the default store list immediately.
          if (qInput === '') {
            setDebugLine('Stores default (lat=' + defaultLat + ', lng=' + defaultLng + ', city=' + defaultCity + ')');
            fetchStoresWithLatLng(defaultLat, defaultLng, defaultCity);
            return;
          }
          var q = qInput;

          if (!hasGoogleGeocode) {
            setDebugLine('Google geocode key missing; using default lat/lng + city.');
            setDebug({ step: 'Stores lookup (NO Google geocode key set)', addressQuery: addressQuery, usedCity: defaultCity });
            var needGeoNote = q !== defaultCity;
            // Without Google Geocoding, we can't convert the typed zip/city into coordinates.
            // Use the default map area so the store dropdown doesn't come back empty.
            fetchStoresWithLatLng(defaultLat, defaultLng, defaultCity, needGeoNote);
            return;
          }

          setStatus('Looking up location…', false);
          updateBtn.disabled = true;
          resetSelect();
          setDebug({ step: 'Google geocode', addressQuery: addressQuery, geocodeUsed: q });
          setDebugLine('Geocoding "' + q + '" ...');

          var geoBody = new URLSearchParams({
            action: 'imm_sections_geocode_ajax',
            nonce: nonce,
            address: q
          });

          fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: geoBody.toString()
          })
          .then(function(r) { return r.json(); })
          .then(function(gres) {
            if (!gres || !gres.success || !gres.data) {
              var gmsg = (gres && gres.data && gres.data.message) ? gres.data.message : 'Unable to look up that location.';
              setDebug({ step: 'Google geocode FAILED', response: gres, message: gmsg });
              // Don't show raw error details in the UI; fall back to default store options.
              setDebugLine('');
              setStatus('Unable to look up that location. Showing default store options.', true);
              fetchStoresWithLatLng(defaultLat, defaultLng, defaultCity);
              return;
            }
            var d = gres.data;
            setDebug({ step: 'Google geocode OK', lat: d.lat, lng: d.lng, city: d.city, formatted_address: d.formatted_address });
            setDebugLine('Geocode OK: lat=' + d.lat + ', lng=' + d.lng + ', city=' + d.city);
            fetchStoresWithLatLng(d.lat, d.lng, d.city);
          })
          .catch(function() {
            setStatus('Unable to look up location. Showing default store options.', true);
            setDebug({ step: 'Google geocode ERROR (network)', error: true });
            setDebugLine('');
            fetchStoresWithLatLng(defaultLat, defaultLng, defaultCity);
          });
        }

        function openModal() {
          modal.classList.add('is-open');
          modal.setAttribute('aria-hidden', 'false');
          clearDebug();
          setDebugLine('');
          setDebug({
            step: 'init',
            hasGoogleGeocode: hasGoogleGeocode,
            defaultLat: defaultLat,
            defaultLng: defaultLng,
            defaultCity: defaultCity
          });
          setStatus('Search by zip, city, or state to see nearby stores.', false);
          searchInput.value = '';
          resetSelect();
          hideSelect();
          fetchStores('');
          setTimeout(function() { searchInput.focus(); }, 50);
        }
        window.immOpenFindStoreModal = openModal;

        function closeModal() {
          modal.classList.remove('is-open');
          modal.setAttribute('aria-hidden', 'true');
        }

        storeSelect.addEventListener('change', function() {
          updateBtn.disabled = !storeSelect.value;
        });
        storeSelect.addEventListener('focus', function() {
          if (storeSelect.options.length <= 1) {
            var addr = (searchInput.value || '').trim();
            fetchStores(addr || (hasGoogleGeocode ? defaultGeocodeAddress : defaultCity));
          }
        });
        storeSelect.addEventListener('mousedown', function() {
          if (storeSelect.options.length <= 1) {
            var addr = (searchInput.value || '').trim();
            fetchStores(addr || (hasGoogleGeocode ? defaultGeocodeAddress : defaultCity));
          }
        });

        searchBtn.addEventListener('click', function() {
          fetchStores(searchInput.value);
        });
        searchInput.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            fetchStores(searchInput.value);
          }
        });

        // Fetch while typing (zip/city/state). Debounced to avoid excessive API calls.
        var immStoreSearchTimer = null;
        searchInput.addEventListener('input', function() {
          if (immStoreSearchTimer) clearTimeout(immStoreSearchTimer);
          immStoreSearchTimer = setTimeout(function() {
            var q = String(searchInput.value || '').trim();
            if (q === '') {
              fetchStores('');
              return;
            }
            if (q.length < 3) return;
            fetchStores(q);
          }, 450);
        });

        updateBtn.addEventListener('click', function() {
          var sid = storeSelect.value;
          if (!sid) return;
          var opt = storeSelect.options[storeSelect.selectedIndex];
          var idx = opt ? parseInt(opt.getAttribute('data-store-idx') || '-1', 10) : -1;
          var selectedStore = (idx >= 0 && storesCache[idx]) ? storesCache[idx] : null;
          var label = selectedStore ? storeDropdownLabel(selectedStore) : (opt ? (opt.textContent || '').trim() : '');

          updateBtn.disabled = true;
          setStatus('Updating your store…', false);
          var updateBody = new URLSearchParams({
            action: 'imm_sections_update_store_ajax',
            nonce: nonce,
            store_id: sid,
            shopper_id: shopperId
          });
          fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: updateBody.toString()
          })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res && res.success) {
              updateHeaderDisplay(label);
              setStatus('Store updated successfully.', false);
              setTimeout(closeModal, 600);
              return;
            }
            var msg = (res && res.data && res.data.message) ? res.data.message : 'Store update failed.';
            setStatus(msg, true);
            updateBtn.disabled = false;
          })
          .catch(function() {
            setStatus('Store update failed. Please try again.', true);
            updateBtn.disabled = false;
          });
        });

        document.addEventListener('click', function(e) {
          if (modal.classList.contains('is-open')) {
            var closeBtn = e.target.closest('[data-imm-find-store-close]');
            if (closeBtn || e.target === modal) {
              e.preventDefault();
              closeModal();
              return;
            }
          }

          var trigger = e.target.closest(
            '[data-imm-change-store], [data-find-store-trigger], .find-store-trigger, .imm-change-store-trigger, a[href="#find-store"], a[href="#change-store"]'
          );
          if (!trigger) {
            var node = e.target.closest('a,button,[role="button"],span,div');
            if (node && !modal.contains(node)) {
              var t = (node.textContent || '').toLowerCase().replace(/\s+/g, ' ').trim();
              if (t.indexOf('my store') === 0 || t === 'find the store' || t === 'find store' || t === 'change store') {
                trigger = node;
              }
            }
          }
          if (trigger && !modal.contains(trigger)) {
            e.preventDefault();
            openModal();
          }
        });

        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
          }
        });
      })();
    </script>
    <?php
});


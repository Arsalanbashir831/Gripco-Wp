<?php
/**
 * Plugin Name: GRIPCO Security Hardening
 * Description: Small, site-specific protections for the public WordPress surface.
 */

defined('ABSPATH') || exit;

const GRIPCO_ADMIN_EMAIL = 'gripcosaudia@gmail.com';

add_filter('xmlrpc_enabled', '__return_false');
add_filter('wp_is_application_passwords_available', '__return_false');
add_filter('the_generator', '__return_empty_string');

remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');

// The business has one authorized WordPress account. Refuse authentication for
// every other account even if one is inserted directly into the database.
add_filter('wp_authenticate_user', static function ($user) {
    if ($user instanceof WP_User && strcasecmp($user->user_email, GRIPCO_ADMIN_EMAIL) !== 0) {
        return new WP_Error(
            'gripco_unauthorized_account',
            __('This account is not authorized to access this WordPress site.')
        );
    }

    return $user;
}, 100);

// Keep the administrative notification address pinned to the authorized
// mailbox, including when another plugin tries to change the option.
add_filter('pre_option_admin_email', static fn () => GRIPCO_ADMIN_EMAIL);
add_filter('pre_update_option_admin_email', static fn () => GRIPCO_ADMIN_EMAIL);

// Prevent dashboard-based creation, deletion, or promotion of extra users.
add_filter('user_has_cap', static function (array $allcaps, array $caps, array $args, WP_User $user): array {
    if (strcasecmp($user->user_email, GRIPCO_ADMIN_EMAIL) === 0) {
        foreach (['create_users', 'promote_users', 'delete_users', 'remove_users'] as $capability) {
            $allcaps[$capability] = false;
        }
    }

    return $allcaps;
}, 100, 4);

// If a plugin nevertheless attempts a role change, never allow another user
// to retain the administrator role.
add_action('set_user_role', static function (int $user_id, string $role): void {
    static $correcting = false;

    if ($correcting || $role !== 'administrator') {
        return;
    }

    $user = get_user_by('id', $user_id);
    if ($user && strcasecmp($user->user_email, GRIPCO_ADMIN_EMAIL) !== 0) {
        $correcting = true;
        $user->set_role('subscriber');
        $correcting = false;
    }
}, 100, 2);

/** Emit the site's recovered icon while the original media record is absent. */
$gripco_output_site_icon = static function (): void {
    $icon_url = esc_url(home_url('/wp-content/uploads/2024/04/Geotech.png'));

    echo '<link rel="icon" href="' . $icon_url . '" sizes="32x32">' . "\n";
    echo '<link rel="icon" href="' . $icon_url . '" sizes="192x192">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . $icon_url . '">' . "\n";
    echo '<meta name="msapplication-TileImage" content="' . $icon_url . '">' . "\n";
};

add_action('wp_head', $gripco_output_site_icon, 1);
add_action('admin_head', $gripco_output_site_icon, 1);

add_filter('rest_endpoints', static function (array $endpoints): array {
    if (!is_user_logged_in()) {
        unset($endpoints['/wp/v2/users']);
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);
    }

    return $endpoints;
});

add_action('send_headers', static function (): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
});

// Shared presentation for the service pages created during the NDT catalogue
// expansion. Keeping this small stylesheet here avoids per-page inline CSS.
add_action('wp_head', static function (): void {
    if (!is_singular('page') || !get_post_meta(get_queried_object_id(), '_gripco_service_catalogue', true)) {
        return;
    }
    ?>
    <style id="gripco-service-catalogue-css">
        .gripco-service{max-width:1180px;margin:0 auto;padding:80px 28px 100px;color:#30343b}
        .gripco-service__eyebrow{color:#379237;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
        .gripco-service h1{max-width:900px;margin:12px 0 24px;color:#001134;font-size:clamp(38px,5vw,68px);line-height:1.08}
        .gripco-service__lead{max-width:850px;font-size:20px;line-height:1.75;color:#60656d}
        .gripco-service__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:28px;margin-top:52px}
        .gripco-service__card{padding:34px;border:1px solid #e7e9ec;border-radius:10px;background:#fff;box-shadow:0 10px 35px rgba(0,17,52,.06)}
        .gripco-service__card h2{margin:0 0 16px;color:#001134;font-size:27px}
        .gripco-service__card p,.gripco-service__card li{line-height:1.7;color:#62666d}
        .gripco-service__card ul{margin:0;padding-left:20px}
        .gripco-service__cta{margin-top:42px;padding:32px;border-radius:10px;background:#001134;color:#fff}
        .gripco-service__cta h2{margin:0 0 10px;color:#fff}.gripco-service__cta p{margin:0;color:#dfe5ed}
        @media(max-width:767px){.gripco-service{padding:50px 20px 70px}.gripco-service__grid{grid-template-columns:1fr}}
    </style>
    <?php
}, 30);

// ElementsKit forces every legacy Elementor column in the Services mega menu
// onto one line. Explicitly make template 268 a three-column wrapping grid.
add_action('wp_head', static function (): void {
    ?>
    <style id="gripco-mega-menu-grid-css">
        .elementor-268 .elementor-element.elementor-element-3a3fa06d > .elementor-container {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: stretch !important;
        }
        .elementor-268 .elementor-element.elementor-element-3a3fa06d > .elementor-container > .elementor-column {
            flex: 0 0 33.333333% !important;
            width: 33.333333% !important;
            max-width: 33.333333% !important;
        }
        .elementor-268 .elementor-element.elementor-element-3a3fa06d > .elementor-container > .elementor-column > .elementor-widget-wrap {
            height: 100%;
        }
        @media (max-width: 1024px) {
            .elementor-268 .elementor-element.elementor-element-3a3fa06d > .elementor-container > .elementor-column {
                flex-basis: 50% !important;
                width: 50% !important;
                max-width: 50% !important;
            }
        }
        @media (max-width: 767px) {
            .elementor-268 .elementor-element.elementor-element-3a3fa06d > .elementor-container > .elementor-column {
                flex-basis: 100% !important;
                width: 100% !important;
                max-width: 100% !important;
            }
        }
    </style>
    <?php
}, 100);

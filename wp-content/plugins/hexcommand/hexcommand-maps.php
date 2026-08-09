<?php
/**
 * Plugin Name: HexCommand Maps
 * Description: Stores hex maps as a custom post type with REST API endpoints.
 * Version: 2.0.0
 * Author: HexCommand
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// INCLUDES
// ============================================================
require_once plugin_dir_path(__FILE__) . 'includes/map-listing.php';
require_once plugin_dir_path(__FILE__) . 'includes/map-creation.php';
require_once plugin_dir_path(__FILE__) . 'includes/game-mechanics.php';


// ============================================================
// NEW USER REGISTRATION — grant 10 starter credits
// ============================================================
add_action('user_register', function (int $user_id): void {
    update_field('credits', 10, 'user_' . $user_id);
});

// ============================================================
// ADMIN MENU
// ============================================================
add_action('admin_menu', 'register_menu');

function register_menu() {
    remove_menu_page('edit.php?post_type=army');
    remove_menu_page('edit.php?post_type=building');
    remove_menu_page('edit.php?post_type=hexmap');
    add_menu_page('Gestion', 'Gestion', 'edit_posts', 'edit.php?post_type=hexmap', '', '', 2);
    remove_submenu_page('edit.php?post_type=hexmap', 'edit.php?post_type=hexmap');
    remove_submenu_page('edit.php?post_type=hexmap', 'post-new.php?post_type=hexmap');
    add_submenu_page('edit.php?post_type=hexmap', 'Hexmaps',   'Hexmaps',   'edit_posts', 'edit.php?post_type=hexmap',   false);
    add_submenu_page('edit.php?post_type=hexmap', 'Armies',    'Armies',    'edit_posts', 'edit.php?post_type=army',     false);
    add_submenu_page('edit.php?post_type=hexmap', 'Buildings', 'Buildings', 'edit_posts', 'edit.php?post_type=building', false);
}

// ============================================================
// CUSTOM POST TYPES
// ============================================================
add_action('init', function () {
    register_post_type('hexmap', [
        'labels'       => ['name' => 'Hex Maps', 'singular_name' => 'Hex Map'],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'show_in_rest' => false,
        'supports'     => ['title', 'custom-fields'],
        'menu_icon'    => 'dashicons-location-alt',
    ]);

    register_post_type('army', [
        'labels'       => ['name' => 'Armies', 'singular_name' => 'Army'],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'show_in_rest' => false,
        'supports'     => ['title', 'author', 'custom-fields'],
    ]);
});

// ============================================================
// SHORTCODES
// ============================================================

// Shared enqueue helper — only injects config variables once per page
function hexcommand_enqueue(string $mode): void {
    wp_enqueue_script('hexcommand-app', get_site_url() . '/9th_campain/assets/index.js', [], null, true);
    wp_enqueue_style('hexcommand-style', get_site_url() . '/9th_campain/assets/style.css');
    // Use wp_add_inline_script to avoid duplicate var declarations from wp_localize_script
    if (!wp_script_is('hexcommand-app', 'done') && !defined('HEXCOMMAND_CONFIG_PRINTED')) {
        define('HEXCOMMAND_CONFIG_PRINTED', true);
        $config = json_encode([
            'nonce' => wp_create_nonce('wp_rest'),
            'mode'  => $mode,
        ]);
        wp_add_inline_script('hexcommand-app', "window.hexcommandNonce = {$config}['nonce']; window.hexcommandMode = {$config}['mode'];", 'before');
    }
}

// Current game shortcode — [hexcommand]
add_shortcode('hexcommand', function () {
    hexcommand_enqueue('game');
    return '<div id="app"></div>';
});



// ============================================================
// ROLE HELPERS
// ============================================================
function hexcommand_is_logged_in(): bool {
    return is_user_logged_in();
}

function hexcommand_get_role(): string {
    if (!is_user_logged_in()) return 'none';
    return 'player';
}

// ============================================================
// CREDITS HELPER
// ============================================================
function hexcommand_get_credits(int $user_id): int {
    return (int) get_field('credits', 'user_' . $user_id);
}

function hexcommand_deduct_credits(int $user_id, int $amount): bool {
    $current = hexcommand_get_credits($user_id);
    if ($current < $amount) return false;
    update_field('credits', $current - $amount, 'user_' . $user_id);
    return true;
}

// ============================================================
// HELPERS
// ============================================================
function hexcommand_find_post_by_uid(string $uid): ?WP_Post {
    $posts = get_posts([
        'post_type'   => 'hexmap',
        'numberposts' => 1,
        'meta_query'  => [['key' => 'hexmap_uid', 'value' => $uid]],
    ]);
    return $posts[0] ?? null;
}

function hexcommand_generate_uid(): string {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

function hexcommand_get_json_field(int $post_id, string $key): array {
    $raw = get_field($key, $post_id);
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function hexcommand_set_json_field(int $post_id, string $key, array $value): void {
    update_field($key, wp_json_encode($value), $post_id);
}

function hexcommand_get_state(int $post_id): string {
    $state = get_field('hexmap_state', $post_id);
    if (in_array($state, ['created', 'ongoing', 'started', 'ended'], true)) {
        return $state;
    }
    return 'created';
}

// ============================================================
// REST API ROUTES
// ============================================================
add_action('rest_api_init', function () {

    // ── Auth / user ──────────────────────────────────────────
    register_rest_route('hexcommand/v1', '/me', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_get_me',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('hexcommand/v1', '/me/heartbeat', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_heartbeat',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // ── Map listing ──────────────────────────────────────────
    register_rest_route('hexcommand/v1', '/maps', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_list_maps',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // ── Map creation / management ────────────────────────────
    register_rest_route('hexcommand/v1', '/maps', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_save_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_load_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})', [
        'methods'             => 'DELETE',
        'callback'            => 'hexcommand_delete_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/forcestart', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_force_start',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/finish', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_finish_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/start', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_start_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/end', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_end_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // ── Player join / setup ──────────────────────────────────
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/join', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_join_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/requests', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_get_requests',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/approve/(?P<user_id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_approve_request',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/deny/(?P<user_id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_deny_request',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/setup', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_save_setup',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // ── Chat ─────────────────────────────────────────────────
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/chat', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_get_chat',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/chat', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_post_chat',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // ── Game mechanics ───────────────────────────────────────
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/claim', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_claim_tile',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/endturn', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_end_turn',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    // nextturn is handled automatically by cron after 16h
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/resign', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_resign',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/buyArmy', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_buy_army',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/moveArmy', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_move_army',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/upgradeArmy', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_upgrade_army',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/renameArmy', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_rename_army',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
});
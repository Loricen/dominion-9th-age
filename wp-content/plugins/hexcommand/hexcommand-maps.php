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
    update_field('credits', 1000, 'user_' . $user_id);
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

    register_post_type('building', [
        'labels'       => ['name' => 'Buildings', 'singular_name' => 'Building'],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => false,
        'show_in_rest' => false,
        'supports'     => ['title', 'author', 'custom-fields'],
    ]);
    wp_enqueue_style('site-style', plugin_dir_url(__FILE__) . 'assets/style_custo.css');
});

// ============================================================
// SHORTCODES
// ============================================================

// Shared enqueue helper — only injects config variables once per page
function hexcommand_enqueue(string $mode): void {
    wp_enqueue_script('hexcommand-app', get_site_url() . '/9th_campain/assets/index.js', [], null, true);
    wp_enqueue_style('hexcommand-style', get_site_url() . '/9th_campain/assets/style.css', array( 'site-style' ));
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
function hexcommand_rest_get_credits(WP_REST_Request $request) {
    $user_id = get_current_user_id();

    return [
        'credits' => (int) hexcommand_get_credits($user_id),
    ];
}

function hexcommand_deduct_credits(int $user_id, int $amount): bool {
    $current = hexcommand_get_credits($user_id);
    if ($current < $amount) return false;
    update_field('credits', $current - $amount, 'user_' . $user_id);
    return true;
}

add_action('wp_enqueue_scripts', function () {
    if (!is_user_logged_in()) {
        return;
    }
    wp_enqueue_script(
        'hexcommand-credits',
        plugin_dir_url(__FILE__) . 'assets/js/crowns.js',
        [],
        '1.0.0',
        true
    );
    wp_localize_script('hexcommand-credits', 'hexcommandCredits', [
        'restUrl' => esc_url_raw(rest_url('hexcommand/v1/me/credits')),
        'nonce'   => wp_create_nonce('wp_rest'),
    ]);
});

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

// ============================================================
// BUILDING HELPERS
// A "building" post represents a city or a farm.
// ACF fields: 'user' (owner user ID), 'map' (linked hexmap post ID),
// 'position' (JSON text field, same shape as army/spy position: {"q":.., "r":..}),
// 'building_type' (text: 'city' or 'farm', defaults to 'city' for older data)
// ============================================================
function hexcommand_get_buildings_for_map(int $post_id): array {
    $posts = get_posts([
        'post_type'      => 'building',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'map', 'value' => $post_id]],
    ]);
    $result = [];
    foreach ($posts as $b) {
        $pos = hexcommand_get_json_field($b->ID, 'position') ?: [];
        $result[] = [
            'id'      => $b->ID,
            'user_id' => get_field('building_user', $b->ID)['ID'],
            'q'       => $pos['q'] ?? null,
            'r'       => $pos['r'] ?? null,
            'building_type' => get_field('building_type', $b->ID) ?: 'city',
        ];
    }
    return $result;
}

function hexcommand_create_building(int $post_id, int $user_id, int $q, int $r, string $title = 'City', string $building_type = 'city'): int {
    $building_id = wp_insert_post([
        'post_type'   => 'building',
        'post_status' => 'publish',
        'post_title'  => $title . '_' . $user_id . '_' . $q . '_' . $r,
        'post_author' => $user_id,
    ]);
    if (!$building_id || is_wp_error($building_id)) return 0;
    update_field('building_user', $user_id, $building_id);
    update_field('map',  $post_id, $building_id);
    update_field('building_type', $building_type, $building_id);
    hexcommand_set_json_field($building_id, 'position', ['q' => $q, 'r' => $r]);
    return $building_id;
}

function hexcommand_tile_has_building(int $post_id, int $q, int $r): ?array {
    foreach (hexcommand_get_buildings_for_map($post_id) as $b) {
        if ((int)($b['q'] ?? -999) === $q && (int)($b['r'] ?? -999) === $r) return $b;
    }
    return null;
}

function hexcommand_tile_is_own_building(int $post_id, int $user_id, int $q, int $r): bool {
    $b = hexcommand_tile_has_building($post_id, $q, $r);
    return $b !== null && $b['user_id'] === $user_id;
}

// Only 'city' buildings count — used to gate unit purchases (you can't buy
// an army, spy, settler or farmer while standing on a farm, only a city)
function hexcommand_tile_is_own_city(int $post_id, int $user_id, int $q, int $r): bool {
    $b = hexcommand_tile_has_building($post_id, $q, $r);
    return $b !== null && $b['user_id'] === $user_id && ($b['building_type'] ?? 'city') === 'city';
}

// True if (q,r) matches the given faction's resource-bonus terrain.
// Shared by income calculation, found_city and found_farm so the rule only
// lives in one place.
function hexcommand_faction_bonus_tile(string $faction, int $q, int $r, array $hex_terrain): bool {
    $terrain_here = $hex_terrain[$q . ',' . $r] ?? '';
    if ($faction === 'elves') return $terrain_here === 'forest';
    if ($faction === 'dwarves')   return $terrain_here === 'mountain';
    if ($faction === 'goblins')    return $terrain_here === 'swamp';
    if ($faction === 'orcs')    return $terrain_here === 'desert';
    if ($faction === 'humans') return $terrain_here === 'plains';
    if ($faction === 'froglings') {
        $offsets = $q % 2 === 0
            ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
            : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];
        foreach ($offsets as [$dq, $dr]) {
            if (($hex_terrain[($q+$dq) . ',' . ($r+$dr)] ?? '') === 'water') return true;
        }
        return false;
    }
    return false;
}

// True if (q,r) is adjacent to (or on) one of $user_id's own CITY buildings
// (farms don't count) — used for the 1.5x "bonus tiles around cities" multiplier.
function hexcommand_tile_near_own_city(int $post_id, int $user_id, int $q, int $r, array $city_tiles): bool {
    $key = $q . ',' . $r;
    if (isset($city_tiles[$key])) return true; // the city's own tile counts too
    $offsets = $q % 2 === 0
        ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
        : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];
    foreach ($offsets as [$dq, $dr]) {
        if (isset($city_tiles[($q+$dq) . ',' . ($r+$dr)])) return true;
    }
    return false;
}

// Flood-fills outward from (q,r) through every connected tile NOT owned by
// $user_id (water acts as a natural boundary and is never part of the
// pocket). Returns the full list of pocket tiles if the region is fully
// sealed off by the claimant's territory/water without ever touching the
// edge of the map, or null if it's open (not enclosed).
function hexcommand_find_enclosed_pocket(int $post_id, int $user_id, int $q, int $r): ?array {
    $hexmap_data = json_decode(get_field('hexmap_data', $post_id), true) ?: [];
    $terrain_map = [];   // "q,r" => terrain
    $valid_tiles = [];   // "q,r" => true, every tile that actually exists on the map
    foreach ($hexmap_data as $hex) {
        $key = $hex['q'] . ',' . $hex['r'];
        $terrain_map[$key] = $hex['terrain'] ?? '';
        $valid_tiles[$key] = true;
    }

    $start_key = $q . ',' . $r;
    if (!isset($valid_tiles[$start_key])) return null;

    $owned_tiles = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];
    $owned_map = [];
    foreach ($owned_tiles as $t) {
        $owned_map[$t['q'] . ',' . $t['r']] = (int)($t['user_id'] ?? 0);
    }
    foreach (hexcommand_get_buildings_for_map($post_id) as $b) {
        $owned_map[$b['q'] . ',' . $b['r']] = $b['user_id'];
    }

    if (($owned_map[$start_key] ?? null) === $user_id) return null; // already yours

    $OFFSETS_EVEN = [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]];
    $OFFSETS_ODD  = [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];

    $visited = [$start_key => true];
    $queue   = [[$q, $r]];
    $pocket  = [['q' => $q, 'r' => $r]];

    while (!empty($queue)) {
        [$cq, $cr] = array_shift($queue);
        $offsets = $cq % 2 === 0 ? $OFFSETS_EVEN : $OFFSETS_ODD;

        foreach ($offsets as [$dq, $dr]) {
            $nq   = $cq + $dq;
            $nr   = $cr + $dr;
            $nkey = $nq . ',' . $nr;

            if (!isset($valid_tiles[$nkey])) {
                // Falls off the edge of the map entirely — the pocket is open, not enclosed
                return null;
            }
            if (($owned_map[$nkey] ?? null) === $user_id) {
                // Sealed by the claimant's own territory on this side — fine
                continue;
            }
            if (($terrain_map[$nkey] ?? '') === 'water') {
                // Water seals the pocket too, but isn't itself claimable
                continue;
            }
            if (isset($visited[$nkey])) continue;

            $visited[$nkey] = true;
            $queue[]  = [$nq, $nr];
            $pocket[] = ['q' => $nq, 'r' => $nr];
        }
    }

    return $pocket;
}

function hexcommand_get_state(int $post_id): string {
    $state = get_field('hexmap_state', $post_id);
    if (in_array($state, ['created', 'awaiting', 'started', 'ended'], true)) {
        return $state;
    }
    return 'created';
}

// ============================================================
// PREVENT BROWSER CACHING OF API RESPONSES
// Without this, some browsers (notably Firefox) can reuse a stale cached
// GET /maps/{uid} response, showing outdated ownership/state data even
// after the underlying data has changed.
// ============================================================
add_filter('rest_pre_serve_request', function ($served, $result, $request) {
    if (strpos($request->get_route(), '/hexcommand/v1') === 0) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    return $served;
}, 10, 3);

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
    register_rest_route('hexcommand/v1', '/me/credits', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_rest_get_credits',
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
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/mergeArmy', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_merge_army',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/upgradeArmy', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_upgrade_army',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/buySpy', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_buy_spy',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/buySettler', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_buy_settler',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/foundCity', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_found_city',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/buyFarmer', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_buy_farmer',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/foundFarm', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_found_farm',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/repairBuildings', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_repair_buildings',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/renameArmy', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_rename_army',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);
});

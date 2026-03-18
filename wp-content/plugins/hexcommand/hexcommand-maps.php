<?php
/**
 * Plugin Name: HexCommand Maps
 * Description: Stores hex maps as a custom post type with REST API endpoints.
 * Version: 1.2.0
 * Author: HexCommand
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// ADMIN MENU
// ============================================================
add_action('admin_menu', 'register_menu');

function register_menu() {
    remove_menu_page('edit.php?post_type=army');
    remove_menu_page('edit.php?post_type=building');
    remove_menu_page('edit.php?post_type=hexmap');
    add_menu_page('Gestion', 'Gestion', 'edit_posts', 'edit.php?post_type=army', '', '', 2);
    remove_submenu_page('edit.php?post_type=army', 'edit.php?post_type=army');
    remove_submenu_page('edit.php?post_type=army', 'post-new.php?post_type=army');
    add_submenu_page('edit.php?post_type=army', 'Armies',    'Armies',    'edit_posts', 'edit.php?post_type=army',     false);
    add_submenu_page('edit.php?post_type=army', 'Buildings', 'Buildings', 'edit_posts', 'edit.php?post_type=building', false);
    add_submenu_page('edit.php?post_type=army', 'Hexmaps',   'Hexmaps',   'edit_posts', 'edit.php?post_type=hexmap',   false);
}

// ============================================================
// CUSTOM POST TYPE
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
});

// ============================================================
// SHORTCODE
// ============================================================
add_shortcode('hexcommand', function () {
    wp_enqueue_script('hexcommand-app', get_site_url() . '/9th_campain/assets/index.js', [], null, true);
    wp_enqueue_style('hexcommand-style', get_site_url() . '/9th_campain/assets/index.css');
    wp_localize_script('hexcommand-app', 'hexcommandNonce', wp_create_nonce('wp_rest'));
    return '<div id="app"></div>';
});

// ============================================================
// ROLE HELPERS
// ============================================================
function hexcommand_is_logged_in(): bool {
    return is_user_logged_in();
}

function hexcommand_is_advanced_player(): bool {
    $user = wp_get_current_user();
    return array_intersect(['advanced_player', 'administrator'], (array) $user->roles) !== [];
}

function hexcommand_get_role(): string {
    $user = wp_get_current_user();
    $roles = (array) $user->roles;
    if (array_intersect(['advanced_player', 'administrator'], $roles) !== []) return 'advanced_player';
    if (in_array('player', $roles, true)) return 'player';
    return 'none';
}

// ============================================================
// HELPER: find post by UID (any author)
// ============================================================
function hexcommand_find_post_by_uid(string $uid): ?WP_Post {
    $posts = get_posts([
        'post_type'   => 'hexmap',
        'numberposts' => 1,
        'meta_query'  => [['key' => 'hexmap_uid', 'value' => $uid]],
    ]);
    return $posts[0] ?? null;
}

// ============================================================
// HELPER: generate UID
// ============================================================
function hexcommand_generate_uid(): string {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

// ============================================================
// HELPER: read/write array fields as JSON in post_meta
// ============================================================
function hexcommand_get_json_field(int $post_id, string $key): array {
    $raw = get_field($key, $post_id);
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function hexcommand_set_json_field(int $post_id, string $key, array $value): void {
    update_field($key, wp_json_encode(array_values($value)), $post_id);
}
// ============================================================
// HELPER: get map state (reads radio field, falls back to legacy booleans)
// ============================================================
function hexcommand_get_state(int $post_id): string {
    $state = get_field('hexmap_state', $post_id);
    if (in_array($state, ['created', 'ongoing', 'started', 'ended'], true)) {
        return $state;
    }
    return 'created';
}

// ============================================================
// HELPER: format map list item
// ============================================================
function hexcommand_format_map(WP_Post $post, int $user_id = 0): array {
    return [
        'hexmap_uid' => get_field('hexmap_uid', $post->ID),
        'name'       => $post->post_title,
        'size'       => get_post_meta($post->ID, '_hexmap_size', true),
        'savedAt'    => $post->post_date,
        'mapStatus'  => hexcommand_get_state($post->ID),
        'is_owner'   => $user_id > 0 && (int) $post->post_author === $user_id,
    ];
}

// ============================================================
// REST API ROUTES
// ============================================================
add_action('rest_api_init', function () {

    // Get current user info + role
    register_rest_route('hexcommand/v1', '/me', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_get_me',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // List maps (owned for advanced_player, linked for player)
    register_rest_route('hexcommand/v1', '/maps', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_list_maps',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // Save a map — advanced_player only
    register_rest_route('hexcommand/v1', '/maps', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_save_map',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);

    // Load a map by UID — both roles
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_load_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // Delete a map — advanced_player owner only
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})', [
        'methods'             => ['DELETE', 'POST'],
        'callback'            => 'hexcommand_delete_map',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);

    // Request to join a map — any logged in user
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/join', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_join_map',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // Get pending requests for maps I own — advanced_player only
    register_rest_route('hexcommand/v1', '/requests', [
        'methods'             => 'GET',
        'callback'            => 'hexcommand_get_requests',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);

    // Approve a join request
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/approve/(?P<user_id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_approve_request',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);

    // Save player setup (faction, color, starting city)
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/setup', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_save_setup',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // Claim a tile — linked player, map must be started
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/claim', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_claim_tile',
        'permission_callback' => 'hexcommand_is_logged_in',
    ]);

    // Advance turn — owner only, map must be started
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/nextturn', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_next_turn',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);

    // Finish (validate/lock) a map — owner only
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/finish', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_finish_map',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);

    // Start the game — owner only, all players must have a setup
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/start', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_start_map',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);

    // End the game — owner only, map must be finished first
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/end', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_end_map',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);

    // Deny a join request
    register_rest_route('hexcommand/v1', '/maps/(?P<uid>[A-Z0-9]{8})/deny/(?P<user_id>\d+)', [
        'methods'             => 'POST',
        'callback'            => 'hexcommand_deny_request',
        'permission_callback' => 'hexcommand_is_advanced_player',
    ]);
});

// ============================================================
// GET ME
// ============================================================
function hexcommand_get_me(): WP_REST_Response {
    $user = wp_get_current_user();
    return new WP_REST_Response([
        'id'   => $user->ID,
        'name' => $user->display_name,
        'role' => hexcommand_get_role(),
    ], 200);
}

// ============================================================
// LIST MAPS
// advanced_player → their owned maps
// player          → maps they are linked to
// ============================================================
function hexcommand_list_maps(): WP_REST_Response {
    $user_id    = get_current_user_id();
    // Player: read linked map post IDs from hex_linked textarea
    $linked_ids = json_decode(get_user_meta($user_id, 'hex_linked', true), true) ?: [];
    if (empty($linked_ids)) {
        return new WP_REST_Response([], 200);
    }

    $posts = get_posts([
        'post_type'   => 'hexmap',
        'post__in'    => $linked_ids,
        'numberposts' => 50,
        'orderby'     => 'date',
        'order'       => 'DESC',
    ]);

    return new WP_REST_Response(array_map(fn($p) => hexcommand_format_map($p, $user_id), $posts), 200);
}

// ============================================================
// SAVE MAP — advanced_player only
// ============================================================
function hexcommand_save_map(WP_REST_Request $request): WP_REST_Response {
    $body = $request->get_json_params();

    if (empty($body['hexes']) || !is_array($body['hexes'])) {
        return new WP_REST_Response(['error' => 'Invalid map data'], 400);
    }

    $user_id = get_current_user_id();
    $uid     = hexcommand_generate_uid();
    $title   = sanitize_text_field($body['name'] ?? 'Untitled Map');

    $post_id = wp_insert_post([
        'post_type'   => 'hexmap',
        'post_status' => 'publish',
        'post_title'  => $title,
        'post_author' => $user_id,
    ]);

    if (is_wp_error($post_id)) {
        return new WP_REST_Response(['error' => 'Failed to save map'], 500);
    }

    update_field('hexmap_uid',       $uid,                              $post_id);
    update_field('hexmap_state',     'created',                         $post_id);
    update_field('hexmap_data',      wp_json_encode($body['hexes']),    $post_id);
    hexcommand_set_json_field($post_id, 'users_linked', []);
    hexcommand_set_json_field($post_id, 'pending_requests', []);

    // Add new map to owner's hex_linked list
    $owner_linked = json_decode(get_user_meta($user_id, 'hex_linked', true), true) ?: [];
    if (!in_array($post_id, $owner_linked, true)) {
        $owner_linked[] = $post_id;
        update_user_meta($user_id, 'hex_linked', json_encode($owner_linked));
    }

    update_post_meta($post_id, '_hexmap_cols',    intval($body['cols']    ?? 80));
    update_post_meta($post_id, '_hexmap_rows',    intval($body['rows']    ?? 55));
    update_post_meta($post_id, '_hexmap_size',    sanitize_text_field($body['size'] ?? 'medium'));
    update_post_meta($post_id, '_hexmap_version', intval($body['version'] ?? 1));

    return new WP_REST_Response([
        'success'    => true,
        'hexmap_uid' => $uid,
        'post_id'    => $post_id,
        'name'       => $title,
    ], 201);
}

// ============================================================
// LOAD MAP — both roles
// Also returns whether current user is linked or pending
// ============================================================
function hexcommand_load_map(WP_REST_Request $request): WP_REST_Response {
    $uid     = strtoupper($request->get_param('uid'));
    $user_id = get_current_user_id();
    $post    = hexcommand_find_post_by_uid($uid);

    if (!$post) {
        return new WP_REST_Response(['error' => 'Map not found'], 404);
    }

    $post_id  = $post->ID;
    $hexes    = json_decode(get_field('hexmap_data', $post_id), true);
    $linked   = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'users_linked') ?: []));
    $pending  = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'pending_requests') ?: []));

    $owner_data = get_userdata((int) $post->post_author);
    $players = [];
    if ($owner_data) {
        $players[] = ['user_id' => (int) $post->post_author, 'name' => $owner_data->display_name, 'is_owner' => true];
    }
    foreach ($linked as $linked_id) {
        $u = get_userdata($linked_id);
        if ($u) $players[] = ['user_id' => $linked_id, 'name' => $u->display_name, 'is_owner' => false];
    }

    return new WP_REST_Response([
        'hexmap_uid' => $uid,
        'name'       => $post->post_title,
        'version'    => (int) get_post_meta($post_id, '_hexmap_version', true),
        'cols'       => (int) get_post_meta($post_id, '_hexmap_cols', true),
        'rows'       => (int) get_post_meta($post_id, '_hexmap_rows', true),
        'size'       => get_post_meta($post_id, '_hexmap_size', true),
        'savedAt'    => $post->post_date,
        'mapStatus'  => hexcommand_get_state($post->ID),
        'hexturn'    => (int) get_field('hexturn', $post_id) ?: 0,
        'hexes'      => $hexes,
        'players'      => $players,
        'owned_tiles'   => hexcommand_get_json_field($post_id, 'owned_tiles') ?: [],
        'player_setups' => hexcommand_get_json_field($post_id, 'player_setups') ?: [],
        'player_setup' => (function() use ($post_id, $user_id) {
            $setups = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
            foreach ($setups as $s) {
                if ((int)($s['user_id'] ?? 0) === $user_id) return $s;
            }
            return null;
        })(),
        'is_owner'   => (int) $post->post_author === $user_id,
        'is_linked'  => in_array($user_id, $linked, true),
        'is_pending' => in_array($user_id, $pending, true),
    ], 200);
}

// ============================================================
// DELETE MAP — advanced_player owner only
// ============================================================
function hexcommand_delete_map(WP_REST_Request $request): WP_REST_Response {
    $method = $request->get_header('X-HTTP-Method-Override') ?? $request->get_method();
    if (!in_array(strtoupper($method), ['DELETE', 'POST'])) {
        return new WP_REST_Response(['error' => 'Method not allowed'], 405);
    }

    $uid     = strtoupper($request->get_param('uid'));
    $user_id = get_current_user_id();
    $post    = hexcommand_find_post_by_uid($uid);

    if (!$post) {
        return new WP_REST_Response(['error' => 'Map not found'], 404);
    }

    if ((int) $post->post_author !== $user_id) {
        return new WP_REST_Response(['error' => 'Forbidden'], 403);
    }

    wp_delete_post($post->ID, true);
    return new WP_REST_Response(['success' => true], 200);
}

// ============================================================
// JOIN MAP — any logged in user
// ============================================================
function hexcommand_join_map(WP_REST_Request $request): WP_REST_Response {
    $uid     = strtoupper($request->get_param('uid'));
    $user_id = get_current_user_id();
    $post    = hexcommand_find_post_by_uid($uid);

    if (!$post) {
        return new WP_REST_Response(['error' => 'Map not found'], 404);
    }

    $post_id = $post->ID;
    $linked  = (array) (hexcommand_get_json_field($post_id, 'users_linked') ?: []);
    $pending = (array) (hexcommand_get_json_field($post_id, 'pending_requests') ?: []);

    $linked  = array_map('intval', $linked);
    $pending = array_map('intval', $pending);

    if (in_array($user_id, $linked, true)) {
        return new WP_REST_Response(['error' => 'Already linked to this map'], 409);
    }

    if (in_array($user_id, $pending, true)) {
        return new WP_REST_Response(['error' => 'Request already pending'], 409);
    }
    array_push($pending, $user_id);
    hexcommand_set_json_field($post_id, 'pending_requests', $pending);

    return new WP_REST_Response(['success' => true, 'status' => 'pending'], 200);
}

// ============================================================
// GET PENDING REQUESTS — advanced_player only
// Returns all pending requests across all maps they own
// ============================================================
function hexcommand_get_requests(): WP_REST_Response {
    $user_id = get_current_user_id();

    $posts = get_posts([
        'post_type'   => 'hexmap',
        'author'      => $user_id,
        'numberposts' => 50,
    ]);

    $requests = [];

    foreach ($posts as $post) {
        $pending = (array) (hexcommand_get_json_field($post->ID, 'pending_requests') ?: []);
        foreach ($pending as $requester_id) {
            $requester_id = intval($requester_id);
            $requester    = get_userdata($requester_id);
            if (!$requester) continue;
            $requests[] = [
                'map_uid'      => get_field('hexmap_uid', $post->ID),
                'map_name'     => $post->post_title,
                'user_id'      => $requester_id,
                'user_name'    => $requester->display_name,
            ];
        }
    }

    return new WP_REST_Response($requests, 200);
}

// ============================================================
// APPROVE REQUEST — advanced_player only
// ============================================================
function hexcommand_approve_request(WP_REST_Request $request): WP_REST_Response {
    $uid         = strtoupper($request->get_param('uid'));
    $requester   = intval($request->get_param('user_id'));
    $owner_id    = get_current_user_id();
    $post        = hexcommand_find_post_by_uid($uid);

    if (!$post || (int) $post->post_author !== $owner_id) {
        return new WP_REST_Response(['error' => 'Forbidden'], 403);
    }

    $post_id = $post->ID;
    $linked  = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'users_linked') ?: []));
    $pending = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'pending_requests') ?: []));

    // Move from pending to linked
    $pending = array_values(array_filter($pending, fn($id) => $id !== $requester));
    if (!in_array($requester, $linked, true)) {
        array_push($linked, $requester);
    }

    hexcommand_set_json_field($post_id, 'users_linked', $linked);
    hexcommand_set_json_field($post_id, 'pending_requests', $pending);

    // Add map to requester's hex_linked text
    $user_linked_maps = json_decode(get_user_meta($requester, 'hex_linked', true), true) ?: [];
    if (!in_array($post_id, $user_linked_maps, true)) {
        $user_linked_maps[] = $post_id;
        update_user_meta($requester, 'hex_linked', json_encode($user_linked_maps));
    }

    return new WP_REST_Response(['success' => true], 200);
}

// ============================================================
// DENY REQUEST — advanced_player only
// ============================================================
function hexcommand_deny_request(WP_REST_Request $request): WP_REST_Response {
    $uid       = strtoupper($request->get_param('uid'));
    $requester = intval($request->get_param('user_id'));
    $owner_id  = get_current_user_id();
    $post      = hexcommand_find_post_by_uid($uid);

    if (!$post || (int) $post->post_author !== $owner_id) {
        return new WP_REST_Response(['error' => 'Forbidden'], 403);
    }

    $post_id = $post->ID;
    $pending = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'pending_requests') ?: []));
    $pending = array_values(array_filter($pending, fn($id) => $id !== $requester));

    hexcommand_set_json_field($post_id, 'pending_requests', $pending);
    

    return new WP_REST_Response(['success' => true], 200);
}
// ============================================================
// SAVE PLAYER SETUP — linked player only
// ============================================================
function hexcommand_save_setup(WP_REST_Request $request): WP_REST_Response {
    $uid     = strtoupper($request->get_param('uid'));
    $user_id = get_current_user_id();
    $post    = hexcommand_find_post_by_uid($uid);

    if (!$post) {
        return new WP_REST_Response(['error' => 'Map not found'], 404);
    }

    $post_id = $post->ID;
    $state   = hexcommand_get_state($post_id);
    if (in_array($state, ['started', 'ended'], true)) {
        return new WP_REST_Response(['error' => 'Setup is locked'], 409);
    }

    // Must be a linked player (or owner)
    $linked = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'users_linked') ?: []));
    $is_owner = (int) $post->post_author === $user_id;
    if (!$is_owner && !in_array($user_id, $linked, true)) {
        return new WP_REST_Response(['error' => 'Forbidden'], 403);
    }

    $body    = $request->get_json_params();
    $faction = sanitize_text_field($body['faction'] ?? '');
    $color   = sanitize_hex_color($body['color'] ?? '') ?: '';
    $city_q  = isset($body['city_q']) ? intval($body['city_q']) : null;
    $city_r  = isset($body['city_r']) ? intval($body['city_r']) : null;

    if (!$faction || !$color || $city_q === null || $city_r === null) {
        return new WP_REST_Response(['error' => 'Missing setup fields'], 400);
    }

    $setups = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    // Index by user_id (stored as string key in JSON)
    $setups_map = [];
    foreach ($setups as $s) {
        $setups_map[$s['user_id']] = $s;
    }
    $existing_actions = isset($setups_map[$user_id]['actions']) ? (int)$setups_map[$user_id]['actions'] : 10;
    $setups_map[$user_id] = [
        'user_id'  => $user_id,
        'faction'  => $faction,
        'color'    => $color,
        'city_q'   => $city_q,
        'city_r'   => $city_r,
        'actions'  => $existing_actions,
    ];
    hexcommand_set_json_field($post_id, 'player_setups', array_values($setups_map));

    return new WP_REST_Response(['success' => true, 'user_id' => $user_id], 200);
}

// ============================================================
// CLAIM TILE — costs 1 action, tile must be adjacent to owned territory
// ============================================================
function hexcommand_claim_tile(WP_REST_Request $request): WP_REST_Response {
    $uid     = strtoupper($request->get_param('uid'));
    $user_id = get_current_user_id();
    $post    = hexcommand_find_post_by_uid($uid);

    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);

    $post_id = $post->ID;
    if (hexcommand_get_state($post_id) !== 'started') {
        return new WP_REST_Response(['error' => 'Map must be started to claim tiles'], 409);
    }

    // Must be owner or linked player
    $linked   = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'users_linked') ?: []));
    $is_owner = (int) $post->post_author === $user_id;
    if (!$is_owner && !in_array($user_id, $linked, true)) {
        return new WP_REST_Response(['error' => 'Forbidden'], 403);
    }

    $body = $request->get_json_params();
    $q    = isset($body['q']) ? intval($body['q']) : null;
    $r    = isset($body['r']) ? intval($body['r']) : null;
    if ($q === null || $r === null) return new WP_REST_Response(['error' => 'Missing coordinates'], 400);

    // Check player has actions remaining
    $setups = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);
    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // Check tile is not water
    $hexmap_data = json_decode(get_field('hexmap_data', $post_id), true) ?: [];
    $tile_terrain = null;
    foreach ($hexmap_data as $hex) {
        if ((int)($hex['q'] ?? -1) === $q && (int)($hex['r'] ?? -1) === $r) {
            $tile_terrain = $hex['terrain'] ?? null;
            break;
        }
    }
    if ($tile_terrain === 'water') {
        return new WP_REST_Response(['error' => 'Water tiles cannot be claimed'], 409);
    }

    // Check tile not already owned
    $owned_tiles = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];
    foreach ($owned_tiles as $t) {
        if ((int)$t['q'] === $q && (int)$t['r'] === $r) {
            return new WP_REST_Response(['error' => 'Tile already claimed'], 409);
        }
    }

    // Check adjacency — must be adjacent to own city or own tile
    $my_city_q = (int)($setups[$setup_idx]['city_q'] ?? -999);
    $my_city_r = (int)($setups[$setup_idx]['city_r'] ?? -999);
    $my_tiles  = [['q' => $my_city_q, 'r' => $my_city_r]];
    foreach ($owned_tiles as $t) {
        if ((int)($t['user_id'] ?? 0) === $user_id) $my_tiles[] = $t;
    }

    $adjacent = false;
    foreach ($my_tiles as $t) {
        // Hex grid adjacency offsets (offset coords, even/odd col)
        $col_parity = (int)$t['q'] % 2;
        $offsets = $col_parity === 0
            ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
            : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];
        foreach ($offsets as [$dq, $dr]) {
            if ((int)$t['q'] + $dq === $q && (int)$t['r'] + $dr === $r) {
                $adjacent = true; break 2;
            }
        }
    }
    if (!$adjacent) return new WP_REST_Response(['error' => 'Tile not adjacent to your territory'], 409);

    // Claim tile and deduct action
    $owned_tiles[] = ['q' => $q, 'r' => $r, 'user_id' => $user_id];
    $setups[$setup_idx]['actions'] = $actions - 1;
    hexcommand_set_json_field($post_id, 'owned_tiles', $owned_tiles);
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    return new WP_REST_Response([
        'success'     => true,
        'owned_tiles' => $owned_tiles,
        'user_id'     => $user_id,
        'actions'     => $setups[$setup_idx]['actions'],
    ], 200);
}

// ============================================================
// NEXT TURN — increments hexturn and resets all player actions
// ============================================================
function hexcommand_next_turn(WP_REST_Request $request): WP_REST_Response {
    $uid      = strtoupper($request->get_param('uid'));
    $owner_id = get_current_user_id();
    $post     = hexcommand_find_post_by_uid($uid);

    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    if ((int) $post->post_author !== $owner_id) return new WP_REST_Response(['error' => 'Forbidden'], 403);

    $post_id = $post->ID;
    if (hexcommand_get_state($post_id) !== 'started') {
        return new WP_REST_Response(['error' => 'Map must be started'], 409);
    }

    $hexturn = ((int) get_field('hexturn', $post_id) ?: 0) + 1;
    update_field('hexturn', $hexturn, $post_id);

    // Reset all player actions to 10
    $setups = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    foreach ($setups as &$setup) {
        $setup['actions'] = 10;
    }
    unset($setup);
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    return new WP_REST_Response([
        'success'       => true,
        'hexturn'       => $hexturn,
        'player_setups' => $setups,
    ], 200);
}

// ============================================================
// FINISH MAP — locks the map after creation (owner only)
// ============================================================
function hexcommand_finish_map(WP_REST_Request $request): WP_REST_Response {
    $uid      = strtoupper($request->get_param('uid'));
    $owner_id = get_current_user_id();
    $post     = hexcommand_find_post_by_uid($uid);

    if (!$post) {
        return new WP_REST_Response(['error' => 'Map not found'], 404);
    }
    if ((int) $post->post_author !== $owner_id) {
        return new WP_REST_Response(['error' => 'Forbidden'], 403);
    }
    $state = get_field('hexmap_state', $post->ID);
    if ($state === 'ongoing') {
        return new WP_REST_Response(['error' => 'Map is already ongoing'], 409);
    }
    else if ($state === 'created') {
        $state = update_field('hexmap_state', 'ongoing', $post->ID);
    }

    return new WP_REST_Response(['success' => true], 200);
}

// ============================================================
// START MAP — owner only, all linked players must have a setup
// ============================================================
function hexcommand_start_map(WP_REST_Request $request): WP_REST_Response {
    $uid      = strtoupper($request->get_param('uid'));
    $owner_id = get_current_user_id();
    $post     = hexcommand_find_post_by_uid($uid);

    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    if ((int) $post->post_author !== $owner_id) return new WP_REST_Response(['error' => 'Forbidden'], 403);

    $post_id = $post->ID;
    $state   = hexcommand_get_state($post_id);
    if ($state !== 'ongoing') return new WP_REST_Response(['error' => 'Map must be ongoing to start'], 409);

    $linked  = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'users_linked') ?: []));
    $setups  = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_user_ids = array_map(fn($s) => (int)($s['user_id'] ?? 0), $setups);

    // All linked players + owner must have a setup
    $all_players = array_merge([$owner_id], $linked);
    if (empty($linked)) {
        return new WP_REST_Response(['error' => 'No players have joined yet'], 409);
    }
    foreach ($all_players as $player_id) {
        if (!in_array($player_id, $setup_user_ids, true)) {
            return new WP_REST_Response(['error' => 'Not all players have chosen a starting city'], 409);
        }
    }

    update_field('hexmap_state', 'started', $post_id);
    return new WP_REST_Response(['success' => true], 200);
}

// ============================================================
// END MAP — ends the game (owner only, map must be finished)
// ============================================================
function hexcommand_end_map(WP_REST_Request $request): WP_REST_Response {
    $uid      = strtoupper($request->get_param('uid'));
    $owner_id = get_current_user_id();
    $post     = hexcommand_find_post_by_uid($uid);

    if (!$post) {
        return new WP_REST_Response(['error' => 'Map not found'], 404);
    }
    if ((int) $post->post_author !== $owner_id) {
        return new WP_REST_Response(['error' => 'Forbidden'], 403);
    }
    $state = get_field('hexmap_state', $post->ID);
    if ($state === 'ongoing') {
        $state = update_field('hexmap_state', 'ended', $post->ID);
    }

    return new WP_REST_Response(['success' => true], 200);
}
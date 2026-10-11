<?php
if (!defined('ABSPATH')) exit;

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

    // Check tile ownership
    $owned_tiles  = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];
    $existing_idx = null;
    $existing_owner = null;
    foreach ($owned_tiles as $i => $t) {
        if ((int)$t['q'] === $q && (int)$t['r'] === $r) {
            $existing_idx   = $i;
            $existing_owner = (int)($t['user_id'] ?? 0);
            break;
        }
    }

    if ($existing_idx !== null && $existing_owner === $user_id) {
        return new WP_REST_Response(['error' => 'Tile already claimed'], 409);
    }

    if ($existing_idx !== null) {
        // Enemy-owned tile — try to annex the whole enclosed pocket it belongs to,
        // not just this single tile
        $tile_building = hexcommand_tile_has_building($post_id, $q, $r);
        if ($tile_building !== null) {
            return new WP_REST_Response(['error' => 'A city tile can only be captured by attacking it'], 409);
        }

        $pocket = hexcommand_find_enclosed_pocket($post_id, $user_id, $q, $r);
        if ($pocket === null) {
            return new WP_REST_Response(['error' => 'This area must be fully surrounded by your territory to claim it'], 409);
        }

        // Annex every non-building tile in the pocket
        $owned_map = [];
        foreach ($owned_tiles as $i => $t) $owned_map[$t['q'] . ',' . $t['r']] = $i;

        $annexed_count = 0;
        foreach ($pocket as $pt) {
            $pkey = $pt['q'] . ',' . $pt['r'];
            if (hexcommand_tile_has_building($post_id, $pt['q'], $pt['r']) !== null) {
                continue; // cities are never auto-annexed, only their surrounding land
            }
            if (isset($owned_map[$pkey])) {
                $owned_tiles[$owned_map[$pkey]]['user_id'] = $user_id;
            } else {
                $owned_tiles[] = ['q' => $pt['q'], 'r' => $pt['r'], 'user_id' => $user_id];
            }
            $annexed_count++;
        }

        $setups[$setup_idx]['actions'] = $actions - 1;
        hexcommand_set_json_field($post_id, 'owned_tiles', $owned_tiles);
        hexcommand_set_json_field($post_id, 'player_setups', $setups);

        return new WP_REST_Response([
            'success'       => true,
            'owned_tiles'   => $owned_tiles,
            'user_id'       => $user_id,
            'actions'       => $setups[$setup_idx]['actions'],
            'annexed'       => true,
            'annexed_count' => $annexed_count,
        ], 200);
    }

    // Unclaimed tile — normal expansion: must be adjacent to one of your own buildings or territory
    $my_tiles = [];
    foreach (hexcommand_get_buildings_for_map($post_id) as $b) {
        if ($b['user_id'] === $user_id) $my_tiles[] = ['q' => $b['q'], 'r' => $b['r']];
    }
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

    $owned_tiles[] = ['q' => $q, 'r' => $r, 'user_id' => $user_id];
    $setups[$setup_idx]['actions'] = $actions - 1;
    hexcommand_set_json_field($post_id, 'owned_tiles', $owned_tiles);
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    return new WP_REST_Response([
        'success'     => true,
        'owned_tiles' => $owned_tiles,
        'user_id'     => $user_id,
        'actions'     => $setups[$setup_idx]['actions'],
        'annexed'     => false,
    ], 200);
}

// ============================================================
// ELIMINATION CHECK — auto-resign players with no armies AND no city
// Returns true if the game ended (all eliminated)
// ============================================================
function hexcommand_check_eliminations(int $post_id, array &$setups): bool {
    $changed = false;
    foreach ($setups as &$setup) {
        if (!empty($setup['resigned'])) continue;
        $player_id = (int)($setup['user_id'] ?? 0);
        if (!$player_id) continue;

        // Check if player still has at least one building (city)
        $has_city = false;
        foreach (hexcommand_get_buildings_for_map($post_id) as $b) {
            // Only real cities keep a player alive — a lone farm doesn't count
            if ($b['user_id'] === $player_id && ($b['building_type'] ?? 'city') === 'city') {
                $has_city = true; break;
            }
        }

        // Check if player still has armies
        $army_count = count(get_posts([
            'post_type'      => 'army',
            'posts_per_page' => 1,
            'author'         => $player_id,
            'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
            'fields'         => 'ids',
        ]));

        if (!$has_city && $army_count === 0) {
            // Auto-resign: remove their tiles
            $owned_tiles = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];
            $owned_tiles = array_values(array_filter($owned_tiles, fn($t) => (int)($t['user_id'] ?? 0) !== $player_id));
            hexcommand_set_json_field($post_id, 'owned_tiles', $owned_tiles);
            $setup['resigned'] = true;
            $changed = true;
        }
    }
    unset($setup);

    if ($changed) {
        hexcommand_set_json_field($post_id, 'player_setups', $setups);
    }

    // End game if only one (or zero) active players remain
    $active = array_filter($setups, fn($s) => empty($s['resigned']));
    if (count($active) <= 1) {
        update_field('hexmap_state', 'ended', $post_id);
        return true;
    }
    return false;
}

function hexcommand_do_next_turn(int $post_id): WP_REST_Response {
    $hexturn     = ((int) get_field('hexturn', $post_id) ?: 0) + 1;
    update_field('hexturn', $hexturn, $post_id);
    update_field('turn_started_at', time(), $post_id);

    $setups      = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $owned_tiles = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];

    // Load hexmap terrain data and all armies once (shared across players)
    $hexmap_data = json_decode(get_field('hexmap_data', $post_id), true) ?: [];
    $hex_terrain = []; // key "q,r" => terrain
    foreach ($hexmap_data as $hex) {
        $hex_terrain[$hex['q'] . ',' . $hex['r']] = $hex['terrain'] ?? '';
    }

    // Load all armies for upkeep calculation
    $all_army_posts = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    $army_data  = []; // player_id => [ power, ... ] (armies only, spies excluded from combat upkeep)
    $spy_counts = []; // player_id => count of spies
    foreach ($all_army_posts as $ap) {
        $owner     = (int) $ap->post_author;
        $unit_type = get_field('unit_type', $ap->ID) ?: 'army';
        if ($unit_type === 'spy') {
            $spy_counts[$owner] = ($spy_counts[$owner] ?? 0) + 1;
            continue;
        }
        $power = (int) get_field('power', $ap->ID);
        if (!isset($army_data[$owner])) $army_data[$owner] = [];
        $army_data[$owner][] = $power;
    }

    // Buildings: group city tiles per owner (for the "near a city" bonus) and
    // farm tiles (any owner — a farm boosts whichever tile it stands on)
    $all_buildings   = hexcommand_get_buildings_for_map($post_id);
    $city_tiles_by_owner = []; // player_id => ["q,r" => true]
    $farm_tiles           = []; // "q,r" => true
    foreach ($all_buildings as $b) {
        $key = $b['q'] . ',' . $b['r'];
        if (($b['building_type'] ?? 'city') === 'farm') {
            $farm_tiles[$key] = true;
        } else {
            $city_tiles_by_owner[$b['user_id']][$key] = true;
        }
    }

    foreach ($setups as &$setup) {
        $setup['actions']   = 10;
        $setup['turn_done'] = false;

        $player_id  = (int)($setup['user_id'] ?? 0);
        $faction    = $setup['faction'] ?? '';

        // ── Tile count ────────────────────────────────────────────────
        $player_tiles = [];
        foreach ($owned_tiles as $t) {
            if ((int)($t['user_id'] ?? 0) === $player_id) {
                $player_tiles[] = ['q' => (int)$t['q'], 'r' => (int)$t['r']];
            }
        }
        $tile_count = count($player_tiles);

        // ── Army upkeep ───────────────────────────────────────────────
        $army_upkeep = 0;
        foreach ($army_data[$player_id] ?? [] as $power) {
            if ($power <= 500) {
                $army_upkeep += $power / 1.5;
            } elseif ($power <= 4000) {
                $army_upkeep += $power;
            } else {
                $army_upkeep += $power * 2;
            }
        }
        $army_upkeep = (int) round($army_upkeep);

        // ── Faction bonus income ──────────────────────────────────────
        // Base +5 per owned tile matching the faction's bonus terrain, ×1.5 if
        // the tile is on/adjacent to one of this player's own cities, ×2 more
        // if a farm stands on that tile. The two multipliers stack.
        $bonus = 0;
        $my_city_tiles = $city_tiles_by_owner[$player_id] ?? [];
        foreach ($player_tiles as $t) {
            if (!hexcommand_faction_bonus_tile($faction, $t['q'], $t['r'], $hex_terrain)) continue;
            $tile_bonus = 5.0;
            if (hexcommand_tile_near_own_city($post_id, $player_id, $t['q'], $t['r'], $my_city_tiles)) {
                $tile_bonus *= 1.5;
            }
            if (isset($farm_tiles[$t['q'] . ',' . $t['r']])) {
                $tile_bonus *= 2;
            }
            $bonus += $tile_bonus;
        }
        $bonus = (int) round($bonus);

        // ── Spy upkeep ───────────────────────────────────────────────
        $spy_count = $spy_counts[$player_id] ?? 0;
        $spy_upkeep = $spy_count > 0 ? (200 + $spy_count * 20) : 0;

        // ── Final income ──────────────────────────────────────────────
        $income = 50 + ($tile_count * 10) - $army_upkeep - $spy_upkeep + $bonus;
        $setup['resources'] = max(0, (int)($setup['resources'] ?? 0) + $income);
        $setup['last_income'] = $income; // expose for debugging / UI
    }
    unset($setup);
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    // Auto-resign any players with no city and no armies
    $game_ended = hexcommand_check_eliminations($post_id, $setups);

    return new WP_REST_Response([
        'success'       => true,
        'all_done'      => true,
        'hexturn'       => $hexturn,
        'player_setups' => $setups,
        'mapStatus'     => $game_ended ? 'ended' : hexcommand_get_state($post_id),
    ], 200);
}

function hexcommand_end_turn(WP_REST_Request $request): WP_REST_Response {
    $uid     = strtoupper($request->get_param('uid'));
    $user_id = get_current_user_id();
    $post    = hexcommand_find_post_by_uid($uid);

    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);

    $post_id = $post->ID;
    if (hexcommand_get_state($post_id) !== 'started') {
        return new WP_REST_Response(['error' => 'Map must be started'], 409);
    }

    // Must be owner or linked player
    $linked   = array_map('intval', (array) (hexcommand_get_json_field($post_id, 'users_linked') ?: []));
    $is_owner = (int) $post->post_author === $user_id;
    if (!$is_owner && !in_array($user_id, $linked, true)) {
        return new WP_REST_Response(['error' => 'Forbidden'], 403);
    }

    // Mark this player done
    $setups = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    foreach ($setups as &$setup) {
        if ((int)($setup['user_id'] ?? 0) === $user_id) {
            $setup['turn_done'] = true;
            break;
        }
    }
    unset($setup);
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    // Check if ALL players are done
    $all_players = array_merge([$post->post_author], $linked);
    $setup_map   = array_column($setups, null, 'user_id');
    $all_done    = !empty($setups) && array_reduce($all_players, function($carry, $pid) use ($setup_map) {
        return $carry && !empty($setup_map[$pid]['turn_done']);
    }, true);

    if ($all_done) {
        return hexcommand_do_next_turn($post_id);
    }

    return new WP_REST_Response([
        'success'       => true,
        'all_done'      => false,
        'player_setups' => $setups,
    ], 200);
}

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

    return hexcommand_do_next_turn($post_id);
}

// ============================================================
// HELPER — load all army posts for a map in frontend shape
// ============================================================
function hexcommand_get_armies_for_map(int $post_id, int $current_user_id): array {
    $army_posts = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    $result = [];
    foreach ($army_posts as $a) {
        $army_user = (int) $a->post_author;
        $pos       = hexcommand_get_json_field($a->ID, 'position') ?: [];
        $result[] = [
            'id'        => $a->ID,
            'name'      => $a->post_title,
            'user_id'   => $army_user,
            'hexmap'    => $post_id,
            'power'     => (int) get_field('power', $a->ID),
            'unit_type' => get_field('unit_type', $a->ID) ?: 'army',
            'stats'     => hexcommand_get_json_field($a->ID, 'stats') ?: [],
            'q'         => $pos['q'] ?? null,
            'r'         => $pos['r'] ?? null,
            'visible'   => $army_user === $current_user_id,
        ];
    }
    return $result;
}

function hexcommand_buy_army(WP_REST_Request $request): WP_REST_Response {
    $body    = $request->get_json_params();
    $uid     = $request->get_param('uid');
    $user_id = get_current_user_id();

    $q = isset($body['q']) ? intval($body['q']) : null;
    $r = isset($body['r']) ? intval($body['r']) : null;
    if ($q === null || $r === null) {
        return new WP_REST_Response(['error' => 'Missing coordinates'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Find player setup
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    // Check player has enough resources (army costs 500)
    $army_cost = 500;
    $resources = (int)($setups[$setup_idx]['resources'] ?? 0);
    if ($resources < $army_cost) {
        return new WP_REST_Response(['error' => 'Not enough resources'], 409);
    }

    // Check player has actions remaining
    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // Check the tile is one of the player's own buildings (capital or founded city)
    if (!hexcommand_tile_is_own_city($post_id, $user_id, $q, $r)) {
        return new WP_REST_Response(['error' => 'Armies can only be built on one of your cities'], 409);
    }

    // Check the tile is not occupied by another army
    $existing_armies = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    foreach ($existing_armies as $a) {
        $pos = hexcommand_get_json_field($a->ID, 'position');
        if (isset($pos['q'], $pos['r']) && (int)$pos['q'] === $q && (int)$pos['r'] === $r) {
            return new WP_REST_Response(['error' => 'Tile already occupied by an army'], 409);
        }
    }

    // Create the army post
    $power   = rand(400, 500); // base army strength
    $army_id = wp_insert_post([
        'post_type'   => 'army',
        'post_status' => 'publish',
        'post_title'  => 'army_' . $user_id . '_' . (count($existing_armies) + 1),
        'post_author' => $user_id,
    ]);
    if (!$army_id || is_wp_error($army_id)) {
        return new WP_REST_Response(['error' => 'Failed to create army'], 500);
    }
    update_field('hexmap', $post_id,  $army_id);
    update_field('power',  $power,    $army_id);
    update_field('user_linked',  $user_id,    $army_id);
    hexcommand_set_json_field($army_id, 'stats',    []);
    hexcommand_set_json_field($army_id, 'position', ['q' => $q, 'r' => $r]);

    // Deduct resources and one action
    $setups[$setup_idx]['resources'] = $resources - $army_cost;
    $setups[$setup_idx]['actions']   = $actions - 1;
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    $all_armies = hexcommand_get_armies_for_map($post_id, $user_id);

    return new WP_REST_Response([
        'success'   => true,
        'armies'    => $all_armies,
        'resources' => $setups[$setup_idx]['resources'],
        'user_id'   => $user_id,
    ], 200);
}

// ============================================================
// BUY SPY — costs 2000 resources, builds on own city tile
// Spies grant vision (removes fog) around themselves but have no combat power
// ============================================================
function hexcommand_buy_spy(WP_REST_Request $request): WP_REST_Response {
    $body    = $request->get_json_params();
    $uid     = $request->get_param('uid');
    $user_id = get_current_user_id();

    $q = isset($body['q']) ? intval($body['q']) : null;
    $r = isset($body['r']) ? intval($body['r']) : null;
    if ($q === null || $r === null) {
        return new WP_REST_Response(['error' => 'Missing coordinates'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Find player setup
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    // Check player has enough resources (spy costs 2000)
    $spy_cost  = 2000;
    $resources = (int)($setups[$setup_idx]['resources'] ?? 0);
    if ($resources < $spy_cost) {
        return new WP_REST_Response(['error' => 'Not enough resources'], 409);
    }

    // Check player has actions remaining
    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // Check the tile is one of the player's own buildings (capital or founded city)
    if (!hexcommand_tile_is_own_city($post_id, $user_id, $q, $r)) {
        return new WP_REST_Response(['error' => 'Spies can only be built on one of your cities'], 409);
    }

    // Check the tile is not occupied by another unit
    $existing_units = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    foreach ($existing_units as $a) {
        $pos = hexcommand_get_json_field($a->ID, 'position');
        if (isset($pos['q'], $pos['r']) && (int)$pos['q'] === $q && (int)$pos['r'] === $r) {
            return new WP_REST_Response(['error' => 'Tile already occupied by a unit'], 409);
        }
    }

    // Create the spy post (stored as an 'army' post with unit_type = 'spy')
    $spy_id = wp_insert_post([
        'post_type'   => 'army',
        'post_status' => 'publish',
        'post_title'  => 'spy_' . $user_id . '_' . (count($existing_units) + 1),
        'post_author' => $user_id,
    ]);
    if (!$spy_id || is_wp_error($spy_id)) {
        return new WP_REST_Response(['error' => 'Failed to create spy'], 500);
    }
    update_field('hexmap',    $post_id, $spy_id);
    update_field('power',     0,        $spy_id); // spies have no combat power
    update_field('unit_type', 'spy',    $spy_id);
    hexcommand_set_json_field($spy_id, 'stats',    []);
    hexcommand_set_json_field($spy_id, 'position', ['q' => $q, 'r' => $r]);

    // Deduct resources and one action
    $setups[$setup_idx]['resources'] = $resources - $spy_cost;
    $setups[$setup_idx]['actions']   = $actions - 1;
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    $all_armies = hexcommand_get_armies_for_map($post_id, $user_id);

    return new WP_REST_Response([
        'success'   => true,
        'armies'    => $all_armies,
        'resources' => $setups[$setup_idx]['resources'],
        'user_id'   => $user_id,
    ], 200);
}

// ============================================================
// BUY SETTLER — costs 5000 resources, builds on own city tile
// Settlers grant no combat power and never expand borders; they can
// later found a new city (see hexcommand_found_city)
// ============================================================
function hexcommand_buy_settler(WP_REST_Request $request): WP_REST_Response {
    $body    = $request->get_json_params();
    $uid     = $request->get_param('uid');
    $user_id = get_current_user_id();

    $q = isset($body['q']) ? intval($body['q']) : null;
    $r = isset($body['r']) ? intval($body['r']) : null;
    if ($q === null || $r === null) {
        return new WP_REST_Response(['error' => 'Missing coordinates'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Find player setup
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    // Check player has enough resources (settler costs 5000)
    $settler_cost = 5000;
    $resources    = (int)($setups[$setup_idx]['resources'] ?? 0);
    if ($resources < $settler_cost) {
        return new WP_REST_Response(['error' => 'Not enough resources'], 409);
    }

    // Check player has actions remaining
    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // Check the tile is one of the player's own buildings (capital or founded city)
    if (!hexcommand_tile_is_own_city($post_id, $user_id, $q, $r)) {
        return new WP_REST_Response(['error' => 'Settlers can only be built on one of your cities'], 409);
    }

    // Check the tile is not occupied by another unit
    $existing_units = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    foreach ($existing_units as $a) {
        $pos = hexcommand_get_json_field($a->ID, 'position');
        if (isset($pos['q'], $pos['r']) && (int)$pos['q'] === $q && (int)$pos['r'] === $r) {
            return new WP_REST_Response(['error' => 'Tile already occupied by a unit'], 409);
        }
    }

    // Create the settler post (stored as an 'army' post with unit_type = 'settler')
    $settler_id = wp_insert_post([
        'post_type'   => 'army',
        'post_status' => 'publish',
        'post_title'  => 'settler_' . $user_id . '_' . (count($existing_units) + 1),
        'post_author' => $user_id,
    ]);
    if (!$settler_id || is_wp_error($settler_id)) {
        return new WP_REST_Response(['error' => 'Failed to create settler'], 500);
    }
    update_field('hexmap',    $post_id, $settler_id);
    update_field('power',     0,        $settler_id); // settlers have no combat power
    update_field('unit_type', 'settler', $settler_id);
    hexcommand_set_json_field($settler_id, 'stats',    []);
    hexcommand_set_json_field($settler_id, 'position', ['q' => $q, 'r' => $r]);

    // Deduct resources and one action
    $setups[$setup_idx]['resources'] = $resources - $settler_cost;
    $setups[$setup_idx]['actions']   = $actions - 1;
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    $all_armies = hexcommand_get_armies_for_map($post_id, $user_id);

    return new WP_REST_Response([
        'success'   => true,
        'armies'    => $all_armies,
        'resources' => $setups[$setup_idx]['resources'],
        'user_id'   => $user_id,
    ], 200);
}

// ============================================================
// FOUND CITY — consumes a settler to create a new "building" post
// Requires the settler's tile to grant the player's faction resource bonus,
// and no other building already present on that tile.
// ============================================================
function hexcommand_found_city(WP_REST_Request $request): WP_REST_Response {
    $body      = $request->get_json_params();
    $uid       = $request->get_param('uid');
    $user_id   = get_current_user_id();
    $unit_id   = isset($body['unit_id']) ? intval($body['unit_id']) : null;

    if (!$unit_id) {
        return new WP_REST_Response(['error' => 'Missing unit_id'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Verify unit ownership and type
    $unit_post = get_post($unit_id);
    if (!$unit_post || $unit_post->post_type !== 'army') {
        return new WP_REST_Response(['error' => 'Unit not found'], 404);
    }
    if ((int) $unit_post->post_author !== $user_id) {
        return new WP_REST_Response(['error' => 'Not your unit'], 403);
    }
    if ((get_field('unit_type', $unit_id) ?: 'army') !== 'settler') {
        return new WP_REST_Response(['error' => 'Only settlers can found a city'], 409);
    }
    if ((int) get_field('hexmap', $unit_id) !== $post_id) {
        return new WP_REST_Response(['error' => 'Unit does not belong to this map'], 403);
    }

    $pos = hexcommand_get_json_field($unit_id, 'position') ?: [];
    $q = (int)($pos['q'] ?? -999);
    $r = (int)($pos['r'] ?? -999);

    // Find player setup (for faction + actions)
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // No other building may already exist on this tile
    if (hexcommand_tile_has_building($post_id, $q, $r) !== null) {
        return new WP_REST_Response(['error' => 'A city already exists on this tile'], 409);
    }

    // Tile must grant the player's faction resource bonus
    $faction     = $setups[$setup_idx]['faction'] ?? '';
    $hexmap_data = json_decode(get_field('hexmap_data', $post_id), true) ?: [];
    $hex_terrain = [];
    foreach ($hexmap_data as $hex) {
        $hex_terrain[$hex['q'] . ',' . $hex['r']] = $hex['terrain'] ?? '';
    }

    if (!hexcommand_faction_bonus_tile($faction, $q, $r, $hex_terrain)) {
        return new WP_REST_Response(['error' => 'This tile does not grant your faction\'s resource bonus'], 409);
    }

    // Found the city: create the building, consume the settler, deduct an action
    $building_id = hexcommand_create_building($post_id, $user_id, $q, $r, 'City', 'city');
    if (!$building_id) {
        return new WP_REST_Response(['error' => 'Failed to found city'], 500);
    }
    wp_delete_post($unit_id, true);

    $setups[$setup_idx]['actions'] = $actions - 1;
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    return new WP_REST_Response([
        'success'   => true,
        'armies'    => hexcommand_get_armies_for_map($post_id, $user_id),
        'buildings' => hexcommand_get_buildings_for_map($post_id),
        'actions'   => $setups[$setup_idx]['actions'],
        'user_id'   => $user_id,
    ], 200);
}

// ============================================================
// BUY FARMER — costs 300 resources, builds on own city tile
// Farmers grant no combat power and never expand borders; they can
// later found a farm on a qualifying resource-bonus tile (see
// hexcommand_found_farm)
// ============================================================
function hexcommand_buy_farmer(WP_REST_Request $request): WP_REST_Response {
    $body    = $request->get_json_params();
    $uid     = $request->get_param('uid');
    $user_id = get_current_user_id();

    $q = isset($body['q']) ? intval($body['q']) : null;
    $r = isset($body['r']) ? intval($body['r']) : null;
    if ($q === null || $r === null) {
        return new WP_REST_Response(['error' => 'Missing coordinates'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Find player setup
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    // Check player has enough resources (farmer costs 300)
    $farmer_cost = 300;
    $resources   = (int)($setups[$setup_idx]['resources'] ?? 0);
    if ($resources < $farmer_cost) {
        return new WP_REST_Response(['error' => 'Not enough resources'], 409);
    }

    // Check player has actions remaining
    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // Check the tile is one of the player's own cities
    if (!hexcommand_tile_is_own_city($post_id, $user_id, $q, $r)) {
        return new WP_REST_Response(['error' => 'Farmers can only be built on one of your cities'], 409);
    }

    // Check the tile is not occupied by another unit
    $existing_units = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    foreach ($existing_units as $a) {
        $pos = hexcommand_get_json_field($a->ID, 'position');
        if (isset($pos['q'], $pos['r']) && (int)$pos['q'] === $q && (int)$pos['r'] === $r) {
            return new WP_REST_Response(['error' => 'Tile already occupied by a unit'], 409);
        }
    }

    // Create the farmer post (stored as an 'army' post with unit_type = 'farmer')
    $farmer_id = wp_insert_post([
        'post_type'   => 'army',
        'post_status' => 'publish',
        'post_title'  => 'farmer_' . $user_id . '_' . (count($existing_units) + 1),
        'post_author' => $user_id,
    ]);
    if (!$farmer_id || is_wp_error($farmer_id)) {
        return new WP_REST_Response(['error' => 'Failed to create farmer'], 500);
    }
    update_field('hexmap',    $post_id, $farmer_id);
    update_field('power',     0,        $farmer_id); // farmers have no combat power
    update_field('unit_type', 'farmer', $farmer_id);
    hexcommand_set_json_field($farmer_id, 'stats',    []);
    hexcommand_set_json_field($farmer_id, 'position', ['q' => $q, 'r' => $r]);

    // Deduct resources and one action
    $setups[$setup_idx]['resources'] = $resources - $farmer_cost;
    $setups[$setup_idx]['actions']   = $actions - 1;
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    $all_armies = hexcommand_get_armies_for_map($post_id, $user_id);

    return new WP_REST_Response([
        'success'   => true,
        'armies'    => $all_armies,
        'resources' => $setups[$setup_idx]['resources'],
        'user_id'   => $user_id,
    ], 200);
}

// ============================================================
// FOUND FARM — consumes a farmer to create a new "farm" building.
// Requires the farmer's tile to grant the player's faction resource bonus,
// to be owned by the player, and to have no other building on it. A farm
// doubles the resource output of the tile it stands on (see do_next_turn).
// ============================================================
function hexcommand_found_farm(WP_REST_Request $request): WP_REST_Response {
    $body    = $request->get_json_params();
    $uid     = $request->get_param('uid');
    $user_id = get_current_user_id();
    $unit_id = isset($body['unit_id']) ? intval($body['unit_id']) : null;

    if (!$unit_id) {
        return new WP_REST_Response(['error' => 'Missing unit_id'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Verify unit ownership and type
    $unit_post = get_post($unit_id);
    if (!$unit_post || $unit_post->post_type !== 'army') {
        return new WP_REST_Response(['error' => 'Unit not found'], 404);
    }
    if ((int) $unit_post->post_author !== $user_id) {
        return new WP_REST_Response(['error' => 'Not your unit'], 403);
    }
    if ((get_field('unit_type', $unit_id) ?: 'army') !== 'farmer') {
        return new WP_REST_Response(['error' => 'Only farmers can found a farm'], 409);
    }
    if ((int) get_field('hexmap', $unit_id) !== $post_id) {
        return new WP_REST_Response(['error' => 'Unit does not belong to this map'], 403);
    }

    $pos = hexcommand_get_json_field($unit_id, 'position') ?: [];
    $q = (int)($pos['q'] ?? -999);
    $r = (int)($pos['r'] ?? -999);

    // Find player setup (for faction + actions)
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // The tile must be owned by the player — a farm is built on your own land
    $owned_tiles = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];
    $owns_tile = false;
    foreach ($owned_tiles as $t) {
        if ((int)($t['q'] ?? -999) === $q && (int)($t['r'] ?? -999) === $r && (int)($t['user_id'] ?? 0) === $user_id) {
            $owns_tile = true; break;
        }
    }
    if (!$owns_tile) {
        return new WP_REST_Response(['error' => 'You must own this tile to build a farm on it'], 409);
    }

    // No other building may already exist on this tile
    if (hexcommand_tile_has_building($post_id, $q, $r) !== null) {
        return new WP_REST_Response(['error' => 'A building already exists on this tile'], 409);
    }

    // Tile must grant the player's faction resource bonus
    $faction     = $setups[$setup_idx]['faction'] ?? '';
    $hexmap_data = json_decode(get_field('hexmap_data', $post_id), true) ?: [];
    $hex_terrain = [];
    foreach ($hexmap_data as $hex) {
        $hex_terrain[$hex['q'] . ',' . $hex['r']] = $hex['terrain'] ?? '';
    }

    if (!hexcommand_faction_bonus_tile($faction, $q, $r, $hex_terrain)) {
        return new WP_REST_Response(['error' => 'This tile does not grant your faction\'s resource bonus'], 409);
    }

    // Found the farm: create the building, consume the farmer, deduct an action
    $building_id = hexcommand_create_building($post_id, $user_id, $q, $r, 'Farm', 'farm');
    if (!$building_id) {
        return new WP_REST_Response(['error' => 'Failed to found farm'], 500);
    }
    wp_delete_post($unit_id, true);

    $setups[$setup_idx]['actions'] = $actions - 1;
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    return new WP_REST_Response([
        'success'   => true,
        'armies'    => hexcommand_get_armies_for_map($post_id, $user_id),
        'buildings' => hexcommand_get_buildings_for_map($post_id),
        'actions'   => $setups[$setup_idx]['actions'],
        'user_id'   => $user_id,
    ], 200);
}


// ============================================================
// ONE-TIME REPAIR — fixes buildings whose post_author was wrongly
// set to the map owner by an earlier buggy deploy. Matches each
// building's position (q,r) against player_setups' city_q/city_r
// to find the correct owner. Owner-only. Safe to call repeatedly
// (no-op once everything is already correct).
// ============================================================
function hexcommand_repair_buildings(WP_REST_Request $request): WP_REST_Response {
    $uid     = strtoupper($request->get_param('uid'));
    $user_id = get_current_user_id();
    $post    = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    if ((int) $post->post_author !== $user_id) {
        return new WP_REST_Response(['error' => 'Only the map owner can run this'], 403);
    }

    $post_id = $post->ID;
    $setups  = hexcommand_get_json_field($post_id, 'player_setups') ?: [];

    $fixed = [];
    foreach (hexcommand_get_buildings_for_map($post_id) as $b) {
        foreach ($setups as $s) {
            $sq = $s['city_q'] ?? null;
            $sr = $s['city_r'] ?? null;
            if ($sq === null || $sr === null) continue;
            if ((int)$sq === (int)$b['q'] && (int)$sr === (int)$b['r']) {
                $correct_owner = (int)($s['user_id'] ?? 0);
                if ($correct_owner > 0 && $correct_owner !== $b['user_id']) {
                    wp_update_post(['ID' => $b['id'], 'post_author' => $correct_owner]);
                    $fixed[] = ['building_id' => $b['id'], 'old_owner' => $b['user_id'], 'new_owner' => $correct_owner];
                }
                break;
            }
        }
    }

    return new WP_REST_Response([
        'success' => true,
        'fixed'   => $fixed,
        'buildings' => hexcommand_get_buildings_for_map($post_id),
    ], 200);
}

// ============================================================
// MOVE ARMY — costs 1 action, captures adjacent unowned tiles
// ============================================================
function hexcommand_move_army(WP_REST_Request $request): WP_REST_Response {
    $body    = $request->get_json_params();
    $uid     = $request->get_param('uid');
    $user_id = get_current_user_id();

    $army_id = isset($body['army_id']) ? intval($body['army_id']) : null;
    $q       = isset($body['q'])       ? intval($body['q'])       : null;
    $r       = isset($body['r'])       ? intval($body['r'])       : null;
    if ($army_id === null || $q === null || $r === null) {
        return new WP_REST_Response(['error' => 'Missing army_id or coordinates'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Find player setup
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    // Check actions
    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // Find the army post and verify ownership
    $army_post = get_post($army_id);
    if (!$army_post || $army_post->post_type !== 'army') {
        return new WP_REST_Response(['error' => 'Army not found'], 404);
    }
    $army_owner = (int) $army_post->post_author;
    if ($army_owner !== $user_id) {
        return new WP_REST_Response(['error' => 'Not your army'], 403);
    }
    $army_hexmap = (int) get_field('hexmap', $army_id);
    if ($army_hexmap !== $post_id) {
        return new WP_REST_Response(['error' => 'Army does not belong to this map'], 403);
    }
    $unit_type = get_field('unit_type', $army_id) ?: 'army';

    // Get current position
    $from_pos = hexcommand_get_json_field($army_id, 'position') ?: [];
    $from_q   = (int)($from_pos['q'] ?? 0);
    $from_r   = (int)($from_pos['r'] ?? 0);

    // Load all armies for adjacency/collision checks
    $all_army_posts = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    $armies = [];
    foreach ($all_army_posts as $ap) {
        $pos = hexcommand_get_json_field($ap->ID, 'position') ?: [];
        $armies[] = [
            'id'      => $ap->ID,
            'user_id' => (int) $ap->post_author,
            'power'   => (int) get_field('power', $ap->ID),
            'q'       => (int)($pos['q'] ?? 0),
            'r'       => (int)($pos['r'] ?? 0),
        ];
    }
    // Destination must be adjacent (offset-grid, even/odd col parity)
    $col_parity  = $from_q % 2;
    $offsets     = $col_parity === 0
        ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
        : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];
    $adjacent = false;
    foreach ($offsets as [$dq, $dr]) {
        if ($from_q + $dq === $q && $from_r + $dr === $r) { $adjacent = true; break; }
    }
    if (!$adjacent) return new WP_REST_Response(['error' => 'Target tile is not adjacent'], 409);

    // Check destination tile is not water
    $hexmap_data = json_decode(get_field('hexmap_data', $post_id), true) ?: [];
    foreach ($hexmap_data as $hex) {
        if ((int)($hex['q'] ?? -1) === $q && (int)($hex['r'] ?? -1) === $r) {
            if (($hex['terrain'] ?? '') === 'water') {
                return new WP_REST_Response(['error' => 'Cannot move onto water'], 409);
            }
            break;
        }
    }

    // Check for an enemy army AND/OR an enemy building (city) on the destination tile.
    // If both are present, their strengths are combined into a single combat roll —
    // a garrisoned city fights as one force, not two separate battles.
    $combat      = null;
    $defender_id = null;
    foreach ($armies as $a) {
        if ((int)$a['q'] === $q && (int)$a['r'] === $r && $a['user_id'] !== $user_id && $a['user_id'] !== 0) {
            $defender_id = $a['id'];
            break;
        }
    }

    $enemy_building = hexcommand_tile_has_building($post_id, $q, $r);
    if ($enemy_building !== null && $enemy_building['user_id'] === $user_id) {
        $enemy_building = null; // your own building — not combat
    }

    if ($defender_id !== null || $enemy_building !== null) {
        // Resolve combined combat
        $attacker_power = 0;
        $defender_power = 0;
        foreach ($armies as $a) {
            if ($a['id'] === $army_id) { $attacker_power = $a['power']; break; }
        }
        if ($defender_id !== null) {
            foreach ($armies as $a) {
                if ($a['id'] === $defender_id) { $defender_power += $a['power']; break; }
            }
        }
        $city_strength = 0;
        if ($enemy_building !== null) {
            // A farm is far easier to overrun than a fortified city
            $city_strength   = (($enemy_building['building_type'] ?? 'city') === 'farm') ? 100 : 1500;
            $defender_power += $city_strength;
        }

        $attacker_roll  = rand(1, 100);
        $defender_roll  = rand(1, 150);
        $attacker_total = $attacker_power + $attacker_roll;
        $defender_total = $defender_power + $defender_roll;
        $is_city_combat = $enemy_building !== null;

        if ($attacker_total >= $defender_total) {
            // Attacker wins — destroy the defending army (if any) and the city (if any)
            $winner_power = max(1, $attacker_total - $defender_total);
            update_field('power', $winner_power, $army_id);

            if ($defender_id !== null) {
                wp_delete_post($defender_id, true);
                $armies = array_values(array_filter($armies, fn($a) => $a['id'] !== $defender_id));
            }
            if ($enemy_building !== null) {
                wp_delete_post($enemy_building['id'], true);
            }

            hexcommand_set_json_field($army_id, 'position', ['q' => $q, 'r' => $r]);
            foreach ($armies as &$a) {
                if ($a['id'] === $army_id) { $a['q'] = $q; $a['r'] = $r; $a['power'] = $winner_power; break; }
            }
            unset($a);

            $combat = [
                'result'         => 'attacker_wins',
                'city_combat'    => $is_city_combat,
                'attacker_roll'  => $attacker_roll,
                'defender_roll'  => $defender_roll,
                'attacker_total' => $attacker_total,
                'defender_total' => $defender_total,
                'winner_power'   => $winner_power,
            ];
        } else {
            // Defender wins — attacker is destroyed; any surviving defending army
            // keeps the leftover power, the city (if any) is untouched
            wp_delete_post($army_id, true);
            $armies = array_values(array_filter($armies, fn($a) => $a['id'] !== $army_id));

            $winner_power = max(1, $defender_total - $attacker_total);
            if ($defender_id !== null) {
                update_field('power', $winner_power, $defender_id);
                foreach ($armies as &$a) {
                    if ($a['id'] === $defender_id) { $a['power'] = $winner_power; break; }
                }
                unset($a);
            }

            $combat = [
                'result'         => 'defender_wins',
                'city_combat'    => $is_city_combat,
                'attacker_roll'  => $attacker_roll,
                'defender_roll'  => $defender_roll,
                'attacker_total' => $attacker_total,
                'defender_total' => $defender_total,
                'winner_power'   => $winner_power,
            ];
        }
    } else {
        // No combat — move the army normally
        hexcommand_set_json_field($army_id, 'position', ['q' => $q, 'r' => $r]);
        foreach ($armies as &$a) {
            if ($a['id'] === $army_id) { $a['q'] = $q; $a['r'] = $r; break; }
        }
        unset($a);
    }

    // Skip capture if attacker lost the combat (their army was deleted)
    if ($combat !== null && $combat['result'] === 'defender_wins') {
        hexcommand_set_json_field($post_id, 'player_setups', $setups);
        $all_armies = hexcommand_get_armies_for_map($post_id, $user_id);
        return new WP_REST_Response([
            'success'     => true,
            'armies'      => $all_armies,
            'buildings'   => hexcommand_get_buildings_for_map($post_id),
            'owned_tiles' => hexcommand_get_json_field($post_id, 'owned_tiles') ?: [],
            'actions'     => $setups[$setup_idx]['actions'],
            'user_id'     => $user_id,
            'combat'      => $combat,
        ], 200);
    }

    // Capture adjacent tiles that are unowned and have no enemy army present
    // Spies are reconnaissance units only — they never expand faction territory
    $owned_tiles = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];

    if (!in_array($unit_type, ['spy', 'settler', 'farmer'], true)) {
        $owned_map = [];
        foreach ($owned_tiles as $t) {
            $owned_map[$t['q'] . ',' . $t['r']] = (int)($t['user_id'] ?? 0);
        }

        // Build set of tiles protected by enemy armies (their tile + all adjacent tiles)
        $enemy_army_tiles = [];
        foreach ($armies as $a) {
            if ($a['user_id'] !== $user_id) {
                $eq = $a['q']; $er = $a['r'];
                $enemy_army_tiles[$eq . ',' . $er] = true;
                $e_parity  = $eq % 2;
                $e_offsets = $e_parity === 0
                    ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
                    : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];
                foreach ($e_offsets as [$dq, $dr]) {
                    $enemy_army_tiles[($eq + $dq) . ',' . ($er + $dr)] = true;
                }
            }
        }

        $dest_parity     = $q % 2;
        $capture_offsets = $dest_parity === 0
            ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
            : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];

        // Also include the destination tile itself
        $tiles_to_capture = [[$q, $r]];
        foreach ($capture_offsets as [$dq, $dr]) {
            $tiles_to_capture[] = [$q + $dq, $r + $dr];
        }

        foreach ($tiles_to_capture as [$tq, $tr]) {
            $key = $tq . ',' . $tr;
            // Skip if an enemy army is defending this tile
            if (isset($enemy_army_tiles[$key])) continue;
            // Skip if already owned by us
            if (isset($owned_map[$key]) && $owned_map[$key] === $user_id) continue;
            // Skip water
            $terrain = null;
            foreach ($hexmap_data as $hex) {
                if ((int)($hex['q'] ?? -1) === $tq && (int)($hex['r'] ?? -1) === $tr) {
                    $terrain = $hex['terrain'] ?? null; break;
                }
            }
            if ($terrain === 'water' || $terrain === null) continue;
            // Capture: overwrite enemy ownership or claim unowned tile
            if (isset($owned_map[$key])) {
                foreach ($owned_tiles as &$t) {
                    if ($t['q'] === $tq && $t['r'] === $tr) { $t['user_id'] = $user_id; break; }
                }
                unset($t);
            } else {
                $owned_tiles[] = ['q' => $tq, 'r' => $tr, 'user_id' => $user_id];
            }
            $owned_map[$key] = $user_id;
        }
    }

    // Deduct one action
    $setups[$setup_idx]['actions'] = $actions - 1;

    hexcommand_set_json_field($post_id, 'owned_tiles',   $owned_tiles);
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    // Auto-resign any players with no city and no armies
    $game_ended = hexcommand_check_eliminations($post_id, $setups);

    $all_armies = hexcommand_get_armies_for_map($post_id, $user_id);

    return new WP_REST_Response([
        'success'     => true,
        'armies'      => $all_armies,
        'buildings'   => hexcommand_get_buildings_for_map($post_id),
        'owned_tiles' => $owned_tiles,
        'actions'     => $setups[$setup_idx]['actions'],
        'user_id'     => $user_id,
        'combat'      => $combat,
        'mapStatus'   => $game_ended ? 'ended' : hexcommand_get_state($post_id),
    ], 200);
}

// ============================================================
// MERGE ARMY — moving army merges into a friendly army on an
// adjacent tile, combining their power. Costs 1 action, same as
// a normal move. Only 'army' unit types can be merged.
// ============================================================
function hexcommand_merge_army(WP_REST_Request $request): WP_REST_Response {
    $body    = $request->get_json_params();
    $uid     = $request->get_param('uid');
    $user_id = get_current_user_id();

    $army_id = isset($body['army_id']) ? intval($body['army_id']) : null;
    $q       = isset($body['q']) ? intval($body['q']) : null;
    $r       = isset($body['r']) ? intval($body['r']) : null;
    if (!$army_id || $q === null || $r === null) {
        return new WP_REST_Response(['error' => 'Missing parameters'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Verify the moving army's ownership, type and map
    $army_post = get_post($army_id);
    if (!$army_post || $army_post->post_type !== 'army') {
        return new WP_REST_Response(['error' => 'Army not found'], 404);
    }
    if ((int) $army_post->post_author !== $user_id) {
        return new WP_REST_Response(['error' => 'Not your army'], 403);
    }
    if ((int) get_field('hexmap', $army_id) !== $post_id) {
        return new WP_REST_Response(['error' => 'Army does not belong to this map'], 403);
    }
    if ((get_field('unit_type', $army_id) ?: 'army') !== 'army') {
        return new WP_REST_Response(['error' => 'Only armies can be merged'], 409);
    }

    // Find player setup / actions
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);
    $actions = (int)($setups[$setup_idx]['actions'] ?? 0);
    if ($actions <= 0) return new WP_REST_Response(['error' => 'No actions remaining'], 409);

    // Destination must be adjacent to the moving army (offset-grid, even/odd col parity)
    $pos    = hexcommand_get_json_field($army_id, 'position') ?: [];
    $from_q = (int)($pos['q'] ?? 0);
    $from_r = (int)($pos['r'] ?? 0);
    $col_parity = $from_q % 2;
    $offsets = $col_parity === 0
        ? [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]]
        : [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];
    $adjacent = false;
    foreach ($offsets as [$dq, $dr]) {
        if ($from_q + $dq === $q && $from_r + $dr === $r) { $adjacent = true; break; }
    }
    if (!$adjacent) return new WP_REST_Response(['error' => 'Target tile is not adjacent'], 409);

    // Find the friendly army at the destination tile
    $target_posts = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'author'         => $user_id,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    $target_id = null;
    foreach ($target_posts as $tp) {
        if ((int) $tp->ID === $army_id) continue;
        $tpos = hexcommand_get_json_field($tp->ID, 'position') ?: [];
        if ((int)($tpos['q'] ?? -999) === $q && (int)($tpos['r'] ?? -999) === $r) {
            if ((get_field('unit_type', $tp->ID) ?: 'army') === 'army') {
                $target_id = $tp->ID;
            }
            break;
        }
    }
    if ($target_id === null) {
        return new WP_REST_Response(['error' => 'No friendly army to merge with on that tile'], 409);
    }

    // Merge: combine power into the stationary (target) army, remove the moving one
    $source_power = (int) get_field('power', $army_id);
    $target_power = (int) get_field('power', $target_id);
    update_field('power', $source_power + $target_power, $target_id);
    wp_delete_post($army_id, true);

    // Deduct one action
    $setups[$setup_idx]['actions'] = $actions - 1;
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    return new WP_REST_Response([
        'success' => true,
        'armies'  => hexcommand_get_armies_for_map($post_id, $user_id),
        'actions' => $setups[$setup_idx]['actions'],
        'user_id' => $user_id,
    ], 200);
}

// ============================================================
// RENAME ARMY
// ============================================================
function hexcommand_rename_army(WP_REST_Request $request): WP_REST_Response {
    $body    = $request->get_json_params();
    $user_id = get_current_user_id();
    $army_id = isset($body['army_id']) ? intval($body['army_id']) : null;
    $name    = isset($body['name'])    ? sanitize_text_field($body['name']) : null;
    if (!$army_id || !$name || $name === '') {
        return new WP_REST_Response(['error' => 'Missing army_id or name'], 400);
    }
    $army_post = get_post($army_id);
    if (!$army_post || $army_post->post_type !== 'army') {
        return new WP_REST_Response(['error' => 'Army not found'], 404);
    }
    if ((int)$army_post->post_author !== $user_id) {
        return new WP_REST_Response(['error' => 'Not your army'], 403);
    }
    wp_update_post(['ID' => $army_id, 'post_title' => $name]);
    return new WP_REST_Response(['success' => true, 'army_id' => $army_id, 'name' => $name], 200);
}


// ============================================================
// CRON — auto force-turn after 16 hours
// ============================================================
add_action('hexcommand_check_turns', 'hexcommand_cron_check_turns');

function hexcommand_cron_check_turns(): void {
    $maps = get_posts([
        'post_type'      => 'hexmap',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [['key' => 'hexmap_state', 'value' => 'started']],
    ]);

    $threshold = 16 * 3600; // 16 hours in seconds

    foreach ($maps as $map) {
        $started_at = (int) get_field('turn_started_at', $map->ID);
        if ($started_at > 0 && (time() - $started_at) >= $threshold) {
            hexcommand_do_next_turn($map->ID);
        }
    }
}

// ============================================================
// UPGRADE ARMY — spend resources to increase army power
// army must be on the player's city tile; 10 resources = 1 power
// ============================================================
function hexcommand_upgrade_army(WP_REST_Request $request): WP_REST_Response {
    $body      = $request->get_json_params();
    $uid       = $request->get_param('uid');
    $user_id   = get_current_user_id();
    $army_id   = isset($body['army_id'])  ? intval($body['army_id'])  : null;
    $resources = isset($body['resources']) ? intval($body['resources']) : null;

    if (!$army_id || !$resources || $resources < 10 || $resources % 10 !== 0) {
        return new WP_REST_Response(['error' => 'Invalid parameters (resources must be a multiple of 10)'], 400);
    }

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    // Find player setup
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    // Check player has enough resources
    $current_resources = (int)($setups[$setup_idx]['resources'] ?? 0);
    if ($current_resources < $resources) {
        return new WP_REST_Response(['error' => 'Not enough resources'], 409);
    }

    // Verify army ownership
    $army_post = get_post($army_id);
    if (!$army_post || $army_post->post_type !== 'army') {
        return new WP_REST_Response(['error' => 'Army not found'], 404);
    }
    if ((int) $army_post->post_author !== $user_id) {
        return new WP_REST_Response(['error' => 'Not your army'], 403);
    }

    // Verify army is on one of the player's own buildings (city tile)
    $pos = hexcommand_get_json_field($army_id, 'position') ?: [];
    $pq  = (int)($pos['q'] ?? -999);
    $pr  = (int)($pos['r'] ?? -999);
    if (!hexcommand_tile_is_own_city($post_id, $user_id, $pq, $pr)) {
        return new WP_REST_Response(['error' => 'Army must be on one of your cities to upgrade'], 409);
    }

    // Apply upgrade
    $power_gain  = intdiv($resources, 10);
    $new_power   = (int) get_field('power', $army_id) + $power_gain;
    update_field('power', $new_power, $army_id);

    $setups[$setup_idx]['resources'] = $current_resources - $resources;
    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    return new WP_REST_Response([
        'success'   => true,
        'armies'    => hexcommand_get_armies_for_map($post_id, $user_id),
        'resources' => $setups[$setup_idx]['resources'],
        'user_id'   => $user_id,
    ], 200);
}

// ============================================================
// RESIGN — player forfeits their territory; game ends when all resign
// ============================================================
function hexcommand_resign(WP_REST_Request $request): WP_REST_Response {
    $uid     = $request->get_param('uid');
    $user_id = get_current_user_id();

    $post = hexcommand_find_post_by_uid($uid);
    if (!$post) return new WP_REST_Response(['error' => 'Map not found'], 404);
    $post_id = $post->ID;

    $state = hexcommand_get_state($post_id);
    if (!in_array($state, ['awaiting', 'started'], true)) {
        return new WP_REST_Response(['error' => 'Game is not active'], 409);
    }

    // Mark player as resigned in their setup
    $setups    = hexcommand_get_json_field($post_id, 'player_setups') ?: [];
    $setup_idx = null;
    foreach ($setups as $i => $s) {
        if ((int)($s['user_id'] ?? 0) === $user_id) { $setup_idx = $i; break; }
    }
    if ($setup_idx === null) return new WP_REST_Response(['error' => 'No setup found'], 409);

    $setups[$setup_idx]['resigned'] = true;

    // Remove all their owned tiles
    $owned_tiles = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];
    $owned_tiles = array_values(array_filter($owned_tiles, fn($t) => (int)($t['user_id'] ?? 0) !== $user_id));
    hexcommand_set_json_field($post_id, 'owned_tiles', $owned_tiles);

    // Delete all their armies
    $army_posts = get_posts([
        'post_type'      => 'army',
        'posts_per_page' => -1,
        'author'         => $user_id,
        'meta_query'     => [['key' => 'hexmap', 'value' => $post_id]],
    ]);
    foreach ($army_posts as $ap) wp_delete_post($ap->ID, true);

    // Delete all their buildings (cities)
    $building_posts = get_posts([
        'post_type'      => 'building',
        'posts_per_page' => -1,
        'author'         => $user_id,
        'meta_query'     => [['key' => 'map', 'value' => $post_id]],
    ]);
    foreach ($building_posts as $bp) wp_delete_post($bp->ID, true);

    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    // End the game if only one (or zero) active players remain
    $active_players = array_filter($setups, fn($s) => empty($s['resigned']));
    if (count($active_players) <= 1) {
        update_field('hexmap_state', 'ended', $post_id);
        return new WP_REST_Response(['success' => true, 'mapStatus' => 'ended'], 200);
    }

    return new WP_REST_Response(['success' => true, 'mapStatus' => $state], 200);
}
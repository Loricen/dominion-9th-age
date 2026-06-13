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
// ELIMINATION CHECK — auto-resign players with no armies AND no city
// Returns true if the game ended (all eliminated)
// ============================================================
function hexcommand_check_eliminations(int $post_id, array &$setups): bool {
    $changed = false;
    foreach ($setups as &$setup) {
        if (!empty($setup['resigned'])) continue;
        $player_id = (int)($setup['user_id'] ?? 0);
        if (!$player_id) continue;

        // Check if player still has a city
        $has_city = !empty($setup['city_q']) || (isset($setup['city_q']) && $setup['city_q'] !== null);

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
    $army_data = []; // player_id => [ power, ... ]
    foreach ($all_army_posts as $ap) {
        $owner = (int) $ap->post_author;
        $power = (int) get_field('power', $ap->ID);
        if (!isset($army_data[$owner])) $army_data[$owner] = [];
        $army_data[$owner][] = $power;
    }

    // Pre-build adjacency helper: which owned tiles are next to water (for Dread Elves)
    $OFFSETS_EVEN = [[1,0],[-1,0],[0,-1],[0,1],[1,-1],[-1,-1]];
    $OFFSETS_ODD  = [[1,0],[-1,0],[0,-1],[0,1],[1,1],[-1,1]];

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
        $bonus = 0;
        if ($faction === 'equitaine') {
            // +5 per owned forest tile
            foreach ($player_tiles as $t) {
                if (($hex_terrain[$t['q'] . ',' . $t['r']] ?? '') === 'forest') $bonus += 5;
            }
        } elseif ($faction === 'dread') {
            // +5 per owned tile adjacent to water
            foreach ($player_tiles as $t) {
                $offsets = $t['q'] % 2 === 0 ? $OFFSETS_EVEN : $OFFSETS_ODD;
                foreach ($offsets as [$dq, $dr]) {
                    if (($hex_terrain[($t['q']+$dq) . ',' . ($t['r']+$dr)] ?? '') === 'water') {
                        $bonus += 5;
                        break; // count tile once even if multiple water neighbours
                    }
                }
            }
        } elseif ($faction === 'dwarven') {
            // +5 per owned mountain tile
            foreach ($player_tiles as $t) {
                if (($hex_terrain[$t['q'] . ',' . $t['r']] ?? '') === 'mountain') $bonus += 5;
            }
        } elseif ($faction === 'vermin') {
            // +5 per owned swamp tile
            foreach ($player_tiles as $t) {
                if (($hex_terrain[$t['q'] . ',' . $t['r']] ?? '') === 'swamp') $bonus += 5;
            }
        } elseif ($faction === 'daemon') {
            // +5 per owned desert tile
            foreach ($player_tiles as $t) {
                if (($hex_terrain[$t['q'] . ',' . $t['r']] ?? '') === 'desert') $bonus += 5;
            }
        } elseif ($faction === 'sonnstahl') {
            // +5 per owned plains tile
            foreach ($player_tiles as $t) {
                if (($hex_terrain[$t['q'] . ',' . $t['r']] ?? '') === 'plains') $bonus += 5;
            }
        }

        // ── Final income ──────────────────────────────────────────────
        $income = 50 + ($tile_count * 10) - $army_upkeep + $bonus;
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
            'id'      => $a->ID,
            'name'    => $a->post_title,
            'user_id' => $army_user,
            'hexmap'  => $post_id,
            'power'   => (int) get_field('power', $a->ID),
            'stats'   => hexcommand_get_json_field($a->ID, 'stats') ?: [],
            'q'       => $pos['q'] ?? null,
            'r'       => $pos['r'] ?? null,
            'visible' => $army_user === $current_user_id,
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

    // Check the tile is the player's own city
    $city_q = (int)($setups[$setup_idx]['city_q'] ?? -999);
    $city_r = (int)($setups[$setup_idx]['city_r'] ?? -999);
    if ($q !== $city_q || $r !== $city_r) {
        return new WP_REST_Response(['error' => 'Armies can only be built on your city tile'], 409);
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
    $power   = rand(1, 500); // base army strength
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

    // Check for enemy army on destination — if present, resolve combat
    $combat       = null;
    $defender_id  = null;
    foreach ($armies as $a) {
        if ((int)$a['q'] === $q && (int)$a['r'] === $r && $a['user_id'] !== $user_id && $a['user_id'] !== 0) {
            $defender_id = $a['id'];
            break;
        }
    }

    if ($defender_id !== null) {
        // Resolve combat
        $attacker_power = 0;
        $defender_power = 0;
        foreach ($armies as $a) {
            if ($a['id'] === $army_id)    $attacker_power = $a['power'];
            if ($a['id'] === $defender_id) $defender_power = $a['power'];
        }
        $attacker_roll  = rand(1, 100);
        $defender_roll  = rand(1, 150);
        $attacker_total = $attacker_power + $attacker_roll;
        $defender_total = $defender_power + $defender_roll;

        if ($attacker_total >= $defender_total) {
            // Attacker wins — remove defender, attacker survives with leftover power
            wp_delete_post($defender_id, true);
            $winner_power = max(1, $attacker_total - $defender_total);
            update_field('power', $winner_power, $army_id);
            // Move attacker to destination
            hexcommand_set_json_field($army_id, 'position', ['q' => $q, 'r' => $r]);
            // Remove defender from local array, update attacker position
            $armies = array_values(array_filter($armies, fn($a) => $a['id'] !== $defender_id));
            foreach ($armies as &$a) {
                if ($a['id'] === $army_id) { $a['q'] = $q; $a['r'] = $r; $a['power'] = $winner_power; break; }
            }
            unset($a);
            $combat = [
                'result'          => 'attacker_wins',
                'attacker_roll'   => $attacker_roll,
                'defender_roll'   => $defender_roll,
                'attacker_total'  => $attacker_total,
                'defender_total'  => $defender_total,
                'winner_power'    => $winner_power,
            ];
        } else {
            // Defender wins — remove attacker, defender survives with leftover power
            wp_delete_post($army_id, true);
            $winner_power = max(1, $defender_total - $attacker_total);
            update_field('power', $winner_power, $defender_id);
            // Remove attacker from local array, update defender power
            $armies = array_values(array_filter($armies, fn($a) => $a['id'] !== $army_id));
            foreach ($armies as &$a) {
                if ($a['id'] === $defender_id) { $a['power'] = $winner_power; break; }
            }
            unset($a);
            $combat = [
                'result'          => 'defender_wins',
                'attacker_roll'   => $attacker_roll,
                'defender_roll'   => $defender_roll,
                'attacker_total'  => $attacker_total,
                'defender_total'  => $defender_total,
                'winner_power'    => $winner_power,
            ];
        }
    } else {
        // Check if destination is an enemy city
        $city_combat    = null;
        $enemy_city_idx = null;
        foreach ($setups as $i => $s) {
            if ((int)($s['user_id'] ?? 0) !== $user_id &&
                (int)($s['city_q'] ?? -999) === $q &&
                (int)($s['city_r'] ?? -999) === $r) {
                $enemy_city_idx = $i;
                break;
            }
        }

        if ($enemy_city_idx !== null) {
            // City combat — city defends with 1500 base strength
            $attacker_power  = 0;
            foreach ($armies as $a) {
                if ($a['id'] === $army_id) { $attacker_power = $a['power']; break; }
            }
            $city_strength   = 1500;
            $attacker_roll   = rand(1, 100);
            $defender_roll   = rand(1, 150);
            $attacker_total  = $attacker_power + $attacker_roll;
            $defender_total  = $city_strength + $defender_roll;

            if ($attacker_total >= $defender_total) {
                // Attacker wins — destroy the enemy city, move army in
                $winner_power = max(1, $attacker_total - $defender_total);
                update_field('power', $winner_power, $army_id);
                $setups[$enemy_city_idx]['city_q'] = null;
                $setups[$enemy_city_idx]['city_r'] = null;
                hexcommand_set_json_field($army_id, 'position', ['q' => $q, 'r' => $r]);
                foreach ($armies as &$a) {
                    if ($a['id'] === $army_id) { $a['q'] = $q; $a['r'] = $r; $a['power'] = $winner_power; break; }
                }
                unset($a);
                $combat = [
                    'result'         => 'attacker_wins',
                    'city_combat'    => true,
                    'attacker_roll'  => $attacker_roll,
                    'defender_roll'  => $defender_roll,
                    'attacker_total' => $attacker_total,
                    'defender_total' => $defender_total,
                    'winner_power'   => $winner_power,
                ];
            } else {
                // Defender wins — army is destroyed
                wp_delete_post($army_id, true);
                $armies = array_values(array_filter($armies, fn($a) => $a['id'] !== $army_id));
                $combat = [
                    'result'         => 'defender_wins',
                    'city_combat'    => true,
                    'attacker_roll'  => $attacker_roll,
                    'defender_roll'  => $defender_roll,
                    'attacker_total' => $attacker_total,
                    'defender_total' => $defender_total,
                    'winner_power'   => $city_strength + $defender_roll - $attacker_total,
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
    }

    // Skip capture if attacker lost the combat (their army was deleted)
    if ($combat !== null && $combat['result'] === 'defender_wins') {
        hexcommand_set_json_field($post_id, 'player_setups', $setups);
        $all_armies = hexcommand_get_armies_for_map($post_id, $user_id);
        return new WP_REST_Response([
            'success'     => true,
            'armies'      => $all_armies,
            'owned_tiles' => hexcommand_get_json_field($post_id, 'owned_tiles') ?: [],
            'actions'     => $setups[$setup_idx]['actions'],
            'user_id'     => $user_id,
            'combat'      => $combat,
        ], 200);
    }

    // Capture adjacent tiles that are unowned and have no enemy army present
    $owned_tiles   = hexcommand_get_json_field($post_id, 'owned_tiles') ?: [];
    $owned_map     = [];
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
        'owned_tiles' => $owned_tiles,
        'actions'     => $setups[$setup_idx]['actions'],
        'user_id'     => $user_id,
        'combat'      => $combat,
        'mapStatus'   => $game_ended ? 'ended' : hexcommand_get_state($post_id),
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

    // Verify army is on the player's city tile
    $city_q = (int)($setups[$setup_idx]['city_q'] ?? -999);
    $city_r = (int)($setups[$setup_idx]['city_r'] ?? -999);
    $pos    = hexcommand_get_json_field($army_id, 'position') ?: [];
    if ((int)($pos['q'] ?? -1) !== $city_q || (int)($pos['r'] ?? -1) !== $city_r) {
        return new WP_REST_Response(['error' => 'Army must be on your city tile to upgrade'], 409);
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
    if (!in_array($state, ['ongoing', 'started'], true)) {
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

    hexcommand_set_json_field($post_id, 'player_setups', $setups);

    // End the game if only one (or zero) active players remain
    $active_players = array_filter($setups, fn($s) => empty($s['resigned']));
    if (count($active_players) <= 1) {
        update_field('hexmap_state', 'ended', $post_id);
        return new WP_REST_Response(['success' => true, 'mapStatus' => 'ended'], 200);
    }

    return new WP_REST_Response(['success' => true, 'mapStatus' => $state], 200);
}
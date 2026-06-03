<?php
if (!defined('ABSPATH')) exit;

// Maps list shortcode — [hexcommand_mapslist]
add_shortcode('hexcommand_mapslist', function () {
    if (!is_user_logged_in()) {
        return '<p class="hexcommand-login-notice">You must be logged in to view your maps.</p>';
    }

    $user_id = get_current_user_id();

    // Maps owned by user
    $owned = get_posts([
        'post_type'      => 'hexmap',
        'posts_per_page' => -1,
        'author'         => $user_id,
        'post_status'    => 'publish',
    ]);

    // Maps the user is linked to (not owner)
    /*$linked = get_posts([
        'post_type'      => 'hexmap',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_query'     => [[
            'key'     => 'linked_players',
            'value'   => '"' . $user_id . '"',
            'compare' => 'LIKE',
        ]],
        'author__not_in' => [$user_id],
    ]);*/
    $linked = get_posts([
        'post_type'      => 'hexmap',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'author__not_in' => [$user_id],
    ]);

    $all_maps = array_merge(
        array_map(fn($p) => hexcommand_format_map($p, $user_id, 'owner'), $owned),
        array_map(fn($p) => hexcommand_format_map($p, $user_id, 'other'), $linked)
    );
    uasort($all_maps, function ($a, $b) {
        if ($a['role'] == $b['role']) {
            return 0;
        }
        return ($a['role'] < $b['role']) ? 1 : -1;
    });
    if (empty($all_maps)) {
        return '<p class="hexcommand-empty">You have no maps yet.</p>';
    }

    $status_label = [
        'created'  => 'Setup',
        'ongoing'  => 'Ongoing',
        'started'  => 'In Progress',
        'ended'    => 'Ended',
    ];

    ob_start(); ?>
    <div class="hexcommand-mapslist">
        <table class="hexcommand-table">
            <thead>
                <tr>
                    <th>Map</th>
                    <th>Status</th>
                    <th>Role</th>
                    <th>Saved</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_maps as $map): ?>
                <tr>
                    <td><?= esc_html($map['name']) ?></td>
                    <td><span class="hc-status hc-status--<?= esc_attr($map['mapStatus']) ?>"><?= esc_html($status_label[$map['mapStatus']] ?? $map['mapStatus']) ?></span></td>
                    <td><?= $map['role'] ?></td>
                    <td><?= esc_html(date('d/m/Y', strtotime($map['savedAt']))) ?></td>
                    <td><a class="hc-btn" href="<?= esc_url(get_permalink(get_page_by_path('current-games'))) ?>?uid=<?= esc_attr($map['hexmap_uid']) ?>">Open</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <style>
    .hexcommand-mapslist { margin: 20px 0; }
    .hexcommand-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .hexcommand-table th,
    .hexcommand-table td { padding: 10px 14px; border-bottom: 1px solid #2a3550; text-align: left; }
    .hexcommand-table thead th { background: #1a2233; color: #aac4e8; font-weight: 600; }
    .hexcommand-table tbody tr:hover { background: #1a2233; }
    .hc-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
    .hc-status--created  { background: #2a3a1a; color: #7bea27; }
    .hc-status--ongoing  { background: #1a2a3a; color: #4a90d9; }
    .hc-status--started  { background: #3a2a1a; color: #ffba00; }
    .hc-status--ended    { background: #2a1a1a; color: #df4a4a; }
    .hc-btn { display: inline-block; padding: 4px 14px; background: #1a3a5a; color: #88b5f1; border-radius: 6px; text-decoration: none; font-size: 13px; }
    .hc-btn:hover { background: #2a4a7a; color: #fff; }
    </style>
    <?php
    return ob_get_clean();
});

// ============================================================
// HELPER: format map list item
// ============================================================
function hexcommand_format_map(WP_Post $post, int $user_id = 0, $role_type = 'other'): array {
    if ($role_type == 'owner') {
        $role_type = '👑 owner';
    } else {
        $linked_ids = json_decode(get_field('users_linked', $post->ID) ?: '[]', true) ?: [];
        $linked_ids = array_map('intval', $linked_ids);
        $role_type  = in_array($user_id, $linked_ids, true) ? '⚔️ player' : '_ __';
    }
    return [
        'hexmap_uid' => get_field('hexmap_uid', $post->ID),
        'name'       => $post->post_title,
        'size'       => get_post_meta($post->ID, '_hexmap_size', true),
        'savedAt'    => $post->post_date,
        'mapStatus'  => hexcommand_get_state($post->ID),
        'is_owner'   => $role_type === 'owner',
        'role'       => $role_type,
    ];
}

// ============================================================
// LIST MAPS
// advanced_player → their owned maps'_ __'
// player          → maps they are linked to
// ============================================================
function hexcommand_list_maps(): WP_REST_Response {
    $user_id = get_current_user_id();

    // Maps owned by this user
    $owned = get_posts([
        'post_type'      => 'hexmap',
        'author'         => $user_id,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    // Maps the user is linked to (not owner)
    $linked_ids = json_decode(get_user_meta($user_id, 'hex_linked', true), true) ?: [];
    $linked = empty($linked_ids) ? [] : get_posts([
        'post_type'      => 'hexmap',
        'post__in'       => $linked_ids,
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'author__not_in' => [$user_id],
    ]);

    $all = array_merge($owned, $linked);
    return new WP_REST_Response(array_map(fn($p) => hexcommand_format_map($p, $user_id), $all), 200);
}
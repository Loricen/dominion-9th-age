<?php
/**
 * HexCommand Turn Cron
 * Call this file from a real server cron job every hour.
 * Place it in the root of your WordPress installation (next to wp-config.php).
 *
 * Cron entry (OVH or any host):
 *   /usr/bin/php /path/to/wordpress/hexcommand-cron.php
 */

// Boot WordPress without loading the full theme/plugins stack
define('DOING_CRON', true);
define('SHORTINIT', false);

// Adjust this path if the file is not in the WP root
require_once __DIR__ . '/wp-load.php';

// Load the plugin functions we need
require_once __DIR__ . '/wp-content/plugins/hexcommand/includes/game-mechanics.php';
require_once __DIR__ . '/wp-content/plugins/hexcommand/hexcommand-maps.php';

$threshold = 16 * 3600; // 16 hours in seconds

$maps = get_posts([
    'post_type'      => 'hexmap',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'meta_query'     => [['key' => 'hexmap_state', 'value' => 'started']],
]);

$advanced = 0;
foreach ($maps as $map) {
    $started_at = (int) get_field('turn_started_at', $map->ID);
    if ($started_at > 0 && (time() - $started_at) >= $threshold) {
        hexcommand_do_next_turn($map->ID);
        $advanced++;
    }
}
if ($map){
    echo "[" . date('Y-m-d H:i:s') . "] ".$advanced." maps advanced.\n";
}
if ($advanced === 0) {
    echo "[" . date('Y-m-d H:i:s') . "] No turns to advance.\n";
}
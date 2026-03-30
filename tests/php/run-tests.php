<?php
/**
 * NC Logic Test Suite
 * Run: ddev wp eval-file wordpress/wp-content/plugins/notification-centre/tests/php/run-tests.php
 */

$results = [ 'passed' => 0, 'failed' => 0 ];
$created_ids = [];

function nc_assert( &$results, $name, $condition, $debug = '' ) {
    if ( $condition ) {
        echo "\033[32m  ✓ {$name}\033[0m\n";
        $results['passed']++;
    } else {
        echo "\033[31m  ✗ {$name}" . ( $debug ? " [{$debug}]" : '' ) . "\033[0m\n";
        $results['failed']++;
    }
}

function nc_make( &$created_ids, $meta = [] ) {
    $id = wp_insert_post( [
        'post_type'   => 'nc_notification',
        'post_status' => 'publish',
        'post_title'  => 'TEST-NC-' . uniqid(),
    ] );
    $defaults = [
        'nc_audience'          => 'all',
        'nc_active_from'       => '',
        'nc_active_to'         => '',
        'nc_countdown_enabled' => '',
        'nc_show_as_topbar'    => '',
        'nc_topbar_permanent'  => '',
    ];
    foreach ( array_merge( $defaults, $meta ) as $key => $val ) {
        update_post_meta( $id, $key, $val );
    }
    $created_ids[] = $id;
    return $id;
}

function nc_query( $created_ids, $ctx = [] ) {
    // Clear option cache and any api transients
    delete_transient( 'nc_all_options' );
    wp_cache_flush();
    $ctx = array_merge( [ 'url' => home_url('/'), 'post_id' => 0, 'user_id' => 0 ], $ctx );
    return NC_Logic::get_valid_notifications( $ctx );
}

function nc_find( $notifications, $id ) {
    foreach ( $notifications as $n ) {
        if ( (int) $n['id'] === $id ) return $n;
    }
    return null;
}

function nc_cleanup( &$created_ids ) {
    foreach ( $created_ids as $id ) {
        wp_delete_post( $id, true );
    }
    $created_ids = [];
}

// Use WordPress time to match NC_Logic::is_valid() which uses current_time('mysql')
$now_ts   = strtotime( current_time( 'mysql' ) );
$past     = date( 'Y-m-d H:i:s', $now_ts - 3600 );
$future   = date( 'Y-m-d H:i:s', $now_ts + 3600 );
$past2h   = date( 'Y-m-d H:i:s', $now_ts - 7200 );
$past26h  = date( 'Y-m-d H:i:s', $now_ts - 93600 );


echo "\n\033[1m── NC Logic Test Suite ──────────────────────\033[0m\n\n";


// ============================================================
echo "\033[1m1. Time Restrictions\033[0m\n";

$id = nc_make( $created_ids );
nc_assert( $results, 'no date range → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_active_from' => $past ] );
nc_assert( $results, 'active_from in past → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_active_from' => $future ] );
nc_assert( $results, 'active_from in future → hidden', nc_find( nc_query( $created_ids ), $id ) === null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_active_to' => $future ] );
nc_assert( $results, 'active_to in future → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_active_to' => $past ] );
nc_assert( $results, 'active_to in past → hidden', nc_find( nc_query( $created_ids ), $id ) === null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_active_from' => $past, 'nc_active_to' => $future ] );
nc_assert( $results, 'within active range → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_active_from' => $past26h, 'nc_active_to' => $past2h ] );
nc_assert( $results, 'expired date range → hidden', nc_find( nc_query( $created_ids ), $id ) === null );
nc_cleanup( $created_ids );


// ============================================================
echo "\n\033[1m2. Day Exclusions\033[0m\n";

$current_day = (string) date( 'N', $now_ts );
$other_day   = (string) ( ( (int) $current_day % 7 ) + 1 );

$id = nc_make( $created_ids, [ 'nc_excluded_days' => [ $current_day ] ] );
nc_assert( $results, 'current day excluded → hidden', nc_find( nc_query( $created_ids ), $id ) === null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_excluded_days' => [ $other_day ] ] );
nc_assert( $results, 'different day excluded → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_excluded_days' => [] ] );
nc_assert( $results, 'empty exclusion list → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );


// ============================================================
echo "\n\033[1m3. Audience\033[0m\n";

$id = nc_make( $created_ids, [ 'nc_audience' => 'all' ] );
nc_assert( $results, 'all → guest sees it',      nc_find( nc_query( $created_ids, [ 'user_id' => 0 ] ), $id ) !== null );
nc_assert( $results, 'all → logged-in sees it',  nc_find( nc_query( $created_ids, [ 'user_id' => 1 ] ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_audience' => 'guests' ] );
nc_assert( $results, 'guests → guest sees it',         nc_find( nc_query( $created_ids, [ 'user_id' => 0 ] ), $id ) !== null );
nc_assert( $results, 'guests → logged-in hidden',      nc_find( nc_query( $created_ids, [ 'user_id' => 1 ] ), $id ) === null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_audience' => 'logged_in' ] );
nc_assert( $results, 'logged_in → guest hidden',        nc_find( nc_query( $created_ids, [ 'user_id' => 0 ] ), $id ) === null );
nc_assert( $results, 'logged_in → logged-in sees it',   nc_find( nc_query( $created_ids, [ 'user_id' => 1 ] ), $id ) !== null );
nc_cleanup( $created_ids );


// ============================================================
echo "\n\033[1m4. Page Rules\033[0m\n";

$id = nc_make( $created_ids, [ 'nc_rules_data' => [ [ 'mode' => 'show', 'type' => 'url', 'value' => '/sklep/' ] ] ] );
nc_assert( $results, 'show URL match → visible',    nc_find( nc_query( $created_ids, [ 'url' => home_url('/sklep/') ] ), $id ) !== null );
nc_assert( $results, 'show URL no match → hidden',  nc_find( nc_query( $created_ids, [ 'url' => home_url('/blog/') ] ), $id ) === null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_rules_data' => [ [ 'mode' => 'hide', 'type' => 'url', 'value' => '/koszyk/' ] ] ] );
nc_assert( $results, 'hide URL match → hidden',     nc_find( nc_query( $created_ids, [ 'url' => home_url('/koszyk/') ] ), $id ) === null );
nc_assert( $results, 'hide URL no match → visible', nc_find( nc_query( $created_ids, [ 'url' => home_url('/') ] ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_rules_data' => [ [ 'mode' => 'show', 'type' => 'id', 'value' => '99' ] ] ] );
nc_assert( $results, 'show post_id match → visible',    nc_find( nc_query( $created_ids, [ 'post_id' => 99 ] ), $id ) !== null );
nc_assert( $results, 'show post_id no match → hidden',  nc_find( nc_query( $created_ids, [ 'post_id' => 42 ] ), $id ) === null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_rules_data' => [
    [ 'mode' => 'show', 'type' => 'url', 'value' => '/oferta/' ],
    [ 'mode' => 'hide', 'type' => 'url', 'value' => '/oferta/' ],
] ] );
nc_assert( $results, 'hide beats show on same URL', nc_find( nc_query( $created_ids, [ 'url' => home_url('/oferta/') ] ), $id ) === null );
nc_cleanup( $created_ids );


// ============================================================
echo "\n\033[1m5. Countdown Visibility\033[0m\n";

$id = nc_make( $created_ids, [ 'nc_countdown_enabled' => '1', 'nc_countdown_type' => 'date', 'nc_countdown_date' => $past, 'nc_countdown_autohide' => '1' ] );
nc_assert( $results, 'date countdown expired + autohide → hidden', nc_find( nc_query( $created_ids ), $id ) === null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_countdown_enabled' => '1', 'nc_countdown_type' => 'date', 'nc_countdown_date' => $future, 'nc_countdown_autohide' => '1' ] );
nc_assert( $results, 'date countdown future + autohide → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_countdown_enabled' => '1', 'nc_countdown_type' => 'date', 'nc_countdown_date' => $past, 'nc_countdown_autohide' => '' ] );
nc_assert( $results, 'date countdown expired + no autohide → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );

$start_past   = date( 'H:i', $now_ts - 7200 );
$start_future = date( 'H:i', $now_ts + 7200 );

$id = nc_make( $created_ids, [ 'nc_countdown_enabled' => '1', 'nc_countdown_type' => 'daily', 'nc_countdown_start_time' => $start_past ] );
nc_assert( $results, 'daily: start_time in past → visible', nc_find( nc_query( $created_ids ), $id ) !== null );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_countdown_enabled' => '1', 'nc_countdown_type' => 'daily', 'nc_countdown_start_time' => $start_future ] );
nc_assert( $results, 'daily: start_time in future → hidden', nc_find( nc_query( $created_ids ), $id ) === null );
nc_cleanup( $created_ids );


// ============================================================
echo "\n\033[1m6. API Response Types\033[0m\n";

$id = nc_make( $created_ids, [ 'nc_show_as_topbar' => '1', 'nc_topbar_permanent' => '1', 'nc_topbar_position' => 'above', 'nc_topbar_style' => 'compact' ] );
$n  = nc_find( nc_query( $created_ids ), $id );
nc_assert( $results, 'topbar is bool true',           $n && $n['settings']['topbar'] === true );
nc_assert( $results, 'topbar_permanent is bool true', $n && $n['settings']['topbar_permanent'] === true );
nc_assert( $results, 'topbar_position correct',       $n && $n['settings']['topbar_position'] === 'above' );
nc_assert( $results, 'topbar_style correct',          $n && $n['settings']['topbar_style'] === 'compact' );
nc_cleanup( $created_ids );

$id = nc_make( $created_ids, [ 'nc_show_as_topbar' => '', 'nc_topbar_permanent' => '' ] );
$n  = nc_find( nc_query( $created_ids ), $id );
nc_assert( $results, 'topbar is bool false when off',           $n && $n['settings']['topbar'] === false );
nc_assert( $results, 'topbar_permanent is bool false when off', $n && $n['settings']['topbar_permanent'] === false );
nc_assert( $results, 'dismissible is boolean',    $n && is_bool( $n['settings']['dismissible'] ) );
nc_assert( $results, 'pinned is boolean',         $n && is_bool( $n['settings']['pinned'] ) );
nc_assert( $results, 'id is integer',             $n && is_int( $n['id'] ) );
nc_assert( $results, 'date field present',        $n && ! empty( $n['date'] ) );
nc_assert( $results, 'colors array present',      $n && is_array( $n['settings']['colors'] ) );
nc_assert( $results, 'countdown array present',   $n && is_array( $n['settings']['countdown'] ) );
nc_assert( $results, 'triggers array present',    $n && is_array( $n['settings']['triggers'] ) );
nc_cleanup( $created_ids );


// ============================================================
echo "\n\033[1m7. Pinned Sort Order\033[0m\n";

$id_normal = nc_make( $created_ids, [ 'nc_pinned' => '' ] );
$id_pinned = nc_make( $created_ids, [ 'nc_pinned' => '1' ] );
$r = nc_query( $created_ids );
$positions = array_map( fn($n) => $n['id'], $r );
$pos_p = array_search( $id_pinned, $positions );
$pos_n = array_search( $id_normal, $positions );
nc_assert( $results, 'pinned sorts before non-pinned',
    $pos_p !== false && $pos_n !== false && $pos_p < $pos_n,
    "pinned@{$pos_p} normal@{$pos_n}"
);
nc_cleanup( $created_ids );


// ============================================================
$total = $results['passed'] + $results['failed'];
echo "\n\033[1m────────────────────────────────────────────\033[0m\n";
printf( "\033[1mResults: %d/%d passed\033[0m", $results['passed'], $total );
if ( $results['failed'] > 0 ) {
    printf( "  \033[31m(%d failed)\033[0m", $results['failed'] );
} else {
    echo "  \033[32m(all passed!)\033[0m";
}
echo "\n\n";

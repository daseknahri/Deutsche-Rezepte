<?php
/**
 * Email capture (Wave 0 pilot) — [wpap_signup] + wpap_subscribers
 *
 * WP-native list, no third party, no sending (Phase 1): a visitor enters an
 * email + ticks the consent checkbox, the row is stored in the site's own
 * database, and the ebook download appears on screen immediately. Phase 2
 * (Brevo) adds double opt-in + welcome mail + broadcasts on top of this
 * same table; nothing built here is thrown away.
 *
 * OFF by default (new features ship disabled): the shortcode, the REST
 * endpoint and the theme placements all render / accept nothing until the
 * `wpap_signup_enabled` option is set to '1' (wp-cli: `wp option update
 * wpap_signup_enabled 1`). The ebook file URL lives in the
 * `wpap_signup_ebook_url` option (set when the ebook is uploaded).
 *
 * GDPR: consent checkbox unchecked by default and required; no IP address is
 * stored anywhere; no tracking on the form; no data leaves the site in
 * Phase 1. Unsubscribe is a per-row random token link (no login needed).
 *
 * Load order is fixed by the main file; every hook self-registers here.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WPAP_SUBSCRIBERS_TABLE', 'wpap_subscribers' );

/* ── Feature flag + ebook URL ─────────────────────────────── */
function wpap_signup_enabled(): bool {
    return '1' === (string) get_option( 'wpap_signup_enabled', '0' );
}
function wpap_signup_ebook_url(): string {
    return esc_url_raw( (string) get_option( 'wpap_signup_ebook_url', '' ) );
}

/* ── Core store: one row per email (UNIQUE email). Shared by the REST
   endpoint and the no-JS admin-post fallback so both run the same contract. ── */
function wpap_signup_store( string $email, string $source ) {
    global $wpdb;
    $email = sanitize_email( $email );
    if ( '' === $email || ! is_email( $email ) ) {
        return new WP_Error( 'invalid_email', 'Bitte gib eine gültige E-Mail-Adresse ein.' );
    }
    $source = sanitize_key( $source );
    if ( ! in_array( $source, array( 'ebook', 'post', 'footer' ), true ) ) { $source = 'ebook'; }
    $table = $wpdb->prefix . WPAP_SUBSCRIBERS_TABLE;

    /* Already on the list: idempotent — no duplicate row, same download. */
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- single-row lookup by unique key, no user table involved
    $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE email = %s", $email ) );
    if ( $existing ) {
        if ( 'subscribed' === $existing->status ) {
            return array( 'already' => true, 'download_url' => wpap_signup_ebook_url() );
        }
        /* Was unsubscribed: re-subscribing is a fresh opt-in (new token, new timestamp). */
        $token = bin2hex( random_bytes( 16 ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- status flip on the unique email row
        $wpdb->update( $table,
            array( 'status' => 'subscribed', 'consent' => 1, 'source' => $source, 'unsub_token' => $token, 'created_at' => current_time( 'mysql' ) ),
            array( 'email' => $email ), array( '%s', '%d', '%s', '%s', '%s' ), array( '%s' ) );
        return array( 'already' => false, 'download_url' => wpap_signup_ebook_url() );
    }

    $token = bin2hex( random_bytes( 16 ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- insert into the plugin's own table
    $ok = $wpdb->insert( $table, array(
        'email'       => $email,
        'list'        => 'deutsche-rezepte',
        'consent'     => 1,
        'status'      => 'subscribed',
        'source'      => $source,
        'unsub_token' => $token,
        'created_at'  => current_time( 'mysql' ),
    ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
    if ( false === $ok ) {
        return new WP_Error( 'store_failed', 'Die Anmeldung konnte nicht gespeichert werden. Bitte versuche es später erneut.' );
    }
    return array( 'already' => false, 'download_url' => wpap_signup_ebook_url() );
}

/* ── Form markup. The checkbox is UNCHECKED by default and required; the
   privacy link sits next to it. One inline <style> per page (static flag),
   same pattern as the theme's share-JS block. ── */
function wpap_signup_form_html( string $context ): string {
    static $styled = false;
    $style = '';
    if ( ! $styled ) {
        $styled = true;
        $style = '<style>'
            . '.wpap-signup{max-width:640px;margin:1.5rem auto;padding:1.25rem 1.25rem 1rem;border:1.5px solid #dfe8dd;border-radius:14px;background:#f7faf5;text-align:left}'
            . '.wpap-signup h3{margin:.1rem 0 .4rem;font-size:1.25rem;line-height:1.3}'
            . '.wpap-signup p{margin:.35rem 0 .7rem}'
            . '.wpap-signup__row{display:flex;gap:.5rem;flex-wrap:wrap}'
            . '.wpap-signup input[type=email]{flex:1 1 220px;min-width:0;padding:.7rem .9rem;border:1.5px solid #b9c9b4;border-radius:999px;font-size:1rem;background:#fff}'
            . '.wpap-signup__consent{display:flex;gap:.5rem;align-items:flex-start;margin:.6rem 0;font-size:.9rem;line-height:1.45}'
            . '.wpap-signup__consent input{margin-top:.2rem;flex:none;width:1.05rem;height:1.05rem}'
            . '.wpap-signup__msg{margin:.5rem 0 0;font-weight:600}'
            . '.wpap-signup__msg--err{color:#b32d2e}'
            . '.wpap-signup__thanks{text-align:center}'
            . '.wpap-signup__hp{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}'
            . '</style>';
    }
    $privacy = get_privacy_policy_url();
    $privacy_link = $privacy ? '<a href="' . esc_url( $privacy ) . '">Datenschutzerklärung</a>' : 'Datenschutzerklärung';
    $nonce = wp_create_nonce( 'wpap_signup' );
    $rest  = esc_url_raw( rest_url( 'wpap/v1/signup' ) );
    $out  = $style;
    $out .= '<div class="wpap-signup" data-wpap-signup>';
    $out .= '<h3>365 Tage Deutsche Rezepte — kostenloses E-Book</h3>';
    $out .= '<p>Ein Rezept für jeden Tag des Jahres: 365 deutsche Rezepte mit Bild, Zutaten und Zubereitung — als PDF zum Herunterladen.</p>';
    $out .= '<form class="wpap-signup__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
    $out .= '<input type="hidden" name="action" value="wpap_signup_submit">';
    $out .= '<input type="hidden" name="wpap_signup_nonce" value="' . esc_attr( $nonce ) . '">';
    $out .= '<input type="hidden" name="source" value="' . esc_attr( $context ) . '">';
    $out .= '<span class="wpap-signup__hp" aria-hidden="true"><label>Bitte leer lassen<input type="text" name="wpap_hp" value="" tabindex="-1" autocomplete="off"></label></span>';
    $out .= '<div class="wpap-signup__row"><input type="email" name="email" required placeholder="Deine E-Mail-Adresse" autocomplete="email" aria-label="E-Mail-Adresse">';
    $out .= '<button type="submit" class="dr-btn dr-btn--more">E-Book erhalten</button></div>';
    $out .= '<label class="wpap-signup__consent"><input type="checkbox" name="consent" value="1" required> <span>Ja, ich möchte das kostenlose E-Book „365 Tage Deutsche Rezepte“ erhalten und gelegentlich Neuigkeiten von Deutsche Rezepte per E-Mail bekommen. Ich habe die ' . $privacy_link . ' gelesen.</span></label>';
    $out .= '<p class="wpap-signup__msg" hidden></p>';
    $out .= '</form>';
    $out .= '<script>(function(){var box=document.currentScript&&document.currentScript.closest?document.currentScript.closest("[data-wpap-signup]"):null;if(!box)return;var form=box.querySelector(".wpap-signup__form");var msg=box.querySelector(".wpap-signup__msg");if(!form)return;form.addEventListener("submit",function(e){e.preventDefault();var email=form.querySelector("input[type=email]");var consent=form.querySelector("input[name=consent]");var hp=form.querySelector("input[name=wpap_hp]");if(!email||!email.value||!consent||!consent.checked){if(msg){msg.hidden=false;msg.textContent="Bitte gib deine E-Mail-Adresse ein und setze das Häkchen.";msg.classList.add("wpap-signup__msg--err");}return;}var btn=form.querySelector("button[type=submit]");if(btn)btn.disabled=true;fetch(' . wp_json_encode( $rest ) . ',{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({email:email.value,consent:"1",source:form.querySelector("input[name=source]").value,nonce:form.querySelector("input[name=wpap_signup_nonce]").value,hp:hp?hp.value:""})}).then(function(r){return r.json().then(function(j){return {status:r.status,body:j};});}).then(function(res){var j=res.body||{};if(res.status===200&&j.ok){var url=j.download_url||"";box.innerHTML=\'<div class="wpap-signup__thanks"><h3>Danke! Dein E-Book ist bereit.</h3>\'+(url?\'<p><a class="dr-btn dr-btn--more" href="\'+url+\'">E-Book herunterladen (PDF)</a></p>\':\'<p>Der Download-Link wird in Kürze freigeschaltet.</p>\')+\'<p style="font-size:.9rem">Schau auch in deinem Postfach nach — die Bestätigung kommt, sobald der Versand startet.</p></div>\';}else{if(msg){msg.hidden=false;msg.textContent=(j&&j.error)?j.error:"Die Anmeldung hat nicht geklappt. Bitte versuche es später erneut.";msg.classList.add("wpap-signup__msg--err");}if(btn)btn.disabled=false;}}).catch(function(){if(msg){msg.hidden=false;msg.textContent="Die Anmeldung hat nicht geklappt. Bitte versuche es später erneut.";msg.classList.add("wpap-signup__msg--err");}if(btn)btn.disabled=false;});});})();</script>';
    $out .= '</div>';
    return $out;
}

function wpap_signup_thanks_html(): string {
    $url = wpap_signup_ebook_url();
    $out  = '<div class="wpap-signup" data-wpap-signup><div class="wpap-signup__thanks"><h3>Danke! Dein E-Book ist bereit.</h3>';
    $out .= $url ? '<p><a class="dr-btn dr-btn--more" href="' . esc_url( $url ) . '">E-Book herunterladen (PDF)</a></p>' : '<p>Der Download-Link wird in Kürze freigeschaltet.</p>';
    $out .= '</div></div>';
    return $out;
}

/* Shortcode: [wpap_signup context="ebook|post|footer"]. Returns '' while the
   feature flag is off (placements ship disabled until go-live). */
add_shortcode( 'wpap_signup', 'wpap_signup_shortcode' );
function wpap_signup_shortcode( $atts ) {
    if ( ! wpap_signup_enabled() ) { return ''; }
    $atts = shortcode_atts( array( 'context' => 'ebook' ), $atts, 'wpap_signup' );
    $context = sanitize_key( $atts['context'] );
    if ( ! in_array( $context, array( 'ebook', 'post', 'footer' ), true ) ) { $context = 'ebook'; }
    /* No-JS fallback lands back here with ?wpap_signup=done. */
    if ( isset( $_GET['wpap_signup'] ) && 'done' === sanitize_key( wp_unslash( $_GET['wpap_signup'] ) ) ) {
        return wpap_signup_thanks_html();
    }
    return wpap_signup_form_html( $context );
}

/* ── REST: POST /wp-json/wpap/v1/signup (public; nonce + honeypot + throttle) ── */
add_action( 'rest_api_init', 'wpap_signup_rest_routes' );
function wpap_signup_rest_routes() {
    register_rest_route( 'wpap/v1', '/signup', array(
        'methods'             => 'POST',
        'callback'            => 'wpap_rest_signup',
        'permission_callback' => '__return_true',
    ) );
    register_rest_route( 'wpap/v1', '/unsubscribe', array(
        'methods'             => 'GET',
        'callback'            => 'wpap_rest_unsubscribe',
        'permission_callback' => '__return_true',
    ) );
}
function wpap_rest_signup( WP_REST_Request $request ) {
    if ( ! wpap_signup_enabled() ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Die Anmeldung ist zurzeit nicht freigeschaltet.' ), 403 );
    }
    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) { $params = $request->get_params(); }
    $nonce = isset( $params['nonce'] ) ? sanitize_text_field( (string) $params['nonce'] ) : '';
    if ( ! wp_verify_nonce( $nonce, 'wpap_signup' ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Sicherheitsprüfung fehlgeschlagen. Bitte lade die Seite neu.' ), 403 );
    }
    /* Honeypot: bots fill it, humans never see it. */
    if ( '' !== trim( (string) ( $params['hp'] ?? '' ) ) ) {
        return new WP_REST_Response( array( 'ok' => true, 'already' => false, 'download_url' => wpap_signup_ebook_url() ), 200 );
    }
    $email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
    if ( '' === $email || ! is_email( $email ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Bitte gib eine gültige E-Mail-Adresse ein.' ), 400 );
    }
    if ( '1' !== (string) ( $params['consent'] ?? '' ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Bitte setze das Häkchen zur Einwilligung.' ), 400 );
    }
    /* Throttle: max 3 attempts per email per hour (transient keyed by a hash of
       the address — no IP is stored anywhere). */
    $tkey = 'wpap_signup_throttle_' . md5( strtolower( $email ) );
    $tries = (int) get_transient( $tkey );
    if ( $tries >= 3 ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Zu viele Versuche für diese Adresse. Bitte versuche es in einer Stunde erneut.' ), 429 );
    }
    set_transient( $tkey, $tries + 1, HOUR_IN_SECONDS );
    $r = wpap_signup_store( $email, isset( $params['source'] ) ? (string) $params['source'] : 'ebook' );
    if ( is_wp_error( $r ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => $r->get_error_message() ), 400 );
    }
    return new WP_REST_Response( array( 'ok' => true, 'already' => (bool) $r['already'], 'download_url' => (string) $r['download_url'] ), 200 );
}
function wpap_rest_unsubscribe( WP_REST_Request $request ) {
    global $wpdb;
    $email = sanitize_email( (string) $request->get_param( 'email' ) );
    $token = sanitize_text_field( (string) $request->get_param( 'token' ) );
    if ( '' === $email || '' === $token || ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Ungültiger Abmeldelink.' ), 400 );
    }
    $table = $wpdb->prefix . WPAP_SUBSCRIBERS_TABLE;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- status flip on the unique email+token row
    $updated = $wpdb->update( $table, array( 'status' => 'unsubscribed' ), array( 'email' => $email, 'unsub_token' => $token ), array( '%s' ), array( '%s', '%s' ) );
    if ( false === $updated || 0 === (int) $updated ) {
        return new WP_REST_Response( array( 'ok' => false, 'error' => 'Dieser Abmeldelink ist unbekannt oder bereits verwendet.' ), 404 );
    }
    return new WP_REST_Response( array( 'ok' => true, 'message' => 'Du wurdest abgemeldet. Deine E-Mail-Adresse erhält keine weiteren Nachrichten von uns.' ), 200 );
}

/* ── No-JS fallback: plain POST to admin-post.php, redirect back with ?wpap_signup=done ── */
add_action( 'admin_post_nopriv_wpap_signup_submit', 'wpap_signup_submit_fallback' );
add_action( 'admin_post_wpap_signup_submit', 'wpap_signup_submit_fallback' );
function wpap_signup_submit_fallback() {
    $redirect = wp_get_referer() ? wp_get_referer() : home_url( '/ebook/' );
    if ( ! wpap_signup_enabled() ) {
        wp_safe_redirect( remove_query_arg( 'wpap_signup', $redirect ) );
        exit;
    }
    $nonce = isset( $_POST['wpap_signup_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpap_signup_nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'wpap_signup' ) ) {
        wp_safe_redirect( remove_query_arg( 'wpap_signup', $redirect ) );
        exit;
    }
    if ( '' !== trim( (string) ( isset( $_POST['wpap_hp'] ) ? wp_unslash( $_POST['wpap_hp'] ) : '' ) ) ) {
        wp_safe_redirect( add_query_arg( 'wpap_signup', 'done', $redirect ) );
        exit;
    }
    $email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $consent = isset( $_POST['consent'] ) ? sanitize_text_field( wp_unslash( $_POST['consent'] ) ) : '';
    $source  = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : 'ebook';
    if ( '' === $email || ! is_email( $email ) || '1' !== $consent ) {
        wp_safe_redirect( remove_query_arg( 'wpap_signup', $redirect ) );
        exit;
    }
    $r = wpap_signup_store( $email, $source );
    if ( is_wp_error( $r ) ) {
        wp_safe_redirect( remove_query_arg( 'wpap_signup', $redirect ) );
        exit;
    }
    wp_safe_redirect( add_query_arg( 'wpap_signup', 'done', $redirect ) );
    exit;
}

/* ── Admin: "Abonnenten" list + CSV export + delete.
   The submenu itself is registered in wpap_admin_menu() (main file, next to
   the other submenu pages); only the render + handlers live here. ── */
function wpap_render_subscribers() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.' ) );
    }
    global $wpdb;
    $table = $wpdb->prefix . WPAP_SUBSCRIBERS_TABLE;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin counts on the plugin's own table
    $total_sub = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'subscribed'" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin counts on the plugin's own table
    $total_unsub = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'unsubscribed'" );
    $per_page = 50;
    $paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
    $offset   = ( $paged - 1 ) * $per_page;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin list on the plugin's own table, ints only
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
    $pages = max( 1, (int) ceil( ( $total_sub + $total_unsub ) / $per_page ) );
    echo '<div class="wrap"><h1>Abonnenten — E-Book-Anmeldungen</h1>';
    echo '<p>Formular: <strong>' . ( wpap_signup_enabled() ? 'aktiviert' : 'deaktiviert' ) . '</strong> &middot; '
        . 'Abonniert: <strong>' . number_format_i18n( $total_sub ) . '</strong> &middot; '
        . 'Abgemeldet: <strong>' . number_format_i18n( $total_unsub ) . '</strong> &middot; '
        . 'E-Book-URL: ' . ( wpap_signup_ebook_url() ? '<code>' . esc_html( wpap_signup_ebook_url() ) . '</code>' : '<strong style="color:#b32d2e">nicht gesetzt</strong>' ) . '</p>';
    echo '<p><a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wpap_export_subscribers' ), 'wpap_export_subscribers' ) ) . '">Als CSV exportieren</a></p>';
    if ( isset( $_GET['wpap_deleted'] ) ) { echo '<div class="notice notice-success"><p>Eintrag gelöscht.</p></div>'; }
    echo '<table class="widefat striped"><thead><tr><th>E-Mail</th><th>Status</th><th>Quelle</th><th>Einwilligung</th><th>Angemeldet am</th><th></th></tr></thead><tbody>';
    if ( empty( $rows ) ) {
        echo '<tr><td colspan="6">Noch keine Anmeldungen.</td></tr>';
    } else {
        foreach ( $rows as $row ) {
            $del = wp_nonce_url( admin_url( 'admin-post.php?action=wpap_delete_subscriber&id=' . (int) $row->id ), 'wpap_delete_subscriber_' . (int) $row->id );
            echo '<tr><td>' . esc_html( $row->email ) . '</td>'
                . '<td>' . esc_html( $row->status ) . '</td>'
                . '<td>' . esc_html( $row->source ) . '</td>'
                . '<td>' . ( $row->consent ? 'ja' : 'nein' ) . '</td>'
                . '<td>' . esc_html( $row->created_at ) . '</td>'
                . '<td><a href="' . esc_url( $del ) . '" onclick="return confirm(\'Eintrag wirklich löschen?\')">Löschen</a></td></tr>';
        }
    }
    echo '</tbody></table>';
    if ( $pages > 1 ) {
        echo '<p>';
        for ( $i = 1; $i <= $pages; $i++ ) {
            echo ( $i === $paged )
                ? '<strong>' . $i . '</strong> '
                : '<a href="' . esc_url( add_query_arg( 'paged', $i ) ) . '">' . $i . '</a> ';
        }
        echo '</p>';
    }
    echo '<p class="description">Liste Phase 1: E-Mail-Adressen werden nur in dieser Datenbank gespeichert, ohne IP-Adresse, ohne Weitergabe an Dritte. Abmeldung über den Token-Link oder per E-Mail an den Betreiber.</p></div>';
}
add_action( 'admin_post_wpap_export_subscribers', 'wpap_handle_export_subscribers' );
function wpap_handle_export_subscribers() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'wpap_export_subscribers' );
    global $wpdb;
    $table = $wpdb->prefix . WPAP_SUBSCRIBERS_TABLE;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin export of the plugin's own table
    $rows = $wpdb->get_results( "SELECT email, list, consent, status, source, created_at FROM {$table} ORDER BY id ASC", ARRAY_A );
    $out = fopen( 'php://temp', 'r+' );
    fputcsv( $out, array( 'email', 'list', 'consent', 'status', 'source', 'created_at' ), ';' );
    foreach ( (array) $rows as $row ) { fputcsv( $out, $row, ';' ); }
    rewind( $out );
    $csv = stream_get_contents( $out );
    fclose( $out );
    $fname = 'deutsche-rezepte-abonnenten-' . gmdate( 'Ymd-His' ) . '.csv';
    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
    header( 'Content-Length: ' . strlen( (string) $csv ) );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'X-Robots-Tag: noindex, nofollow' );
    echo $csv;   // CSV download, not rendered as HTML
    exit;
}
add_action( 'admin_post_wpap_delete_subscriber', 'wpap_handle_delete_subscriber' );
function wpap_handle_delete_subscriber() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Unauthorized', '', array( 'response' => 403 ) ); }
    $id = (int) ( $_GET['id'] ?? 0 );
    check_admin_referer( 'wpap_delete_subscriber_' . $id );
    global $wpdb;
    $table = $wpdb->prefix . WPAP_SUBSCRIBERS_TABLE;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin delete on the plugin's own table
    $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
    wp_safe_redirect( add_query_arg( 'wpap_deleted', '1', admin_url( 'admin.php?page=wpap-subscribers' ) ) );
    exit;
}

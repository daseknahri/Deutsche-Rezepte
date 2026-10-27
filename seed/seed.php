<?php
/**
 * Deutsche Rezepte site seed — run by docker/site-init.sh via `wp eval-file` on every start.
 *
 * Idempotent: the brand/site setup runs once per DR_SEED_VERSION, and each recipe bundle is imported once
 * (tracked in the dr_imported_bundles option), so a restart mid-import simply resumes.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

const DR_SEED_VERSION = 1;   /* 1 = Deutsche Rezepte launch (brand, German recipe categories, pages, author, ads) */
const DR_SEED_FB      = 'https://www.facebook.com/profile.php?id=61595540835887';
const DR_AUTHOR_BIO   = 'Die Redaktion von Deutsche Rezepte kocht, backt und sammelt die besten deutschen Rezepte – von Schnitzel und Käsespätzle bis zur Schwarzwälder Kirschtorte, mit klaren Schritt-für-Schritt-Anleitungen zum Nachkochen.';
const DR_DATA         = '/opt/site/data';
const DR_BRAND_DIR    = WP_CONTENT_DIR . '/themes/deutsche-rezepte/assets/brand';

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

function dr_log( $msg ) { WP_CLI::log( '[seed] ' . $msg ); }

function dr_admin_id() {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	return $admins ? (int) $admins[0] : 1;
}

/* Copy a bundled brand file into the media library once (keyed by filename). */
function dr_brand_media( $file, $title ) {
	$found = get_posts( array( 'post_type' => 'attachment', 'meta_key' => '_dr_brand', 'meta_value' => $file, 'fields' => 'ids', 'numberposts' => 1 ) );
	if ( $found ) { return (int) $found[0]; }
	$tmp = wp_tempnam( $file );
	copy( DR_BRAND_DIR . '/' . $file, $tmp );
	$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) { dr_log( 'media failed: ' . $file . ' ' . $id->get_error_message() ); return 0; }
	update_post_meta( $id, '_dr_brand', $file );
	return (int) $id;
}

function dr_page( $slug, $title, $html, $status = 'publish' ) {
	$p = get_page_by_path( $slug );
	$data = array( 'post_type' => 'page', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $html, 'post_status' => $status, 'post_author' => dr_admin_id() );
	if ( $p ) { $data['ID'] = $p->ID; return (int) wp_update_post( wp_slash( $data ) ); }
	return (int) wp_insert_post( wp_slash( $data ) );
}

/* The editorial "Redaktion" is the site's author: display name, bio, brand portrait and the Facebook page
   (feeds byline, author box and the Person schema). No invented persona — the brand mark is the avatar. */
function dr_seed_author() {
	$uid = dr_admin_id();
	wp_update_user( array( 'ID' => $uid, 'display_name' => 'Redaktion Deutsche Rezepte', 'nickname' => 'Redaktion', 'description' => DR_AUTHOR_BIO ) );
	$avatar = dr_brand_media( 'deutsche-rezepte-icon-512.png', 'Deutsche Rezepte' );
	if ( $avatar ) { update_user_meta( $uid, 'vr_author_avatar', $avatar ); }
	update_user_meta( $uid, 'vr_social_facebook', DR_SEED_FB );
}

function dr_seed_site() {
	update_option( 'blogname', 'Deutsche Rezepte' );
	update_option( 'blogdescription', 'Authentische deutsche Rezepte zum Nachkochen' );
	update_option( 'timezone_string', 'Europe/Berlin' );
	update_option( 'date_format', 'j. F Y' );
	update_option( 'time_format', 'H:i' );
	update_option( 'start_of_week', 1 );
	update_option( 'posts_per_page', 12 );
	update_option( 'default_comment_status', 'closed' );
	update_option( 'default_ping_status', 'closed' );
	update_option( 'blog_public', 1 );
	update_option( 'permalink_structure', '/%postname%/' );

	/* Theme options: one editorial voice, and the theme's nouns read "Rezept". */
	set_theme_mod( 'vr_byline_author', true );
	set_theme_mod( 'vr_noun', 'recipe' );
	set_theme_mod( 'vr_brand_social_facebook', DR_SEED_FB );   /* footer icon row */

	$icon = dr_brand_media( 'deutsche-rezepte-icon-512.png', 'Deutsche Rezepte Icon' );
	if ( $icon ) { update_option( 'site_icon', $icon ); }
	$logo = dr_brand_media( 'deutsche-rezepte-logo.png', 'Deutsche Rezepte Logo' );
	if ( $logo ) { set_theme_mod( 'custom_logo', $logo ); update_option( 'site_logo', $logo ); }

	/* WordPress sample content. */
	foreach ( array( array( 'hello-world', 'post' ), array( 'sample-page', 'page' ) ) as $x ) {
		$p = get_page_by_path( $x[0], OBJECT, $x[1] );
		if ( $p ) { wp_delete_post( $p->ID, true ); }
	}

	/* Categories — slugs fixed so the mood colours in the child theme CSS match. */
	$cats = array(
		'deutsche-klassiker' => array( 'Deutsche Klassiker', 'Schnitzel, Sauerbraten, Bratwurst und Co. – die Gerichte, die jeder kennt und liebt, Originalrezepte inklusive.' ),
		'schnelle-kueche'    => array( 'Schnelle Küche', 'Fertig in unter 30 Minuten: deutsche Alltagsgerichte für Feierabend, Familie und großen Hunger.' ),
		'suppen-eintoepfe'   => array( 'Suppen und Eintöpfe', 'Kartoffelsuppe, Linseneintopf, Gulasch – warme Töpfe für kalte Tage, wie bei Oma.' ),
		'backen'             => array( 'Backen und Kuchen', 'Schwarzwälder Kirschtorte, Streuselkuchen, Brezeln und Plätzchen – Rezepte aus der deutschen Backstube.' ),
		'vegetarisch'        => array( 'Vegetarisch', 'Käsespätzle, Kartoffelgratin, Maultaschen und mehr – deutsche Küche ganz ohne Fleisch.' ),
	);
	foreach ( $cats as $slug => $c ) {
		$t = get_term_by( 'slug', $slug, 'category' );
		if ( ! $t ) { $t = get_term_by( 'name', $c[0], 'category' ); }
		if ( $t ) { wp_update_term( $t->term_id, 'category', array( 'name' => $c[0], 'slug' => $slug, 'description' => $c[1] ) ); }
		else { wp_insert_term( $c[0], 'category', array( 'slug' => $slug, 'description' => $c[1] ) ); }
	}
	$uncat = get_term_by( 'slug', 'uncategorized', 'category' );
	if ( $uncat ) { wp_update_term( $uncat->term_id, 'category', array( 'name' => 'Allgemein', 'slug' => 'allgemein' ) ); }

	/* Pages */
	$site = home_url( '/' );
	dr_page( 'ueber-uns', 'Über uns',
		'<p><strong>Deutsche Rezepte:</strong> Authentische deutsche Rezepte zum Nachkochen – von Schnitzel, Currywurst und Käsespätzle bis zur Schwarzwälder Kirschtorte. Jedes Rezept wird von unserer Redaktion ausprobiert, mit klaren Schritt-für-Schritt-Anleitungen und Zutaten aus dem nächsten Supermarkt aufgeschrieben.</p>'
		. '<p>Du findest uns jeden Tag mit neuen Rezepten auf <a href="' . DR_SEED_FB . '" rel="noopener nofollow">Facebook</a> – das Rezept zum Video gibt es hier auf der Seite, ganz ohne Anmeldung.</p>' );
	dr_page( 'kontakt', 'Kontakt',
		'<p>Du hast eine Frage zu einem Rezept, einen Tipp oder eine Rückmeldung? Schreib uns über die <a href="' . DR_SEED_FB . '" rel="noopener nofollow">Facebook-Seite von Deutsche Rezepte</a> – wir lesen jede Nachricht.</p>' );
	$privacy = dr_page( 'datenschutz', 'Datenschutzerklärung',
		'<h2>1. Verantwortlicher</h2><p>Die Kontaktdaten des Verantwortlichen findest du im Impressum.</p>'
		. '<h2>2. Hosting und Server-Logfiles</h2><p>Beim Aufruf dieser Website werden technisch notwendige Daten (z. B. IP-Adresse, Datum und Uhrzeit, aufgerufene Seite, Browser) in Server-Logfiles verarbeitet, um den sicheren Betrieb zu gewährleisten (Art. 6 Abs. 1 lit. f DSGVO).</p>'
		. '<h2>3. Schriftarten</h2><p>Die verwendeten Schriftarten werden lokal von unserem Server geladen. Es findet keine Verbindung zu Servern von Google Fonts statt.</p>'
		. '<h2>4. Werbung (Google AdSense) und Einwilligung</h2><p>Diese Website finanziert sich über Werbung von Google AdSense (Google Ireland Ltd., Gordon House, Barrow Street, Dublin 4, Irland). Google kann Cookies und ähnliche Technologien einsetzen, um Anzeigen auszuliefern und zu messen. Personalisierte Werbung erfolgt nur mit deiner Einwilligung (Art. 6 Abs. 1 lit. a DSGVO), die du über das Einwilligungsbanner erteilen, ablehnen und jederzeit widerrufen kannst. Weitere Informationen: <a href="https://policies.google.com/technologies/ads" rel="nofollow noopener">policies.google.com/technologies/ads</a>.</p>'
		. '<h2>5. Besucherstatistik (Histats)</h2><p>Zur anonymen Reichweitenmessung nutzen wir Histats (histats.com). Dabei werden beim Seitenaufruf technische Daten wie IP-Adresse, Browser, Gerät, Referrer und aufgerufene Seite an Histats übertragen und zu Statistiken zusammengefasst. Rechtsgrundlage ist unser berechtigtes Interesse an der Auswertung der Nutzung (Art. 6 Abs. 1 lit. f DSGVO), soweit Cookies gesetzt werden, deine Einwilligung (§ 25 TDDDG). Weitere Informationen: <a href="https://www.histats.com/?act=1001" rel="nofollow noopener">Datenschutzhinweise von Histats</a>.</p>'
		. '<h2>6. Teilen-Schaltflächen</h2><p>Die Schaltflächen „Auf Facebook teilen“ und „Per WhatsApp senden“ sind einfache Links. Daten werden erst übertragen, wenn du sie anklickst.</p>'
		. '<h2>7. Deine Rechte</h2><p>Du hast das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung, Datenübertragbarkeit und Widerspruch sowie das Recht auf Beschwerde bei einer Datenschutz-Aufsichtsbehörde.</p>' );
	update_option( 'wp_page_for_privacy_policy', $privacy );
	/* Impressum (§ 5 DDG / § 18 MStV) — operator details supplied by the owner on 2026-10-02. */
	dr_page( 'impressum', 'Impressum',
		'<h2>Angaben gemäß § 5 DDG</h2>'
		. '<p>Dasek Nahri<br>Boukhalef<br>90090 Tanger<br>Marokko</p>'
		. '<h2>Kontakt</h2><p>E-Mail: <a href="mailto:daseknahri@gmail.com">daseknahri@gmail.com</a></p>'
		. '<h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2><p>Dasek Nahri, Anschrift wie oben</p>'
		. '<h2>Hinweis zu den Rezepten</h2><p>Alle Rezepte werden von uns nachgekocht, ausprobiert und redaktionell geprüft. Angaben zu Zubereitungszeiten und Mengen sind Richtwerte. Bei Allergien und Unverträglichkeiten bitte die Etiketten der verwendeten Zutaten beachten.</p>'
		. '<h2>Haftung für Links</h2><p>Unsere Seiten können Links zu externen Websites enthalten, auf deren Inhalte wir keinen Einfluss haben. Für diese Inhalte ist stets der jeweilige Anbieter verantwortlich.</p>'
		. '<h2>Verbraucherstreitbeilegung</h2><p>Wir sind nicht bereit oder verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>',
		'publish' );
	dr_page( 'hinweis', 'Hinweis zu unseren Rezepten',
		'<p>Alle Rezepte auf Deutsche Rezepte werden von unserer Redaktion nachgekocht und redaktionell geprüft. Zubereitungszeiten, Temperaturen und Mengenangaben sind Richtwerte – Backofen und Herd können variieren. Bei Allergien und Unverträglichkeiten prüfe bitte immer die Etiketten deiner Zutaten.</p>' );

	/* Primary menu: the recipe pillars. */
	$menu = wp_get_nav_menu_object( 'Hauptmenü' );
	$mid  = $menu ? $menu->term_id : wp_create_nav_menu( 'Hauptmenü' );
	if ( ! $menu ) {
		foreach ( array_keys( $cats ) as $slug ) {
			$t = get_term_by( 'slug', $slug, 'category' );
			if ( $t ) { wp_update_nav_menu_item( $mid, 0, array( 'menu-item-object' => 'category', 'menu-item-object-id' => $t->term_id, 'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish' ) ); }
		}
	}
	$locs = get_theme_mod( 'nav_menu_locations', array() );
	foreach ( array_keys( get_registered_nav_menus() ) as $loc ) { if ( false !== strpos( $loc, 'primary' ) || 'menu-1' === $loc ) { $locs[ $loc ] = $mid; } }
	set_theme_mod( 'nav_menu_locations', $locs );

	/* Drop WordPress's default sidebar widgets (English 'Recent Posts', Archives, Meta…); the theme renders its own
	   'Neueste Rezepte' + topics blocks when the sidebar is empty. */
	$sw = (array) get_option( 'sidebars_widgets', array() );
	foreach ( $sw as $k => $v ) { if ( is_array( $v ) && 'wp_inactive_widgets' !== $k ) { $sw[ $k ] = array(); } }
	update_option( 'sidebars_widgets', $sw );

	dr_seed_author();

	flush_rewrite_rules( false );
	update_option( 'dr_seed_version', DR_SEED_VERSION );
	dr_log( 'site seeded' );
}

/* AdSense Auto ads through the plugin. Applied on every start so a changed ADSENSE_CLIENT takes effect on the next
   redeploy; falls back to the site's publisher id when the variable arrives empty (Coolify passes declared-but-unset
   variables through as empty strings). */
const DR_ADSENSE_DEFAULT = 'ca-pub-6869205417923902';
function dr_apply_ads() {
	$client = trim( (string) getenv( 'ADSENSE_CLIENT' ) );
	if ( '' === $client ) { $client = DR_ADSENSE_DEFAULT; }
	if ( 'off' === $client || ! preg_match( '/^ca-pub-\d{10,20}$/', $client ) ) { return; }
	$ads = (array) get_option( 'wpap_ads_inject', array() );
	$ads['enabled']   = 1;
	$ads['scope_all'] = 1;
	$ads['auto_code'] = '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $client . '" crossorigin="anonymous"></script>';

	/* Manual placements in every story (Auto ads keeps the overlay formats: anchor + vignette).
	   Stories are ~9 paragraphs → ads after paragraphs 2, 5 and 8, plus a multiplex grid at the end.
	   Units: "LE - in story" (in-article) and "LE - end of story (multiplex)". ADS_MANUAL=off disables. */
	if ( 'off' !== trim( (string) getenv( 'ADS_MANUAL' ) ) ) {
		$push      = '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
		$in_story  = '<ins class="adsbygoogle" style="display:block;text-align:center" data-ad-layout="in-article" data-ad-format="fluid" data-ad-client="' . $client . '" data-ad-slot="5976014231"></ins>' . $push;
		$multiplex = '<ins class="adsbygoogle" style="display:block" data-ad-format="autorelaxed" data-matched-content-ui-type="image_stacked,image_stacked" data-matched-content-rows-num="3,2" data-matched-content-columns-num="2,4" data-ad-client="' . $client . '" data-ad-slot="6921515967"></ins>' . $push;
		$ads['slots'] = array(
			'top'       => array( 'on' => 0, 'code' => '' ),
			'incontent' => array( 'on' => 1, 'code' => $in_story, 'after' => 2 ),
			'repeat'    => array( 'on' => 1, 'code' => $in_story, 'every' => 3, 'max' => 2 ),
			'bottom'    => array( 'on' => 1, 'code' => $multiplex ),
		);
		/* Page zones: an ad right under the header on EVERY page (first thing a Facebook visitor sees — the
		   child theme also enables it on the home page) and one in the desktop sidebar. */
		/* Fixed 300x250 (no layout jump). The header ad is printed by the child theme (dr_top_ad: 300x250 on phones,
		   728x90 on desktop, with a same-size fallback card), so the plugin's header zone stays off. */
		$sidebar = '<ins class="adsbygoogle" style="display:inline-block;width:300px;height:250px" data-ad-client="' . $client . '" data-ad-slot="6714380837"></ins><script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
		$ads['zones'] = array(
			'header'  => array( 'on' => 0, 'code' => '' ),
			'sidebar' => array( 'on' => 1, 'code' => $sidebar ),   /* LE - in feed / sidebar */
			'footer'  => array( 'on' => 0, 'code' => '' ),
		);
		$ads['min_gap'] = 2;
		$ads['max_ads'] = 4;
		$ads['label']   = 0;   /* the theme already prints the translated "Anzeige" label above each slot */
	} else {
		$ads['slots'] = array();
		$ads['zones'] = array();
	}
	update_option( 'wpap_ads_inject', $ads );
	update_option( 'wpap_ads_txt', 'google.com, ' . str_replace( 'ca-', '', $client ) . ', DIRECT, f08c47fec0942fa0' );
}

/* The reader question for the end-of-story box: the last line of the Facebook comment that ends in "?". */
function dr_question_from_comment( $comment ) {
	$lines = array_reverse( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $comment ) ) ) );
	foreach ( $lines as $l ) {
		if ( false === strpos( $l, '{{link}}' ) && false !== strpos( $l, '?' ) ) { return trim( preg_replace( '/[\x{1F300}-\x{1FAFF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u', '', $l ) ); }
	}
	return '';
}

function dr_import_bundles() {
	if ( ! function_exists( 'wpap_publish_article' ) ) { dr_log( 'plugin not loaded — skipping import' ); return; }
	$done  = (array) get_option( 'dr_imported_bundles', array() );
	$zips  = glob( DR_DATA . '/bundles/*.zip' ) ?: array();
	sort( $zips );
	$admin = dr_admin_id();
	wp_set_current_user( $admin );
	foreach ( $zips as $zip ) {
		$name = basename( $zip );
		if ( in_array( $name, $done, true ) ) { continue; }
		$work = trailingslashit( get_temp_dir() ) . 'dr-' . sanitize_file_name( $name );
		$z = new ZipArchive();
		if ( true !== $z->open( $zip ) ) { dr_log( "cannot open $name" ); continue; }
		$z->extractTo( $work );
		$z->close();
		$items = json_decode( (string) file_get_contents( $work . '/posts.json' ), true );
		$n = 0;
		foreach ( (array) $items as $item ) {
			$slug = sanitize_title( $item['slug'] ?? $item['title'] ?? '' );
			if ( $slug && get_page_by_path( $slug, OBJECT, 'post' ) ) { continue; }   /* resume-safe */
			$opts = array( 'default_parts' => 1, 'schedule_window' => 0, 'author' => $admin );
			$img  = isset( $item['image'] ) ? realpath( $work . '/' . ltrim( (string) $item['image'], '/' ) ) : false;
			if ( $img && 0 === strpos( $img, realpath( $work ) ) ) { $opts['local_image_path'] = $img; }
			$id = wpap_publish_article( $item, $opts );
			if ( is_wp_error( $id ) ) { dr_log( 'skip: ' . $id->get_error_message() ); continue; }
			/* build-v9 derives the permalink from the title and ignores item slug — pin the
			   planned slug so the resume check above and [[link:slug]] tokens stay valid. */
			if ( $slug ) { wp_update_post( array( 'ID' => (int) $id, 'post_name' => $slug ) ); }
			$q = dr_question_from_comment( $item['comment'] ?? '' );
			if ( '' !== $q ) { update_post_meta( (int) $id, '_dr_question', $q ); }
			$n++;
		}
		/* Clean up the extracted bundle. */
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $work, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); }
		rmdir( $work );
		$done[] = $name;
		update_option( 'dr_imported_bundles', $done, false );
		dr_log( "$name: $n Rezepte importiert" );
	}
	if ( function_exists( 'wpap_internal_links_bake' ) ) { wpap_internal_links_bake( 500 ); }
	delete_option( 'dr_cover_cache' );
}

if ( (int) get_option( 'dr_seed_version', 0 ) < DR_SEED_VERSION ) { dr_seed_site(); }
dr_apply_ads();
dr_import_bundles();

/* Self-heal pretty permalinks on every start: a long import can be killed by the PHP
   time limit right after something emptied the rewrite rules, leaving every pretty URL
   (including ads.txt) as a 404 until a flush. Idempotent and cheap. */
if ( '' === get_option( 'permalink_structure', '' ) ) {
	update_option( 'permalink_structure', '/%postname%/' );
}
if ( ! get_option( 'rewrite_rules' ) ) {
	flush_rewrite_rules( false );
	dr_log( 'rewrite rules regenerated' );
}

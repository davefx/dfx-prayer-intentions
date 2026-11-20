<?php
/**
 * The leaders' installable app.
 *
 * @package DFXPRAY
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the app at /<base>-app/: a private page for leaders, installable as
 * a PWA (manifest and service worker under the same address). The page only
 * carries the app's code; every piece of data comes from DFXPRAY_App_API.
 */
final class DFXPRAY_App {

	use DFXPRAY_Singleton;

	/**
	 * Query variable: shell, manifest or worker.
	 */
	public const QUERY_VAR = 'dfxpray_app';

	/**
	 * Hook into WordPress.
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 1 );
	}

	/**
	 * The app's address, with a trailing slash.
	 */
	public function url(): string {
		return home_url( '/' . $this->slug() . '/' );
	}

	/**
	 * Path segment of the app: the forms' base plus "-app".
	 */
	private function slug(): string {
		return DFXPRAY_Settings::get_instance()->get( 'url_base' ) . '-app';
	}

	/**
	 * Rewrite rules: regex => query.
	 *
	 * @return array<string, string>
	 */
	public function rules(): array {
		$slug = preg_quote( $this->slug(), '#' );

		return array(
			'^' . $slug . '/?$'                     => 'index.php?' . self::QUERY_VAR . '=shell',
			'^' . $slug . '/manifest\.webmanifest$' => 'index.php?' . self::QUERY_VAR . '=manifest',
			'^' . $slug . '/sw\.js$'                => 'index.php?' . self::QUERY_VAR . '=worker',
		);
	}

	/**
	 * Register the rules.
	 */
	public function add_rewrite_rules(): void {
		foreach ( $this->rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Flush the rules once per plugin version, so an update brings the app.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( DFXPRAY_VERSION . '|' . $this->slug() !== get_option( 'dfxpray_app_rules' ) ) {
			flush_rewrite_rules( false );
			update_option( 'dfxpray_app_rules', DFXPRAY_VERSION . '|' . $this->slug(), false );
		}
	}

	/**
	 * Register the query variable.
	 *
	 * @param string[] $vars Query variables.
	 * @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Who may open the app: 'ok', 'login' (not logged in) or 'forbidden'.
	 */
	public function access(): string {
		if ( ! is_user_logged_in() ) {
			return 'login';
		}

		return DFXPRAY_Permissions::get_instance()->can_access_panel() ? 'ok' : 'forbidden';
	}

	/**
	 * Answer a request for the app.
	 */
	public function serve(): void {
		$what = (string) get_query_var( self::QUERY_VAR );

		if ( 'manifest' === $what ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( $this->manifest() );
			exit;
		}

		if ( 'worker' === $what ) {
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Cache-Control: no-cache' );
			header( 'Service-Worker-Allowed: ' . wp_parse_url( $this->url(), PHP_URL_PATH ) );
			echo $this->service_worker(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Script built from escaped JSON.
			exit;
		}

		if ( 'shell' !== $what ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by cache plugins.
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$access = $this->access();
		if ( 'login' === $access ) {
			wp_safe_redirect( wp_login_url( $this->url() ) );
			exit;
		}
		if ( 'forbidden' === $access ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'dfx-prayer-intentions' ), '', array( 'response' => 403 ) );
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		echo $this->shell(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with escaping in shell().
		exit;
	}

	/**
	 * The HTML page of the app.
	 */
	public function shell(): string {
		$config = array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'action'    => DFXPRAY_App_API::AJAX_ACTION,
			'nonce'     => wp_create_nonce( DFXPRAY_App_API::NONCE ),
			'appUrl'    => $this->url(),
			'manualUrl' => DFXPRAY_Admin::get_instance()->url( 'dfxpray-manual' ),
			'worker'    => $this->url() . 'sw.js',
			'version'   => DFXPRAY_VERSION,
			'today'     => DFXPRAY_Clock::get_instance()->today(),
			'icon'      => $this->asset( 'images/app-icon-192.png' ),
			'strings'   => $this->strings(),
			'types'     => DFXPRAY_Lifecycle::get_instance()->types(),
			'kinds'     => DFXPRAY_Lifecycle::get_instance()->kind_choices(),
			'reasons'   => DFXPRAY_Lifecycle::get_instance()->close_reasons(),
			'statuses'  => DFXPRAY_Lifecycle::get_instance()->statuses(),
			'levels'    => DFXPRAY_Discretion::get_instance()->levels(),
			'scopes'    => DFXPRAY_Intentions::get_instance()->scope_labels(),
			'topics'    => DFXPRAY_Intentions::get_instance()->topic_labels(),
		);
		$title  = __( 'Prayer intentions', 'dfx-prayer-intentions' );

		return '<!doctype html>' . "\n"
			. '<html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '">' . "\n"
			. '<head>' . "\n"
			. '<meta charset="utf-8">' . "\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n"
			. '<meta name="robots" content="noindex, nofollow">' . "\n"
			. '<meta name="theme-color" content="#24305a">' . "\n"
			. '<title>' . esc_html( $title ) . '</title>' . "\n"
			. '<link rel="manifest" href="' . esc_url( $this->url() . 'manifest.webmanifest' ) . '">' . "\n"
			. '<link rel="icon" href="' . esc_url( $this->asset( 'images/app-icon-192.png' ) ) . '">' . "\n"
			. '<link rel="apple-touch-icon" href="' . esc_url( $this->asset( 'images/app-icon-192.png' ) ) . '">' . "\n"
			. '<link rel="stylesheet" href="' . esc_url( $this->asset( 'css/app.css' ) ) . '">' . "\n" // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone app page, outside the theme and wp_head.
			. '</head>' . "\n"
			. '<body>' . "\n"
			. '<div id="dfxpray-app"><p class="dfxpray-app-loading">' . esc_html__( 'Loading…', 'dfx-prayer-intentions' ) . '</p></div>' . "\n"
			. '<noscript>' . esc_html__( 'The app needs JavaScript.', 'dfx-prayer-intentions' ) . '</noscript>' . "\n"
			. '<script>window.DFXPRAY_APP = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ';</script>' . "\n"
			. '<script src="' . esc_url( $this->asset( 'js/app.js' ) ) . '"></script>' . "\n" // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone app page, outside the theme and wp_head.
			. '</body>' . "\n"
			. '</html>' . "\n";
	}

	/**
	 * Web app manifest.
	 *
	 * @return array
	 */
	public function manifest(): array {
		return array(
			'id'               => $this->url(),
			'name'             => __( 'Prayer intentions', 'dfx-prayer-intentions' ),
			'short_name'       => __( 'Prayer', 'dfx-prayer-intentions' ),
			'start_url'        => $this->url(),
			'scope'            => $this->url(),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => '#24305a',
			'icons'            => array(
				array(
					'src'     => $this->asset( 'images/app-icon-192.png' ),
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => $this->asset( 'images/app-icon-512.png' ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => $this->asset( 'images/app-icon-512.png' ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
		);
	}

	/**
	 * Service worker: caches the app's own files (one cache per version), so
	 * the app opens without connection. Data is never cached here.
	 */
	public function service_worker(): string {
		$files = array(
			$this->url(),
			$this->asset( 'js/app.js' ),
			$this->asset( 'css/app.css' ),
			$this->asset( 'images/app-icon-192.png' ),
			$this->asset( 'images/app-icon-512.png' ),
			$this->asset( 'fonts/EBGaramond-wght.woff2' ),
			$this->asset( 'fonts/EBGaramond-Italic-wght.woff2' ),
			$this->asset( 'fonts/Cinzel-wght.woff2' ),
		);

		return 'const CACHE = ' . wp_json_encode( 'dfxpray-app-' . DFXPRAY_VERSION ) . ";\n"
			. 'const FILES = ' . wp_json_encode( $files, JSON_UNESCAPED_SLASHES ) . ";\n"
			. <<<'JS'
self.addEventListener('install', (event) => {
	event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(FILES)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
	event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key.startsWith('dfxpray-app-') && key !== CACHE).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (event) => {
	const request = event.request;
	if (request.method !== 'GET') {
		return;
	}
	if (request.mode === 'navigate') {
		event.respondWith(fetch(request).catch(() => caches.match(FILES[0])));
		return;
	}
	if (FILES.includes(request.url)) {
		event.respondWith(caches.match(request).then((cached) => cached || fetch(request)));
	}
});
JS;
	}

	/**
	 * URL of a plugin asset, versioned.
	 *
	 * @param string $path Path under assets/.
	 */
	private function asset( string $path ): string {
		return add_query_arg( 'ver', DFXPRAY_VERSION, plugins_url( 'assets/' . $path, DFXPRAY_PLUGIN_FILE ) );
	}

	/**
	 * Texts of the app, translated here so the script needs no catalogue.
	 *
	 * @return array<string, string>
	 */
	private function strings(): array {
		return array(
			'appName'                 => __( 'Prayer intentions', 'dfx-prayer-intentions' ),
			'today'                   => __( 'Today', 'dfx-prayer-intentions' ),
			'intentions'              => __( 'Intentions', 'dfx-prayer-intentions' ),
			'moderation'              => __( 'Moderation', 'dfx-prayer-intentions' ),
			'add'                     => __( 'Add', 'dfx-prayer-intentions' ),
			'share'                   => __( 'Share', 'dfx-prayer-intentions' ),
			'manual'                  => __( 'Manual', 'dfx-prayer-intentions' ),
			'installApp'              => __( 'Install the app', 'dfx-prayer-intentions' ),
			'installIos'              => __( 'To install it: tap Share and then Add to Home Screen.', 'dfx-prayer-intentions' ),
			'copy'                    => __( 'Copy', 'dfx-prayer-intentions' ),
			'copied'                  => __( 'Copied!', 'dfx-prayer-intentions' ),
			'search'                  => __( 'Search', 'dfx-prayer-intentions' ),
			'current'                 => __( 'Current', 'dfx-prayer-intentions' ),
			'nothing'                 => __( 'Nothing here.', 'dfx-prayer-intentions' ),
			'loading'                 => __( 'Loading…', 'dfx-prayer-intentions' ),
			'offline'                 => __( 'No connection: showing the last data saved on this device.', 'dfx-prayer-intentions' ),
			'queued'                  => __( 'No connection: it will be sent as soon as there is one.', 'dfx-prayer-intentions' ),
			'pendingSend'             => __( 'Waiting to be sent:', 'dfx-prayer-intentions' ),
			'error'                   => __( 'Something went wrong. Please try again.', 'dfx-prayer-intentions' ),
			'back'                    => __( 'Back', 'dfx-prayer-intentions' ),
			'save'                    => __( 'Save', 'dfx-prayer-intentions' ),
			'saved'                   => __( 'Saved.', 'dfx-prayer-intentions' ),
			'updates'                 => __( 'Updates', 'dfx-prayer-intentions' ),
			'newUpdate'               => __( 'New update', 'dfx-prayer-intentions' ),
			'addUpdate'               => __( 'Add update', 'dfx-prayer-intentions' ),
			'asNews'                  => __( 'Show as news in the daily digest', 'dfx-prayer-intentions' ),
			'stillCurrent'            => __( 'Still current', 'dfx-prayer-intentions' ),
			'closeAs'                 => __( 'Close as', 'dfx-prayer-intentions' ),
			'close'                   => __( 'Close', 'dfx-prayer-intentions' ),
			'addThanksgiving'         => __( 'Answered: add a thanksgiving', 'dfx-prayer-intentions' ),
			'thanksSummary'           => __( 'Summary of the thanksgiving', 'dfx-prayer-intentions' ),
			'thanksWillAppear'        => __( 'In the daily and monthly digests it will appear as:', 'dfx-prayer-intentions' ),
			'givesThanksFor'          => __( 'Gives thanks for:', 'dfx-prayer-intentions' ),
			'itsThanksgiving'         => __( 'Its thanksgiving:', 'dfx-prayer-intentions' ),
			'reopen'                  => __( 'Reopen', 'dfx-prayer-intentions' ),
			'markHigh'                => __( 'Mark as high priority', 'dfx-prayer-intentions' ),
			'markNormal'              => __( 'Back to normal priority', 'dfx-prayer-intentions' ),
			'postponeTo'              => __( 'Postpone review to', 'dfx-prayer-intentions' ),
			'postpone'                => __( 'Postpone', 'dfx-prayer-intentions' ),
			'approve'                 => __( 'Approve', 'dfx-prayer-intentions' ),
			'discard'                 => __( 'Discard', 'dfx-prayer-intentions' ),
			'toModerate'              => __( 'Waiting for moderation', 'dfx-prayer-intentions' ),
			'toReview'                => __( 'To review', 'dfx-prayer-intentions' ),
			'summary'                 => __( 'Summary', 'dfx-prayer-intentions' ),
			'summaryHelp'             => __( 'What follows the name in the message.', 'dfx-prayer-intentions' ),
			'willAppear'              => __( 'How it will appear', 'dfx-prayer-intentions' ),
			'stepWho'                 => __( 'Who asks', 'dfx-prayer-intentions' ),
			'stepForWhom'             => __( 'Who we pray for', 'dfx-prayer-intentions' ),
			'stepWhat'                => __( 'What is happening', 'dfx-prayer-intentions' ),
			'stepMessage'             => __( 'How it appears in the message', 'dfx-prayer-intentions' ),
			'stepFollow'              => __( 'Follow-up', 'dfx-prayer-intentions' ),
			'fullName'                => __( 'Full name', 'dfx-prayer-intentions' ),
			'name'                    => __( 'Name', 'dfx-prayer-intentions' ),
			'calledAs'                => __( 'How we call them', 'dfx-prayer-intentions' ),
			'calledAsHelp'            => __( 'A nickname, a compound name or a surname to tell brothers apart: Toni, Juan Tomás, Javi Cavallé. Empty: the first word, as shown in grey.', 'dfx-prayer-intentions' ),
			'whatsapp'                => __( 'WhatsApp', 'dfx-prayer-intentions' ),
			'whatsappHelp'            => __( 'To ask them how it goes.', 'dfx-prayer-intentions' ),
			'ourGroup'                => __( 'One of our group', 'dfx-prayer-intentions' ),
			'formContact'             => __( 'Contact left in the form:', 'dfx-prayer-intentions' ),
			'whenAsked'               => __( 'When they asked', 'dfx-prayer-intentions' ),
			'whenAskedHelp'           => __( 'Empty: today.', 'dfx-prayer-intentions' ),
			'forThemselves'           => __( 'For themselves', 'dfx-prayer-intentions' ),
			'forSomeoneElse'          => __( 'For someone else', 'dfx-prayer-intentions' ),
			'relationshipToRequester' => __( 'What they are to the requester', 'dfx-prayer-intentions' ),
			/* translators: %s: how the requester is called ("Javier"). */
			'relationshipTo'          => __( 'What they are to %s', 'dfx-prayer-intentions' ),
			'relationshipHelp'        => __( '“mother of”, “friend of”… It goes before the requester’s name: “Carmen, mother of Luis”.', 'dfx-prayer-intentions' ),
			'eventHint'               => __( 'An operation, an exam, a retreat…', 'dfx-prayer-intentions' ),
			'date'                    => __( 'Date', 'dfx-prayer-intentions' ),
			'dateOfDeath'             => __( 'Date of death', 'dfx-prayer-intentions' ),
			'rememberYearly'          => __( 'Remember it every year', 'dfx-prayer-intentions' ),
			'toldUs'                  => __( 'What they told us', 'dfx-prayer-intentions' ),
			'urgent'                  => __( 'Urgent: it opens “Especially”', 'dfx-prayer-intentions' ),
			'discretionAndNames'      => __( 'Discretion and names', 'dfx-prayer-intentions' ),
			'lookAgainOn'             => __( 'Look at it again on', 'dfx-prayer-intentions' ),
			'prayUntil'               => __( 'Or pray for it only until', 'dfx-prayer-intentions' ),
			'cancel'                  => __( 'Cancel', 'dfx-prayer-intentions' ),
			'members'                 => __( 'Members', 'dfx-prayer-intentions' ),
			'birthday'                => __( 'Birthday', 'dfx-prayer-intentions' ),
			/* translators: %d: the age a member turns. */
			'turns'                   => __( 'Turns %d', 'dfx-prayer-intentions' ),
			'deathAnniversary'        => __( 'Anniversary of his death', 'dfx-prayer-intentions' ),
			'tomorrow'                => __( 'Tomorrow', 'dfx-prayer-intentions' ),
			'nobody'                  => __( 'Nobody this month.', 'dfx-prayer-intentions' ),
			'noDate'                  => __( 'No date', 'dfx-prayer-intentions' ),
			'editIntention'           => __( 'Edit intention', 'dfx-prayer-intentions' ),
			'fullText'                => __( 'Full text', 'dfx-prayer-intentions' ),
			'neverPublished'          => __( 'Never published. Only leaders read it.', 'dfx-prayer-intentions' ),
			'highPriority'            => __( 'High priority', 'dfx-prayer-intentions' ),
			'addIntention'            => __( 'Add intention', 'dfx-prayer-intentions' ),
			'group'                   => __( 'Group', 'dfx-prayer-intentions' ),
			'nextReview'              => __( 'Next review', 'dfx-prayer-intentions' ),
			'endsOnHelp'              => __( 'The day after, it leaves the messages and closes by itself.', 'dfx-prayer-intentions' ),
			'until'                   => _x( 'Until', 'followed by the last day an intention is prayed for', 'dfx-prayer-intentions' ),
			'installHint'             => __( 'To install it, use “Add to home screen” in your browser’s menu.', 'dfx-prayer-intentions' ),
			'noGroups'                => __( 'You do not lead any group yet.', 'dfx-prayer-intentions' ),
			'discardConfirm'          => __( 'Discard this request?', 'dfx-prayer-intentions' ),
			'edit'                    => __( 'Edit', 'dfx-prayer-intentions' ),
			'addedOn'                 => __( 'Added on', 'dfx-prayer-intentions' ),
			'requestedBy'             => __( 'Asked by', 'dfx-prayer-intentions' ),
			'daily'                   => __( 'Daily', 'dfx-prayer-intentions' ),
			'monthly'                 => __( 'Monthly list', 'dfx-prayer-intentions' ),
			'askWhatsApp'             => __( 'Ask by WhatsApp how it goes', 'dfx-prayer-intentions' ),
			'toAskTab'                => __( 'To ask', 'dfx-prayer-intentions' ),
			'toAskTitle'              => __( 'Pending to ask how they go', 'dfx-prayer-intentions' ),
			'toAskHelp'               => __( 'Those you marked to ask about, and those whose review date has come. Adding an update or closing one takes it off the list.', 'dfx-prayer-intentions' ),
			'toAskMark'               => __( 'Pending to ask', 'dfx-prayer-intentions' ),
			'askAllWhatsApp'          => __( 'Ask about all of them by WhatsApp', 'dfx-prayer-intentions' ),
			'noPhone'                 => __( 'No phone yet: add it when editing one of them.', 'dfx-prayer-intentions' ),
			'topic'                   => __( 'Topic', 'dfx-prayer-intentions' ),
			'saveChanges'             => __( 'Save changes', 'dfx-prayer-intentions' ),
			'visibleRequester'        => __( 'Description of the requester instead of the name', 'dfx-prayer-intentions' ),
			'visibleBeneficiary'      => __( 'Description of the beneficiary instead of the name', 'dfx-prayer-intentions' ),
			'descriptionHelp'         => __( 'Only for discreet cases: “Bro. John’s daughter”. Nothing is added to it.', 'dfx-prayer-intentions' ),
			'time'                    => __( 'Time', 'dfx-prayer-intentions' ),
			'discretion'              => __( 'Discretion', 'dfx-prayer-intentions' ),
		);
	}
}

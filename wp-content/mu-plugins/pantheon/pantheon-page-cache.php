<?php
/*	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/
class Pantheon_Cache {

	/**
	 * Define the capability required to see and modify the settings page.
	 *
	 * @var string
	 */
	public $options_capability = 'manage_options';

	/**
	 * Define the default options, which are overridden by what's in wp_options.
	 *
	 * @var array
	 */
	public $default_options = array();

	/**
	 * Stores the options for this plugin (from wp_options).
	 *
	 * @var array
	 */
	public $options = array();

	/**
	 * Store the Paths to be flushed at shutdown.
	 *
	 * @var array
	 */
	public $paths = array();

	/**
	 * The slug for the plugin, used in various places like the options page.
	 */
	const SLUG = 'pantheon-cache';

	/**
	 * Holds the singleton instance.
	 *
	 * @static
	 * @var object
	 */
	protected static $instance;

	/**
	 * Get a reference to the singleton.
	 *
	 * @return object The singleton instance.
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new Pantheon_Cache;
			self::$instance->setup();
		}
		return self::$instance;
	}

	protected function __construct() {
		/** Don't do anything **/
	}


	/**
	 * Setup the actions and filters we need to hook into, and initialize any properties we need.
	 *
	 * @return void
	 */
	protected function setup() {
		$this->options = get_option( self::SLUG, array() );
		$this->default_options = array(
			'default_ttl' => 600,
			'maintenance_mode' => 'disabled',
		);
		$this->options = wp_parse_args( $this->options, $this->default_options );

		add_action( 'init', array( $this, 'action_init_do_maintenance_mode' ) );

		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
		add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );
		add_action( 'load-plugin-install.php', array( $this, 'action_load_plugin_install' ) );

		add_action( 'admin_post_pantheon_cache_flush_site',  array( $this, 'flush_site' ) );

		if ( ! is_admin() && function_exists( 'is_user_logged_in' ) && ! is_user_logged_in() ) {
			add_action( 'send_headers',               array( $this, 'cache_add_headers' ) );
		}
		else {
			add_action( 'send_headers',               array( $this, 'no_cache_add_headers' ) );
		}
		add_filter( 'rest_post_dispatch', array( $this, 'filter_rest_post_dispatch_send_cache_control' ), 10, 2 );

		add_action( 'admin_notices', function(){
			global $wp_object_cache;
			if ( empty( $wp_object_cache->missing_redis_message ) ) {
				return;
			}
			$wp_object_cache->missing_redis_message = 'Alert! The Pantheon Redis service needs to be enabled before the WP Redis object cache will function properly.';
		}, 9 ); // Before the message is displayed in the plugin notice.

		add_action( 'shutdown', array( $this, 'cache_clean_urls' ), 999 );
	}

	/**
	 * Displays maintenance mode when enabled.
	 */
	public function action_init_do_maintenance_mode() {

		$do_maintenance_mode = false;

		if ( in_array( $this->options['maintenance_mode'], [ 'anonymous', 'everyone' ], true )
			&& ! is_user_logged_in() ) {
			$do_maintenance_mode = true;
		}

		if ( 'everyone' === $this->options['maintenance_mode']
			&& is_user_logged_in()
			&& ! current_user_can( 'manage_options' ) ) {
			$do_maintenance_mode = true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$do_maintenance_mode = false;
		}

		if ( 'wp-login.php' === $GLOBALS['pagenow'] ) {
			$do_maintenance_mode = false;
		}

		/**
		 * Modify maintenance mode behavior with more advanced conditionals.
		 *
		 * @var boolean $do_maintenance_mode Whether or not to do maintenance mode.
		 */
		$do_maintenance_mode = apply_filters( 'pantheon_cache_do_maintenance_mode', $do_maintenance_mode );

		if ( ! $do_maintenance_mode ) {
			return;
		}

		wp_die(
			__( 'Briefly unavailable for scheduled maintenance. Check back in a minute.' ),
			__( 'Maintenance' ),
			503
		);
	}

	/**
	 * Prep the Settings API.
	 *
	 * @return void
	 */
	public function action_admin_init() {
		register_setting( self::SLUG, self::SLUG, array( self::$instance, 'sanitize_options' ) );
		add_settings_section( 'general', false, '__return_false', self::SLUG );
		add_settings_field( 'default_ttl', null, array( self::$instance, 'default_ttl_field' ), self::SLUG, 'general' );
		add_settings_field( 'maintenance_mode', null, array( self::$instance, 'maintenance_mode_field' ), self::SLUG, 'general' );
	}


	/**
	 * Add the settings page to the menu.
	 *
	 * @return void
	 */
	public function action_admin_menu() {
		add_options_page( __( 'Pantheon Page Cache', 'pantheon-cache' ), __( 'Pantheon Page Cache', 'pantheon-cache' ), $this->options_capability, self::SLUG, array( self::$instance, 'view_settings_page' ) );
	}

	/**
	 * Check to see if JavaScript should trigger the opening of the plugin install box
	 */
	public function action_load_plugin_install() {
		if ( empty( $_GET['action'] ) || 'pantheon-load-infobox' !== $_GET['action'] ) {
			return;
		}
		add_action( 'admin_footer', array( $this, 'action_admin_footer_trigger_plugin_open' ) );
	}

	/**
	 * Trigger the opening of the Pantheon Advanced Page Cache infobox
	 */
	public function action_admin_footer_trigger_plugin_open() {
		?>
		<script>
			jQuery(document).ready(function(){
				// Wait until the click event handler is bound by core JavaScript
				setTimeout(function(){
					jQuery('.plugin-card-pantheon-advanced-page-cache a.open-plugin-details-modal').trigger('click');
				}, 1 )
			});
		</script>
		<?php
	}

	/**
	 * Add the HTML for the default TTL field.
	 *
	 * @return void
	 */
	public function default_ttl_field() {
		echo '<h3>' . __( 'Default Time to Live (TTL)', 'pantheon-cache' ) . '</h3>';
		echo '<p>' . __( 'Maximum time a cached page will be served. A higher TTL typically improves site performance.', 'pantheon-cache' ) . '</p>';
		echo '<input type="text" name="' . self::SLUG . '[default_ttl]" value="' . $this->options['default_ttl'] . '" size="5" /> ' . __( 'seconds', 'pantheon-cache' );
	}

	/**
	 * Add the HTML for the maintenance mode field.
	 *
	 * @return void
	 */
	public function maintenance_mode_field() {
		echo '<h3>' . __( 'Maintenance Mode', 'pantheon-cache' ) . '</h3>';
		echo '<p>' . __( 'Enable maintenance mode to work on your site while serving cached pages to:', 'pantheon-cache' ) . '</p>';
		echo '<label style="display: block; margin-bottom: 5px;"><input type="radio" name="' . self::SLUG . '[maintenance_mode]" value="" ' . checked( 'disabled', $this->options['maintenance_mode'], false ) . ' /> ' . __( 'Disabled', 'pantheon-cache' ) . '</label>';
		echo '<label style="display: block; margin-bottom: 5px;"><input type="radio" name="' . self::SLUG . '[maintenance_mode]" value="anonymous" ' . checked( 'anonymous', $this->options['maintenance_mode'], false ) . ' /> ' . __( 'Visitors and Bots', 'pantheon-cache' ) . '</label>';
		echo '<label style="display: block; margin-bottom: 5px;"><input type="radio" name="' . self::SLUG . '[maintenance_mode]" value="everyone" ' . checked( 'everyone', $this->options['maintenance_mode'], false ) . ' /> ' . __( 'Everyone except Administrators', 'pantheon-cache' ) . '</label>';
	}


	/**
	 * Sanitize our options.
	 *
	 * @param  array $in The POST values.
	 * @return array     The sanitized POST values.
	 */
	public function sanitize_options( $in ) {
		$out = $this->default_options;

		// Validate default_ttl
		$out['default_ttl'] = absint( $in['default_ttl'] );
		if ( $out['default_ttl'] < 60 && isset( $_ENV['PANTHEON_ENVIRONMENT'] ) && 'live' === $_ENV['PANTHEON_ENVIRONMENT'] ) {
			$out['default_ttl'] = 60;
		}

		if ( ! empty( $in['maintenance_mode'] )
			&& in_array( $in['maintenance_mode'], [ 'anonymous', 'everyone' ], true ) ) {
			$out['maintenance_mode'] = $in['maintenance_mode'];
		} else {
			$out['maintenance_mode'] = 'disabled';
		}
		return $out;
	}


	/**
	 * Output the settings page.
	 *
	 * @return void
	 */
	public function view_settings_page() {
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Pantheon Page Cache', 'pantheon-cache' ); ?></h2>

			<?php if ( ! empty( $_GET['cache-cleared'] ) && 'true' == $_GET['cache-cleared'] ) : ?>
				<div class="updated below-h2">
					<p><?php esc_html_e( 'Site cache flushed.', 'pantheon-cache' ); ?></p>
				</div>
			<?php endif ?>

			<?php if ( class_exists( 'Pantheon_Advanced_Page_Cache\Purger' ) ) : // translators: %s is a link. ?>
				<div class="notice notice-success"><p><?php echo esc_html( sprintf( __( 'Pantheon Advanced Page Cache activated. <a target="_blank" href="%s">Learn more</a>', 'pantheon-cache' ), 'https://pantheon.io/docs/wordpress-cache-plugin' ) ); ?></p></div>
			<?php else : // translators: %s is a link. ?>
				<div class="notice notice-warning"><p><?php echo esc_html( sprintf( __( 'Want to automatically clear related pages when you update content? Learn more about the <a href="%s">Pantheon Advanced Page Cache</a>.', 'pantheon-cache' ), 'https://pantheon.io/docs/wordpress-cache-plugin' ) ); ?></p></div>
			<?php endif; ?>

			<?php
			/**
			 * Permits the Pantheon Advanced Page Cache plugin to add
			 * supplemental text.
			 */
			do_action( 'pantheon_cache_settings_page_top' );
			?>

			<?php if ( apply_filters( 'pantheon_cache_allow_clear_all', true ) ) : ?>

				<form action="admin-post.php" method="POST">
					<input type="hidden" name="action" value="pantheon_cache_flush_site" />
					<?php wp_nonce_field( 'pantheon-cache-clear-all', 'pantheon-cache-nonce' ); ?>
					<h3><?php _e( 'Clear Site Cache', 'pantheon-cache' ); ?></h3>
					<p><?php _e( 'Use with care. Clearing the entire site cache will negatively impact performance for a short period of time.', 'pantheon-cache' ); ?></p>
					<?php submit_button( __( 'Clear Cache', 'pantheon-cache' ), 'secondary' ); ?>
				</form>

				<hr />

			<?php endif ?>

			<style>
			.ttl-form th[scope="row"] {
				display: none;
			}
			.ttl-form td {
				padding-left: 0;
			}
			.ttl-form td p {
				margin-bottom: 1em;
				font-size: 13px;
			}
			</style>

			<form action="options.php" method="POST" class="ttl-form">
				<?php settings_fields( self::SLUG ); ?>
				<?php do_settings_sections( self::SLUG ); ?>
				<?php submit_button( __( 'Save Changes', 'pantheon-cache' ) ); ?>
			</form>

			<hr />

			<?php
			/**
			 * Permits the Pantheon Advanced Page Cache plugin to add
			 * supplemental text.
			 */
			do_action( 'pantheon_cache_settings_page_bottom' ); ?>

		</div>
		<?php
	}

	/**
	 * Set a stronger cache-control header for admin or logged in requests.
	 *
	 * This removes "max-age=0" which could hypothetically be used by
	 * Varnish on an immediate subsequent request.
	 *
	 * @return void
	 */
	public function no_cache_add_headers() {
		header( 'cache-control: no-cache, no-store, must-revalidate');
	}

	/**
	 * Add the cache-control header.
	 *
	 * @return void
	 */
	public function cache_add_headers() {
		$ttl = absint( $this->options['default_ttl'] );
		if ( $ttl < 60 && isset( $_ENV['PANTHEON_ENVIRONMENT'] ) && 'live' === $_ENV['PANTHEON_ENVIRONMENT'] ) {
			$ttl = 60;
		}

		header( 'cache-control: public, max-age=' . $ttl );
	}

	/**
	 * Send the cache control header for REST API requests
	 */
	public function filter_rest_post_dispatch_send_cache_control( $response, $server ) {
		$ttl = absint( $this->options['default_ttl'] );
		if ( $ttl < 60 && isset( $_ENV['PANTHEON_ENVIRONMENT'] ) && 'live' === $_ENV['PANTHEON_ENVIRONMENT'] ) {
			$ttl = 60;
		}
		$response->header( 'Cache-Control', 'public, max-age=' . $ttl );
		return $response;
	}

	/**
	 * Clear the cache for the entire site.
	 *
	 * @return void
	 */
	public function flush_site() {
		if ( ! function_exists( 'current_user_can' ) || false == current_user_can( 'manage_options' ) )
			return false;

		if ( ! empty( $_POST['pantheon-cache-nonce'] ) && wp_verify_nonce( $_POST['pantheon-cache-nonce'], 'pantheon-cache-clear-all' ) ) {
			if ( function_exists( 'pantheon_clear_edge_all' ) ) {
				pantheon_clear_edge_all();
			}
			wp_cache_flush();
			wp_redirect( admin_url( 'options-general.php?page=pantheon-cache&cache-cleared=true' ) );
			exit();
		}
	}


	/**
	 * Clear the cache for a post.
	 *
	 * @deprecated
	 *
	 * @param  int $post_id A post ID to clean.
	 * @return void
	 */
	public function clean_post_cache( $post_id, $include_homepage = true ) {
		if ( method_exists( 'Pantheon_Advanced_Page_Cache\Purger', 'action_clean_post_cache' ) ) {
			Pantheon_Advanced_Page_Cache\Purger::action_clean_post_cache( $post_id );
		}
	}


	/**
	 * Clear the cache for a given term or terms and taxonomy.
	 *
	 * @deprecated
	 *
	 * @param int|array $ids Single or list of Term IDs.
	 * @param string $taxonomy Can be empty and will assume tt_ids, else will use for context.
	 * @return void
	 */
	public function clean_term_cache( $term_ids, $taxonomy ) {
		if ( method_exists( 'Pantheon_Advanced_Page_Cache\Purger', 'action_clean_term_cache' ) ) {
			Pantheon_Advanced_Page_Cache\Purger::action_clean_term_cache( $term_ids );
		}
	}


	/**
	 * Clear the cache for a given term or terms and taxonomy.
	 *
	 * @deprecated
	 *
	 * @param int|array $object_ids Single or list of term object ID(s).
	 * @param array|string $object_type The taxonomy object type.
	 * @return void
	 */
	public function clean_object_term_cache( $object_ids, $object_type ) {
		// Handled by Pantheon Integrated CDN
	}

	/**
	 * Enqueue Fully-qualified urls to be cleared on shutdown.
	 *
	 * @param array|string $urls List of full urls to clear.
	 * @return void
	 */
	public function enqueue_urls( $urls ) {
		$paths = array();
		$urls = array_filter( (array) $urls, 'is_string' );
		foreach ( $urls as $full_url ) {
			# Parse down to the path+query, escape regex.
			$parsed = parse_url( $full_url );
			# Sometimes parse_url can return false, on malformed urls
			if (FALSE == $parsed) {
				continue;
			}
			# Build up the path, checking if the array key exists first
			if (array_key_exists('path', $parsed)) {
				$path = $parsed['path'];
				if (array_key_exists('query', $parsed))  {
					$path = $path . $parsed['query'];
				}
			}
			# If the path doesn't exist, set it to the null string
			else {
				$path = '';
			}
			if ( '' == $path ) {
				continue;
			}
			$path = '^' . preg_quote( $path ) . '$';
			$paths[] = $path;
		}

		$this->paths = array_merge( $this->paths, $paths );
	}

	/**
	 * Enqueue a regex to be cleared.
	 *
	 * You must understand regular expressions to use this, and be careful.
	 *
	 * @param string $regex path regex to clear.
	 * @return void
	 */
	public function enqueue_regex( $regex ) {
		$this->paths[] = $regex;
	}


	public function cache_clean_urls() {
		if ( empty( $this->paths ) )
			return;

		$this->paths = apply_filters( 'pantheon_clean_urls', array_unique( $this->paths ) );

		# Call the big daddy here
		$url = home_url();
		$host = parse_url( $url, PHP_URL_HOST );
		$this->paths = apply_filters( 'pantheon_final_clean_urls', $this->paths );
		if ( function_exists( 'pantheon_clear_edge_paths' ) ) {
			pantheon_clear_edge_paths( $this->paths );
		}
	}

	/**
	 * Sets maintenance mode status.
	 *
	 * Enable maintenance mode to work on your site while serving cached pages
	 * to visitors and bots, or everyone except administators.
	 *
	 * ## OPTIONS
	 *
	 * <status>
	 * : Maintenance mode status.
	 * ---
	 * options:
	 *   - disabled
	 *   - anonymous
	 *   - everyone
	 * ---
	 *
	 * @subcommand set-maintenance-mode
	 */
	public function set_maintenance_mode_command( $args ) {

		list( $status ) = $args;

		$out = $this->default_options;
		if ( ! empty( $status )
			&& in_array( $status, [ 'anonymous', 'everyone' ], true ) ) {
			$out['maintenance_mode'] = $status;
		} else {
			$out['maintenance_mode'] = 'disabled';
		}
		update_option( self::SLUG, $out );
		WP_CLI::success( sprintf( 'Maintenance mode set to: %s', $out['maintenance_mode'] ) );
	}
}



/**
 * Get a reference to the singleton.
 *
 * This can be used to reference public methods, e.g. `Pantheon_Cache()->clean_post_cache( 123 )`
 *
 * @return void
 */
function Pantheon_Cache() {
	return Pantheon_Cache::instance();
}
add_action( 'plugins_loaded', 'Pantheon_Cache' );


if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'pantheon-cache set-maintenance-mode', [ Pantheon_Cache::instance(), 'set_maintenance_mode_command' ] );
}

/**
 * @see Pantheon_Cache::clean_post_cache
 *
 * @deprecated Please call Pantheon Integrated CDN instead.
 */
function pantheon_clean_post_cache( $post_id, $include_homepage = true ) {
	Pantheon_Cache()->clean_post_cache( $post_id, $include_homepage );
}


/**
 * @see Pantheon_Cache::clean_term_cache
 *
 * @deprecated Please call Pantheon Integrated CDN instead.
 */
function pantheon_clean_term_cache( $term_ids, $taxonomy ) {
	Pantheon_Cache()->clean_term_cache( $term_ids, $taxonomy );
}


/**
 * @see Pantheon_Cache::enqueue_urls
 *
 * @deprecated Please call Pantheon Integrated CDN instead.
 */
function pantheon_enqueue_urls( $urls ) {
	Pantheon_Cache()->enqueue_urls( $urls );
}

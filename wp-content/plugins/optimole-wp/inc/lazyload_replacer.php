<?php

/**
 * The class handles the img tag replacements for lazyload.
 *
 * @package    \Optml\Inc
 * @author     Optimole <friends@optimole.com>
 */
final class Optml_Lazyload_Replacer extends Optml_App_Replacer {
	use Optml_Normalizer;
	use Optml_Validator;

	/**
	 * Cached object instance.
	 *
	 * @var Optml_Tag_Replacer
	 */
	protected static $instance = null;
	/**
	 * Holds classes for listening to lazyload on background.
	 *
	 * @var array Lazyload background classes.
	 */
	private static $lazyload_background_classes = null;
	/**
	 * Holds flags which remove noscript tag bundle causing issues on render, i.e slider plugins.
	 *
	 * @var array Noscript flags.
	 */
	private static $ignore_no_script_flags = null;
	/**
	 * Holds classes responsabile for watching lazyload behaviour.
	 *
	 * @var array Lazyload classes.
	 */
	private static $lazyload_watcher_classes = null;
	/**
	 * Should we use the generic placeholder?
	 *
	 * @var bool Lazyload placeholder flag.
	 */
	private static $is_lazyload_placeholder = false;

	/**
	 * Class instance method.
	 *
	 * @codeCoverageIgnore
	 * @static
	 * @return Optml_Tag_Replacer
	 * @since  1.0.0
	 * @access public
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_action( 'optml_replacer_setup', array( self::$instance, 'init' ) );
		}

		return self::$instance;
	}

	/**
	 * Returns background classes for lazyload.
	 *
	 * @return array
	 */
	public static function get_lazyload_bg_classes() {

		if ( null != self::$lazyload_background_classes && is_array( self::$lazyload_background_classes ) ) {
			return self::$lazyload_background_classes;
		}

		self::$lazyload_background_classes = apply_filters( 'optml_lazyload_bg_classes', [] );

		return self::$lazyload_background_classes;
	}

	/**
	 * Returns classes for lazyload additional watch.
	 *
	 * @return array
	 */
	public static function get_watcher_lz_classes() {

		if ( null != self::$lazyload_watcher_classes && is_array( self::$lazyload_watcher_classes ) ) {
			return self::$lazyload_watcher_classes;
		}

		self::$lazyload_watcher_classes = apply_filters( 'optml_watcher_lz_classes', [] );

		return self::$lazyload_watcher_classes;
	}

	/**
	 * The initialize method.
	 */
	public function init() {
		parent::init();

		if ( ! $this->settings->use_lazyload() ) {
			return;
		}
		if ( ! Optml_Filters::should_do_page( self::$filters[ Optml_Settings::FILTER_TYPE_LAZYLOAD ][ Optml_Settings::FILTER_URL ] ) ) {
			return;
		}

		add_filter(
			'max_srcset_image_width',
			function () {
				return 1;
			}
		);
		self::$is_lazyload_placeholder = self::$instance->settings->get( 'lazyload_placeholder' ) === 'enabled';
		add_filter( 'optml_tag_replace', array( $this, 'lazyload_tag_replace' ), 2, 6 );

	}

	/**
	 * Replaces the tags with lazyload tags.
	 *
	 * @param string $new_tag The new tag.
	 * @param string $original_url The original URL.
	 * @param string $new_url The optimized URL.
	 * @param array  $optml_args Options passed for URL optimization.
	 * @param bool   $is_slashed If the url needs slashes.
	 * @param string $full_tag Full tag, wrapper included.
	 *
	 * @return string
	 */
	public function lazyload_tag_replace( $new_tag, $original_url, $new_url, $optml_args, $is_slashed = false, $full_tag = '' ) {

		if ( ! $this->can_lazyload_for( $original_url, $full_tag ) ) {
			return Optml_Tag_Replacer::instance()->regular_tag_replace( $new_tag, $original_url, $new_url, $optml_args, $is_slashed );
		}
		if ( ! self::$is_lazyload_placeholder ) {
			$optml_args['quality'] = 'eco';
			$optml_args['resize']  = [];
			$low_url               = apply_filters( 'optml_content_url', $is_slashed ? stripslashes( $original_url ) : $original_url, $optml_args );
			$low_url               = $is_slashed ? addcslashes( $low_url, '/' ) : $low_url;
		} else {
			$low_url = self::get_svg_for(
				isset( $optml_args['width'] ) ? $optml_args['width'] : '100%',
				isset( $optml_args['height'] ) ? $optml_args['height'] : '100%'
			);
		}

		$opt_format = '';

		if ( $this->should_add_data_tag( $full_tag ) ) {
			$opt_format = ' data-opt-src="%s" ';
			$opt_format = $is_slashed ? addslashes( $opt_format ) : $opt_format;
		}

		$new_url = $is_slashed ? addcslashes( $new_url, '/' ) : $new_url;

		$opt_src = sprintf( $opt_format, $new_url );

		$no_script_tag = str_replace(
			$original_url,
			$new_url,
			$new_tag
		);
		$new_tag       = preg_replace(
			[
				'/( src(?>=|"|\'|\s|\\\\)*)' . preg_quote( $original_url, '/' ) . '/m',
				'/ src=/m',
			],
			[
				"$1$low_url",
				$opt_src . ' src=',
			],
			$new_tag,
			1
		);

		$new_tag = str_replace( 'srcset=', 'old-srcset=', $new_tag );

		if ( ! $this->should_add_noscript( $new_tag ) ) {
			return $new_tag;
		}

		return $new_tag . '<noscript>' . $no_script_tag . '</noscript>';
	}

	/**
	 * Check if the lazyload is allowed for this url.
	 *
	 * @param string $url Url.
	 * @param string $tag Html tag.
	 *
	 * @return bool We can lazyload?
	 */
	public function can_lazyload_for( $url, $tag = '' ) {
		foreach ( self::possible_lazyload_flags() as $banned_string ) {
			if ( strpos( $tag, $banned_string ) !== false ) {
				return false;
			}
		}

		if ( false === Optml_Filters::should_do_image( $url, self::$filters[ Optml_Settings::FILTER_TYPE_LAZYLOAD ][ Optml_Settings::FILTER_FILENAME ] ) ) {
			return false;
		}
		$url = strtok( $url, '?' );

		$type = wp_check_filetype(
			basename( $url ),
			Optml_Config::$extensions
		);

		if ( ! isset( $type['ext'] ) || empty( $type['ext'] ) ) {
			return false;
		}

		if ( false === Optml_Filters::should_do_extension( self::$filters[ Optml_Settings::FILTER_TYPE_LAZYLOAD ][ Optml_Settings::FILTER_EXT ], $type['ext'] ) ) {
			return false;
		}

		if ( defined( 'OPTML_DISABLE_PNG_LAZYLOAD' ) && OPTML_DISABLE_PNG_LAZYLOAD ) {
			return $type['ext'] !== 'png';
		}

		return true;
	}

	/**
	 * Check if we should add the data-opt-tag.
	 *
	 * @param string $tag Html tag.
	 *
	 * @return bool Should add?
	 */
	public function should_add_data_tag( $tag ) {
		foreach ( self::possible_data_ignore_flags() as $banned_string ) {
			if ( strpos( $tag, $banned_string ) !== false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get SVG markup with specific width/height.
	 *
	 * @param int $width Markup Width.
	 * @param int $height Markup Height.
	 *
	 * @return string SVG code.
	 */
	public static function get_svg_for( $width, $height ) {
		$width  = ! is_numeric( $width ) ? '100%' : $width;
		$height = ! is_numeric( $height ) ? '100%' : $height;

		static $SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="#width#" height="#height#" style=""> <rect id="backgroundrect" width="100%" height="100%" x="0" y="0" fill="#FFFFFF" stroke="none"/> <g style="" class="currentLayer"> <rect fill="#ffffff" stroke="#ffffff" stroke-width="2" stroke-linejoin="round" stroke-dashoffset="" fill-rule="nonzero"   style="color: rgb(0, 0, 0);" class="selected" stroke-opacity="1" fill-opacity="1"/></g></svg>';

		return 'data:image/svg+xml,' . rawurlencode(
			str_replace(
				[ '#width#', '#height#' ],
				[
					$width,
					$height,
				],
				$SVG
			)
		);
	}

	/**
	 * Check if we should add the noscript tag.
	 *
	 * @param string $tag Html tag.
	 *
	 * @return bool Should add?
	 */
	public function should_add_noscript( $tag ) {
		foreach ( self::get_ignore_noscript_flags() as $banned_string ) {
			if ( strpos( $tag, $banned_string ) !== false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns flags for ignoring noscript tag additional watch.
	 *
	 * @return array
	 */
	public static function get_ignore_noscript_flags() {

		if ( null != self::$ignore_no_script_flags && is_array( self::$ignore_no_script_flags ) ) {
			return self::$ignore_no_script_flags;
		}

		self::$ignore_no_script_flags = apply_filters( 'optml_ignore_noscript_on', [] );

		return self::$ignore_no_script_flags;
	}

	/**
	 * Throw error on object clone
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @codeCoverageIgnore
	 * @access public
	 * @return void
	 * @since  1.0.0
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'optimole-wp' ), '1.0.0' );
	}

	/**
	 * Disable unserializing of the class
	 *
	 * @codeCoverageIgnore
	 * @access public
	 * @return void
	 * @since  1.0.0
	 */
	public function __wakeup() {
		// Unserializing instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'optimole-wp' ), '1.0.0' );
	}

}

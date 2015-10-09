<?php
/*
Plugin Name: The Events Calendar - iCalendar Importer
Description: Imports events from remotely-located iCalendar files into The Events Calendar.
Author: r-a-y
Author URI: http://profiles.wordpress.org/r-a-y
Version: 0.1-alpha
License: GPL v2 or later
*/

/**
 * TEC iCal Importer
 *
 * @package TEC-ICAL
 * @subpackage Loader
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

add_action( 'plugins_loaded', array( 'TEC_iCal', 'init' ) );

/**
 * TEC iCal Importer Core
 *
 * @package TEC-ICAL
 * @subpackage Classes
 */
class TEC_iCal {
	/**
	 * Settings from database.
	 * @var array
	 */
	public static $settings;

	/**
	 * Absolute path to this plugin's directory.
	 * @var string
	 */
	public static $plugin_dir;

	/**
	 * URL to this plugin's directory.
	 * @var string
	 */
	public static $plugin_url;

	/**
	 * Internal name for this plugin.
	 * @var string
	 */
	protected $name = 'tec-ical';

	/**
	 * Cron interval used in {@link TEC_iCal::schedule_cron()}.
	 * @var string
	 */
	protected $cron_interval;

	/**
	 * Static initializer.
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! class_exists( 'Tribe__Events__Main' ) ) {
			return false;
		}

		// setup properties
		$this->setup_properties();

		// setup cron
		add_action( 'init', array( $this, 'setup_cron' ), 0  );

		// Set up admin area if in the WP dashboard
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			require self::$plugin_dir . '/includes/tec-ical-admin.php';
			TEC_iCal_Admin::init();
		}
	}

	/**
	 * Setup pertinent class properties.
	 */
	protected function setup_properties() {
		// load settings
		self::$settings = get_option( $this->name );

		// plugin properties
		self::$plugin_dir = dirname( __FILE__ );
		self::$plugin_url = plugin_dir_url( __FILE__ );
	}

	/**
	 * Setup cron.
	 */
	public function setup_cron() {
		// setup cron interval
		//
		// default cron interval
		if ( empty( self::$settings['custom-cron-interval'] ) ) {
			// default to daily
			// can also accept 'twicedaily' or 'hourly'
			$this->cron_interval = apply_filters( 'tec_ical_default_cron_interval', 'daily' );

		// custom cron interval
		} else {
			$this->cron_interval = 'tec_ical_custom';
			add_filter( 'cron_schedules', array( $this, 'custom_cron_schedule' ) );
		}

		// schedule cron
		$this->schedule_cron();

		// run cronjob when WP hits our scheduled task
		add_action( 'tec_ical_schedule', array( $this, 'run_cronjob' ) );
	}

	/**
	 * Add custom cron interval to WordPress if set.
	 *
	 * @param array $schedules The different cron schedules available
	 * @return array
	 */
	public function custom_cron_schedule( $schedules ) {
		$interval = self::$settings['custom-cron-interval'];

		$schedules[$this->cron_interval] = array(
			'interval' => (int) $interval * 60, // interval in seconds
			'display'  => sprintf( __( 'Every %s minutes', 'tec-ical' ), $interval )
		);

		return $schedules;
	}

	/**
	 * Schedule our task.
	 */
	public function schedule_cron() {
		if ( ! wp_next_scheduled( 'tec_ical_schedule' ) ) {
			wp_schedule_event( time(), $this->cron_interval, 'tec_ical_schedule' );
		}
	}

	/**
	 * Sync our iCalendars when WordPress hits our scheduled task.
	 */
	public function run_cronjob() {
		if ( ! class_exists( 'TEC_iCal_Parser' ) ) {
			require self::$plugin_dir . '/includes/tec-ical-parser.php';
		}

		TEC_iCal_Parser::init();
	}

	/**
	 * Get iCalendar data saved from settings.
	 *
	 * @return array
	 */
	public static function get_icals() {
		return ! empty( self::$settings['icals'] ) ? self::$settings['icals'] : array();
	}

}

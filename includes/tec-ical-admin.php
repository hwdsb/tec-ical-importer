<?php
/**
 * TEC iCal Importer Admin
 *
 * @package TEC-ICAL
 * @subpackage Classes
 */

/**
 * Admin settings for the plugin.
 */
class TEC_iCal_Admin {

	/**
	 * Internal name.
	 * @var string
	 */
	protected $name = 'tec-ical';

	/**
	 * Settings from database.
	 * @var array
	 */
	protected $settings;

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
		// register menu
		add_action( 'admin_menu', array( $this, 'menu' ) );

		// in order to use register_setting(), this must be done on 'admin_init'
		add_action( 'admin_init', array( $this, 'load_settings' ) );
	}

	/**
	 * Registers admin menu.
	 */
	public function menu() {
		// register admin menu
		$page = add_submenu_page(
			'edit.php?post_type=' . TribeEvents::POSTTYPE,
			__( 'iCalendar Importer','tec-ical' ),
			__( 'iCal Import','tec-ical' ),
			'administrator',
			'ical-importer',
			array( $this, 'contents' )
		);
	}

	/**
	 * Load settings.
	 */
	public function load_settings() {
		$this->settings = TEC_iCal::$settings;

		// handles niceties like nonces and form validation
		register_setting( $this->name, $this->name, array( $this, 'validate' ) );
	}

	/**
	 * Validate and sanitize our options before saving to the DB.
	 *
	 * Callback from register_setting().
	 *
	 * @param array $input The submitted values from the form
	 * @return array
	 */
	public function validate( $input ) {
		$messages = false;

		// existing, saved icals
		$icals = TEC_iCal::get_icals();

		// unverified icals
		$untested_icals = $this->array_diff_assoc_recursive( $input['icals'], $icals );
		$untested_icals = $this->array_remove_empty( $untested_icals );

		// validate untested icals
		if ( ! empty( $untested_icals ) ) {
			foreach ( $untested_icals as $key => $untested_ical ) {
				// iCalendar link
				if ( ! empty( $untested_ical['link'] ) ) {
					// google calendars need to switch to https
					// in order for the ical_exists() check to work properly
					if( strpos( $untested_ical['link'] , 'google.com/calendar' ) !== false ) {
						$untested_ical['link'] = $input['icals'][$key]['link'] = str_replace( 'http:', 'https:', $untested_ical['link'] );
					}

					// iCalendar does not exist
					if ( ! $this->ical_exists( $untested_ical['link'] ) ) {
						$messages["ical_error_{$key}"] = sprintf( __( '<strong>Error:</strong> Link for iCalendar #%d - "%s" - is invalid.  Please check the URL and make sure it is pointing to a valid iCalendar file.', 'tec-ical' ), $key + 1, esc_url( $untested_ical['link'] ) );

						// calendar link is invalid so reset saved value
						if ( ! empty( $icals[$key]['link'] ) ) {
							$input['icals'][$key]['link'] = $icals[$key]['link'];
						} else {
							unset( $input['icals'][$key]['link'] );
						}
					}
				}

				// category slug
				$input['icals'][$key]['category'] = sanitize_title( $untested_ical['category'] );
			}
		}

		// remove empty array values
		$input = $this->array_remove_empty( $input );

		// check if custom cron interval is numeric
		if ( ! empty( $input['custom-cron-interval'] ) && ! is_numeric( $input['custom-cron-interval'] ) ) {
			unset( $input['custom-cron-interval'] );

			$messages['cron_error'] = __( 'Error: Custom interval must be numeric.', 'tec-ical' );
		}

		// sync icalendars manually
		if ( ! empty( $input['manual-sync'] ) ) {
			unset( $input['manual-sync'] );

			// require our parser class
			if ( ! class_exists( 'TEC_iCal_Parser' ) ) {
				require TEC_iCal::$plugin_dir . '/includes/tec-ical-parser.php';
			}

			// parse the iCalendars
			$parser  = TEC_iCal_Parser::init( $input['icals'] );

			// get counts from parsed iCals
			$counts = $parser->get_counts();

			// set up feedback message
			$messages['parsed_results'] = '<strong>' . __( 'iCalendars successfully parsed', 'tec-ical' ) . '</strong></p><p>';

			foreach ( $counts as $i => $count ) {
				if ( ! empty( $count['added'] ) || ! empty( $count['updated'] ) ) {
					$messages['parsed_results'] .= '&bull; <strong>' . sprintf( __( 'iCalendar #%d', 'tec-ical' ), $i + 1 ) . '</strong>: ';
				}

				if ( ! empty( $count['added'] ) ) {
					$messages['parsed_results'] .= sprintf( _n( '1 event added', '%d events added', $count['added'], 'tec-ical' ), $count['added'] );
				}

				if ( ! empty( $count['added'] ) && ! empty( $count['updated'] ) ) {
					$messages['parsed_results'] .= ', ';
				}

				if ( ! empty( $count['updated'] ) ) {
					$messages['parsed_results'] .= sprintf( _n( '1 event updated', '%d events updated', $count['updated'], 'tec-ical' ), $count['updated'] );
				}

				if ( ! empty( $count['added'] ) || ! empty( $count['updated'] ) ) {
					$messages['parsed_results'] .= '.<br />';
				}
			}
		}

		// set error messages in our DB option
		// @see TEC_iCal_Admin::display_errors()
		if ( is_array( $messages ) ) {
			 $input['messages'] = $messages;
		}

		return $input;
	}

	/** FORM CONTENTS ******************************************************/

	public function contents() {
	?>

		<div class="wrap">
			<h2><?php _e( 'iCalendar Import', 'tec-ical' ) ?></h2>

			<?php $this->display_errors(); ?>

			<form action="options.php" method="post">
				<?php settings_fields( $this->name ); ?>

				<h3><?php _e( 'Calendar Configuration', 'tec-ical' ); ?></h3>

				<table class="form-table">
					<?php $this->render_ical_fields(); ?>
				</table>

				<?php submit_button( __( 'Save and Add Another', 'tec-ical' ) ); ?>

				<h3><?php _e( 'Other Settings', 'tec-ical' ) ?></h3>

				<table class="form-table">
					<?php $this->render_field(
						array(
							'name'      => 'custom-cron-interval',
							'labelname' => __( 'Custom interval', 'tec-ical' ),
							'desc'      => __( 'The length in minutes to check each iCalendar for updates.  Defaults to daily.', 'tec-ical' ),
							'size'      => 'small'
						) ) ?>

					<?php $this->render_field(
						array(
							'type'      => 'checkbox',
							'name'      => 'manual-sync',
							'labelname' => __( 'Manual sync', 'tec-ical' ),
							'desc'      => __( 'Check this box to manually sync the iCalendars above without waiting for the scheduled cron of WordPress to hit.', 'tec-ical' )
						) ) ?>
				</table>

				<?php submit_button( __( 'Save Changes', 'tec-ical' ) ); ?>
			</form>

			<!--
			<form action="https://www.paypal.com/cgi-bin/webscr" method="post" style="margin-left:-6px;">
				<input type="hidden" name="cmd" value="_s-xclick" />
				<input type="hidden" name="hosted_button_id" value="V9AUZCMECZEQJ" />
				<input title="<?php _e( 'If you\'re a fan of this plugin, support further development with a donation!', 'tec-ical' ); ?>" type="image" src="http<?php if ( is_ssl() ) echo 's'; ?>://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" name="submit" alt="PayPal - The safer, easier way to pay online!" />
				<img alt="" src="http<?php if ( is_ssl() ) echo 's'; ?>://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1" />
			</form>
			-->

		</div>

	<?php
	}

	/** FORM HELPERS *******************************************************/

	protected function render_ical_fields() {
		$ical_settings = TEC_iCal::get_icals();

		$count = ! empty( $ical_settings ) ? count( $ical_settings ) : 0;

		for( $i = 0; $i <= $count; ++$i ) {
			echo '<tr><td colspan="2" style="padding:0; line-height:0; border-bottom:1px solid #999;"><h4>' . sprintf( __( 'iCalendar #%d', 'tec-ical' ), $i + 1 ) . '</h4></td></tr>';

			// ugly way to support associative array field names
			$field_prefix = "icals][{$i}][";

			$this->render_field( array(
				'name'  => "{$field_prefix}link",
				'labelname' => __( 'iCalendar Link *', 'tec-ical' ),
				'desc'  => __( 'Enter the direct link where the iCalendar file is located.', 'tec-ical' ),
				'value' => ! empty( $ical_settings[$i]['link'] ) ? esc_url( $ical_settings[$i]['link'] ) : ''
			) );

			$this->render_field( array(
				'name'  => "{$field_prefix}category",
				'labelname' => __( 'Category Slug', 'tec-ical' ),
				'desc'  => sprintf( __( '(Optional) Enter the <a href="%s">event category</a> slug where imported events for this calendar should reside.  If the calendar slug does not exist, a new event category will be created.  If left blank, events will not be saved into any category.', 'tec-ical' ), admin_url( 'edit-tags.php?taxonomy=' . TribeEvents::TAXONOMY . '&posttype=' . TribeEvents::POSTTYPE ) ),
				'value' => ! empty( $ical_settings[$i]['category'] ) ? $ical_settings[$i]['category'] : ''
			) );
		}
	}

	/**
	 * Alternative approach to WP Settings API's add_settings_error().
	 *
	 * Show any messages/errors saved to a setting during validation in {@link TEC_iCal_Admin::validate()}.
	 * Used as a template tag.
	 *
	 * Uses a ['messages'] array inside $this->settings.
	 * Format should be ['messages'][{$id}_error], $id is the setting id.
	 *
	 * Lightly modified from Jeremy Clark's observations - {@link http://old.nabble.com/Re%3A-Settings-API%3A-Showing-errors-if-validation-fails-p26834868.html}
	 */
	protected function display_errors() {
		$option = $this->settings;

		// output error message(s)
		if ( ! empty( $option['messages'] ) && is_array( $option['messages'] ) ) {
			foreach ( (array) $option['messages'] as $id => $message ) {
				echo "<div id='message' class='error fade $id'><p>$message</p></div>";
				unset( $option['messages'][$id] );
			}

			update_option( $this->name, $option );

		// success!
		} elseif ( isset( $_REQUEST['settings-updated'] ) ) {
			echo '<div id="message" class="updated"><p>' . __( 'Settings updated successfully.', 'tec-ical' ) . '</p></div>';
		}
	}

	/**
	 * Renders the output of a form field in the admin area.
	 *
	 * I like this better than add_settings_field() so sue me!
	 *
	 * @uses TEC_iCal_Admin::field()
	 * @uses TEC_iCal_Admin::get_option()
	 *
	 * @param array $args Arguments for the field
	 */
	protected function render_field( $args = '' ) {
		$defaults = array(
			'type'      => 'text',    // text, password, checkbox, radio, dropdown
			'labelname' => '',        // the label for the field
			'labelfor'  => true,      // should <label> be used?
			'name'      => '',        // the input name of the field
			'desc'      => '',        // used to describe a checkbox, radio or option value
			'size'      => 'regular', // text field size - small,
			'value'     => '',        // pass a value to use as the default value
			'options'   => array()    // options for checkbox, radio, select - not used currently
		);

		$r = wp_parse_args( $args, $defaults );

		echo '<tr class="' . $this->field( $r['name'], true, false ). '">';

		if ( $r['labelfor'] ) {
			echo '<th scope="row"><label for="' . $this->field( $r['name'], true, false ) . '">' . $r['labelname'] . '</label></th>';
		} else {
			echo '<th scope="row">' . $r['labelname'] . '</th>';
		}

		echo '<td>';

		switch ( $r['type'] ) {
			case 'checkbox' :
			?>
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo $r['labelname']; ?></span></legend>

					<label for="<?php $this->field( $r['name'], true ) ?>">
						<input type="checkbox" name="<?php $this->field( $r['name'] ) ?>" id="<?php $this->field( $r['name'], true ) ?>" value="1" <?php if ( ! empty( $this->settings[$r['name']] ) ) checked( $this->settings[$r['name']], 1 ); ?> />

						<?php echo $r['desc']; ?>
				</label>
				<br />
				</fieldset>
			<?php
			break;

			case 'text' :
				$value = $this->get_option( $r['name'], false );

			?>
				<input class="<?php echo $r['size']; ?>-text" value="<?php echo $value; ?>" name="<?php $this->field( $r['name'] ) ?>" id="<?php $this->field( $r['name'], true ) ?>" type="<?php echo $r['type']; ?>" />
			<?php
				if ( $r['desc'] ) {
					echo '<p class="description">' . $r['desc'] . '</p>';
				}

			break;
		}

		echo '</td>';
		echo '</tr>';
	}

	/**
	 * Returns or outputs a field name / ID in the admin area.
	 *
	 * @param string $name Input name for the field.
	 * @param bool $id Are we outputting the field's ID?  If so, output unique ID.
	 * @param bool $echo Are we echoing or returning?
	 * @return mixed Either echo or returns a string
	 */
	protected function field( $name, $id = false, $echo = true ) {
		$name = $id ? "{$this->name}-" . sanitize_title( $name ) : "{$this->name}[$name]";

		if( $echo ) {
			echo $name;
		} else {
			return $name;
		}
	}

	/**
	 * Returns or outputs an admin setting in the admin area.
	 *
	 * Uses settings declared in $this->settings.
	 *
	 * @param string $name Name of the setting.
	 * @param bool $echo Are we echoing or returning?
	 * @return mixed Either echo or returns a string
	 */
	protected function get_option( $name, $echo = true ) {
		$val = '';

		if( is_array( $this->settings ) && isset( $this->settings[$name] ) )
			$val = $this->settings[$name];

		if( $echo ) {
			esc_attr_e( $val );
		} else {
			return esc_attr( $val );
		}
	}

	/** VALIDATION HELPERS *************************************************/

	/**
	 * Recursive method to remove empty array values.
	 *
	 * @see http://stackoverflow.com/a/7696597
	 */
	protected function array_remove_empty( $haystack ) {
		foreach ( $haystack as $key => $value ) {
			if ( is_array( $value ) ) {
				$haystack[$key] = $this->array_remove_empty( $haystack[$key] );
			}

			if ( empty( $haystack[$key] ) ) {
				unset( $haystack[$key] );
			}
		}

		return $haystack;
	}

	/**
	 * Recursive version of {@link array_diff_assoc()}.
	 *
	 * @see http://www.php.net/manual/en/function.array-diff-assoc.php#111675
	 */
	protected function array_diff_assoc_recursive( $array1, $array2 ) {
		$difference = array();

		foreach( $array1 as $key => $value ) {
			if( is_array($value) ) {
				if( ! isset( $array2[$key] ) || ! is_array( $array2[$key] ) ) {
					$difference[$key] = $value;
				} else {
					$new_diff = $this->array_diff_assoc_recursive( $value, $array2[$key] );

					if( ! empty( $new_diff ) ) {
						$difference[$key] = $new_diff;
					}
				}

			} elseif( ! array_key_exists( $key, $array2 ) || $array2[$key] !== $value ) {
				$difference[$key] = $value;
			}
		}

		return $difference;
	}

	/**
	 * Check to see if a remote iCalendar URL is indeed an iCalendar file.
	 *
	 * @return bool
	 */
	protected function ical_exists( $ical_url = '' ) {
		// get iCal headers
		$ical_headers = get_headers( $ical_url, 1 );

		if ( empty( $ical_headers ) ) {
			return false;
		}

		// Content-Type shouldn't be an array
		if ( ! empty( $ical_headers['Content-Type'] ) && is_array( $ical_headers['Content-Type'] ) ) {
			return false;
		}

		// link is not an iCalendar file, so stop!
		if ( strpos( $ical_headers['Content-Type'], 'text/calendar' ) ===  false ) {
			return false;
		}

		// link exists and is an iCal file
		return true;
	}
}
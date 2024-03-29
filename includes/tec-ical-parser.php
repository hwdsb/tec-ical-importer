<?php
/**
 * TEC iCal Importer Parser
 *
 * @package TEC-ICAL
 * @subpackage Classes
 */

/**
 * Main iCalendar parser class.
 */
class TEC_iCal_Parser {
	protected $counts = array();

	/**
	 * Static initializer.
	 */
	public static function init( $icals = array() ) {
		return new self( $icals );
	}

	/**
	 * Constructor.
	 *
	 * $icals should be formatted like:
	 *
	 * URL:
	 *   $icals[0]['link'] = 'URL TO ICALENDAR';
	 *
	 * Category slug where you want the event to be categorized
	 *   $icals[0]['category'] = 'category-slug';
	 *
	 * @param array $icals Array of iCalendar data. See phpDoc.
	 */
	public function __construct( $icals = array() ) {
		if ( ! class_exists( 'Tribe__Events__Main' ) ) {
			return false;
		}

		if ( ! class_exists( 'TEC_iCal' ) ) {
			return false;
		}

		$this->parse( $icals );
	}

	/**
	 * Do the iCalendar parsing.
	 */
	protected function parse( $icals = array() ) {
		// get icals
		$icals = ! empty( $icals ) ? $icals : TEC_iCal::get_icals();

		// no iCalendars, so stop now!
		if ( empty( $icals ) ) {
			return;
		}

		// require ics parser library
		if ( ! class_exists( 'ICal\ICal' ) ) {
			require_once TEC_iCal::$plugin_dir . '/vendor/autoload.php';
		}

		// add hook to update custom event meta
		add_action( 'tribe_events_update_meta', array( $this, 'ical_meta' ), 10, 2 );

		// If safe mode isn't on, then let's set the execution time to unlimited
		if ( ! ini_get( 'safe_mode' ) ) {
			set_time_limit( 0 );
		}

		// parse saved iCalendar data
		foreach( $icals as $key => $ical ) {
			// sanity check!
			if ( empty( $ical['link'] ) ) {
				continue;
			}

			// parse the actual iCalendar
			try {
				$parser = new ICal\ICal(
					$ical['link'],
					[
						'httpUserAgent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.4 Safari/605.1.15',
						'skipRecurrence' => true
					]
				);

			} catch ( \Exception $e ) {
				die( $e );
			}
//var_dump( $parser->events() ); die();
			$updated_count = 0;
			$added_count   = 0;

			$uids = array();

			$timezone = $parser->calendarTimeZone();
			if ( ! empty( $timezone ) ) {
				$timezone = $this->validate_timezone( $timezone );
			} else {
				$timezone = '';
			}

			// parse each iCalendar event
			foreach ( (array) $parser->events() as $event ) {
				// record the UID for later use
				$uids[] = $event->uid;

				// last modified
				$last_modified = $event->last_modified;
				if ( empty( $last_modified ) ) {
					$last_modified = '';
				}

				// setup default args
				$args = array(
					'post_title'         => $event->summary,
					'post_status'        => 'publish',
					'post_content'       => (string) $event->description,
					'ical_uid'           => $event->uid,
					'ical_last_modified' => $last_modified,
				        'ical_sequence'      => $event->sequence,
				        'ical_link'          => $ical['link'],

					// saving for posterity's sake; these are unix timestamps
				        'ical_start_timestamp' => $event->dtstart_array[2],
				        'ical_end_timestamp'   => $event->dtend_array[2],

					// default to show in calendar
					'EventShowInCalendar'  => 1
				);

				// recurrence - for PRO version only
				if ( class_exists( 'Tribe__Events__Pro__Main' ) ) {
					$rrule = $event->rrule;
					if ( ! empty( $rrule ) ) {
						$args['recurrence'] = $this->get_recurrence_data( $rrule );
					}
				}

				// set event timezone
				$date_convert_args = array();
				$event_tz = $event->tzid;
				if ( ! empty( $event_tz ) ) {
					$event_tz = $this->validate_timezone( $event_tz );

					if ( ! empty( $event_tz ) ) {
						$args['EventTimezone'] = $event_tz;
					}
				}
				if ( empty( $args['EventTimezone'] ) && ! empty( $timezone ) ) {
					$args['EventTimezone'] = $timezone;
				}
				if ( ! empty( $args['EventTimeZone'] ) )  {
					$date_convert_args['timezone'] = $args['EventTimeZone'];
				} else {
					$date_convert_args['offset'] = get_option( 'gmt_offset' );
				}

				// whole day event?
				if ( $this->isWholeDay( $event ) ) {
					$args['EventAllDay']         = 'yes';
					$date_convert_args['offset'] = 0;
				}

				// convert unix date to proper date with timezone factored in
				$startdate = self::convert_unix_timestamp_to_date( $args['ical_start_timestamp'], $date_convert_args );

				// for whole day events, iCal spec adds a day, but for The Events Calendar,
				// we need the enddate to be the same as the startdate
				if ( $this->isWholeDay( $event ) ) {
					$enddate = $startdate;
				} else {
					$enddate = self::convert_unix_timestamp_to_date( $args['ical_end_timestamp'], $date_convert_args );
				}

				// save event date / time
				$args['EventStartDate']     = Tribe__Date_Utils::date_only( $startdate );
				$args['EventStartHour']     = Tribe__Date_Utils::hour_only( $startdate );
				$args['EventStartMinute']   = Tribe__Date_Utils::minutes_only( $startdate );
				$args['EventStartMeridian'] = Tribe__Date_Utils::meridian_only( $startdate );
				$args['EventEndDate']       = Tribe__Date_Utils::date_only( $enddate );
				$args['EventEndHour']       = Tribe__Date_Utils::hour_only( $enddate );
				$args['EventEndMinute']     = Tribe__Date_Utils::minutes_only( $enddate );
				$args['EventEndMeridian']   = Tribe__Date_Utils::meridian_only( $enddate );

				/** FOR LATER? **/
				//$args['Venue'] = $event->getProperty( 'location' );

				// hide from event listings
				//$args['EventHideFromUpcoming'] = 'yes';

				// $event->isBlocking() - uses TRANSP;
				// $event->isConfirmed() - uses STATUS;

				// Events Calendar adds a bunch of stuff to WP_Query for event queries
				// we don't want their injections, so remove it here
				remove_action( 'parse_query', array( 'Tribe__Events__Query', 'parse_query' ), 50 );

				// try to find out if the event already exists
				$existing_event = new WP_Query( array(
					'post_type'  => Tribe__Events__Main::POSTTYPE,
					'meta_key'   => '_tec_ical_uid',
					'meta_value' => $event->uid,

					// decreases the complexity of the SQL query; good for performance
					'nopaging'   => true,
					'orderby'    => 'none'
				) );

				// existing event exists!
				// check if there are any updates
				if ( ! empty( $existing_event->post->ID ) ) {

					// there are new updates, so update event
					if ( $last_modified !== get_post_meta( $existing_event->post->ID, '_tec_ical_last_modified', true ) ) {
						// iterate count
						++$updated_count;

						// apply a filter just in case!
						$args = apply_filters( 'tec_ical_update_event_args', $args, $event, $existing_event->post->ID );

						// update the event
						tribe_update_event( $existing_event->post->ID, $args );
					}


				// create new event
				} else {
					// iterate count
					++$added_count;

					// set the post type, it's REQUIRED for an update
					$args["post_type"] = Tribe__Events__Main::POSTTYPE;

					// apply a filter just in case!
					$args = apply_filters( 'tec_ical_create_event_args', $args, $event );

					// create it!
					$post_id = tribe_create_event( $args );

					// save category if set in admin area
					if ( ! empty( $ical['category'] ) ) {
						wp_set_object_terms( $post_id, $ical['category'], Tribe__Events__Main::TAXONOMY );
					}

					// hook for developers!
					do_action( 'tec_ical_created_event', $post_id, $args, $event );
				}

			}

			// check if any events have been deleted
			$deleted_event_ids = $this->get_deleted_event_ids_for_ical( $ical['link'], $uids );

			// delete events that have been removed from the iCal
			if ( ! empty( $deleted_event_ids ) ) {
				foreach( $deleted_event_ids as $event_id ) {
					tribe_delete_event( $event_id, true );
				}
			}

			// update counts
			$this->counts[$key]['updated'] = $updated_count;
			$this->counts[$key]['added']   = $added_count;

		}

		remove_action( 'tribe_events_update_meta', array( $this, 'ical_meta' ), 10, 2 );

	}

	/**
	 * Match iCal recurrence data to Event Calendar PRO's version of recurrence.
	 *
	 * @param  string $rrule Event's RRULE string from iCalendar.
	 * @return array
	 */
	protected function get_recurrence_data( $rrule ) {
		$data = array();
		$rules = array();

		$rrule = $this->parse_rrule( $rrule );

		// interval
		$interval = ! empty( $rrule['INTERVAL'] ) ? (int) $rrule['INTERVAL'] : 1;

		// occurrences
		if ( ! empty( $rrule['COUNT'] ) ) {
			$rules['end-count'] = $rrule['COUNT'];
			$rules['end-type']  = 'After';

		// until
		// @todo this needs testing
		} elseif ( ! empty( $rrule['UNTIL'] ) ) {
			$until = new DateTime( $rrule['UNTIL'] );
			$rules['end-type'] = 'On';
			$rules['end']      = $until->format( 'Y-m-d' );

		// event is infinite
		} else {
			$rules['end-type'] = 'Never';
		}

		// get by___ properties
		$eventby = array();
		foreach ( $rrule as $key => $val ) {
			if ( 'BY' == substr( $key, 0, 2 ) ) {
				$eventby[$key] = explode( ',', $val );
			}
		}

		// custom recurring event
		//
		// Events Calendar PRO doesn't support crazy, advanced recurring events
		// View latter examples @ http://www.kanzaki.com/docs/ical/rrule.html#example
		$rules['type'] = 'Custom';
		$rules['custom'] = array();
		$rules['custom']['type'] = ucfirst( strtolower( $rrule['FREQ'] ) );
		$rules['custom']['interval'] = $interval;

		// TEC needs the number to be a string... wasted an hour here
		$this->day_to_number = array(
			'MO' => '1',
			'TU' => '2',
			'WE' => '3',
			'TH' => '4',
			'FR' => '5',
			'SA' => '6',
			'SU' => '7'
		);

		// grab event BY___ properties
		switch( strtolower( $rrule['FREQ'] ) ) {
			case 'daily' :
				$rules['custom']['same-time'] = 'yes';
				break;

			case 'weekly' :
				if ( ! empty( $eventby['BYDAY'] ) ) {
					$rules['custom']['week'] = array();
					$rules['custom']['week']['same-time'] = 'yes';

					foreach( $eventby['BYDAY'] as $eday ) {
						if ( isset( $this->day_to_number[$eday] ) ) {
							$rules['custom']['week']['day'][] = $this->day_to_number[$eday];
						}
					}
				}
				break;

			case 'monthly' :
				if ( ! empty( $eventby['BYMONTHDAY'] ) ) {
					$rules['custom']['month'] = array();
					$rules['custom']['month']['same-time'] = 'yes';

					// EVP only supports one condition and not multiple
					// so we only grab the first condition...
					$monthday = $eventby['BYMONTHDAY'][0];

					// the last nth day of the month
					// EVP supports the last day only; it doesn't values less than -1, so we only
					// check for the last day...
					if( '-' == substr( $monthday, 0, 1 ) && 1 == substr( $monthday, 1 ) && 2 == strlen( $monthday ) ) {
						$rules['custom']['month']['number'] = 'Last';
						$rules['custom']['month']['day']    = -1;

					// the first day of the month
					} elseif ( 1 == strlen( $monthday ) && 1 == $monthday ) {
						$rules['custom']['month']['number'] = 'First';
						$rules['custom']['month']['day']    = -1;

					// the nth day of the month
					} else {
						$rules['custom']['month']['number'] = $monthday;
					}


				// EVP only supports one condition and not multiple
				// so we only grab the first condition...
				} elseif ( ! empty( $eventby['BYDAY'] ) ) {
					$day = $this->get_recurrence_by_day_data( $eventby['BYDAY'][0] );

					if ( ! empty( $day ) ) {
						$rules = array_merge( $rules, $day );
					}
				}
				break;

			case 'yearly' :
				if ( ! empty( $eventby['BYMONTH'] ) ) {
					$rules['custom-year-month'] = $eventby['BYMONTH'];
				}

				if ( ! empty( $eventby['BYDAY'] ) ) {
					// EVP only supports one condition and not multiple
					// so we only grab the first condition...
					$day = $this->get_recurrence_by_day_data( $eventby['BYDAY'][0], 'year' );

					if ( ! empty( $day ) ) {
						$rules = array_merge( $rules, $day );
					}
				}
				break;
		}

		$data['rules'][] = $rules;

		return $data;
	}

	/**
	 * Match iCal recurrence day data to Event Calendar PRO's version.
	 *
	 * @param string $day  The iCal day data
	 * @param string $type Either 'month' or 'year'. Default: 'month'
	 * @return array
	 */
	protected function get_recurrence_by_day_data( $day = '', $type = 'month' ) {
		$retval = array();

		$nominal_to_ordinal_number = array(
			'1' => 'First',
			'2' => 'Second',
			'3' => 'Third',
			'4' => 'Fourth',
			'5' => 'Fifth',
		);

		// last _day of the month
		if( '-' == substr( $day, 0, 1 ) ) {
			$daysuffix = substr( $day, 1 );

			// EVP only supports the last _day of the month not last nth _day of the month
			if ( ! is_numeric( $daysuffix ) ) {
				$data['number'] = 'Last';
				$data['day']    = $this->day_to_number[$daysuffix];
			}

		// nth _day of the month
		} else {
			$dayprefix = substr( $day, 0, 1 );

			if ( is_numeric( $dayprefix ) ) {
				$day = substr( $day, 1 );
				$data['number'] = $nominal_to_ordinal_number[$dayprefix];
				$data['day']    = $this->day_to_number[$day];

			// every month, every _day
			// this condition doesn't exist in EVP at the moment
			} else {
				// BLANK FOR NOW
			}
		}

		if ( 'month' === $type ) {
			$retval['custom']['month'] = $data;
			$retval['custom']['month']['same-time'] = 'yes';
		} else {
			$retval['custom']['year']['month'] = $data;
			$retval['custom']['year']['month']['same-time'] = 'yes';
		}

		return $retval;
	}

	/**
	 * Validate timezone.
	 *
	 * Only let through tz timezones. We do this by checking if the timezone
	 * has a space in it.  If it does, it's not valid.
	 *
	 * @see https://en.wikipedia.org/wiki/Tz_database
	 *
	 * @param  string $tz Timezone string
	 * @return string
	 */
	protected function validate_timezone( $tz = '' ) {
		if ( ! empty( $tz ) ) {
			// we do not support non-standard tz timezones
			// in this case, TEC will revert to WP's current timezone
			if ( false !== strpos( $tz, ' ' ) ) {
				$tz = '';
			}
		} else {
			$tz = '';
		}

		return $tz;
	}

	/**
	 * Returns an array of the number of events updated/added during parsing for
	 * each iCalendar.
	 *
	 * Should be used after this class is instantiated.
	 *
	 * @return array
	 */
	public function get_counts() {
		return $this->counts;
	}

	/**
	 * Get the deleted event post IDs for an iCalendar.
	 *
	 * When we check the iCal file during sync, we have to check the current UIDs
	 * in the iCal file against the UIDs that are in the database.  Then, we can
	 * determine if there are any events that should be marked for deletion.
	 *
	 * @param string $ical The iCal link used to import the events.
	 * @param array $current_uids The current UIDs from the current iCal
	 * @return array Array of post IDs that should be deleted.
	 */
	public function get_deleted_event_ids_for_ical( $ical = '', $current_uids = array() ) {
		global $wpdb;

		$parsed_uids = array();
		foreach( $current_uids as $uid ) {
			$parsed_uids[] = $wpdb->prepare( '%s', $uid );
		}

		$parsed_uids = implode( ',', $parsed_uids );

		if ( ! empty( $parsed_uids ) ) {
			$not_in_sql = " AND mv2.meta_value NOT IN ( {$parsed_uids} )";
		} else {
			$not_in_sql = '';
		}

		$query = $wpdb->get_col( "
			SELECT mv2.post_id
				FROM {$wpdb->postmeta} mv1
				INNER JOIN {$wpdb->postmeta} mv2
			WHERE ( mv2.meta_key = '_tec_ical_uid'{$not_in_sql} ) AND
				( mv1.meta_value = '{$ical}' AND mv1.meta_key = '_tec_ical_link' ) AND
				mv1.post_id = mv2.post_id
		" );

		return $query;
	}

	/**
	 * Sets pertinent iCalendar data as post meta.
	 *
	 * @param int $post_id The post ID for the event
	 * @param array $data The event data.
	 */
	public function ical_meta( $post_id, $data ) {
		if ( empty( $data['ical_uid'] ) ) {
			return;
		}

		update_post_meta( $post_id, '_tec_ical_link',            $data['ical_link'] );
		update_post_meta( $post_id, '_tec_ical_uid',             $data['ical_uid'] );
		update_post_meta( $post_id, '_tec_ical_last_modified',   $data['ical_last_modified'] );
		update_post_meta( $post_id, '_tec_ical_sequence',        $data['ical_sequence'] );
		update_post_meta( $post_id, '_tec_ical_start_timestamp', $data['ical_start_timestamp'] );
		update_post_meta( $post_id, '_tec_ical_end_timestamp',   $data['ical_end_timestamp'] );
	}

	/**
	 * Converts a unix timestamp to a formatted date.
	 *
	 * @param int   $unix_timestamp The unix timestamp
	 * @param array $args {
	 *     An array of arguments. All items are optional.
	 *     @type string $offset   A string ranging from '-12' to '+12' denoting the hourly offset. Default: 0.
	 *     @type string $timezone Olson timezone.  For example, 'America/Toronto' is a valid timezone.
	 *     @type string $format   Date format.  Same as used in date(). Default: 'c'.
	 * }
	 * @param string $gmt_offset A string ranging from '-12' to '+12' denoting the hourly offset.
	 * @param string $format The date format. See first parameter of {@link date()}.
	 * @return string The formatted date as a string.
	 */
	public static function convert_unix_timestamp_to_date( $unix_timestamp, $args = array() ) {
		$args = array_merge( array(
			'offset' => 0,
			'format' => 'c',
		), $args );

		$date = new DateTime( date( $args['format'], $unix_timestamp ), new DateTimeZone( 'UTC' ) );

		// Use offset if passed.
		if ( isset( $args['offset'] ) ) {
			$date->modify( $args['offset'] . ' hours' );

		// Use timezone if passed. Must be valid Olson timezone.
		} elseif ( isset( $args['timezone'] ) ) {
			$timezone = new DateTimeZone( $args['timezone'] );
			$date->setTimezone( $timezone );
		}

		return $date->format( $args['format'] );
	}

	/**
	 * Check if event occurs for the whole day.
	 *
	 * @param  object $event Event data.
	 * @return bool
	 */
	public function isWholeDay( $event ) {
		// Outlook iCal has a handy header, so check for that.
		$microsoft_header = filter_var( $event->x_microsoft_cdo_alldayevent, FILTER_VALIDATE_BOOLEAN );
		if ( ! empty( $microsoft_header ) ) {
			return true;
		}

		// Check by duration. Logic taken from SG_iCalendar.
		$dur = $event->dtend_array[2] - $event->dtstart_array[2];
		if ( $dur > 0 && ( $dur % 86400 ) == 0 ) {
			return true;
		}

		return false;
	}

	/**
	 * Parse an event's 'RRULE' header to an array.
	 *
	 * @param  string $rrule RRULE string
	 * @return array
	 */
	public function parse_rrule( $rrule = '' ) {
		$rrule = str_replace( ';', '&', $rrule );
		parse_str( $rrule, $rrule);
		return $rrule;
	}
}

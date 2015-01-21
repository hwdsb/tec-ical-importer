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
		if ( ! class_exists( 'TribeEvents' ) ) {
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

		// require SG_iCalendar parser library
		if ( ! class_exists( 'SG_iCalReader' ) ) {
			require TEC_iCal::$plugin_dir . '/includes/SG-iCalendar/SG_iCal.php';
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
			$parser = new SG_iCalReader( $ical['link'] );

			$updated_count = 0;
			$added_count   = 0;

			$uids = array();

			// parse each iCalendar event
			foreach ( $parser->getEvents() as $event ) {
/* Sample event data
SG_iCal_VEvent Object
(
    [uid:protected] => t7m727q9g0gkqtp7md1jh58g2g@google.com
    [start:protected] => 1408645800
    [end:protected] => 1408649400
    [summary:protected] => Recurring Event
    [description:protected] => Recurring every Thursday.
    [location:protected] =>
    [laststart:protected] => 1502994991
    [lastend:protected] => 1502998591
    [recurrence] => SG_iCal_Recurrence Object
        (
            [rrule] => FREQ=WEEKLY;BYDAY=TH
            [freq:protected] => WEEKLY
            [until:protected] => 20170817T113631-0700
            [count:protected] =>
            [interval:protected] =>
            [bysecond:protected] =>
            [byminute:protected] =>
            [byhour:protected] =>
            [byday:protected] => Array
                (
                    [0] => TH
                )

            [bymonthday:protected] =>
            [byyearday:protected] =>
            [byweekno:protected] =>
            [bymonth:protected] =>
            [bysetpos:protected] =>
            [wkst:protected] =>
            [listProperties:protected] => Array
                (
                    [0] => bysecond
                    [1] => byminute
                    [2] => byhour
                    [3] => byday
                    [4] => bymonthday
                    [5] => byyearday
                    [6] => byweekno
                    [7] => bymonth
                    [8] => bysetpos
                )

        )

    [recurex] =>
    [excluded] =>
    [added] =>
    [freq] =>
    [data] => Array
        (
            [dtstamp] => 20140817T183555Z
            [created] => 20140817T183037Z
            [last-modified] => 20140817T183037Z
            [sequence] => 0
            [status] => CONFIRMED
            [transp] => OPAQUE
        )

    [previous_tz] => UTC
    [tzid] => America/Vancouver
)
*/

				// record the UID for later use
				$uids[] = $event->getProperty( 'uid' );

				// get gmt offset
				$gmt_offset = get_option( 'gmt_offset' );

				// setup default args
				$args = array(
					'post_title'    => $event->getProperty( 'summary' ),
					'post_status'   => 'publish',
					'post_content'  => $event->getProperty( 'description' ),
					'ical_uid'      => $event->getProperty( 'uid' ),
				        'ical_sequence' => $event->getProperty( 'sequence' ),
				        'ical_link'     => $ical['link'],

					// saving for posterity's sake; these are unix timestamps
				        'ical_start_timestamp' => $event->getProperty( 'start' ),
				        'ical_end_timestamp'   => $event->getProperty( 'end' ),

					// default to show in calendar
					'EventShowInCalendar'  => 1
				);

				// recurrence - for PRO version only
				if ( class_exists( 'TribeEventsPro' ) && ! empty( $event->recurrence ) ) {
					$args['recurrence'] = $this->get_recurrence_data( $event->recurrence );
				}

				// whole day event?
				if ( $event->isWholeDay() ) {
					$args['EventAllDay'] = 'yes';
					$gmt_offset = 0;
				}

				// convert unix date to proper date with timezone factored in
				$startdate = self::convert_unix_timestamp_to_date( $event->getProperty( 'start' ), $gmt_offset );

				// for whole day events, iCal spec adds a day, but for The Events Calendar,
				// we need the enddate to be the same as the startdate
				if ( $event->isWholeDay() ) {
					$enddate = $startdate;
				} else {
					$enddate = self::convert_unix_timestamp_to_date( $event->getProperty( 'end' ), $gmt_offset );
				}

				// save event date / time
				$args['EventStartDate']   = TribeDateUtils::dateOnly( $startdate );
				$args['EventStartHour']   = TribeDateUtils::hourOnly( $startdate );
				$args['EventStartMinute'] = TribeDateUtils::minutesOnly( $startdate );
				$args['EventEndDate']     = TribeDateUtils::dateOnly( $enddate );
				$args['EventEndHour']     = TribeDateUtils::hourOnly( $enddate );
				$args['EventEndMinute']   = TribeDateUtils::minutesOnly( $enddate );

				/** FOR LATER? **/
				//$args['Venue'] = $event->getProperty( 'location' );

				// hide from event listings
				//$args['EventHideFromUpcoming'] = 'yes';

				// $event->isBlocking() - uses TRANSP;
				// $event->isConfirmed() - uses STATUS;

				// Events Calendar adds a bunch of stuff to WP_Query for event queries
				// we don't want their injections, so remove it here
				remove_action( 'parse_query', array( 'TribeEventsQuery', 'parse_query' ), 50 );

				// try to find out if the event already exists
				$existing_event = new WP_Query( array(
					'post_type'  => TribeEvents::POSTTYPE,
					'meta_key'   => '_tec_ical_uid',
					'meta_value' => $event->getProperty( 'uid' ),

					// decreases the complexity of the SQL query; good for performance
					'nopaging'   => true,
					'orderby'    => 'none'
				) );

				// existing event exists!
				// check if there are any updates
				if ( ! empty( $existing_event->post->ID ) ) {
					$existing_sequence = (int) get_post_meta( $existing_event->post->ID, '_tec_ical_sequence', true );

					// there are new updates, so update event
					if ( $event->getProperty( 'sequence' ) > $existing_sequence ) {
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

					// apply a filter just in case!
					$args = apply_filters( 'tec_ical_create_event_args', $args, $event );

					// create it!
					$post_id = tribe_create_event( $args );

					// save category if set in admin area
					if ( ! empty( $ical['category'] ) ) {
						wp_set_object_terms( $post_id, $ical['category'], TribeEvents::TAXONOMY );
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
	 * @param SG_iCal_Recurrence $event
	 * @return array
	 */
	protected function get_recurrence_data( $event ) {
		$data = array();

		// interval
		$interval = $event->getInterval() ? (int) $event->getInterval() : 1;

		// occurences
		if ( $event->getCount() ) {
			$data['end-count'] = $event->getCount();
			$data['end-type']  = 'After';

		// until
		// @todo this needs testing
		} elseif ( $event->getUntil() ) {
			$data['end-type'] = 'On';
			$data['end']      = self::convert_unix_timestamp_to_date(
				strtotime( $event->getUntil() ),
				get_option( 'gmt_offset' ),
				'Y-m-d'
			);

		// event is infinite
		} else {
			$data['end-type'] = 'Never';
		}

		// get by___ properties
		$eventby = array();
		$eventbyprops = array(
			'GetByDay', 'GetByMonthDay', 'GetByYearDay', 'GetByWeekNo', 'GetByMonth', 'GetBySetPos'
		);
		foreach ( $eventbyprops as $prop ) {
			if ( $event->$prop() ) {
				// stupid SG-iCalendar...
				$key = strtolower( str_replace( 'Get', '', $prop ) );

				$eventby[$key] = $event->$prop();
			}
		}

		// simple recurring event
		if( 1 === $interval && empty( $eventby ) ) {
			// set type
			switch( strtolower( $event->getFreq() ) ) {
				case 'daily' :
					$type = 'Every Day';
					break;

				case 'weekly' :
					$type = 'Every Week';
					break;

				case 'monthly' :
					$type = 'Every Month';
					break;

				case 'yearly' :
					$type = 'Every Year';
					break;
			}

			$data['type'] = $type;
			$data['occurrence-count-text'] = strtolower( str_replace( 'Every ', '', $type ) );

		// custom recurring event
		//
		// Events Calendar PRO doesn't support crazy, advanced recurring events
		// View latter examples @ http://www.kanzaki.com/docs/ical/rrule.html#example
		} else {
			$data['type'] = 'Custom';
			$data['custom-interval'] = $interval;
			$data['occurrence-count-text'] = 'event';

			$data['custom-type'] = ucfirst( strtolower( $event->getFreq() ) );

			$this->day_to_number = array(
				'MO' => 1,
				'TU' => 2,
				'WE' => 3,
				'TH' => 4,
				'FR' => 5,
				'SA' => 6,
				'SU' => 7
			);

			// grab event BY___ properties
			switch( strtolower( $event->getFreq() ) ) {
				case 'weekly' :
					$data['custom-type-text'] = __( 'Week(s) on:', 'tribe-events-calendar-pro' );

					if ( ! empty( $eventby['byday'] ) ) {
						$data['custom-week-day'] = array();

						foreach( $eventby['byday'] as $eday ) {
							if ( isset( $this->day_to_number[$eday] ) ) {
								$data['custom-week-day'][] = $this->day_to_number[$eday];
							}
						}
					}
					break;

				case 'monthly' :
					$data['custom-type-text'] = __( 'Month(s) on the:','tribe-events-calendar-pro' );

					if ( ! empty( $eventby['bymonthday'] ) ) {
						// EVP only supports one condition and not multiple
						// so we only grab the first condition...
						$monthday = $eventby['bymonthday'][0];

						// the last nth day of the month
						// EVP supports the last day only; it doesn't values less than -1, so we only
						// check for the last day...
						if( '-' == substr( $monthday, 0, 1 ) && 1 == substr( $monthday, 1 ) && 2 == strlen( $monthday ) ) {
							$data['custom-month-number'] = 'Last';
							$data['custom-month-day']    = -1;

						// the first day of the month
						} elseif ( 1 == strlen( $monthday ) && 1 == $monthday ) {
							$data['custom-month-number'] = 'First';
							$data['custom-month-day']    = -1;

						// the nth day of the month
						} else {
							$data['custom-month-number'] = $monthday;
						}


					// EVP only supports one condition and not multiple
					// so we only grab the first condition...
					} elseif ( ! empty( $eventby['byday'] ) ) {
						$day = $this->get_recurrence_by_day_data( $eventby['byday'][0] );

						if ( ! empty( $day ) ) {
							$data = array_merge( $data, $day );
						}
					}
					break;

				case 'yearly' :
					$data['custom-type-text'] = __( 'Year(s) on:','tribe-events-calendar-pro' );

					if ( ! empty( $eventby['bymonth'] ) ) {
						$data['custom-year-month'] = $eventby['bymonth'];
					}

					if ( ! empty( $eventby['byday'] ) ) {
						// EVP only supports one condition and not multiple
						// so we only grab the first condition...
						$day = $this->get_recurrence_by_day_data( $eventby['byday'][0], 'custom-year-month-number', 'custom-year-month-day' );

						if ( ! empty( $day ) ) {
							$data = array_merge( $data, $day );
						}
					}
					break;
			}
		}

		return $data;
	}

	/**
	 * Match iCal recurrence day data to Event Calendar PRO's version.
	 *
	 * @param string $day The iCal day data
	 * @param string $month_number_key The key used in EVP to set the month number
	 * @param string $month_day_key The key used in EVP to set the month day
	 * @return array
	 */
	protected function get_recurrence_by_day_data( $day = '', $month_number_key = 'custom-month-number', $month_day_key = 'custom-month-day' ) {
		$data = array();

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
				$data[$month_number_key] = 'Last';
				$data[$month_day_key]    = $this->day_to_number[$daysuffix];
			}

		// nth _day of the month
		} else {
			$dayprefix = substr( $day, 0, 1 );

			if ( is_numeric( $dayprefix ) ) {
				$day = substr( $day, 1 );
				$data[$month_number_key] = $nominal_to_ordinal_number[$dayprefix];
				$data[$month_day_key]    = $this->day_to_number[$day];

			// every month, every _day
			// this condition doesn't exist in EVP at the moment
			} else {
				// BLANK FOR NOW
			}
		}

		return $data;
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
		update_post_meta( $post_id, '_tec_ical_sequence',        $data['ical_sequence'] );
		update_post_meta( $post_id, '_tec_ical_start_timestamp', $data['ical_start_timestamp'] );
		update_post_meta( $post_id, '_tec_ical_end_timestamp',   $data['ical_end_timestamp'] );
	}

	/**
	 * Converts a unix timestamp to a formatted date.  Accepts GMT offset.
	 *
	 * @param int $unix_timestamp The unix timestamp
	 * @param string $gmt_offset A string ranging from '-12' to '+12' denoting the hourly offset.
	 * @param string $format The date format. See first parameter of {@link date()}.
	 * @return string The formatted date as a string.
	 */
	public static function convert_unix_timestamp_to_date( $unix_timestamp, $gmt_offset = '0' , $format = 'c' ) {
		$date = new DateTime( date( $format, $unix_timestamp ), new DateTimeZone( 'UTC' ) );
		$date->modify( $gmt_offset . ' hours' );
		return $date->format( $format );
	}
}
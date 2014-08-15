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

		// get gmt offset
		$gmt_offset = get_option( 'gmt_offset' );

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

			// parse each iCalendar event
			foreach ( $parser->getEvents() as $event ) {

				/*
				    [0] => SG_iCal_VEvent Object
				        (
				            [uid:protected] => XXXX
				            [start:protected] => 1379077500
				            [end:protected] => 1379077500
				            [summary:protected] =>
				            [description:protected] =>
				            [location:protected] =>
				            [laststart:protected] =>
				            [lastend:protected] =>
				            [recurrence] =>
				            [recurex] =>
				            [excluded] =>
				            [added] =>
				            [freq] =>
				            [data] => Array
				                (
				                    [dtstamp] => 20131214T045050Z
				                    [class] => PUBLIC
				                    [sequence] => 0
				                    [last-modified] => 20130904T130630Z
				                )

				        )
				*/

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

/* @TODO RECURRENCE

NEED SAMPLE ICAL with recurrence before I can work on this

<select name="recurrence[type]">
	<option value="None" data-plural="">None</option>
	<option value="Every Day" data-plural="days" data-single="day">Every Day</option>
	<option selected="selected" value="Every Week" data-plural="weeks" data-single="week">Every Week</option>
	<option value="Every Month" data-plural="months" data-single="month">Every Month</option>
	<option value="Every Year" data-plural="years" data-single="year">Every Year</option>
	<option value="Custom" data-plural="events" data-single="event">Custom</option>
</select>

<select name="recurrence[custom-type]">
	<option data-tablerow="" data-plural="Day(s)" value="Daily">Daily</option>
	<option data-tablerow="#custom-recurrence-weeks" data-plural="Week(s) on:" value="Weekly">Weekly</option>
	<option data-tablerow="#custom-recurrence-months" data-plural="Month(s) on the:" value="Monthly">Monthly</option>
	<option data-tablerow="#custom-recurrence-years" data-plural="Year(s) on:" value="Yearly">Yearly</option>
</select>

<input type="text" value="" name="recurrence[custom-interval]">


<select name="recurrence[end-type]">
	<option value="On">On</option>
	<option value="After">After</option>
	<option selected="selected" value="Never">Never</option>
</select>

// end-type = on
<input type="text" style="" value="" id="recurrence_end" name="recurrence[end]" class="datepicker hasDatepicker" placeholder="2013-12-15" autocomplete="off">

// end-type = after
recurrence['end_count'] = value

$recurrenceData['type'];
$recurrenceData['end-type'];
$recurrenceData['end'];
$recurrenceData['end-count'];
$recurrenceData['custom-type'];
$recurrenceData['custom-interval'];
$recurrenceData['custom-type-text'];
$recurrenceData['occurrence-count-text'];
$recurrenceData['recurrence-description']; // optional
$recurrenceData['custom-week-day'];
$recurrenceData['custom-month-number'];
$recurrenceData['custom-month-day'];
$recurrenceData['custom-year-month'];
$recurrenceData['custom-year-filter'];
$recurrenceData['custom-year-month-number'];
$recurrenceData['custom-year-month-day'];

*/

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

			// update counts
			$this->counts[$key]['updated'] = $updated_count;
			$this->counts[$key]['added']   = $added_count;

		}

		remove_action( 'tribe_events_update_meta', array( $this, 'ical_meta' ), 10, 2 );

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
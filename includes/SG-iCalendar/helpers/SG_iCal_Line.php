<?php // BUILD: Remove line

/**
 * A class for storing a single (complete) line of the iCal file.
 * Will find the line-type, the arguments and the data of the file and
 * store them.
 *
 * The line-type can be found by querying getIdent(), data via either
 * getData() or typecasting to a string.
 * Params can be access via the ArrayAccess. A iterator is also avilable
 * to iterator over the params.
 *
 * @package SG_iCalReader
 * @author Morten Fangel (C) 2008
 * @license http://creativecommons.org/licenses/by-sa/2.5/dk/deed.en_GB CC-BY-SA-DK
 */
class SG_iCal_Line implements ArrayAccess, Countable, IteratorAggregate {
	protected $ident;
	protected $data;
	protected $params = array();

	protected $replacements = array('from'=>array('\\,', '\\n', '\\;', '\\:', '\\"'), 'to'=>array(',', "\n", ';', ':', '"'));

	protected $zones = '';

	/**
	 * Constructs a new line.
	 */
	public function __construct( $line ) {
		// Ugh.
		$line = $this->fixMicrosoftTimeZones( $line );

		$split = strpos($line, ':');
		$idents = explode(';', substr($line, 0, $split));
		$ident = strtolower(array_shift($idents));

		$data = trim(substr($line, $split+1));
		$data = str_replace($this->replacements['from'], $this->replacements['to'], $data);

		$params = array();
		foreach( $idents AS $v) {
			list($k, $v) = explode('=', $v);
			$params[ strtolower($k) ] = $v;
		}

		$this->ident = $ident;
		$this->params = $params;
		$this->data = $data;
	}

	/**
	 * Fix parsing of Microsoft timezones, mostly used by Outlook iCals.
	 *
	 * SG-iCalendar has problems parsing TZIDs, notably by Outlook iCals.
	 *
	 * For example, for the following:
	 *    DTSTART;TZID="(UTC-05:00) Eastern Time (US & Canada)":20180411T080000
	 *
	 * We convert this to a UTC timestamp and strip the TZID:
	 *    DTSTART:20180411T120000
	 *
	 * In an ideal world, this shouldn't be done in the SG_iCal_Line class, but
	 * Outlook iCals suck!
	 *
	 * @since TEC-ICAL 0.1 This is a custom mod by HWDSB.
	 *
	 * @param  string $line Current line to parse.
	 * @return string
	 */
	public function fixMicrosoftTimeZones( $line ) {
		// If not a DTSTART or DTEND line, bail.
		if ( 0 !== strpos( $line, 'DTSTART' ) && 0 !== strpos( $line, 'DTEND' ) ) {
			return $line;
		}

		$_line = str_replace( array( 'DTSTART;', 'DTEND;' ), '', $line );
		$parts = explode( ':', $_line );
		if ( ! empty( $parts[0] ) && false !== strpos( $parts[0], 'TZID=' ) ) {
			$tzid = $parts[0];

			// Leave UTC alone.
			if ( false !== strpos( $tzid, '(UTC)' ) ) {
				$line = str_replace( ';' . $tzid, '', $line );

			// This is a Microsoft timezone.
			} elseif ( false !== strpos( $tzid, '(UTC' ) ) {
				$offset_pos = strpos( $tzid, '(UTC' );
				$offset = substr( $tzid, $offset_pos + 1 );
				if ( ! empty( $parts[2] ) ) {
					$tzid = $parts[0] . ':' . $parts[1];
					$time = $parts[2];
					$offset .= substr( $parts[1], 0, 2 );
				} else {
					$time = $parts[1];
				}

				$map = '';

				// Try to map Microsoft timezone to Olson timezone.
				$tz = substr( $tzid, strpos( $tzid, '(' ) );
				$tz = trim( $tz, '"' );
				$findZone = strpos( $this->getZones(), $tz );

				// Fetch the first Olson timezone that matches a Microsoft timezone.
				if ( false !== $findZone ) {
					$findZoneStart = strpos( $this->zones, "\n", $findZone );
					$findZoneEnd = strpos( $this->zones, "\n", $findZoneStart + 1 );
					$map = substr( $this->zones, $findZoneStart, $findZoneEnd - $findZoneStart );
					$map = substr( $map, strpos( $map, 'type=' ) + 6, -3 );
				}

				// Success, we can use a valid timezone!
				if ( ! empty( $map ) ) {
					$d = new DateTime( $time, new DateTimeZone( $map ) );

				// Use offset. This is not accurate for those that observe DST...
				} else {
					$offset = substr( $offset, 3 );
					$offsettime = $time . $offset;
					$d = new DateTime( $offsettime );
				}

				// Standardize timezone to UTC.
				$d->setTimezone( new DateTimeZone( 'UTC' ) );

				$line = str_replace( ';' . $tzid, '', $line );
				$line = str_replace( $time, $d->format( 'Ymd\THis' ), $line );
			}
		}
		return $line;
	}

	/**
	 * Fetch Windows timezone mapping data.
	 *
	 * Uses timezone data from Unicode CLDR. Licensed under the ICU.
	 *
	 * @link   https://unicode.org/cldr/trac/browser/trunk/common/supplemental/windowsZones.xml
	 * @link   http://source.icu-project.org/repos/icu/trunk/icu4j/main/shared/licenses/LICENSE
	 * @return string
	 */
	protected function getZones() {
		if ( empty( $this->zones ) ) {
			$this->zones = file_get_contents( realpath( __DIR__ . '/../tz-map/windowsZones.xml' ) );
		}

		return $this->zones;
	}

	/**
	 * Is this line the begining of a new block?
	 * @return bool
	 */
	public function isBegin() {
		return $this->ident == 'begin';
	}

	/**
	 * Is this line the end of a block?
	 * @return bool
	 */
	public function isEnd() {
		return $this->ident == 'end';
	}

	/**
	 * Returns the line-type (ident) of the line
	 * @return string
	 */
	public function getIdent() {
		return $this->ident;
	}

	/**
	 * Returns the content of the line
	 * @return string
	 */
	public function getData() {
		return $this->data;
	}

	/**
	 * Returns the content of the line
	 * @return string
	 */
	public function getDataAsArray() {
		if (strpos($this->data,",") !== false) {
			return explode(",",$this->data);
		}
		else
			return array($this->data);
	}

	/**
	 * A static helper to get a array of SG_iCal_Line's, and calls
	 * getData() on each of them to lay the data "bare"..
	 *
	 * @param SG_iCal_Line[]
	 * @return array
	 */
	public static function Remove_Line($arr) {
		$rtn = array();
		foreach( $arr AS $k => $v ) {
			if(is_array($v)) {
				$rtn[$k] = self::Remove_Line($v);
			} elseif( $v instanceof SG_iCal_Line ) {
				$rtn[$k] = $v->getData();
			} else {
				$rtn[$k] = $v;
			}
		}
		return $rtn;
	}

	/**
	 * @see ArrayAccess.offsetExists
	 */
	public function offsetExists( $param ) {
		return isset($this->params[ strtolower($param) ]);
	}

	/**
	 * @see ArrayAccess.offsetGet
	 */
	public function offsetGet( $param ) {
		$index = strtolower($param);
		if (isset($this->params[ $index ])) {
			return $this->params[ $index ];
		}
	}

	/**
	 * Disabled ArrayAccess requirement
	 * @see ArrayAccess.offsetSet
	 */
	public function offsetSet( $param, $val ) {
		return false;
	}

	/**
	 * Disabled ArrayAccess requirement
	 * @see ArrayAccess.offsetUnset
	 */
	public function offsetUnset( $param ) {
		return false;
	}

	/**
	 * toString method.
	 * @see getData()
	 */
	public function __toString() {
		return $this->getData();
	}

	/**
	 * @see Countable.count
	 */
	public function count() {
		return count($this->params);
	}

	/**
	 * @see IteratorAggregate.getIterator
	 */
	public function getIterator() {
		return new ArrayIterator($this->params);
	}
}

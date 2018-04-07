The Events Calendar - iCalendar Importer
=======================================

This plugin allows site administrators to enter remotely-located iCalendar files to be synched and imported into [The Events Calendar](http://wordpress.org/plugins/the-events-calendar/) plugin for [WordPress](http://wordpress.org).

Tested with The Events Calendar 4.2.7 and The Events Calendar PRO 4.2.6.

How to use?
-
* Make sure The Events Calendar is already installed and activated.
* Download, install and activate this plugin.
* In the WP admin dashboard under "Settings > General", make sure you have selected the timezone that is reflective of your install.
* In the WP admin dashboard, navigate to the "Events > iCal Import" page.
* Next, enter in the location of your iCalendar files.
* Optionally, add a category slug so your imported events can be categorized appropriately and also set a custom interval to let WordPress know when it should sync the iCalendar.
* Lastly, you can immediately test the import by checking the "Manual Sync" checkbox.
* Hit "Save changes" and that should be it!

Thanks
-
* SG-iCalendar - A simple and fast iCal parser.  Currently using [lipemat's fork](https://github.com/lipemat/SG-iCalendar).  Original is by [fangal](https://github.com/fangel/SG-iCalendar).  Licensed under the [Creative Commons Attribution-ShareAlike 2.5 (Denmark)](https://creativecommons.org/licenses/by-sa/2.5/dk/deed.en).
* [Unicode CLDR](http://cldr.unicode.org/index) - For their [Windows timezones XML file](https://unicode.org/cldr/trac/browser/trunk/common/supplemental/windowsZones.xml). Licensed under the [ICU](http://source.icu-project.org/repos/icu/trunk/icu4j/main/shared/licenses/LICENSE).


License
-
[Creative Commons Attribution-ShareAlike 2.5 (Denmark)](https://creativecommons.org/licenses/by-sa/2.5/dk/deed.en).

<sub>*Since SG-iCalendar's license is CC BY-SA, this plugin must also follow this license.  Therefore, this plugin is [incompatible with the GPL](https://www.gnu.org/licenses/license-list.html#ccbysa).</sub>
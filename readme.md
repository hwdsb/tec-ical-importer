The Events Calendar - iCalendar Importer
=======================================

This plugin allows site administrators to enter remotely-located iCalendar files to be synched and imported into [The Events Calendar](http://wordpress.org/plugins/the-events-calendar/) plugin for [WordPress](http://wordpress.org).

Tested with The Events Calendar 6.0.11 and The Events Calendar PRO 6.0.9.2.

How to use?
-
* Make sure The Events Calendar is already installed and activated.
* Download the plugin.
* Run `composer install` in the same directory where you installed the plugin.
* Next, activate and install this plugin.
* In the WP admin dashboard under "Settings > General", make sure you have selected the timezone that is reflective of your install.
* In the WP admin dashboard, navigate to the "Events > iCal Import" page.
* Next, enter in the location of your iCalendar files.
* Optionally, add a category slug so your imported events can be categorized appropriately and also set a custom interval to let WordPress know when it should sync the iCalendar.
* Lastly, you can immediately test the import by checking the "Manual Sync" checkbox.
* Hit "Save changes" and that should be it!

Thanks
-
* [PHP ICS Parser](https://github.com/u01jmg3/ics-parser) - Parser for iCalendar Events.  Licensed under the [MIT](https://github.com/u01jmg3/ics-parser/blob/master/LICENSE).


License
-
GPL v2 or later
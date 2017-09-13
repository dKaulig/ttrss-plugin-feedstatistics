<?php
/*******************************************************************************
 * ttrss-plugin-feedstatistics, open source plugin for tiny tiny rss.
 * Copyright (c) 2016 jsoares, https://github.com/jsoares/ttrss-plugin-feedstatistics
 * Copyright (c) 2017 dKaulig, https://github.com/dKaulig/ttrss-plugin-feedstatistics
 *
 * ttrss-plugin-feedstatistics is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * ttrss-plugin-feedstatistics is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *******************************************************************************/
 
class FeedStatistics extends Plugin {

	private $host;
	
	function about() {
		return array(1.07
			, "Provides extended statistics on your feeds"
			, "dekay"
			, false // Must be a system plugin to add an API.
			, "https://github.com/dKaulig/ttrss-plugin-feedstatistics"
			);
	}

	function init($host) {
		$this->host = $host;
		
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}
	
	function save() {
		$interval_days = (int) db_escape_string($_POST["interval_days"]);
		$enable_all = checkbox_to_sql_bool($_POST["enable_all"]) == "true";

		$this->host->set($this, "interval_days", $interval_days);
		$this->host->set($this, "enable_all", $enable_all);
		
		echo T_sprintf("Data saved (Interval %s days, Fetch whole %d).", $interval_days, $enable_all);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		$owner_uid = $_SESSION["uid"] ? $_SESSION["uid"] : "NULL";
		
		$interval_days = $this->host->get($this, "interval_days");
		$enable_all = $this->host->get($this, "enable_all");
		
		if (!$interval_days) $interval_days = '30';
		
		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__('Statistics')."\">"; # start pane
		
		print "<form dojoType=\"dijit.form.Form\">";

		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
			if (this.validate()) {
				console.log(dojo.objectToQuery(this.getValues()));
				new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						notify_info(transport.responseText);
					}
				});
				//this.reset();
			}
			</script>";

		print_hidden("op", "pluginhandler");
		print_hidden("method", "save");
		print_hidden("plugin", "feedstatistics");

		print "<h3>" . __('Settings') . "</h3>";
		print_warning("Fetch complete data option, overwrites defined days.");
		print "<table>";

		print "<tr><td width=\"40%\">" . __("Fetch for days:") . "</td>";
		print "<td>
			<input dojoType=\"dijit.form.ValidationTextBox\"
			placeholder=\"30\"
			required=\"1\" id=\"interval_days\" name=\"interval_days\" value=\"$interval_days\"></td></tr>";
		print "<tr><td width=\"40%\">" . __("Fetch whole data:") . "</td>";
		print "<td>";
		print_checkbox("enable_all", $enable_all);
		print "</td></tr>";

		print "</table>";

		print "<p>"; print_button("submit", __("Save"));
		print "</form>";
		
		// By default, use previous 30 days for statistics. 
		$interval = (int) $interval_days;
		
		if ($enable_all) {
			// Get first entry
			$result = db_query("SELECT last_read
								FROM ttrss_feeds
								LEFT JOIN ttrss_user_entries ON ttrss_feeds.id = ttrss_user_entries.feed_id
								WHERE ttrss_feeds.owner_uid = {$owner_uid}
								ORDER BY last_read
								LIMIT 1");
			if (db_num_rows($result) == 1) {				
				$first_article = db_fetch_result($result, 0, "last_read");
				$start_date = date_create($first_article);
				$cur_date = date_create('now');				
				$dteDiff = $start_date->diff($cur_date); 
				$daysDiff = $dteDiff->format("%a");
				if($daysDiff >= 1) {
					$interval = $daysDiff;
				}
			}
		}

		
		// However, if the purge limit is lower, adjust accordingly
		$result = db_query("SELECT value FROM ttrss_user_prefs
							WHERE pref_name = 'PURGE_OLD_DAYS' AND owner_uid = $owner_uid AND profile IS NULL");
		if (db_num_rows($result) == 1) {
			$purge_limit = db_fetch_result($result, 0, "value");
			if ($purge_limit > 0) {
				$interval = min($interval,$purge_limit);
			}
		}
		$date = new DateTime();
		$date->sub(new DateInterval("P{$interval}D"));
		$datestr = $date->format("Y-m-d");
		
		// Google Reader-like one-line summary
		$result = db_query("SELECT
							COUNT(DISTINCT ttrss_feeds.id) AS feeds,
							COUNT(NULLIF(last_read > '{$datestr}', false)) AS items,
							COUNT(NULLIF(last_marked > '{$datestr}', false)) AS starred,
							COUNT(NULLIF(last_published > '{$datestr}', false)) AS published
							FROM ttrss_feeds
							LEFT JOIN ttrss_user_entries ON ttrss_feeds.id = ttrss_user_entries.feed_id
							WHERE ttrss_feeds.owner_uid = {$owner_uid}");
		if(db_num_rows($result)) {		
			$row = db_fetch_assoc($result);
			print_notice("From your " . $row['feeds'] . " subscriptions, over the last {$interval} days  you read " . $row['items'] . " items, starred " . $row['starred'] . " items, and published " .  $row['published'] . " items.");
		}
		
		// Per-feed statistics
		$result = db_query("SELECT
							ttrss_feeds.title AS feed,
							ttrss_feed_categories.title AS category,
							COUNT(NULLIF(last_read > '{$datestr}', false)) AS items,
							COUNT(NULLIF(last_marked > '{$datestr}', false)) AS starred,
							COUNT(NULLIF(last_published > '{$datestr}', false)) AS published,
							ROUND(CAST(COUNT(NULLIF(last_read > '{$datestr}', false)) AS DECIMAL) / {$interval}, 2) AS items_day
							FROM ttrss_feeds
							LEFT JOIN ttrss_user_entries ON ttrss_feeds.id = ttrss_user_entries.feed_id
							LEFT JOIN ttrss_entries ON ttrss_user_entries.ref_id = ttrss_entries.id
							LEFT JOIN ttrss_feed_categories ON ttrss_feeds.cat_id = ttrss_feed_categories.id
							WHERE ttrss_feeds.owner_uid = {$owner_uid}
							GROUP BY feed, category
							ORDER BY items DESC");
		if(db_num_rows($result)) {
			print "<table cellpadding=\"5\" class=\"feed-table\">";
			print "<tr class=\"title\"><td>Feed</td><td>Category</td><td>Items</td><td>Starred</td><td>Published</td><td>Items/day</td></tr>";
			while($row = db_fetch_assoc($result)) {
				print "<tr>";
				foreach($row as $key=>$value) {
					print "<td>{$value}</td>";
				}
				print "</tr>";
			}
			print "</table>";
		}		
		
		print "</div>"; #pane
	}

	function api_version() {
		return 2;
	}
}
?>

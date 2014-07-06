<?php
class FeedStatistics extends Plugin {

	function about() {
		return array(1.2,
			"Provides simple statistics on your feeds",
			"jsoares",
			false,
			"");
	}

	function init($host) {
		$host->add_hook($host::HOOK_PREFS_TAB, $this);
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;

		print "<div dojoType=\"dijit.layout.AccordionPane\" title=\"".__("Statistics")."\">"; # start pane
		
		$owner_uid = $_SESSION["uid"] ? $_SESSION["uid"] : "NULL";
		
		// By default, use previous 30 days for statistics. 
		$interval = 30;
		// However, if the purge limit is lower, adjust accordingly
		$result = db_query("SELECT value FROM ttrss_user_prefs
							WHERE pref_name = 'PURGE_OLD_DAYS' AND owner_uid = $owner_uid");
		if (db_num_rows($result) == 1) {
			$purge_limit = db_fetch_result($result, 0, "value");
			$interval = min($interval,$purge_limit);
		}
		$date = new DateTime();
		$date->sub(new DateInterval("P{$interval}D"));
		$datestr = $date->format("Y-m-d");
		
		// Sum does not support booleans in pgsql, hence we cast. Different types, though...
		if (DB_TYPE == "pgsql") {
			$type = 'INT';
		} else {
			$type = 'SIGNED';
		}
		
		// Google Reader-like one-line summary
		$result = db_query("SELECT COUNT(DISTINCT feed_id) AS feeds, COUNT(DISTINCT ref_id) as items, SUM(CAST(marked AS {$type})) as starred, SUM(CAST(published AS {$type})) AS published
							FROM ttrss_user_entries 				
							WHERE last_read > '{$datestr}'
							AND owner_uid = {$owner_uid}");
		if(db_num_rows($result)) {		
			$row = db_fetch_assoc($result);
			print_notice("From your " . $row['feeds'] . " subscriptions, over the last {$interval} days you read " . $row['items'] . " items, starred " . $row['starred'] . " items, and published " .  $row['published'] . " items.");
		}
		
		// Per-feed statistics
		$result = db_query("SELECT ttrss_feeds.title as feed, ttrss_feed_categories.title as category, COUNT(ref_id) as items, 
							SUM(CAST(marked AS {$type})) as starred, SUM(CAST(published AS {$type})) AS published, ROUND(CAST(COUNT(ref_id) AS DECIMAL)/{$interval},2) as items_day
							FROM ttrss_user_entries 
							INNER JOIN ttrss_feeds ON ttrss_user_entries.feed_id = ttrss_feeds.id
							INNER JOIN ttrss_entries ON ttrss_user_entries.ref_id = ttrss_entries.id
							LEFT JOIN ttrss_feed_categories ON ttrss_feeds.cat_id=ttrss_feed_categories.id							
							WHERE ttrss_entries.date_entered > '{$datestr}'
							AND ttrss_user_entries.owner_uid = {$owner_uid}
							GROUP BY feed, category
							ORDER BY items_day DESC");
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

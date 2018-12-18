<?php
// Clean the backend cache. We do this with an AJAX request because
// a) It doesn't slow down the loading of the page, and
// b) If we do it at page load time Chrome's page preload feature can result in two of them running simultaneously,
//    which produces 'cannot stat' errors.

chdir('..');
include("includes/vars.php");
include("includes/functions.php");
require_once("utils/imagefunctions.php");
include("backends/sql/backend.php");

debuglog("Checking Cache","CACHE CLEANER");

// DO NOT REDUCE the values for musicbrainz or discogs
// - we have to follow their API rules and as we don't check
// expiry headers at all we need to keep everything for a month
// otherwise they will ban us. Don't spoil it for everyone.

// One Month
clean_cache_dir('prefs/jsoncache/musicbrainz/', 2592000);
// One Month
clean_cache_dir('prefs/jsoncache/allmusic/', 2592000);
// One Month
clean_cache_dir('prefs/jsoncache/discogs/', 2592000);
// One Month
clean_cache_dir('prefs/jsoncache/wikipedia/', 2592000);
// One Month
clean_cache_dir('prefs/jsoncache/lastfm/', 2592000);
// One Month
clean_cache_dir('prefs/jsoncache/soundcloud/', 2592000);
// One Month
clean_cache_dir('prefs/jsoncache/spotify/', 2592000);
// One Month
clean_cache_dir('prefs/jsoncache/google/', 2592000);
// Six Months - after all, lyrics are small and don't change
clean_cache_dir('prefs/jsoncache/lyrics/', 15552000);
// One week (or it can get REALLY big)
clean_cache_dir('prefs/imagecache/', 648000);
// Clean the albumart temporary upload directory
clean_cache_dir('albumart/', 1);
// Clean the temp directory
clean_cache_dir('prefs/temp/', 1);
debuglog("Cache has been cleaned","CACHE CLEANER");

if ($mysqlc) {

    $now = time();
    debuglog("Checking database for hidden album art","CACHE CLEANER");
    // Note the final line checking that image isn't in use by another album
    // it's an edge case where we have the album local but we also somehow have a spotify or whatever
    // version with hidden tracks
    $result = generic_sql_query("SELECT DISTINCT Albumindex, Albumname, Image, Domain FROM
        Tracktable JOIN Albumtable USING (Albumindex) JOIN Playcounttable USING (TTindex)
        WHERE Hidden = 1
        AND ".sql_two_weeks()."
        AND
            Albumindex NOT IN (SELECT Albumindex FROM Albumtable JOIN Tracktable USING (Albumindex) WHERE Hidden = 0)
        AND
            Image NOT IN (SELECT Image FROM Albumtable JOIN Tracktable USING (Albumindex) WHERE Hidden = 0)", false, PDO::FETCH_OBJ);
    foreach ($result as $obj) {
        if (preg_match('#^albumart/small/#', $obj->Image)) {
            debuglog("Removing image for hidden album ".$obj->Albumname." ".$obj->Image,"CACHE CLEANER");
            generic_sql_query("UPDATE Albumtable SET Image = NULL, Searched = 0 WHERE Albumindex = ".$obj->Albumindex, true);
        }
    }
    debuglog("== Check For Hidden Album Art took ".format_time(time() - $now),"CACHE CLEANER",4);


    if ($prefs['cleanalbumimages']) {
        $now = time();
        debuglog("Checking albumart folder for unneeded images","CACHE CLEANER");
        $files = glob('albumart/small/*.*');
        foreach ($files as $image) {
            // Remove images for hidden tracks and search results. The missing check below will reset the db entries for those albums
            // Keep everything for 24 hours regardless, we might be using it in a playlist or something
            if (filemtime($image) < time()-86400) {
                $count = sql_prepare_query(false, null, 'acount', 0, "SELECT COUNT(Albumindex) AS acount FROM Albumtable WHERE Image = ? AND Albumindex IN (SELECT DISTINCT Albumindex FROM Tracktable WHERE Hidden = 0 AND isSearchResult < 2 AND URI IS NOT NULL)", $image);
                if ($count < 1) {
                    debuglog("  Removing Unused Album image ".$image,"CACHE CLEANER");
                    $albumimage = new baseAlbumImage(array('baseimage' => $image));
                    array_map('unlink', $albumimage->get_images());
                }
            }
        }
        debuglog("== Check For Unneeded Images took ".format_time(time() - $now),"CACHE CLEANER",4);

        debuglog("Checking for orphaned radio station images","CACHE CLEANER");
        $now = time();
        $files = glob('prefs/userstreams/*');
        foreach ($files as $image) {
            $count = generic_sql_query("SELECT COUNT(Stationindex) AS acount FROM RadioStationtable WHERE Image LIKE '".$image."%'", false, null, 'acount', 0);
            if ($count < 1) {
                debuglog("  Removing orphaned radio station image ".$image,"CACHE CLEANER");
                rrmdir($image);
            }
        }
        debuglog("== Check For Orphaned Radio Station Images took ".format_time(time() - $now),"CACHE CLEANER",4);

        debuglog("Checking for orphaned podcast data","CACHE CLEANER");
        $now = time();
        $files = glob('prefs/podcasts/*');
        $pods = sql_get_column("SELECT PODindex FROM Podcasttable", 'PODindex');
        foreach ($files as $file) {
            if (!in_array(basename($file), $pods)) {
                debuglog("  Removing orphaned podcast directory ".$file,"CACHE CLEANER");
                rrmdir($file);
            }
        }
        debuglog("== Check For Orphaned Podcast Data took ".format_time(time() - $now),"CACHE CLEANER",4);
    }

    debuglog("Checking database for missing album art","CACHE CLEANER");
    $now = time();
    $result = generic_sql_query("SELECT Albumindex, Albumname, Image, Domain FROM Albumtable WHERE Image NOT LIKE 'getRemoteImage%'", false, PDO::FETCH_OBJ);
    foreach ($result as $obj) {
        if ($obj->Image != '' && !file_exists($obj->Image)) {
            debuglog($obj->Albumname." has missing image ".$obj->Image,"CACHE CLEANER");
            if (file_exists("newimages/".$obj->Domain."-logo.svg")) {
                $image = "newimages/".$obj->Domain."-logo.svg";
                $searched = 1;
            } else {
                $image = '';
                $searched = 0;
            }
            sql_prepare_query(true, null, null, null, "UPDATE Albumtable SET Searched = ?, Image = ? WHERE Albumindex = ?", $searched, $image, $obj->Albumindex);
        }
    }
    debuglog("== Check For Missing Album Art took ".format_time(time() - $now),"CACHE CLEANER",4);

    debuglog("Checking for orphaned Wishlist Sources","CACHE CLEANER");
    $now = time();
    generic_sql_query("DELETE FROM WishlistSourcetable WHERE Sourceindex NOT IN (SELECT DISTINCT Sourceindex FROM Tracktable WHERE Sourceindex IS NOT NULL)");
    debuglog("== Check For Orphaned Wishlist Sources took ".format_time(time() - $now),"CACHE CLEANER",4);

    // Compact the database
    if ($prefs['collection_type'] == 'sqlite') {
        debuglog("Vacuuming Database","CACHE CLEANER");
        $now = time();
        generic_sql_query("VACUUM", true);
        generic_sql_query("PRAGMA optimize", true);
        debuglog("== Database Optimisation took ".format_time(time() - $now),"CACHE CLEANER",4);
    }

    debuglog("Cache Cleaning Is Complete","CACHE CLEANER");

}

function clean_cache_dir($dir, $time) {

    debuglog("Cache Cleaner is running on ".$dir,"CACHE CLEANER");
    $cache = glob($dir."*");
    $now = time();
    foreach($cache as $file) {
        if (!is_dir($file)) {
            if($now - filemtime($file) > $time) {
                debuglog("Removing file ".$file,"CACHE CLEANER",4);
                @unlink ($file);
            }
        }
    }
}

?>

<html></html>

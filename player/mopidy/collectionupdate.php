<?php

function musicCollectionUpdate() {
	global $prefs, $collection;
    debuglog("Starting Music Collection Update", "MOPIDY",4);
    $collection = new musicCollection();
	$monitor = fopen('prefs/monitor','w');
    $dirs = $prefs['mopidy_collection_folders'];
    while (count($dirs) > 0) {
        $dir = array_shift($dirs);
        if ($dir == "Spotify Playlists") {
        	musicCollectionSpotifyPlaylistHack($monitor);
        } else {
			fwrite($monitor, "\n<b>".get_int_text('label_scanningf', array($dir))."</b><br />".get_int_text('label_fremaining', array(count($dirs))));
        	doMpdParse('lsinfo "'.format_for_mpd(local_media_check($dir)).'"', $dirs, false);
	    	$collection->tracks_to_database();
	    }
    }
    fwrite($monitor, "\nUpdating Database");
    fclose($monitor);
}

function musicCollectionSpotifyPlaylistHack($monitor) {
	global $collection;
	$dirs = array();
	$playlists = do_mpd_command("listplaylists", true, true);
    if (is_array($playlists) && array_key_exists('playlist', $playlists)) {
        foreach ($playlists['playlist'] as $pl) {
			if (preg_match('/\(by spotify\)/', $pl)) {
				debuglog("Ignoring Playlist ".$pl,"COLLECTION",7);
			} else {
		    	debuglog("Scanning Playlist ".$pl,"COLLECTION",7);
				fwrite($monitor, "\n<b>".get_int_text('label_scanningp', array($pl))."</b>");
		    	doMpdParse('listplaylistinfo "'.format_for_mpd($pl).'"', $dirs, array("spotify"));
			    $collection->tracks_to_database();
			}
	    }
	}
}

function local_media_check($dir) {
	if ($dir == "Local media") {
		// Mopidy-Local-SQlite contains a virtual tree sorting things by various keys
		// If we scan the whole thing we scan every file about 8 times. This is stoopid.
		// Check to see if 'Local media/Albums' is browseable and use that instead if it is.
		// Using Local media/Folders causes every file to be re-scanned every time we update
		// the collection, which takes ages and also includes m3u and pls stuff that we don't want
		$r = do_mpd_command('lsinfo "'.$dir.'/Albums"', false, false);
		if ($r === false) {
			return $dir;
		} else {
			return $dir.'/Albums';
		}
	}
	return $dir;
}

?>

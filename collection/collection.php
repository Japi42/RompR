<?php

include ("player/".$prefs['player_backend']."/streamhandler.php");
require_once ("includes/spotifyauth.php");

$numtracks = 0;
$numalbums = 0;
$numartists = 0;
$totaltime = 0;
$playlist = array();
$putinplaylistarray = false;
$count = 1;
$divtype = "album1";
$collection = null;
$dbterms = array( 'tags' => null, 'rating' => null );
$trackbytrack = false;

define('ROMPR_MIN_TRACKS_TO_DETERMINE_COMPILATION', 3);
define('ROMPR_MIN_NOT_COMPILATION_THRESHOLD', 0.6);

class musicCollection {

	public function __construct() {
		$this->albums = array();
		$this->filter_duplicates = false;
	}

	public function newTrack(&$track) {

		global $playlist, $prefs, $putinplaylistarray;

		$albumkey = md5($track->tags['folder'].strtolower($track->tags['Album']).strtolower($track->get_sort_artist(true)));
		if (array_key_exists($albumkey, $this->albums)) {
			if (!$this->filter_duplicates || !$this->albums[$albumkey]->checkForDuplicate($track)) {
				$this->albums[$albumkey]->newTrack($track);
			}
		} else {
			$this->albums[$albumkey] = new album($track);
		}

        if ($putinplaylistarray) {
            $playlist[] = $track;
        }

	}

    public function getAllTracks($cmd) {
        $tracks = array();
        foreach($this->albums as $album) {
            $tracks = array_merge($album->getAllTracks($cmd), $tracks);
        }
        return $tracks;
    }

    public function tracks_to_database() {
        global $cp_time;
        $cstart = microtime(true);
        foreach ($this->albums as $album) {
            $this->do_artist_database_stuff($album);
        }
        $this->albums = array();
        $cp_time += microtime(true) - $cstart;
    }

	public function get_albumartist_by_folder($f) {
		foreach ($this->albums as $album) {
			if ($album->folder == $f) {
				debuglog("   Found albumartist by folder ".$album->artist,"COLLECTION");
				return $album->artist;
			}
		}
		return null;
	}

	public function filter_duplicate_tracks() {
		$this->filter_duplicates = true;
	}

    public function tracks_as_array() {
        $results = array();
        foreach($this->albums as $album) {
			debuglog("Doing Album ".$album->name,"COLLECTION");
            $album->sortTracks();
            foreach($album->tracks as $trackobj) {
                $track = array(
                    "uri" => $trackobj->tags['file'],
                    "album" => $album->name,
                    "title" => $trackobj->tags['Title'],
                    "artist" => $trackobj->get_artist_string(),
                    "albumartist" => $album->artist,
                    "trackno" => $trackobj->tags['Track'],
                    "disc" => $trackobj->tags['Disc'],
                    "albumuri" => $album->uri,
                    "image" => $album->getImage('asdownloaded'),
                    "duration" => $trackobj->tags['Time'],
                    "date" => $album->datestamp
                );
				debuglog("Title - ".$trackobj->tags['Title'],"COLLECTION");
                // A lot of code that depends on this was written to handle mopidy model search results.
                // The above is not mopidy model, so friggicate it into just such a thing
                $d = getDomain($track['uri']);
				if (!array_key_exists($d, $results)) {
					debuglog("Creating Results Set For ".$d,"COLLECTION",8);
                    $results[$d] = array(
                        "tracks" => array(),
                        "uri" => $d.':bodgehack'
                    );
                }
                array_push($results[$d]['tracks'], $track);
            }
        }
        return $results;
    }

    private function do_artist_database_stuff(&$album) {

        $album->sortTracks();
        $artistname = $album->artist;
        $artistindex = check_artist($artistname);
        if ($artistindex == null) {
            debuglog("ERROR! Checked artist ".$artistname." and index is still null!","MYSQL",1);
            return false;
        }
        $params = array(
            'album' => $album->name,
            'albumai' => $artistindex,
            'albumuri' => $album->uri,
            'image' => $album->getImage('small'),
            'date' => $album->getDate(),
            'searched' => "0",
            'imagekey' => $album->getKey(),
            'ambid' => $album->musicbrainz_albumid,
            'domain' => $album->domain);
        $albumindex = check_album($params);

        if ($albumindex == null) {
            debuglog("ERROR! Album index for ".$album->name." is still null!","MYSQL",1);
            return false;
        }

        foreach($album->tracks as $trackobj) {
            check_and_update_track($trackobj, $albumindex, $artistindex, $artistname);
        }

    }

}

class album {

	public function __construct(&$track) {
		global $numalbums;
		$numalbums++;
		$this->tracks = array($track);
		$this->artist = $track->get_sort_artist(true);
		$this->name = trim($track->tags['Album']);
		$this->folder = $track->tags['folder'];
		$this->musicbrainz_albumid = $track->tags['MUSICBRAINZ_ALBUMID'];
		$this->datestamp = $track->tags['Date'];
		$this->uri = $track->tags['X-AlbumUri'];
		$this->numOfDiscs = $track->tags['Disc'];
		$this->image = $track->tags['X-AlbumImage'];
		$this->key = $track->tags['ImgKey'];
		$this->numOfDiscs = $track->tags['Disc'];
		$this->numOfTrackOnes = $track->tags['Track'] == 1 ? 1 : 0;
        $this->domain = $track->tags['domain'];
	}

	public function newTrack(&$track, $clear = false) {
		if ($clear) {
			$this->tracks = array($track);
		} else {
			$this->tracks[] = $track;
		}
		if ($this->artist == null) {
			$this->artist = $track->get_sort_artist(true);
		}
        if ($this->image == null) {
            $this->image = $track->tags['X-AlbumImage'];
        }
        if ($this->datestamp == null) {
            $this->datestamp = $track->tags['Date'];
        }
        if ($this->musicbrainz_albumid == '') {
            $this->musicbrainz_albumid = $track->tags['MUSICBRAINZ_ALBUMID'];
        }
        if ($track->tags['Disc'] !== null && $this->numOfDiscs < $track->tags['Disc']) {
            $this->numOfDiscs = $track->tags['Disc'];
        }
        if ($track->tags['Track'] == 1) {
            $this->numOfTrackOnes++;
        }
        if ($this->uri == null) {
            $this->uri = $track->tags['X-AlbumUri'];
        }
	}

    public function getKey() {
        return $this->key;
    }

    public function getImage($size) {
		$albumimage = new baseAlbumImage(array(
			'baseimage' => ($this->image) ? $this->image : '',
			'artist' => artist_for_image($this->tracks[0]->tags['type'], $this->artist),
			'album' => $this->name
		));
		$albumimage->check_image($this->domain, $this->tracks[0]->tags['type']);
		$images = $albumimage->get_images();
		$this->key = $albumimage->get_image_key();
        return $images[$size];
    }

    public function trackCount() {
        return count($this->tracks);
    }

    public function getDate() {
        return getYear($this->datestamp);
    }

    public function getAllTracks($cmd) {
        $tracks = array();
        foreach ($this->tracks as $track) {
            if (preg_match('/:track:/', $track->tags['file'])) {
                $tracks[] = $cmd.' "'.format_for_mpd($track->tags['file']).'"';
            }
        }
        return $tracks;
    }

    public function sortTracks($always = false) {

        // NB. BLOODY WELL CALL THIS FUNCTION
        // Unless you're so sure you know how all this works and you really don't need it.
        // Collection updates might be one such area but if you're not sure CALL IT ANYWAY and see what happens.

        // Some Mopidy backends don't send disc numbers. If we're using the sql backend
        // we don't really need to pre-sort tracks because we can do it on the fly.
        // However, when there are no disc numbers multi-disc albums don't sort properly.
        // Hence we do a little check that we have have the same number of 'Track 1's
        // as discs and only do the sort if they're not the same. This'll also
        // sort out badly tagged local files. It's essential that disc numbers are set
        // because the database will not find the tracks otherwise.

    	// Also here, because ths gets called always, we try to set our albumartist
    	// to something sensible. So far it has been set to Composer tags if required by the
    	// user, or to the AlbumArtist setting, which will be null if no AlbumArtist tag is present -
        // as is the case with many mopidy backends

        if ($this->artist == null) {
            debuglog("Finding AlbumArtist for album ".$this->name,"COLLECTION",5);
            if (count($this->tracks) < ROMPR_MIN_TRACKS_TO_DETERMINE_COMPILATION) {
                debuglog("  Album ".$this->name." has too few tracks to determine album artist","COLLECTION",5);
                $this->decideOnArtist($this->tracks[0]->get_sort_artist());
            } else {
                $artists = array();
                foreach ($this->tracks as $track) {
                    $a = $track->get_sort_artist();
                    if (!array_key_exists($a, $artists)) {
                        $artists[$a] = 0;
                    }
                    $artists[$a]++;
                }
                $q = array_flip($artists);
                rsort($q);
                $candidate_artist = $q[0];
                $fraction = $artists[$candidate_artist]/count($this->tracks);
                debuglog("  Artist ".$candidate_artist." has ".$artists[$candidate_artist]." tracks out of ".count($this->tracks),"COLLECTION",5);
                if ($fraction > ROMPR_MIN_NOT_COMPILATION_THRESHOLD) {
                    debuglog("    ... which is good enough. Album ".$this->name." is by ".$candidate_artist,"COLLECTION",5);
                    $this->artist = $candidate_artist;
                } else {
                    debuglog("   ... which is not enough","COLLECTION",5);
                    $this->decideOnArtist("Various Artists");
                }
            }
    	}

        foreach ($this->tracks as $track) {
            $track->tags['AlbumArtist'] = $this->artist;
        }

        if ($always == false && $this->numOfDiscs > 0 && ($this->numOfTrackOnes <= 1 || $this->numOfTrackOnes == $this->numOfDiscs)) {
            return $this->numOfDiscs;
        }

        $discs = array();
        $number = 1;
        foreach ($this->tracks as $ob) {
            if ($ob->tags['Track'] !== '') {
                $track_no = intval($ob->tags['Track']);
            } else {
                $track_no = $number;
            }
            # Just in case we have a multiple disc album with no disc number tags
            $discno = intval($ob->tags['Disc']);
            if ($discno == '' || $discno == null || $discno == 0) {
                $discno = 1;
            }
            if (!array_key_exists($discno, $discs)) {
            	$discs[$discno] = array();
            }
            while(array_key_exists($track_no, $discs[$discno])) {
                $discno++;
	            if (!array_key_exists($discno, $discs)) {
    	        	$discs[$discno] = array();
                }
            }
            $discs[$discno][$track_no] = $ob;
            $ob->updateDiscNo($discno);
            $number++;
        }
        $numdiscs = count($discs);

        $this->tracks = array();
        ksort($discs, SORT_NUMERIC);
        foreach ($discs as $disc) {
            ksort($disc, SORT_NUMERIC);
            $this->tracks = array_merge($this->tracks, $disc);
        }
        $this->numOfDiscs = $numdiscs;

        return $numdiscs;
    }

	public function checkForDuplicate($t) {
		foreach ($this->tracks as $track) {
			if ($t->tags['file'] == $track->tags['file']) {
				debuglog("Filtering Duplicate Track ".$t->tags['file'],"COLLECTION",7);
				return true;
			}
		}
		return false;
	}

    private function decideOnArtist($candidate) {
        if ($this->artist == null) {
            debuglog("  ... Setting artist to ".$candidate,"COLLECTION",5);
            $this->artist = $candidate;
        }

    }

}

class track {
    public function __construct(&$filedata) {
        $this->tags = $filedata;
    }

    public function updateDiscNo($disc) {
        $this->tags['Disc'] = $disc;
    }

    public function get_artist_string() {
        return format_artist($this->tags['Artist']);
    }

    public function get_sort_artist($return_albumartist = false) {
        return format_sortartist($this->tags, $return_albumartist);
    }

    public function get_checked_url() {
        global $prefs;
        $matches = array();
        if ($prefs['player_backend'] == 'mpd' &&
            preg_match("/api\.soundcloud\.com\/tracks\/(\d+)\//", $this->tags['file'], $matches)) {
            return array('clickcue', "soundcloud://track/".$matches[1]);
        } else {
            return array('clicktrack', $this->tags['file']);
        }
    }

    public function get_artist_track_title() {
        if ($this->tags['Album'] == ROMPR_UNKNOWN_STREAM) {
            return $this->tags['file'];
        } else {
            if ($this->tags['type'] == "stream") {
                return $this->tags['Album'];
            } else {
                return htmlentities($this->tags['Title']).'<br/><span class="playlistrow2">'.htmlentities($this->get_artist_string()).'</span>';
            }
        }
    }
}

function format_artist($artist, $empty = null) {
    $a = concatenate_artist_names($artist);
    if ($a != '.' && $a != "") {
        return $a;
    } else {
        return $empty;
    }
}

function format_sortartist($tags, $return_albumartist = false) {
    global $prefs;
    $sortartist = null;
    if ($prefs['sortbycomposer'] && $tags['Composer'] !== null) {
        if ($prefs['composergenre'] && $tags['Genre'] &&
            checkComposerGenre($tags['Genre'], $prefs['composergenrename'])) {
                $sortartist = $tags['Composer'];
        } else if (!$prefs['composergenre']) {
            $sortartist = $tags['Composer'];
        }
    }
    if ($sortartist == null) {
        if ($return_albumartist || $tags['AlbumArtist'] != null) {
            $sortartist = $tags['AlbumArtist'];
        } else if ($tags['Artist'] != null) {
            $sortartist = $tags['Artist'];
        } else if ($tags['station'] != null) {
            $sortartist = $tags['station'];
        }
    }
    $sortartist = concatenate_artist_names($sortartist);
    //Some discogs tags have 'Various' instead of 'Various Artists'
    if ($sortartist == "Various") {
        $sortartist = "Various Artists";
    }
    return $sortartist;
}

function munge_youtube_track_into_artist($t) {
    // Risky, but mopidy-youtube doesn't return artists (for obvious reasons)
    if (preg_match('/^(.*?)\s*[-|\|+]\s*/', $t, $matches)) {
        if ($matches[1] !== "") {
            return array($matches[1]);
        } else {
            return array("Youtube");
        }
    } else {
        return array("Youtube");
    }
}

function munge_youtube_track_into_album($t) {
    // Even riskier, but mopidy-youtube doesn't return albums except 'Youtube' (for obvious reasons)
    if (preg_match('/^.*?\s*[-|\|+]\s*(.*?)\s+[-|\|+]\s+/', $t, $matches)) {
        if ($matches[1] !== "") {
            return $matches[1];
        }
    }

    if (preg_match('/^.*?\s*[-|\|+]\s*(.*?)\s+[\(|\[]*full album[\)|\]]*/i', $t, $matches)) {
        if ($matches[1] !== "") {
            return $matches[1];
        }
    }

    return "Youtube";

}

function munge_youtube_track_into_title($t) {
    // Risky as fuck!
    if (preg_match('/^.*?\s*[-|\|+]\s*.*?\s+[-|\|+]\s+(.*?)$/', $t, $matches)) {
        return $matches[1];
    } else {
        return $t;
    }
}

function album_from_path($p) {
    $a = rawurldecode(basename(dirname($p)));
    if ($a == ".") {
        $a = '';
    }
    return $a;
}

function artist_from_path($p, $f) {
    $a = rawurldecode(basename(dirname(dirname($p))));
    if ($a == "." || $a == "" || $a == " & ") {
        $a = ucfirst(getDomain(urldecode($f)));
    }
    return $a;
}

function unmopify_file(&$filedata) {
	global $prefs, $collection_type;
	if ($filedata['Pos'] !== null) {
		// Convert URIs for different player types to be appropriate for the collection
		// but only when we're getting the playlist
		if ($prefs['mopidy_slave'] && $filedata['domain'] == 'file') {
			$filedata['file'] = swap_file_for_local($filedata['file']);
			$filedata['domain'] = 'local';
		}
		if ($collection_type == 'mopidy' && $prefs['player_backend'] == 'mpd') {
			$filedata['file'] = mpd_to_mopidy($filedata['file']);
		}
		if ($collection_type == 'mpd' && $prefs['player_backend'] == 'mopidy') {
			$filedata['file'] = mopidy_to_mpd($filedata['file']);
		}
	}
	// eg local:track:some/uri/of/a/file
	// We want the path, not the domain or type
	// This is much faster than using a regexp
	$cock = explode(':', $filedata['file']);
    if (count($cock) > 1) {
        $file = array_pop($cock);
    } else {
		$file = $filedata['file'];
	}
	return $file;
}

function check_undefined_tags(&$filedata, $unmopfile) {
	if ($filedata['Title'] == null) $filedata['Title'] = rawurldecode(basename($filedata['file']));
	if ($filedata['Album'] == null) $filedata['Album'] = album_from_path($unmopfile);
	if ($filedata['Artist'] == null) $filedata['Artist'] = array(artist_from_path($unmopfile, $filedata['file']));
}

function process_file($filedata) {

    global $numtracks, $totaltime, $prefs, $dbterms, $collection, $trackbytrack, $doing_search;

    global $db_time, $coll_time, $rtime;

    // Pre-process the file data

    $mytime = microtime(true);

    if ($dbterms['tags'] !== null || $dbterms['rating'] !== null) {
        // If this is a search and we have tags or ratings to search for, check them here.
        if (check_url_against_database($filedata['file'], $dbterms['tags'], $dbterms['rating']) == false) {
            return false;
        }
    }
   if (strpos($filedata['Title'], "[unplayable]") === 0) {
        debuglog("Ignoring unplayable track ".$filedata['file'],"COLLECTION",9);
        return false;
    }
    if (strpos($filedata['Title'], "[loading]") === 0) {
        debuglog("Ignoring unloaded track ".$filedata['file'],"COLLECTION",9);
        return false;
    }

    $filedata['domain'] = getDomain($filedata['file']);
	$unmopfile = unmopify_file($filedata);

	if ($filedata['Track'] == null) {
        $filedata['Track'] = format_tracknum(basename(rawurldecode($filedata['file'])));
    } else {
        $filedata['Track'] = format_tracknum(ltrim($filedata['Track'], '0'));
    }

    // cue sheet link (mpd only). We're only doing CUE sheets, not M3U
    if ($filedata['X-AlbumUri'] === null && strtolower(pathinfo($filedata['playlist'], PATHINFO_EXTENSION)) == "cue") {
        $filedata['X-AlbumUri'] = $filedata['playlist'];
        debuglog("Found CUE sheet for album ".$filedata['Album'],"COLLECTION");
    }

    // Disc Number
    if ($filedata['Disc'] != null) {
        $filedata['Disc'] = format_tracknum(ltrim($filedata['Disc'], '0'));
    }

    if (strpos($filedata['file'], ':artist:') !== false) {
        $filedata['X-AlbumUri'] = $filedata['file'];
        $filedata['Album'] = get_int_text("label_allartist").concatenate_artist_names($filedata['Artist']);
        $filedata['Disc'] = 0;
        $filedata['Track'] = 0;
    } else if (strpos($filedata['file'], ':album:') !== false) {
        $filedata['X-AlbumUri'] = $filedata['file'];
        $filedata['Disc'] = 0;
        $filedata['Track'] = 0;
    }

    switch($filedata['domain']) {

		case 'local':
            // mopidy-local-sqlite sets album URIs for local albums, but sometimes it gets it very wrong
			// We don't need Album URIs for local tracks, since we can already add an entire album
            $filedata['X-AlbumUri'] = null;
			check_undefined_tags($filedata, $unmopfile);
			$filedata['folder'] = dirname($unmopfile);
			if ($prefs['audiobook_directory'] != '') {
				$f = rawurldecode($filedata['folder']);
				if (strpos($f, $prefs['audiobook_directory']) === 0) {
					debuglog("  This is an audiobook","COLLECTION");
					$filedata['type'] = 'audiobook';
				}
			}
            break;

		case 'http':
		case 'https':
		case 'mms':
		case 'mmsh':
		case 'mmst':
		case 'mmsu':
		case 'gopher':
		case 'rtp':
		case 'rtsp':
		case 'rtmp':
		case 'rtmpt':
		case 'rtmps':
		case 'dirble':
		case 'tunein':
		case 'radio-de':
		case 'audioaddict':
		case 'oe1':
		case 'bassdrive':
			preprocess_stream($filedata);
			break;

        case "soundcloud":
			preprocess_soundcloud($filedata);
            break;

        case "youtube":
            $filedata['folder'] = $filedata['file'];
            $filedata['Artist'] = munge_youtube_track_into_artist($filedata['Title']);
            $filedata['Album'] = munge_youtube_track_into_album($filedata['Title']);
            $filedata['Title'] = munge_youtube_track_into_title($filedata['Title']);
            $filedata['AlbumArtist'] = $filedata['Artist'];
            $filedata['X-AlbumUri'] = $filedata['file'];
            break;

        case "spotify":
            $filedata['folder'] = $filedata['X-AlbumUri'];
            break;

        case "internetarchive":
			check_undefined_tags($filedata, $unmopfile);
            $filedata['X-AlbumUri'] = $filedata['file'];
            $filedata['folder'] = $filedata['file'];
            $filedata['AlbumArtist'] = "Internet Archive";
            break;

        case "podcast":
            $filedata['folder'] = $filedata['X-AlbumUri'];
			// $filedata['AlbumArtist'] = "Podcasts";
			// $filedata['Artist'] = "Podcasts";
			if ($filedata['Artist'] !== null) {
				$filedata['AlbumArtist'] = $filedata['Artist'];
			}
			if ($filedata['AlbumArtist'] === null) {
                $filedata['AlbumArtist'] = array("Podcasts");
			}
            if (is_array($filedata['Artist']) &&
				($filedata['Artist'][0] == "http" ||
				$filedata['Artist'][0] == "https" ||
                $filedata['Artist'][0] == "ftp" ||
				$filedata['Artist'][0] == "file" ||
                substr($filedata['Artist'][0],0,7) == "podcast")) {
                $filedata['Artist'] = $filedata['AlbumArtist'];
            }
			$filedata['type'] = 'podcast';
            break;

        default:
			check_undefined_tags($filedata, $unmopfile);
            $filedata['folder'] = dirname($unmopfile);
            break;
    }

    $rtime += microtime(true) - $mytime;

    if ($doing_search) {
		// If we're doing a search, we check to see if that track is in the database
		// because the user might have set the AlbumArtist to something different
        $tstart = microtime(true);
		$filedata = array_replace($filedata, get_extra_track_info($filedata));
        $db_time += microtime(true) - $tstart;
    }

    if ($filedata['Pos'] !== null) {
        // Playlist track. Swerve the collectioniser and use the database
        $tstart = microtime(true);
		$filedata = array_replace($filedata, get_extra_track_info($filedata));
        $db_time += microtime(true) - $tstart;
        $cstart = microtime(true);
        doNewPlaylistFile($filedata);
        $coll_time += microtime(true) - $cstart;
    } else if ($trackbytrack && $filedata['AlbumArtist'] && $filedata['Disc'] !== null) {
        // We have a track with full info and so we can insert it directly into the database.
        $tstart = microtime(true);
        do_track_by_track( new track($filedata) );
        $db_time += microtime(true) - $tstart;
    } else  {
        // Tracks without full info end up in the collection data structures.
        // During a collection update we sort the tracks album-by-album - i.e. after each directory is scanned.
		// After each directory we empty the collection data structures which saves enormously on memory.
        // This means we don't catch the very few edge cases where someone might have incompletely
        // tagged files spread across multiple directories, but that's so unlikely I doubt it'll ever happen.

		// But one place it might happen is during search, where we can't assume tracks will come in album order
		// (especially from Spotify etc). So when doing a search we don't do album-by-album.

        $cstart = microtime(true);
        if ($filedata['Disc'] === null) $filedata['Disc'] = 1;
        $t = new track($filedata);
        $collection->newTrack( $t );
        $coll_time += microtime(true) - $cstart;
    }

    $numtracks++;
    $totaltime += $filedata['Time'];
}

?>

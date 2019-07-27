<?php

chdir ('..');
include ("includes/vars.php");
include ("includes/functions.php");
include ("international.php");
include ("backends/sql/backend.php");
include ('utils/phpQuery.php');
require_once ('utils/imagefunctions.php');

logger::log("USERSTREAMS", "Doing User Radio Stuff");

if (array_key_exists('populate', $_REQUEST)) {
    do_radio_list();
} else if (array_key_exists('remove', $_REQUEST)) {
    remove_user_radio_stream($_REQUEST['remove']);
    do_radio_list();
} else if (array_key_exists('order', $_REQUEST)) {
    save_radio_order($_REQUEST['order']);
} else if (array_key_exists('addfave', $_REQUEST)) {
    add_fave_station($_REQUEST);
    do_radio_list();
} else if (array_key_exists('updatename', $_REQUEST)) {
    update_radio_station_name($_REQUEST);
    do_radio_list();
}

function do_radio_list() {

    $playlists = get_user_radio_streams();

    foreach($playlists as $playlist) {

        $albumimage = new albumImage(array('artist' => 'STREAM', 'album' => $playlist['StationName']));

        $html = albumHeader(array(
            'id' => 'nodrop',
            'Image' => $playlist['Image'],
            'Searched' => 1,
            'AlbumUri' => null,
            'Year' => null,
            'Artistname' => null,
            'Albumname' => utf8_encode($playlist['StationName']),
            'why' => 'whynot',
            'ImgKey' => $albumimage->get_image_key(),
            'streamuri' => $playlist['PlaylistUrl'],
            'streamname' => $playlist['StationName'],
            'streamimg' => $playlist['Image'],
            'class' => 'faveradio',
            'expand' => true
        ));

        $out = addUserRadioButtons($html, $playlist['Stationindex'], $playlist['PlaylistUrl'], $playlist['StationName'], $playlist['Image']);
        print $out->html();

    }

}

?>

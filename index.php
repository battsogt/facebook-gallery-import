<?php
require('init.php');
header('Content-Type: text/html; charset=utf-8');

if (isset($_POST['gallery_export_url'])) {
	$_SESSION['gallery_export_url'] = trim($_POST['gallery_export_url']);
	if (strstr($_SESSION['gallery_export_url'], '/fb/export_fb') === FALSE) {
		if (strstr($_SESSION['gallery_export_url'], '/export_facebook/export_fb') === FALSE) {
			die("Error: Gallery2: the export_fb.php script needs to be in a 'fb' subdirectory of your Gallery installation. Example: http://www.domain.com/gallery/fb/export_fb.php. Gallery3: your URL needs to end with /export_facebook/export_fb");
		}
	}
	if (strpos($_SESSION['gallery_export_url'], '/fb/export_fb')) {
		$_SESSION['gallery_version'] = 2;
	} else {
		$_SESSION['gallery_version'] = 3;
	}
	$_COOKIE['gallery_url'] = $_SESSION['gallery_export_url'];
	if (preg_match('@(https?://.+)/index.php/export_facebook/export_fb@', $_SESSION['gallery_export_url'], $regs)) {
		$_SESSION['gallery_url'] = $regs[1];
	} else if (preg_match('@(https?://.+)/export_facebook/export_fb@', $_SESSION['gallery_export_url'], $regs)) {
		$_SESSION['gallery_url'] = $regs[1];
	} else if (preg_match('@(https?://.+)/fb/export_fb@', $_SESSION['gallery_export_url'], $regs)) {
		$_SESSION['gallery_url'] = $regs[1];
	} else {
		die("Error: Can't isolate hostname in URL " . $_SESSION['gallery_export_url']);
	}
	$url = $_SESSION['gallery_export_url'] . '?a=albums';
	$html = file_get_contents($url);
	if ($html === FALSE) {
		$error404 = TRUE;
		$template = 'step1.html';
	} else {
		$albums_infos = explode("\n", $html);
		$template = 'step2.html';
	}
} else if (isset($_POST['gallery_album']) || isset($_GET['gallery_album'])) {
	if (isset($_GET['gallery_album'])) {
		$_POST['gallery_album'] = $_GET['gallery_album'];
	}
	$albumId = array_shift(explode(';', $_POST['gallery_album']));
	$_SESSION['gallery_name'] = str_replace("$albumId;", "", $_POST['gallery_album']);

	$url = $_SESSION['gallery_export_url'] . '?a=photos&id=' . $albumId;
	
	$ctx = stream_context_create(array('http' => array('timeout' => 5*60))); 
	$html = file_get_contents($url, 0, $ctx);
	
	$photos_infos = explode("\n", $html);

	$num_photos = sizeof($photos_infos) - 1;

	$template = 'step3.html';

} else if (isset($_POST['gallery_photos'])) {
	$_SESSION['gallery_selected_photos'] = $_POST['gallery_photos'];
	if (isset($_POST['captions'])) {
		$_SESSION['gallery_captions'] = $_POST['captions'];
	}
	if (!is_array($_SESSION['gallery_captions'])) {
		$_SESSION['gallery_captions'] = array($_SESSION['gallery_captions']);
	}
	$_SESSION['gallery_redirects'] = 1;
	$_SESSION['gallery_uploads'] = 0;
	$_SESSION['gallery_skips'] = 0;
	$_SESSION['gallery_existing_photos'] = array();
	session_write_close();
	$facebook->redirect("http://gallery.danslereseau.com/fb/?gallery_import=".time());

} else if (isset($_GET['gallery_import'])) {

	while (TRUE) {
		if (empty($_SESSION['gallery_selected_photos'])) {
			// Done. Redirecting to new album so you can approve newly uploaded photos
			$facebook->redirect($_SESSION['gallery_album']['link']);
		}

		// Create a new album to continue the import
		if ($_SESSION['gallery_uploads'] == 0 && $_SESSION['gallery_skips'] == 0) {
			$gallery_name = $_SESSION['gallery_name'];
			$album_exists = FALSE;
		    $albums = $facebook->api_client->photos_getAlbums(null, null, null);
			if ($albums && is_array($albums)) {
				foreach ($albums as $album) {
					if ($album['name'] == $gallery_name) {
						$album_exists = TRUE;
						break;
					}
				}
			}
			if (!$album_exists) {
			    $album = $facebook->api_client->photos_createAlbum($gallery_name);
			}

			$_SESSION['gallery_album'] = $album;
			$p = $facebook->api_client->photos_get(null, $_SESSION['gallery_album']['aid'], null);
			if (is_array($p)) {
				$_SESSION['gallery_existing_photos'] = array_merge($_SESSION['gallery_existing_photos'], $p);
			}
		}

		$photo_url = array_shift($_SESSION['gallery_selected_photos']);
		$photo = $_SESSION['gallery_photos'][$photo_url];
		$photo->caption = get_photo_caption($photo);
		
		// Try not to re-upload a photo that is already there.
		$existing_photo = FALSE;
		if (!empty($_SESSION['gallery_existing_photos'])) {
			foreach ($_SESSION['gallery_existing_photos'] as $old_photo) {
				if ($old_photo['caption'] == $photo->caption) {
					$existing_photo = TRUE;
					break;
				}
			}
		}
		if (!$existing_photo) {
			if ($photo->url[0] == '/') {
				$photo_url = $_SESSION['gallery_url'] . $photo->url;
			} else {
				$photo_url = $photo->url;
			}
			try {
			    $photo = $facebook->api_client->photos_upload($photo_url, $_SESSION['gallery_album']['aid'], $photo->caption);
			} catch (FacebookRestClientException $e) {
				if ($e->getMessage() == 'Album is full') {
					# Create a new album
					$album = create_next_album($_SESSION['gallery_name']);
					$_SESSION['gallery_album'] = $album;
					$p = $facebook->api_client->photos_get(null, $_SESSION['gallery_album']['aid'], null);
					if (is_array($p)) {
						$_SESSION['gallery_existing_photos'] = array_merge($_SESSION['gallery_existing_photos'], $p);
					}
					# Retry the upload
				    $photo = $facebook->api_client->photos_upload($photo_url, $_SESSION['gallery_album']['aid'], $photo->caption);
				}
  		} catch (Exception $e) {
  			$facebook->redirect("http://gallery.danslereseau.com/fb/?url_get_error=" . urlencode($photo_url));
			}
			$_SESSION['gallery_uploads']++;
			break;
		}
		$_SESSION['gallery_skips']++;
	}

	$_SESSION['gallery_redirects']++;
	if ($_SESSION['gallery_redirects'] <= 15) {
		$facebook->redirect("http://gallery.danslereseau.com/fb/?gallery_import=".time());
	} else {
		$_SESSION['gallery_redirects'] = 0;
		$template = 'step4.html';
	}

} else if (isset($_GET['url_get_error'])) {
  die("There was a problem trying to fetch a photo using <a href=\"" . $_GET['url_get_error'] . "\">the following URL</a>.<br/>Please check that this URL returns an image file for users not logged in on your Gallery, then try again.");

} else {
	$template = 'step1.html';
}

function get_photo_caption($photo) {
	$caption = '';
	if (array_search('title', $_SESSION['gallery_captions']) !== FALSE && !empty($photo->title)) {
		$caption .= clean_gallery_text(html_entity_decode($photo->title));
	}
	if (array_search('summary', $_SESSION['gallery_captions']) !== FALSE && !empty($photo->summary)) {
		if (strlen($caption) > 0) { $caption .= ' - '; }
		$caption .= clean_gallery_text(html_entity_decode($photo->summary));
	}
	if (array_search('keywords', $_SESSION['gallery_captions']) !== FALSE && !empty($photo->keywords)) {
		if (strlen($caption) > 0) { $caption .= ' - '; }
		$caption .= html_entity_decode($photo->keywords);
	}
	if (array_search('description', $_SESSION['gallery_captions']) !== FALSE && !empty($photo->description)) {
		if (strlen($caption) > 0) { $caption .= ' - '; }
		$caption .= clean_gallery_text(html_entity_decode($photo->description));
	}
	if (array_search('url', $_SESSION['gallery_captions']) !== FALSE) {
		if (strlen($caption) > 0) { $caption .= ' - '; }
		if ($_SESSION['gallery_version'] == 2) {
			$caption .= $_SESSION['gallery_url'] . '/main.php?g2_itemId=' . $photo->id;
		} else {
			$caption .= $_SESSION['gallery_url'] . '/index.php/photos/' . $photo->id;
		}
	}
	return $caption;
}

function clean_gallery_text($text) {
	$text = preg_replace('@\[url=(.+)\](.+)\[/url\]@i', '\2 [\1]', $text);
	$text = str_replace(array('[b]', '[/b]', '[i]', '[/i]', '[/color]', '[list]', '[/list]', '[*]'), '', $text);
	$text = preg_replace('@\[color=#......\]@i', '', $text);
	return $text;
}

function create_next_album($root_name) {
	global $facebook;
	$new_album = null;
    $albums = $facebook->api_client->photos_getAlbums(null, null, null);
	if ($albums && is_array($albums)) {
		$max_num = 0;
		foreach ($albums as $album) {
			if (strpos($album['name'], $root_name) === 0) {
				if ($album['name'] == $root_name && $max_num == 0) {
					$max_num = 1;
				}
				if (preg_match('@'.$root_name.' \(([0-9]+)\)@', $album['name'], $regs)) {
					$num = (int) $regs[1];
					if ($num > $max_num) {
						$max_num = $num;
					}
				}
			}
		}
		$next_num = $max_num+1;
		if ($next_num == 1) {
			$gallery_name = $root_name;
		} else {
			$gallery_name = "$root_name ($next_num)";
		}
		$new_album = $facebook->api_client->photos_createAlbum($gallery_name);
	}
	return $new_album;
}

include('template.html');
session_write_close();

?>

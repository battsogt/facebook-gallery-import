<?php
/*
  This script is a helper script for the 'Gallery Import' Facebook Application.
  @version r5

  Instructions:
    1. Create a new 'fb' subdirectory in your Gallery installation (using FTP or SSH), i.e. in the directory that contains main.php etc.
    2. Rename this file 'export_fb.php' and upload it in this new directory.
       The resulting script should be available at: http://your_gallery_url/fb/export_fb.php
       This URL is what the 'Gallery Import' Facebook Application will ask you in Step 1.
*/

ob_start();
require_once(dirname(__FILE__) . '/../main.php');
ob_clean();
list ($ret, $user) = GalleryCoreApi::fetchUserByUsername("guest");
$gallery->setActiveUser($user);

// $_SESSION['gallery_url'] . '/fb/export_fb.php?a=albums'
// Returns: Level, Album ID, Album Title
if ($_GET['a'] == 'albums') {
	list ($ret, $albumsTree) = GalleryCoreApi::fetchAlbumTree();
	list ($ret, $albums) = GalleryCoreApi::fetchAllItemIds('GalleryAlbumItem');
	list ($ret, $albums) = GalleryCoreApi::loadEntitiesById($albums);

	$albumsById = array();
	foreach ($albums as $album) {
		$albumsById[$album->id] = $album;
	}

	function outputAlbumTree($tree, $level=0) {
		global $albumsById;
		foreach ($tree as $aid => $childs) {
			$title = $albumsById[$aid]->title;
			if (empty($title)) {
				$title = $albumsById[$aid]->pathComponent;
			}
			$title = str_replace(array("\n","\t"), ' ', str_replace("\r",'',trim($title)));
			list ($ret, $photos) = GalleryCoreApi::fetchChildItemIds($albumsById[$aid]);
			$num_photos = sizeof($photos);
			echo "$level\t$aid\t$title\t$num_photos\n";
			if (!empty($childs)) {
				outputAlbumTree($childs, $level+1);
			}
		}
	}
	outputAlbumTree($albumsTree);
}

// $_SESSION['gallery_url'] . '/fb/export_fb.php?a=photos&id=' . $_GET['gallery_album']
// Returns: Item ID, Caption, Photo URL, Thumbnail URL
else if ($_GET['a'] == 'photos') {
	list ($ret, $albums) = GalleryCoreApi::loadEntitiesById(array($_GET['id']));
	list ($ret, $photos) = GalleryCoreApi::fetchChildItemIds($albums[0]);
	list ($ret, $thumbs) = GalleryCoreApi::fetchThumbnailsByItemIds($photos);
	list ($ret, $photos) = GalleryCoreApi::loadEntitiesById($photos);
	
	foreach ($photos as $photo) {
		if ($photo->mimeType != 'image/jpeg' && $photo->mimeType != 'image/gif' && $photo->mimeType != 'image/png') {
			continue;
		}
		$thumb = $thumbs[$photo->id];

		// If there is a preferred derivative (like a watermarked photo), use it instead of the original
		list ($ret, $derivativeTable) = GalleryCoreApi::fetchDerivativesByItemIds(array($photo->id));
		$derivatives = array();
		$add = array();
		$hasPreferred = false;
		if (isset($derivativeTable[$photo->id])) {
		    $derivatives = $derivativeTable[$photo->id];
		    foreach ($derivatives as $derivative) {
				if ($derivative->getDerivativeType() == DERIVATIVE_TYPE_IMAGE_PREFERRED) {
					$derivative->title = $photo->title;
					$photo = $derivative;
				}
		    }
		}

		$photo->description = str_replace("\n", '\n', str_replace("\r", '', $photo->description));
		$photo->keywords = str_replace("\n", '\n', str_replace("\r", '', $photo->keywords));

		echo "$photo->id\t$photo->title\t/main.php?g2_view=core.DownloadItem&g2_itemId=$photo->id&g2_serialNumber=1\t/main.php?g2_view=core.DownloadItem&g2_itemId=$thumb->id&g2_serialNumber=2\t$photo->summary\t$photo->description\t$photo->keywords\n";
	}
}
?>
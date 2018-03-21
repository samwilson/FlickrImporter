<?php

namespace MediaWiki\Extension\FlickrImporter;
use Exception;
use ExtensionRegistry;
use JsonContent;
use Maintenance;
use MediaWiki\MediaWikiServices;
use MWException;
use Samwilson\PhpFlickr\Util;
use Status;
use stdClass;
use Title;
use UploadFromUrl;
use User;
use WikiPage;

/**
 * Maintenance script to import photos from Flickr.
 */
class MaintenanceFlickrImporter extends Maintenance {

	/** @var FlickrImporter */
	protected $flickrImporter;

	/** @var string[][] Runtime cache of Flickr license names. */
	protected $licenses;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import photos from Flickr.' );
	}

	public function execute() {
		// Make sure the extension is loaded.
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'FlickrImporter' ) ) {
			$this->output("The FlickrImporter extension is not loaded.\n");
			return false;
		}

		// Find all the users' import JSON pages.
		$searchEngine = MediaWikiServices::getInstance()->newSearchEngine();
		$searchEngine->setNamespaces( [ NS_USER ] );
		$searchResults = $searchEngine->searchTitle( 'FlickrImporter.json' );
		$total = $searchResults->numRows();
		$this->output( $total . " pages found\n" );
		foreach ( $searchResults->extractTitles() as $titleNum => $title ) {
			$this->output( ( $titleNum + 1 ) . '/' . $total . ' - ' . $title->getDBkey() . "\n" );
			$this->processOneJsonPage( $title );
		}
	}

	protected function processOneJsonPage( Title $title ) {
		$username = $title->getRootText();
		$user = User::newFromName( $username );
		$this->output( "    For User:" . $user->getName() . "\n" );
		/** @var JsonContent $importString */
		$importContent = WikiPage::factory( $title )->getContent();
		if ( !$importContent instanceof JsonContent ) {
			return false;
		}
		$imports = $importContent->getData()->getValue();
		foreach ( $imports as $import ) {
			// Validate import structure.
			if ( !isset( $import->type ) || !isset( $import->id ) ) {
				$this->error( 
					"Import has no 'type' or 'id' defined:\n"
					. json_encode( $import, JSON_PRETTY_PRINT )
				);
				continue;
			}
			// Run the import.
			$this->processOneImport( $user, $import );
		}
	}

	/**
	 * @param User $user
	 * @param stdClass $import
	 */
	protected function processOneImport( User $user, $import ) {
		// Display the label.
		if (isset($import->description)) {
			$this->output( '    - Import: ' . $import->description . "\n" );
		}

		// Set up Flickr.
		$this->flickrImporter = new FlickrImporter( $user );

		// Determine user ID.
		if ( $import->type === 'user' ) {
			// Validate the user ID.
			$userInfo1 = $this->flickrImporter->getPhpFlickr()->people_getInfo( $import->id );
			if ( !isset( $userInfo1['id'] ) ) {
				// If not found, try as a username.
				$userInfo2 = $this->flickrImporter->getPhpFlickr()->people()->findByUsername( $import->id );
				if ( !isset( $userInfo2['id'] ) ) {
					$this->error( "Unable to determine ID for user '$import->id'\n" );
					return;
				}
				// Use the user's ID instead of username.
				$import->id = $userInfo2['id'];
			}
		}

		// Sanitize privacy.
		if ( !isset( $import->privacy ) ) {
			$import->privacy = Util::PRIVACY_PUBLIC;
		}
		if ( !is_array( $import->privacy ) ) {
			$import->privacy = [ $import->privacy ];
		}

		// Get the photos.
		$page = 1;
		do {
			$photos = $this->getPhotos( $import, $page );
			// Make sure we got something.
			if ( $photos === false ) {
				$this->output( "      Unable to find any photos for $import->type '$import->id'\n" );
				return;
			}
			$this->output( 
				'Page ' . $photos['page'] . ' of ' . $photos['pages']
				. " (". $photos['total'] ." photos)\n"
			);
			// Import this page's worth of photos.
			foreach ( $photos['photo'] as $photoInfo ) {
				$photoInfo['privacy'] = Util::privacyLevel(
					$photoInfo['ispublic'],
					$photoInfo['isfriend'],
					$photoInfo['isfamily']
				);
				if ( !in_array( $photoInfo['privacy'], $import->privacy ) ) {
					// Ignore this photo if it's privacy level is not listed in the ones
					// we want for this import.
					continue;
				}
				$this->importOnePhoto( $photoInfo, $user );
			}
			$page++;
		} while ($page < $photos['pages']);
	}

	protected function getPhotos($import, $page = 1) {
		// Request parameters.
		$extras = 'description, license, date_upload, date_taken, owner_name, original_format, '
				. 'last_update, geo, tags, machine_tags, media, url_o';
		$perPage = 500;

		// Get photos.
		$phpFlickr = $this->flickrImporter->getPhpFlickr();
		if ( $import->type === 'group' ) {
			$photos = $phpFlickr->groups_pools_getPhotos(
				$import->id, null, null, null, $extras, $perPage, $page
			);
		} elseif ( $import->type === 'user' ) {
			$photos = $phpFlickr->people()->getPhotos(
				$import->id, null, null, null,
				null, null, null, null, $extras,
				$perPage, $page
			);
		} elseif ( $import->type === 'album' ) {
			$photos = $phpFlickr->photosets_getPhotos(
				$import->id, $extras, null, $perPage, $page
			);
		} elseif ( $import->type === 'gallery' ) {
			$photos = $phpFlickr->galleries_getPhotos( $import->id, $extras, $perPage, $page );
		} else {
			$this->error( "Unknown import type '$import->type'\n" );
			return false;
		}
		return $photos;
	}

	/**
	 * @param $photo
	 * @param User $user
	 * @throws MWException
	 */
	public function importOnePhoto( $photo, User $user ) {
		$title = $photo['title'];
		$photopageUrl = 'https://flic.kr/p/' . Util::base58encode( $photo['id'] );

		// See if we need to import this.
		$alreadyImported = $this->flickrImporter->findFlickrPhoto( $photo['id'] );
		if ( $alreadyImported instanceof WikiPage ) {
			$this->output(
				"      - " . $photo['id'] . " already imported as "
				. $alreadyImported->mTitle->getDBkey() . " $photopageUrl\n"
			);
			return;
		}

		// Set up the page template and any other wikitext.
		$this->output( "      - {$photo['id']} importing $title $photopageUrl\n" );
		$fileUrl = $photo['url_o'];
		$templateName = wfMessage( 'flickrimporter-template-name' );
		$latitude = empty( $photo['latitude'] ) ? '' : $photo['latitude'];
		$longitude = empty( $photo['longitude'] ) ? '' : $photo['longitude'];
		$wikiText = '{{' . $templateName . "\n"
			. ' | title = ' . $title. "\n"
			. ' | description = ' . $photo['description'] . "\n"
			. ' | author = ' . $photo['ownername'] . "\n"
			. ' | date_taken = ' . $photo['datetaken'] . "\n"
			. ' | date_taken_granularity = ' . $photo['datetakengranularity'] . "\n"
			. ' | date_published = ' . date( 'Y-m-d H:i:s', $photo['dateupload'] ) . "\n"
			. ' | latitude = ' . $latitude . "\n"
			. ' | longitude = ' . $longitude . "\n"
			. ' | license = ' . $this->getLicenseName($photo['license']) . "\n"
			. ' | privacy = ' . Util::getPrivacyLevelById( $photo['privacy'] ) . "\n"
			. ' | flickr_id = ' . $photo['id'] . "\n"
			. '}}' . "\n";

		// We also have to query info on each photo, to get the tags and comments.
		$photoInfo = $this->flickrImporter->getPhpFlickr()->photos()->getInfo( $photo['id'] );
		if (!$photoInfo) {
			throw new Exception( 'Unable to fetch information about Flickr photo ' . $photo['id'] );
		}

		// Tags.
		foreach ( $photoInfo['tags']['tag'] as $tag ) {
			if ( $tag['machine_tag'] ) {
				continue;
			}
			$tagTitle = Title::newFromText( 'Category:' . $tag['raw'] );
			$wikiText .= "[[" . $tagTitle->getFullText() . "]]\n";
		}

		// Sets (a.k.a. albums).
		$sets = $this->flickrImporter->getPhpFlickr()
			->photos()
			->getSets( [ $photo['id'] ], $photo['owner'] );
		foreach ( $sets as $set ) {
			$setTitle = Title::newFromText( 'Category:' . $set['title'] );
			$wikiText .= "[[" . $setTitle->getFullText() . "]]\n";
		}

		// Upload the file. It will not be uploaded if it already exists.
		$upload = new UploadFromUrl();
		$fileTitle = $this->flickrImporter->getUniqueFilename( $title )
			. '.' . $photo['originalformat'];
		$upload->initialize( $fileTitle, $fileUrl );
		/** @var Status $status */
		$status = $upload->fetchFile();
		if ( !$status->isGood() ) {
			$this->error(
				"        Unable to get file $fileUrl\n"
				 ."        Status: " . $status->getMessage()
			);
			return;
		}
		$comment = wfMessage( 'flickrimporter-upload-comment', $photopageUrl );
		$uploadStatus = $upload->performUpload(
			$comment->plain(), $wikiText, true, $user, [ 'FlickrImporter' ]
		);
		if ( !$uploadStatus->isGood() ) {
			$this->error("        " . $uploadStatus->getMessage()->plain() );
			return;
		}
		$this->output( "        imported as: $fileTitle\n" );
	}

	/**
	 * Get the English name of the given license.
	 * @link https://www.flickr.com/services/api/flickr.photos.licenses.getInfo.html
	 * @param $licenseId
	 * @return string
	 */
	public function getLicenseName($licenseId) {
		if (is_null($this->licenses)) {
			$this->licenses = $this->flickrImporter->getPhpFlickr()->photosLicenses()->getInfo();
		}
		return (isset($this->licenses[$licenseId])) ? $this->licenses[$licenseId]['name'] : ''; 
	}
}

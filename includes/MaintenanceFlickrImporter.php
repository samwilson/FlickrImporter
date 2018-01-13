<?php

namespace MediaWiki\Extension\FlickrImporter;
use ExtensionRegistry;
use JsonContent;
use Maintenance;
use MediaWiki\MediaWikiServices;
use Samwilson\PhpFlickr\PhpFlickr;
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

	/** @var PhpFlickr */
	protected $flickr;

	/** @var string[][] Runtime cache of Flickr license names. */
	protected $licenses;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import photos from Flickr.' );
	}

	public function execute() {
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
		$flickrImporter = new FlickrImporter( $user );
		$this->flickr = $flickrImporter->getPhpFlickr();

		// Determine user ID.
		if ( $import->type === 'user' ) {
			// Validate the user ID.
			$userInfo1 = $this->flickr->people_getInfo( $import->id );
			if ( !isset( $userInfo1['id'] ) ) {
				// If not found, try as a username.
				$userInfo2 = $this->flickr->people()->findByUsername( $import->id );
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
		if ( $import->type === 'group' ) {
			$photos = $this->flickr->groups_pools_getPhotos(
				$import->id, null, null, null, $extras, $perPage, $page
			);
		} elseif ( $import->type === 'user' ) {
			$photos = $this->flickr->people()->getPhotos(
				$import->id, null, null, null,
				null, null, null, null, $extras,
				$perPage, $page
			);
		} elseif ( $import->type === 'album' ) {
			$photos = $this->flickr->photosets_getPhotos(
				$import->id, $extras, null, $perPage, $page
			);
		} elseif ( $import->type === 'gallery' ) {
			$photos = $this->flickr->galleries_getPhotos( $import->id, $extras, $perPage, $page );
		} else {
			$this->error( "Unknown import type '$import->type'\n" );
			return false;
		}
		return $photos;
	}

	public function importOnePhoto( $photo, User $user ) {
		$templateName = wfMessage( 'flickrimporter-template-name' );
		$this->output(
			"      - Photo " . $photo['id'] . " -- ".$photo['title'] ."\n"
		);
		$title = $photo['title'];
		$photopageUrl = 'https://flic.kr/p/' . Util::base58encode( $photo['id'] );
		$fileUrl = $photo['url_o'];
		$license = $this->getLicenseName($photo['license']);

		$wikiText = '{{' . $templateName . "\n"
			. ' | title = ' . $title. "\n"
			. ' | description = ' . $photo['description'] . "\n"
			. ' | author = ' . $photo['ownername'] . "\n"
			. ' | date_taken = ' . $photo['datetaken'] . "\n"
			. ' | date_taken_granularity = ' . $photo['datetakengranularity'] . "\n"
			. ' | date_published = ' . date( 'Y-m-d H:i:s', $photo['dateupload'] ) . "\n"
			. ' | latitude = ' . $photo['latitude'] . "\n"
			. ' | longitude = ' . $photo['longitude'] . "\n"
			. ' | license = ' . $license . "\n"
			. ' | privacy = ' . $photo['privacy'] . "\n"
			. ' | flickr_id = ' . $photo['id'] . "\n"
			. ' | flickr_page_url = ' . $photopageUrl . "\n"
			. ' | flickr_file_url = ' . $fileUrl . "\n"
			. '}}' . "\n";
		if ( ExtensionRegistry::getInstance()->isLoaded( 'GeoData' ) ) {
			// Add coords directly
			$wikiText .= "{{#coordinates:{$photo['latitude']}|${photo['longitude']}|primary}}\n"; 
		}

		// We also have to query info on each photo, to get the tags and comments.
		$photoInfo = $this->flickr->photos_getInfo( $photo['id'] );

		foreach ( $photoInfo['photo']['tags']['tag'] as $tag ) {
			if ( $tag['machine_tag'] ) {
				continue;
			}
			$tagTitle = Title::newFromText( 'Category:' . $tag['raw'] );
			$wikiText .= "[[" . $tagTitle->getFullText() . "]]\n";
		}

		// Upload the file. It will not be uploaded if it already exists.
		$upload = new UploadFromUrl();
		$upload->initialize( $title, $fileUrl );
		/** @var Status $status */
		$status = $upload->fetchFile();
		if ( !$status->isGood() ) {
			$this->error("Unable to get file $fileUrl\nStatus: " . $status->getMessage() );
			exit();
		}
		$comment = wfMessage( 'flickrimporter-upload-comment', $photopageUrl );
		$uploadStatus = $upload->performUpload(
			$comment->plain(), $wikiText, true, $user, [ 'FlickrImporter' ]
		);
		if ( !$uploadStatus->isGood() ) {
			$this->error("        " . $uploadStatus->getMessage()->plain() );
		}
	}

	public function getLicenseName($licenseId) {
		if (is_null($this->licenses)) {
			$this->licenses = $this->flickr->photosLicenses()->getInfo();
		}
		return (isset($this->licenses[$licenseId])) ? $this->licenses[$licenseId]['name'] : ''; 
	}
}

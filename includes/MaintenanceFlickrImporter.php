<?php

namespace MediaWiki\Extension\FlickrImporter;
use JsonContent;
use Maintenance;
use MediaWiki\MediaWikiServices;
use stdClass;
use Title;
use User;
use WikiPage;

/**
 * Maintenance script to import photos from Flickr.
 */
class MaintenanceFlickrImporter extends Maintenance {

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
			$this->processOneImport( $user, $import );
		}
	}

	/**
	 * @param User $user
	 * @param stdClass $import
	 */
	protected function processOneImport( User $user, $import ) {
		// Display the label.
		if (isset($import->label)) {
			$this->output( '    - ' . $import->label . "\n" );
		}

		// Set up Flickr.
		$flickrImporter = new FlickrImporter( $user );
		$flickr = $flickrImporter->getPhpFlickr();

		if (isset($import->user)) {
			$userInfo1 = $flickr->people_getInfo( $import->user );
			if ( !isset( $userInfo1['id'] ) ) {
				$userInfo2 = $flickr->people_findByUsername( $import->user );
				if (!isset($userInfo2['id'])) {
					$this->output( "Unable to determine ID for user '$import->user'\n" );
					return;
				}
				$userId = $userInfo2['id'];
			} else {
				$userId = $userInfo1['id'];
			}
			$photos = $flickr->people_getPhotos( $userId );
			if ( $photos === false ) {
				$this->output( "      Unable to find any photos for '$import->user'\n" );
			}
			$this->output(
				'      ' . $photos['photos']['total'] . ' photos found for ' . $import->user . "\n"
			);
			// Pages
			foreach ( $photos['photos']['pages'] as $pageNum ) {
				// Import this page's worth of photos.
				foreach ( $photos['photos']['photo'] as $photoInfo ) {
					$this->importOnePhoto( $photoInfo );
				}
				// Then get the next page.
				$photos2 = $flickr->people_getPhotos( $userId, [ 'page' => $pageNum + 1 ] );
			}
			
		}
	}

	public function importOnePhoto( $photoInfo ) {
		$templateName = wfMessage( 'flickrimporter-template-name' );
		$wikiText = '{{' . $templateName . "\n"
			. ' | title = ' . $photoInfo['title'] . "\n"
			. ' | flickr_id = ' . $photoInfo['id'] . "\n"
			. '}}' . "\n";
	}
}

<?php
/**
 * Hooks for the FlickrImporter extension
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\FlickrImporter;

use Html;
use MediaWiki\MediaWikiServices;
use Samwilson\PhpFlickr\PhpFlickr;
use SpecialPage;
use User;

class Hooks {

	/**
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 * @param User $user
	 * @param array $preferences
	 * @return bool
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
//		$config = MediaWikiServices::getInstance()
//			->getConfigFactory()
//			->makeConfig( 'flickrimporter' );
//		if ( !$config->has( 'FlickrImporterKey' )
//			 || !$config->has( 'FlickrImporterSecret' )
//		) {
//			// Not configured; do nothing.
//			return true;
//		}
//		$flickr = new PhpFlickr(
//			$config->get( 'FlickrImporterKey' ),
//			$config->get( 'FlickrImporterSecret' )
//		);
		$flickrImporter = new FlickrImporter( $user );
		$flickr = $flickrImporter->getPhpFlickr();
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		if ( $username = $flickr->test_login() ) {
			// Logged in.
			$logoutLink = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'FlickrImporter', 'disconnect' ),
				wfMessage('flickrimporter-disconnect')
			);
			$loginoutDefault = wfMessage('flickrimporter-connected', $username )
				. ' ' . $logoutLink;
		} else {
			// Not logged in.
			$loginLink = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'FlickrImporter', 'connect' ),
				wfMessage('flickrimporter-connect')
			);
			$loginoutDefault = wfMessage('flickrimporter-not-connected') . ' ' . $loginLink;
		}
		$preferences['flickrimporter-loginout'] = [
			'type' => 'info',
			'raw' => true,
			'default' => $loginoutDefault,
			'section' => 'misc/flickrimporter',
		];
		
		return true;
	}

}

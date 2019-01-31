<?php
/**
 * Hooks for the FlickrImporter extension
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\FlickrImporter;

use MediaWiki\MediaWikiServices;
use Parser;
use Samwilson\PhpFlickr\PhpFlickr;
use SpecialPage;
use Title;
use User;

class Hooks {

	/**
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/GetPreferences
	 * @param User $user
	 * @param array &$preferences
	 * @return bool
	 */
	public static function onGetPreferences( User $user, array &$preferences ) {
		$flickrImporter = new FlickrImporter( $user );
		$flickr = $flickrImporter->getPhpFlickr();
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		// Link to the Flickr connection process, or display current connection status.
		$flickrUser = $flickr instanceof PhpFlickr ? $flickr->test()->login() : false;
		if ( $flickrUser ) {
			// Connected.
			$logoutLink = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'FlickrImporter', 'disconnect' ),
				wfMessage( 'flickrimporter-disconnect' )
			);
			$message = wfMessage( 'flickrimporter-connected', $flickrUser['username'] );
			$loginoutDefault = $message . ' ' . $logoutLink;
		} else {
			// Not connected.
			$loginLink = $linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'FlickrImporter', 'connect' ),
				wfMessage( 'flickrimporter-connect' )
			);
			$loginoutDefault = wfMessage( 'flickrimporter-not-connected' ) . ' ' . $loginLink;
		}
		$preferences['flickrimporter-loginout'] = [
			'type' => 'info',
			'raw' => true,
			'default' => $loginoutDefault,
			'section' => 'misc/flickrimporter',
		];

		// Link to the user's FlickrImporter.json configuration page.
		$jsonPage = Title::newFromText( $user->getUserPage()->getFullText() . '/FlickrImporter.json' );
		$specialLink = $linkRenderer->makeLink( $jsonPage );
		$preferences['flickrimporter-special-link'] = [
			'type' => 'info',
			'raw' => true,
			'default' => wfMessage( 'flickrimporter-imports-link', $specialLink )->plain(),
			'section' => 'misc/flickrimporter',
		];

		return true;
	}

	/**
	 * @param Parser &$parser
	 * @return bool
	 * @throws \MWException
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		$flickr = new FlickrImporter();
		$callback = [ $flickr, 'handlePageProp' ];
		$flags = Parser::SFH_NO_HASH;
		$parser->setFunctionHook( 'FLICKRID', $callback, $flags );
		return true;
	}
}

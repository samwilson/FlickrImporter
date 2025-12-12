<?php
/**
 * Hooks for the FlickrImporter extension
 *
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\FlickrImporter;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\Parser;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\UserOptionsManager;
use Samwilson\PhpFlickr\FlickrException;
use Samwilson\PhpFlickr\PhpFlickr;
use Wikimedia\Rdbms\IConnectionProvider;

class Hooks implements GetPreferencesHook, ParserFirstCallInitHook {

	public function __construct(
		private readonly UserOptionsManager $userOptionsManager,
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly LinkRenderer $linkRenderer
	) {
	}

	/** @inheritDoc */
	public function onGetPreferences( $user, &$preferences ) {
		$flickrImporter = new FlickrImporter(
			$this->userOptionsManager,
			$this->wikiPageFactory,
			$this->connectionProvider,
			$user
		);
		$flickr = $flickrImporter->getPhpFlickr();

		// Link to the Flickr connection process, or display current connection status.
		$flickrUser = false;
		if ( $flickr instanceof PhpFlickr ) {
			try {
				$flickrUser = $flickr->test()->login();
			} catch ( FlickrException $exception ) {
				// Log in failed; do nothing.
			}
		}
		if ( $flickrUser ) {
			// Connected.
			$logoutLink = $this->linkRenderer->makeLink(
				SpecialPage::getTitleFor( 'FlickrImporter', 'disconnect' ),
				wfMessage( 'flickrimporter-disconnect' )
			);
			$message = wfMessage( 'flickrimporter-connected', $flickrUser['username'] );
			$loginoutDefault = $message . ' ' . $logoutLink;
		} else {
			// Not connected.
			$loginLink = $this->linkRenderer->makeLink(
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
		$specialLink = $this->linkRenderer->makeLink( $jsonPage );
		$preferences['flickrimporter-special-link'] = [
			'type' => 'info',
			'raw' => true,
			'default' => wfMessage( 'flickrimporter-imports-link', $specialLink )->plain(),
			'section' => 'misc/flickrimporter',
		];

		return true;
	}

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$flickr = new FlickrImporter( $this->userOptionsManager, $this->wikiPageFactory, $this->connectionProvider );
		$callback = [ $flickr, 'handlePageProp' ];
		$flags = Parser::SFH_NO_HASH;
		$parser->setFunctionHook( 'FLICKRID', $callback, $flags );
		return true;
	}
}

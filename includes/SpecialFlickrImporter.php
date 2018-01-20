<?php
/**
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\FlickrImporter;

use Html;
use MediaWiki\MediaWikiServices;
use OAuth\Common\Http\Exception\TokenResponseException;
use OAuth\Common\Storage\Session;
use Samwilson\PhpFlickr\PhpFlickr;
use SpecialPage;

class SpecialFlickrImporter extends SpecialPage {

	public function __construct( $name = 'FlickrImporter', $restriction = '', $listed = false ) {
		parent::__construct( $name, $restriction, $listed );
	}

	/**
	 * Show the page to the user.
	 * @param string $sub The subpage string argument (if any).
	 * @return string
	 */
	public function execute( $sub ) {
		$this->getOutput()->setPageTitle( $this->msg( 'flickrimporter' ) );
		$this->requireLogin();

		// Configuration.
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'flickrimporter' );
		if ( !$config->has( 'FlickrImporterKey' )
			 || !$config->has( 'FlickrImporterSecret' )
		) {
			// Not configured; complain about this fact.
			return '';
		}
		$flickr = new PhpFlickr(
			$config->get( 'FlickrImporterKey' ),
			$config->get( 'FlickrImporterSecret' )
		);
		$storage = new Session();
		$flickr->setOauthStorage($storage);

		if ( $sub === 'help' ) {
			$this->help();
		} elseif ( $sub === 'connect' ) {
			$this->redirectToFlickr( $flickr );
		} elseif ( $sub === 'callback' ) {
			$this->retrieveAccessToken( $flickr );
		} elseif ( $sub === 'disconnect' ) {
			$this->disconnectFromFlickr( $flickr );
		}
	}

	protected function redirectToFlickr( PhpFlickr $flickr ) {
		$callbackUrl = SpecialPage::getTitleFor('FlickrImporter/callback' )->getCanonicalURL();
		try {
			$url = $flickr->getAuthUrl( 'read', $callbackUrl );
			$this->getOutput()->redirect( $url );
		} catch ( TokenResponseException $exception ) {
			$err = $this->msg('flickrimporter-error-no-auth-url', $exception->getMessage() );
			$this->getOutput()->addHTML( Html::rawElement('p', ['class'=>'error'], $err ) );
		}
	}

	protected function retrieveAccessToken( PhpFlickr $flickr ) {
		$oauthVerifier = $this->getRequest()->getVal( 'oauth_verifier' );
		$oauthToken = $this->getRequest()->getVal( 'oauth_token' );
		$accessToken = $flickr->retrieveAccessToken( $oauthVerifier, $oauthToken );
		$json = json_encode( [
			'token' => $accessToken->getAccessToken(),
			'secret' => $accessToken->getAccessTokenSecret(),
		] );
		$this->getUser()->setOption( 'flickrimporter-accesstoken', $json );
		$this->getUser()->saveSettings();
		$prefsTitle = SpecialPage::getTitleFor(
			'Preferences',
			null, 
			'mw-prefsection-misc' );
		$this->getOutput()->redirect( $prefsTitle->getCanonicalURL() );
	}

	protected function disconnectFromFlickr( PhpFlickr $flickr ) {
		$this->getUser()->setOption( 'flickrimporter-accesstoken', null );
		$this->getUser()->saveSettings();
		$prefsTitle = SpecialPage::getTitleFor(
			'Preferences',
			null,
			'mw-prefsection-misc' );
		$this->getOutput()->redirect( $prefsTitle->getCanonicalURL() );
	}

	protected function help() {
		$this->getOutput()->setPageTitle( $this->msg( 'flickrimporter-help' ) );
		$lang = $this->getUser()->getOption( 'language' );
		$helpDir = dirname( __DIR__ ) . '/help';
		$helpFile = "$helpDir/$lang.txt";
		if ( !file_exists( $helpFile ) ) {
			$helpFile = "$helpDir/en.txt";
		}
		$helpWikitext = file_get_contents( $helpFile );
		$this->getOutput()->addWikiText( $helpWikitext );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}

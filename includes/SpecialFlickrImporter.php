<?php
/**
 * @file
 * @ingroup Extensions
 */

namespace MediaWiki\Extension\FlickrImporter;

use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserOptionsManager;
use OAuth\Common\Http\Exception\TokenResponseException;
use OAuth\Common\Storage\Session;
use Samwilson\PhpFlickr\PhpFlickr;
use SpecialPage;

class SpecialFlickrImporter extends SpecialPage {

	/** @var UserOptionsManager */
	private $userOptionsManager;

	/**
	 * SpecialFlickrImporter constructor.
	 * @param string $name
	 * @param string $restriction
	 * @param bool $listed
	 */
	public function __construct( $name = 'FlickrImporter', $restriction = '', $listed = false ) {
		parent::__construct( $name, $restriction, $listed );
		$this->userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
	}

	/**
	 * Show the page to the user.
	 * @param string $sub The subpage string argument (if any).
	 * @return string
	 */
	public function execute( $sub ) {
		$this->getOutput()->setPageTitle( $this->msg( 'flickrimporter' )->text() );
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
		$flickr->setOauthStorage( $storage );

		if ( $sub === 'connect' ) {
			$this->redirectToFlickr( $flickr );
		} elseif ( $sub === 'callback' ) {
			$this->retrieveAccessToken( $flickr );
		} elseif ( $sub === 'disconnect' ) {
			$this->disconnectFromFlickr( $flickr );
		}
	}

	/**
	 * @param PhpFlickr $flickr
	 * @throws \MWException
	 */
	protected function redirectToFlickr( PhpFlickr $flickr ) {
		$callbackUrl = SpecialPage::getTitleFor( 'FlickrImporter/callback' )->getCanonicalURL();
		try {
			$url = $flickr->getAuthUrl( 'read', $callbackUrl );
			$this->getOutput()->redirect( $url );
		} catch ( TokenResponseException $exception ) {
			$err = $this->msg( 'flickrimporter-error-no-auth-url', $exception->getMessage() );
			$this->getOutput()->addHTML( Html::rawElement( 'p', [ 'class' => 'error' ], $err ) );
		}
	}

	/**
	 * @param PhpFlickr $flickr
	 * @throws \MWException
	 */
	protected function retrieveAccessToken( PhpFlickr $flickr ) {
		$oauthVerifier = $this->getRequest()->getVal( 'oauth_verifier' );
		$oauthToken = $this->getRequest()->getVal( 'oauth_token' );
		$accessToken = $flickr->retrieveAccessToken( $oauthVerifier, $oauthToken );
		$json = json_encode( [
			'token' => $accessToken->getAccessToken(),
			'secret' => $accessToken->getAccessTokenSecret(),
		] );
		$this->userOptionsManager->setOption( $this->getUser(), 'flickrimporter-accesstoken', $json );
		$this->userOptionsManager->saveOptions( $this->getUser() );
		$prefsTitle = SpecialPage::getTitleFor(
			'Preferences',
			null,
			'mw-prefsection-misc' );
		$this->getOutput()->redirect( $prefsTitle->getCanonicalURL() );
	}

	/**
	 * @param PhpFlickr $flickr
	 * @throws \MWException
	 */
	protected function disconnectFromFlickr( PhpFlickr $flickr ) {
		$this->userOptionsManager->setOption( $this->getUser(), 'flickrimporter-accesstoken', null );
		$this->userOptionsManager->saveOptions( $this->getUser() );
		$prefsTitle = SpecialPage::getTitleFor(
			'Preferences',
			null,
			'mw-prefsection-misc' );
		$this->getOutput()->redirect( $prefsTitle->getCanonicalURL() );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'other';
	}
}

<?php

namespace MediaWiki\Extension\FlickrImporter;

use MediaWiki\MediaWikiServices;
use OAuth\Common\Storage\Memory;
use OAuth\OAuth1\Token\StdOAuth1Token;
use OAuth\OAuth1\Token\TokenInterface;
use Samwilson\PhpFlickr\PhpFlickr;
use User;

class FlickrImporter {

	/** @var User */
	protected $user;

	/** @var string */
	protected $optionName = 'flickrimporter-accesstoken';

	/**
	 * @param User $user
	 */
	public function __construct( User $user ) {
		$this->user = $user;
	}

	public function saveAccessToken( TokenInterface $accessToken ) {
		$json = json_encode( [
			'token' => $accessToken->getAccessToken(),
			'secret' => $accessToken->getAccessTokenSecret(),
		] );
		$this->user->setOption( $this->optionName, $json );
		$this->user->saveSettings();
	}

	/**
	 * @return PhpFlickr|bool
	 */
	public function getPhpFlickr() {
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'flickrimporter' );
		if ( !$config->has( 'FlickrImporterKey' )
			 || !$config->has( 'FlickrImporterSecret' )
		) {
			// Not configured.
			return false;
		}
		$flickr = new PhpFlickr(
			$config->get( 'FlickrImporterKey' ),
			$config->get( 'FlickrImporterSecret' )
		);
		$tokenJson = $this->user->getOption( $this->optionName );
		if ( $tokenJson ) {
			$tokenData = json_decode( $tokenJson, true );
			$token = new StdOAuth1Token();
			$token->setAccessToken( $tokenData['token'] );
			$token->setAccessTokenSecret( $tokenData['secret'] );
			$storage = new Memory();
			$storage->storeAccessToken('Flickr', $token);
			$flickr->setOauthStorage($storage);
		}
		return $flickr;
	}
}
<?php

namespace MediaWiki\Extension\FlickrImporter;

use Html;
use MediaWiki\MediaWikiServices;
use OAuth\Common\Storage\Memory;
use OAuth\OAuth1\Token\StdOAuth1Token;
use OAuth\OAuth1\Token\TokenInterface;
use Parser;
use Samwilson\PhpFlickr\PhpFlickr;
use Samwilson\PhpFlickr\Util;
use Title;
use User;
use WikiPage;

class FlickrImporter {

	/** @var User */
	protected $user;

	/** @var string */
	protected $optionName = 'flickrimporter-accesstoken';

	/** @var string */
	const PAGE_PROP_FLICKRID = 'flickrimporter_flickrid';

	/**
	 * @param User $user
	 */
	public function __construct( User $user = null ) {
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

	public function handlePageProp(Parser &$parser, $flickrId) {
		$parser->getOutput()->setProperty( static::PAGE_PROP_FLICKRID, $flickrId );
		$url = 'https://flic.kr/p/' . Util::base58encode( (int)$flickrId );
		$link = Html::element('a', [ 'href' => $url ], $flickrId );
		$linkText = wfMessage( 'flickrimporter-flickrid-link', $link )->plain();
		$html = Html::rawElement('span', [ 'class' => 'flickr-id' ], $linkText);
		return [ 0 => $html, 'isHTML' => true ];
	}

	/**
	 * Get the page relating to a Flickr ID.
	 * @param int $flickrId The Flickr ID.
	 * @return WikiPage|bool The relevant page, or false if none found.
	 */
	public function findFlickrPhoto( $flickrId ) {
		$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$pageId = $db->selectField( 
			'page_props',
			'pp_page',
			[ 'pp_propname' => static::PAGE_PROP_FLICKRID, 'pp_value' => $flickrId ],
			__METHOD__
		);
		if ( $pageId ) {
			return WikiPage::newFromID( $pageId );
		}
		return false;
	}

	/**
	 * Get a filename that is unique, without taking the file extension into account.
	 * @param string $initialTitle The input filename, without an extension or NS prefix.
	 * @return string
	 */
	public function getUniqueFilename( $initialTitle ) {
		$title = Title::newFromText( $initialTitle );
		$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$similar = $db->selectField(
			'page',
			'COUNT(*)',
			[
				'page_namespace' => $title->getNamespace(),
				'page_title' . $db->buildLike( $title->getDBkey(), $db->anyString() ),
			],
			__METHOD__
		);

		if ( $similar > 0 ) {
			$newTitleText = $title->getPrefixedDBkey() . ' (' . ( $similar + 1 ) . ')';
			$title = Title::newFromText( $newTitleText );
		}
		return $title->getText();
	}
}
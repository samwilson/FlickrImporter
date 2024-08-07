<?php

namespace MediaWiki\Extension\FlickrImporter;

use Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPageFactory;
use MediaWikiTitleCodec;
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
	public const PAGE_PROP_FLICKRID = 'flickrimporter_flickrid';

	/** @var UserOptionsManager */
	private $userOptionsManager;

	/**
	 * @param User|null $user
	 */
	public function __construct( User $user = null ) {
		$this->user = $user;
		$this->userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
	}

	/**
	 * @param TokenInterface $accessToken
	 */
	public function saveAccessToken( TokenInterface $accessToken ) {
		$json = json_encode( [
			'token' => $accessToken->getAccessToken(),
			'secret' => $accessToken->getAccessTokenSecret(),
		] );
		$this->userOptionsManager->setOption( $this->user, $this->optionName, $json );
		$this->userOptionsManager->saveOptions( $this->user );
	}

	/**
	 * @return PhpFlickr|bool
	 */
	public function getPhpFlickr() {
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'flickrimporter' );
		if ( !$config->get( 'FlickrImporterKey' )
			 || !$config->get( 'FlickrImporterSecret' )
		) {
			// Not configured.
			return false;
		}
		$flickr = new PhpFlickr(
			$config->get( 'FlickrImporterKey' ),
			$config->get( 'FlickrImporterSecret' )
		);
		$tokenJson = $this->userOptionsManager->getOption( $this->user, $this->optionName );
		if ( $tokenJson ) {
			$tokenData = json_decode( $tokenJson, true );
			$token = new StdOAuth1Token();
			$token->setAccessToken( $tokenData['token'] );
			$token->setAccessTokenSecret( $tokenData['secret'] );
			$storage = new Memory();
			$storage->storeAccessToken( 'Flickr', $token );
			$flickr->setOauthStorage( $storage );
		}
		return $flickr;
	}

	/**
	 * @param Parser &$parser
	 * @param string $flickrId
	 * @return array
	 */
	public function handlePageProp( Parser &$parser, $flickrId ) {
		$parser->getOutput()->setPageProperty( static::PAGE_PROP_FLICKRID, $flickrId );
		$url = 'https://flic.kr/p/' . Util::base58encode( (int)$flickrId );
		$link = Html::element( 'a', [ 'href' => $url ], $flickrId );
		$linkText = wfMessage( 'flickrimporter-flickrid-link', $link )->plain();
		$html = Html::rawElement( 'span', [ 'class' => 'flickr-id' ], $linkText );
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
			return WikiPageFactory::newFromID( $pageId );
		}
		return false;
	}

	/**
	 * Get a filename that is unique, without taking the file extension into account.
	 * @param string $initialTitle The input filename, without an extension or NS prefix.
	 * @return string
	 */
	public function getUniqueFilename( $initialTitle ) {
		$shortTitle = substr( $initialTitle, 0, 230 );
		$cleanTitle = preg_replace( MediaWikiTitleCodec::getTitleInvalidRegex(), ' ', $shortTitle );
		$title = Title::newFromTextThrow( $cleanTitle );
		$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_REPLICA );
		$similar = $db->selectField(
			'page',
			'COUNT(*)',
			[
				'page_namespace' => NS_FILE,
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

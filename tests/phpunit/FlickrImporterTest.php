<?php

namespace MediaWiki\Extension\FlickrImporter\Tests;

use MediaWiki\Extension\FlickrImporter\FlickrImporter;
use MediaWikiTestCase;

class FlickrImporterTest extends MediaWikiTestCase {

	/**
	 * @covers FlickrImporter
	 */
	public function testUniqueFilename() {
		$flickrImporter = new FlickrImporter();

		// A new file keeps the same name.
		$this->assertEquals(
			'Test file',
			$flickrImporter->getUniqueFilename( 'Test file' )
		);

		// The 2nd file gets a suffix.
		$this->insertPage( 'Test file.jpg' );
		$this->assertEquals(
			'Test file (2)',
			$flickrImporter->getUniqueFilename( 'Test file' )
		);

		// The 3rd file also gets a suffix.
		$this->insertPage( 'Test file.pdf' );
		$this->assertEquals(
			'Test file (3)',
			$flickrImporter->getUniqueFilename( 'Test file' )
		);

		// A file that differs in more than just the extension doesn't get changed.
		$this->assertEquals(
			'Test file four',
			$flickrImporter->getUniqueFilename( 'Test file four' )
		);
	}
}

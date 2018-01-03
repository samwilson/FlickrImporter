<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';
require_once __DIR__ . '/../includes/MaintenanceFlickrImporter.php';

use MediaWiki\Extension\FlickrImporter\MaintenanceFlickrImporter;

$maintClass = MaintenanceFlickrImporter::class;

require_once RUN_MAINTENANCE_IF_MAIN;

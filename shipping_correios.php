<?php
/**
 * @package J2Store
 * @copyright Copyright (c)2016-17 Airton Torres / alphahost.net.br
 * @license GNU GPL v3 or later
 */
/** ensure this file is being included by a parent file */
defined('_JEXEC') or die('Restricted access');
require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/version.php');

if(version_compare(J2STORE_VERSION, '3.0.0', 'ge')) {
	//we are using latest version.
	require_once (JPATH_SITE.'/plugins/j2store/shipping_correios/correiosv3.php');	
	
} else {
    Jerror::raiseWarning ( null, 'You are using an old version of J2Store. Please upgrade to the latest version' );
}
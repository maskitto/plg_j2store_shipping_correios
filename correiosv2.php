<?php
/**
 * @package J2Store
* @copyright Copyright (c)2016-17 Airton Torres / alphahost.net.br
* @license GNU GPL v3 or later
*/
// No direct access to this file
defined ( '_JEXEC' ) or die ();

defined('_JEXEC') or die('Restricted access');

require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/library/plugins/shipping.php');
require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/library/base.php');
require_once (JPATH_ADMINISTRATOR.'/components/com_j2store/library/tax.php');
require_once (JPATH_SITE.'/components/com_j2store/helpers/utilities.php');
require_once (JPATH_SITE.'/components/com_j2store/helpers/cart.php');

class plgJ2StoreShipping_Correios extends J2StoreShippingPlugin
{
	/**
	 * @var $_element  string  Should always correspond with the plugin's filename,
	 *                         forcing it to be unique
	 */
    var $_element   = 'shipping_correios';
    private $_isLog      = false;
	private $correios_username = '';
	private $correios_password = '';

    function __construct($subject, $config) {
    	parent::__construct($subject, $config);
    	$this->correios_username = trim($this->params->get('correios_username', ''));
    	$this->correios_password = trim($this->params->get('correios_password', ''));
    	$this->_isLog      = $this->params->get('show_debug')?true:false;
    }

	/**
	 * Method to get shipping rates from CORREIOS
	 *
	 * @param string $element
	 * @param object $order
	 * @return an array of shopping rates
	 */

   function onJ2StoreGetShippingRates($element, $order)
    {
    	$rates = array();
    	//initialise system variables
    	$app = JFactory::getApplication();
    	$db = JFactory::getDbo();

    	// Check if this is the right plugin
    	if (!$this->_isMe($element))
        {
            return $rates;
        }

        //set the address
        $order->setAddress();

        //get the shipping address
        $address = $order->getShippingAddress();

        $rates = $this->getRates($address,$order);
    	return $rates;
    }

    /**
     * Method to get rates from the CORREIOS shipping API
     *
     * @param array $address
     * @return array rates array
     */

    private function getRates($address,$order) {

    	$rates = array();
    	$shipping_status = false;
        
    	//first check if shippable items are in cart
    	JModelLegacy::addIncludePath( JPATH_SITE.'/components/com_j2store/models' );
    	$model = JModelLegacy::getInstance( 'Mycart', 'J2StoreModel');
    	$products = $model->getDataNew();

    	$currencyObject = J2StoreFactory::getCurrencyObject();
    	$store_address = J2StoreHelperCart::getStoreAddress();

    	$domestic_services = $this->params->get('domestic_services');
    	$quote_data = array();
    	$method_data = array();
    	$weight = 0;
        $totalPrice = 0;
    	$weightObject = J2StoreFactory::getWeightObject();
    	foreach ($products as $product) {
    		if ($product['shipping']) {
    			$shipping_status = true;    			
    			$weight_class_id = $product['weight_class_id']?$product['weight_class_id']:$store_address->config_weight_class_id;
    			$pro_weight = $weightObject->convert($product['weight'], $weight_class_id, $this->params->get('correios_weight_class_id', 1));
                        for ($i = 0; $i < $product['quantity'];$i++){
                                $weight += $pro_weight;
                                $totalPrice += $product['price'];
                        }
    		}
    	}
    	
    	if($shipping_status === false) return $rates;

        $countryObject = $this->getCountry($address['country_id']);
        if (!$countryObject->country_isocode_2 == 'BR') return $rates;

    	$postcode = str_replace(' ', '', $address['postal_code']);
        $postcode = str_replace('-', '', $postcode);
        
        //get country data
        $useDeclaredValue = $this->params->get('correios_declared_value', 0);
        $declaredValue = $useDeclaredValue ? intval($totalPrice) : 0;
        $displayTime = $this->params->get('correios_display_time', 0);

        $request = 'nCdEmpresa=' . $this->correios_username .
                   '&sDsSenha=' . $this->correios_password .
                   '&sCepOrigem=' . $this->params->get('correios_postcode') .
                   '&sCepDestino=' . $postcode .
                   '&VlPeso=' . $weight .
                   '&nCdFormato=' . $this->params->get('correios_container') .
                   '&nVlComprimento=' . $this->params->get('correios_length') . 
                   '&nVlAltura=' . $this->params->get('correios_height') .
                   '&nVlLargura=' . $this->params->get('correios_width') .
                   '&CdMaoPropria=' . $this->params-get('correios_own_hand') .
                   '&nVlValorDeclarado=' . $DeclaredValue .
                   '&sCdAvisoRecebimento=' . $this->params-get('correios_delivery_note') .
                   '&nCdServico=' . implode(',', $domestic_services) . 
                   '&nVlDiametro=0&strRetorno=xml&nIndicaCalculo=' . $displayTime ? '3' : '0';
        
        $result = $this->_sendRequest($request);

        if ($result) {
                if ($this->params->get('show_debug')) {
                        $this->_log("CORREIOS DATA SENT: " . urldecode($request));
                        $this->_log("CORREIOS DATA RECV: " . $result);
                }

                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->loadXml($result);
                $postages = $dom->getElementsByTagName('cServico');
                
                foreach ($postages as $postage)
                {
                       $rate = array();
                       $rate['code'] = $postage->getElementsByTagName('Codigo')->Item(0)->nodeValue;
                       $rate['price'] = $postage->getElementsByTagName('Valor')->Item(0)->nodeValue;
                       $rate['extra'] = $this->getHandlingCast($rate['price']);
                       $rate['total'] = $rate['price'] + $rate['extra'];
                       $rate['tax'] = "0.00";
                       $rate['element'] = $this->_element;
                       $rates[] = $rate;
                }
    	}

    	return $rates;

    }

    public function getHandlingCast($price){
    	$handling_percent = $this->params->get ( 'correios_handling_percent', 0 );
    	$handling_fixed = $this->params->get ( 'correios_handling_fix', 0 );
    	$handling = 0;
    	if(( float ) $handling_percent > 0 || ( float ) $handling_fixed > 0){
    		// percentage
    		if (( float ) $handling_percent > 0) {
    			$handling += ($price * ( float ) $handling_percent) / 100;
    		}
    
    		if (( float ) $handling_fixed > 0) {
    			$handling += ( float ) $handling_fixed;
    		}
    	}
    	return $handling;
    }
    
    /**
     *
     * @param string $request
     * @return mixed
     */

    private function _sendRequest($request) {
    	$url = $this->_getActionUrl();

    	$curl = curl_init();

    	curl_setopt($curl, CURLOPT_URL, $url.'?'.$request);
    	curl_setopt($curl, CURLOPT_HEADER, 0);
    	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

    	$result = curl_exec($curl);

    	curl_close($curl);

    	$result = str_replace("\r\n", '', $result);
    	$result = str_replace('\"', '"', $result);

    	return $result;
    }

    /**
     *
     * @return string USPS shipping API url
     */

    private function _getActionUrl() {

        $url = 'http://ws.correios.com.br/calculador/CalcPrecoPrazo.aspx';

    	return $url;
    }

    /**
     * Simple logger
     *
     * @param string $text
     * @param string $type
     * @return void
     */
    function _log($text, $type = 'message')
    {
    	if ($this->_isLog) {
    		$file = JPATH_ROOT . "/cache/{$this->_element}.log";
    		$date = JFactory::getDate();

    		$f = fopen($file, 'a');
    		fwrite($f, "\n\n" . $date->format('Y-m-d H:i:s'));
    		fwrite($f, "\n" . $type . ': ' . $text);
    		fclose($f);
    	}
    }


    function getCountries() {
    	$db = JFactory::getDbo();
    	$query = $db->getQuery(true);
    	$query->select('*')->from('#__j2store_countries');
    	$db->setQuery($query);
    	return $db->loadAssocList('country_isocode_2', 'country_name');
    }

    function getCountry($country_id) {
    	$db = JFactory::getDbo();
    	$query = $db->getQuery(true);
    	$query->select('*')->from('#__j2store_countries')->where('country_id='.$db->q($country_id));
    	$db->setQuery($query);
    	return $db->loadObject();
    }
}